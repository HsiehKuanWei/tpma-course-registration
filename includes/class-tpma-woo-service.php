<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 共用的 WooCommerce 報名處理服務：建立草稿、加車、商品處理
 */
class TPMA_CR_Woo_Service {

    /**
     * 確保 WC session / cart 已初始化
     */
    public static function ensure_wc_session_ready() {
        if (!function_exists('WC')) {
            return new WP_Error('no_woocommerce', 'WooCommerce 尚未載入', array('status' => 500));
        }
        wc_load_cart();

        if (is_null(WC()->session)) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        $cart = WC()->cart;
        if (!$cart) {
            return new WP_Error('no_cart', '無法初始化購物車', array('status' => 500));
        }

        return $cart;
    }

    /**
     * 依據課程/場次/學員資料組裝草稿與金額
     */
    public static function build_draft($course_id, $session_id, $learners_raw, $source = '', $note = '') {
        if (!class_exists('TPMA_CR_DB')) {
            return new WP_Error('no_tpma_db', 'TPMA 資料庫模組尚未載入', array('status' => 500));
        }

        global $wpdb;
        $courses_table   = TPMA_CR_DB::table('courses');
        $sessions_table  = TPMA_CR_DB::table('sessions');
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $course = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $course_id)
        );
        if (!$course) {
            return new WP_Error('course_not_found', '課程不存在', array('status' => 404));
        }

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d AND course_id = %d", $session_id, $course_id)
        );
        if (!$session || empty($session->session_datetime)) {
            return new WP_Error('session_required', '需先排定上課時間後才能報名', array('status' => 400));
        }

        $duration_minutes   = intval($course->duration_minutes ?? 0);
        $hours              = $duration_minutes / 60;
        $base_remit_amount  = (int) round($hours * 1000);
        $remit_amount_per_learner = $base_remit_amount;

        $clean_learners = array();
        foreach ($learners_raw as $learner) {
            $name = sanitize_text_field($learner['student_name'] ?? '');
            if ($name === '') continue;
            $clean_learners[] = array(
                'student_name' => $name,
                'department'   => sanitize_text_field($learner['department'] ?? ''),
                'job_title'    => sanitize_text_field($learner['job_title'] ?? ''),
                'mobile'       => sanitize_text_field($learner['mobile'] ?? ''),
                'emails'       => sanitize_email($learner['emails'] ?? ''),
            );
        }

        $total_learners = count($clean_learners);
        if ($total_learners === 0) {
            return new WP_Error('no_learners', '請至少填寫一位學員', array('status' => 400));
        }
        if ($total_learners >= 6) {
            $remit_amount_per_learner = (int) round($base_remit_amount * 0.8);
        }
        $total_order_amount = $remit_amount_per_learner * $total_learners;

        $lecturer_name = '';
        if (!empty($course->lecturer_code)) {
            $lect = $wpdb->get_row($wpdb->prepare(
                "SELECT lecturers_name, lecturers_title FROM {$lecturers_table} WHERE lecturers_code = %s",
                $course->lecturer_code
            ));
            if ($lect && !empty($lect->lecturers_name)) {
                $lecturer_name = trim($lect->lecturers_name . (!empty($lect->lecturers_title) ? ' ' . $lect->lecturers_title : ''));
            }
        }

        $draft = array(
            'course_id'    => $course_id,
            'session_id'   => $session_id,
            'course_name'  => $course->course_name,
            'lecturer'     => $lecturer_name,
            'session_datetime' => $session->session_datetime,
            'duration_minutes' => $duration_minutes,
            'class_date'   => date('Y-m-d', strtotime($session->session_datetime)),
            'learners'     => $clean_learners,
            'total_learners' => $total_learners,
            'remit_amount_per_learner' => $remit_amount_per_learner,
            'total_order_amount'       => $total_order_amount,
            'source'       => $source,
            'note'         => $note,
        );

        return $draft;
    }

    /**
     * 透過草稿加入購物車並回傳 checkout URL
     */
    public static function add_to_cart_from_draft($draft) {
        $cart = self::ensure_wc_session_ready();
        if (is_wp_error($cart)) {
            return $cart;
        }

        list($product_id, $product) = self::resolve_registration_product();
        if (!$product) {
            return new WP_Error('wc_product_not_found', 'WooCommerce 課程商品不存在', array('status' => 500));
        }
        if (!$product->is_type('simple')) {
            return new WP_Error('wc_product_invalid', '課程商品請設定為「簡單商品」類型', array('status' => 500));
        }
        $allowed_statuses = apply_filters('tpma_cr_wc_product_allowed_statuses', array('publish', 'private'));
        $status = $product->get_status();
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('wc_product_status', '課程商品狀態需為上架或私人（目前狀態：' . esc_html($status) . '）', array('status' => 500));
        }

        // 為避免商品未設售價導致不可購買，執行期設定價格/庫存狀態（不寫回資料庫）
        $product = self::prepare_product_for_registration($product, $draft['remit_amount_per_learner'] ?? 0);

        // 更新 session 草稿供後續結帳摘要與 regs 使用
        WC()->session->set('tpma_reg_draft', $draft);

        // 移除舊草稿品項，避免混淆
        foreach ($cart->get_cart() as $key => $item) {
            if (!empty($item['tpma_reg_draft'])) {
                $cart->remove_cart_item($key);
            }
        }

        $added_key = null;
        self::with_temp_product_overrides($product_id, $draft['remit_amount_per_learner'] ?? 0, function() use (&$added_key, $cart, $product_id, $draft) {
            $added_key = $cart->add_to_cart($product_id, intval($draft['total_learners'] ?? 1), 0, array(), array(
                'tpma_reg_draft' => true,
            ));
        });
        if (!$added_key) {
            $reason = self::get_wc_notices_error_string();
            return new WP_Error('add_to_cart_failed', $reason ?: '加入購物車失敗', array('status' => 500));
        }

        // REST 情境下 Woo 可能不會自動把 cart/session 寫回並下發 session cookie，導致跳轉到 checkout 時購物車為空
        $cart->calculate_totals();
        if (method_exists($cart, 'set_session')) {
            $cart->set_session();
        }
        if (WC()->session && method_exists(WC()->session, 'set_customer_session_cookie')) {
            WC()->session->set_customer_session_cookie(true);
        }
        if (method_exists($cart, 'maybe_set_cart_cookies')) {
            $cart->maybe_set_cart_cookies();
        }
        if (WC()->session && method_exists(WC()->session, 'save_data')) {
            WC()->session->save_data();
        }

        return wc_get_checkout_url();
    }

    /**
     * 取得報名用的 WooCommerce 商品（可透過 option 或 filter 覆寫），並回傳 [商品ID, 商品物件]
     */
    public static function resolve_registration_product() {
        $product_id = intval(get_option('tpma_cr_wc_product_id', 0));
        if (!$product_id) {
            $product_id = 1083; // 預設 ID，可在後台 option tpma_cr_wc_product_id 覆蓋
        }
        $product_id = intval(apply_filters('tpma_cr_registration_product_id', $product_id));
        if (!$product_id) {
            return array(0, null);
        }
        return array($product_id, wc_get_product($product_id));
    }

    /**
     * 為報名流程設定商品的即時售價與庫存狀態（不寫回資料庫）
     */
    public static function prepare_product_for_registration($product, $unit_price) {
        if (!$product) {
            return $product;
        }
        $price = floatval($unit_price);
        $product->set_regular_price($price);
        $product->set_sale_price('');
        $product->set_price($price);
        // 避免商品被標示為缺貨時 add_to_cart 失敗
        if (method_exists($product, 'set_stock_status')) {
            $product->set_stock_status('instock');
        }
        return $product;
    }

    /**
     * 暫時強制指定商品可購買且有價格，避免被 Woo 驗證擋下
     */
    public static function with_temp_product_overrides($product_id, $unit_price, $callback) {
        $price = floatval($unit_price);
        $cb_purchasable = function($purchasable, $product) use ($product_id) {
            if ($product && intval($product->get_id()) === intval($product_id)) {
                return true;
            }
            return $purchasable;
        };
        $cb_price = function($value, $product) use ($product_id, $price) {
            if ($product && intval($product->get_id()) === intval($product_id)) {
                return $price;
            }
            return $value;
        };
        $cb_regular_price = $cb_price;
        $cb_in_stock = function($in_stock, $product) use ($product_id) {
            if ($product && intval($product->get_id()) === intval($product_id)) {
                return true;
            }
            return $in_stock;
        };

        add_filter('woocommerce_is_purchasable', $cb_purchasable, 10, 2);
        add_filter('woocommerce_product_get_price', $cb_price, 10, 2);
        add_filter('woocommerce_product_get_regular_price', $cb_regular_price, 10, 2);
        add_filter('woocommerce_product_is_in_stock', $cb_in_stock, 10, 2);

        try {
            return call_user_func($callback);
        } finally {
            remove_filter('woocommerce_is_purchasable', $cb_purchasable, 10);
            remove_filter('woocommerce_product_get_price', $cb_price, 10);
            remove_filter('woocommerce_product_get_regular_price', $cb_regular_price, 10);
            remove_filter('woocommerce_product_is_in_stock', $cb_in_stock, 10);
        }
    }

    /**
     * 把 WooCommerce error notices 串成一行，方便回傳 REST 錯誤訊息
     */
    public static function get_wc_notices_error_string() {
        if (!function_exists('wc_get_notices')) {
            return '';
        }
        $errors = wc_get_notices('error');
        if (empty($errors) || !is_array($errors)) {
            return '';
        }
        $parts = array();
        foreach ($errors as $err) {
            if (is_array($err) && isset($err['notice'])) {
                $parts[] = wp_strip_all_tags($err['notice']);
            } elseif (is_string($err)) {
                $parts[] = wp_strip_all_tags($err);
            }
        }
        return implode('；', array_filter($parts));
    }
}

