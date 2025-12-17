<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 共用的 WooCommerce 報名處理服務：建立草稿、加車、商品處理 */
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
        return implode('、', array_filter($parts));
    }

    /**
     * 建立 WooCommerce 訂單並回寫到註冊紀錄。
     *
     * @param array $context 訂單建立所需的上下文資料。
     * @return array|WP_Error ['order' => WC_Order, 'order_id' => int]
     */
    public static function create_registration_order(array $context) {
        global $wpdb;

        $ctx = wp_parse_args($context, array(
            'shared'                   => array(),
            'learners'                 => array(),
            'course_id'                => 0,
            'session_id'               => 0,
            'reg_no'                   => '',
            'remit_amount_per_learner' => 0,
            'total_learners'           => 0,
            'total_order_amount'       => 0,
            'inserted_reg_ids'         => array(),
            'regs_table'               => '',
        ));

        list($woocommerce_product_id, $product) = self::resolve_registration_product();
        if (!$product) {
            error_log("TPMA Integration: WooCommerce product ID {$woocommerce_product_id} not found.");
            return new WP_Error('wc_product_not_found', 'WooCommerce 課程商品不存在', array('status' => 500));
        }
        if (!$product->is_type('simple')) {
            return new WP_Error('wc_product_invalid', '課程商品必須是簡單商品類型', array('status' => 500));
        }

        $allowed_statuses = apply_filters('tpma_cr_wc_product_allowed_statuses', array('publish', 'private'));
        $status = $product->get_status();
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('wc_product_status', '課程商品狀態未開放購買 ' . esc_html($status), array('status' => 500));
        }

        $product = self::prepare_product_for_registration($product, $ctx['remit_amount_per_learner']);

        $order = null;
        $item  = null;
        self::with_temp_product_overrides($product->get_id(), $ctx['remit_amount_per_learner'], function() use (&$order, &$item, $product, $ctx) {
            $order = wc_create_order();
            $item  = $order->add_product($product, (int) $ctx['total_learners']);
        });

        $shared    = $ctx['shared'];
        $learners  = $ctx['learners'];
        $bill_name = sanitize_text_field($shared['contact_name'] ?? '');
        $bill_mail = sanitize_email($shared['contact_email'] ?? '');

        if (!empty($shared['contact_same_first']) && !empty($learners[0])) {
            $bill_name = sanitize_text_field($learners[0]['student_name'] ?? '');
            $bill_mail = sanitize_email($learners[0]['emails'] ?? '');
        }

        $order->set_address(array(
            'first_name' => $bill_name,
            'email'      => $bill_mail,
            'company'    => sanitize_text_field($shared['company_name'] ?? ''),
            'phone'      => sanitize_text_field($shared['phone'] ?? ''),
            'address_1'  => sanitize_text_field($shared['address'] ?? ''),
            'city'       => '',
            'state'      => '',
            'postcode'   => '',
            'country'    => 'TW',
        ), 'billing');

        $order->set_address(array(
            'first_name' => sanitize_text_field($shared['receiver'] ?? $bill_name),
            'address_1'  => sanitize_text_field($shared['address'] ?? ''),
            'city'       => '',
            'state'      => '',
            'postcode'   => '',
            'country'    => 'TW',
        ), 'shipping');

        $order->set_total((int) $ctx['total_order_amount']);
        $order->set_currency('TWD');
        $order->set_status('pending');
        $order->set_payment_method('bacs');
        $order->set_payment_method_title('銀行轉帳');

        if (!empty($shared['note'])) {
            $order->add_order_note(sanitize_textarea_field($shared['note']));
        }

        $order->update_meta_data('_tpma_reg_no', $ctx['reg_no']);
        $order->update_meta_data('_tpma_course_id', (int) $ctx['course_id']);
        $order->update_meta_data('_tpma_session_id', (int) $ctx['session_id']);
        $order->update_meta_data('_tpma_receipt_type', sanitize_text_field($shared['receipt_type'] ?? ''));
        $order->update_meta_data('_billing_vat_id', sanitize_text_field($shared['tax_id'] ?? ''));
        $order->update_meta_data('_tpma_learner_count', (int) $ctx['total_learners']);

        if ($item) {
            $line_total = (int) $ctx['total_order_amount'];
            $item->set_subtotal($line_total);
            $item->set_total($line_total);
        }

        $order->set_total((int) $ctx['total_order_amount']);
        $order->calculate_totals(false);
        $order->save();
        $woocommerce_order_id = $order->get_id();

        if (!empty($ctx['inserted_reg_ids']) && $woocommerce_order_id && !empty($ctx['regs_table'])) {
            $ids_in = implode(',', array_map('intval', (array) $ctx['inserted_reg_ids']));
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$ctx['regs_table']} SET woocommerce_order_id = %d, payment_status = %s WHERE id IN ({$ids_in})",
                    $woocommerce_order_id,
                    $order->get_status()
                )
            );
        }

        return array(
            'order'    => $order,
            'order_id' => $woocommerce_order_id,
        );
    }

    /**
     * Checkout 前置摘要。
     */
    public static function render_checkout_summary() {
        if (did_action('tpma_render_checkout_summary')) {
            return;
        }
        do_action('tpma_render_checkout_summary');

        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (empty($draft) || empty($draft['course_name'])) {
            return;
        }

        $date_str = self::format_class_datetime($draft['session_datetime'] ?? '', intval($draft['duration_minutes'] ?? 0));
        echo '<div id="tpma-checkout-summary" class="tpma-checkout-summary" style="margin-bottom:12px;padding:10px;border:1px solid #ddd;box-sizing:border-box;max-width:100%;clear:both;">';
        echo '<strong>課程：</strong>' . esc_html($draft['course_name']) . '<br>';
        if ($date_str) {
            echo '<strong>上課時間：</strong>' . esc_html($date_str) . '<br>';
        }
        if (!empty($draft['learners'])) {
            echo '<strong>學員：</strong><ul style="margin:6px 0 0 16px;padding:0;">';
            foreach ($draft['learners'] as $l) {
                $line = $l['student_name'] ?? '';
                if (!empty($l['job_title'])) {
                    $line .= '（' . $l['job_title'] . '）';
                }
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /**
     * 依草稿設定購物車價格。
     */
    public static function apply_cart_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !WC()->session) {
            return;
        }
        $draft = WC()->session->get('tpma_reg_draft');
        if (empty($draft) || empty($draft['remit_amount_per_learner'])) {
            return;
        }
        $price = floatval($draft['remit_amount_per_learner']);
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['tpma_reg_draft'])) {
                $cart_item['data']->set_price($price);
            }
        }
    }

    /**
     * Checkout 自訂欄位。
     */
    public static function add_checkout_fields($fields) {
        if (!self::is_tpma_reg_cart()) {
            return $fields;
        }
        $fields['billing']['tpma_receipt_type'] = array(
            'type'     => 'select',
            'required' => true,
            'label'    => '發票類型',
            'options'  => array(
                ''            => '請選擇',
                'electronic'  => '電子發票',
                'paper'       => '紙本發票',
            ),
            'priority' => 100,
        );

        if (!isset($fields['billing']['billing_vat_id'])) {
            $fields['billing']['billing_vat_id'] = array(
                'type'     => 'text',
                'required' => false,
                'label'    => '統一編號',
                'priority' => 40,
            );
        } else {
            $fields['billing']['billing_vat_id']['required'] = false;
            if (empty($fields['billing']['billing_vat_id']['label'])) {
                $fields['billing']['billing_vat_id']['label'] = '統一編號';
            }
            $fields['billing']['billing_vat_id']['priority'] = $fields['billing']['billing_vat_id']['priority'] ?? 40;
        }
        if (isset($fields['billing']['billing_country'])) {
            $fields['billing']['billing_country']['priority'] = 50;
        }

        if (isset($fields['billing']['billing_first_name'])) {
            $fields['billing']['billing_first_name']['label'] = '聯絡人';
            $fields['billing']['billing_first_name']['placeholder'] = '請填寫聯絡人';
            $fields['billing']['billing_first_name']['priority'] = 10;
        }
        if (isset($fields['billing']['billing_last_name'])) {
            $fields['billing']['billing_last_name']['required'] = false;
            $fields['billing']['billing_last_name']['label'] = '姓氏（可留空）';
            $fields['billing']['billing_last_name']['class'][] = 'tpma-hide-lastname';
            $fields['billing']['billing_last_name']['priority'] = 11;
        }

        if (isset($fields['billing']['billing_company'])) {
            $fields['billing']['billing_company']['required'] = true;
            $fields['billing']['billing_company']['label'] = $fields['billing']['billing_company']['label'] ?: '公司抬頭';
            $fields['billing']['billing_company']['priority'] = 30;
        }

        foreach (array('billing_country', 'billing_state', 'billing_city', 'billing_postcode', 'billing_address_1', 'billing_address_2') as $f) {
            if (isset($fields['billing'][$f])) {
                unset($fields['billing'][$f]);
            }
        }
        foreach (array('shipping_country', 'shipping_state', 'shipping_city', 'shipping_postcode', 'shipping_address_1', 'shipping_address_2') as $f) {
            if (isset($fields['shipping'][$f])) {
                unset($fields['shipping'][$f]);
            }
        }

        $fields['billing']['tpma_postcode'] = array(
            'type'     => 'text',
            'required' => true,
            'label'    => '郵遞區號',
            'priority' => 60,
            'class'    => array('form-row-first'),
        );
        $fields['billing']['tpma_state'] = array(
            'type'     => 'text',
            'required' => true,
            'label'    => '縣市',
            'priority' => 70,
            'class'    => array('form-row-last'),
        );
        $fields['billing']['tpma_city'] = array(
            'type'     => 'text',
            'required' => true,
            'label'    => '行政區',
            'priority' => 80,
            'class'    => array('form-row-first'),
        );
        $fields['billing']['tpma_street'] = array(
            'type'     => 'text',
            'required' => true,
            'label'    => '街道地址',
            'priority' => 90,
            'class'    => array('form-row-wide'),
        );

        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['label'] = '聯絡信箱';
        }

        return $fields;
    }

    /**
     * 結帳欄位驗證。
     */
    public static function validate_checkout_fields() {
        if (!self::is_tpma_reg_cart()) {
            return;
        }
        if (empty($_POST['tpma_receipt_type'])) {
            wc_add_notice('請選擇發票類型', 'error');
        }
        if (empty($_POST['billing_company'])) {
            wc_add_notice('請填寫公司抬頭', 'error');
        }

        $custom_address_required = array(
            'tpma_postcode' => '請填寫郵遞區號',
            'tpma_state'    => '請填寫縣市/州',
            'tpma_city'     => '請填寫行政區',
            'tpma_street'   => '請填寫街道地址',
        );
        foreach ($custom_address_required as $key => $msg) {
            if (empty($_POST[$key])) {
                wc_add_notice($msg, 'error');
            }
        }

        if (empty($_POST['billing_last_name']) && !empty($_POST['billing_first_name'])) {
            $_POST['billing_last_name'] = sanitize_text_field($_POST['billing_first_name']);
        }
        if (!empty($_POST['billing_vat_id']) && !preg_match('/^[0-9]{8}$/', $_POST['billing_vat_id'])) {
            wc_add_notice('統一編號需為 8 碼數字', 'error');
        }
    }

   /**
     * 儲存自訂欄位到訂單。
     */
    public static function save_checkout_fields($order, $data) {
        if (!self::is_tpma_reg_cart()) {
            return;
        }
        $receipt_type = sanitize_text_field($_POST['tpma_receipt_type'] ?? '');
        if ($receipt_type) {
            $order->update_meta_data('_tpma_receipt_type', $receipt_type);
        }
        if (!empty($_POST['billing_vat_id'])) {
            $order->update_meta_data('_billing_vat_id', sanitize_text_field($_POST['billing_vat_id']));
        }

        $zip    = sanitize_text_field($_POST['tpma_postcode'] ?? '');
        $state  = sanitize_text_field($_POST['tpma_state'] ?? '');
        $city   = sanitize_text_field($_POST['tpma_city'] ?? '');
        $street = sanitize_text_field($_POST['tpma_street'] ?? '');
        if ($zip || $state || $city || $street) {
            $billing_addr = $order->get_address('billing');
            $billing_addr['postcode']  = $zip;
            $billing_addr['state']     = $state;
            $billing_addr['city']      = $city;
            $billing_addr['address_1'] = $street;
            $billing_addr['country']   = $billing_addr['country'] ?: 'TW';
            $order->set_address($billing_addr, 'billing');

            $shipping_addr = $order->get_address('shipping');
            if (empty($shipping_addr['first_name'])) {
                $shipping_addr['first_name'] = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
            }
            $shipping_addr['postcode']  = $zip;
            $shipping_addr['state']     = $state;
            $shipping_addr['city']      = $city;
            $shipping_addr['address_1'] = $street;
            $shipping_addr['country']   = $shipping_addr['country'] ?: 'TW';
            $order->set_address($shipping_addr, 'shipping');

            $order->update_meta_data('_tpma_postcode', $zip);
            $order->update_meta_data('_tpma_state', $state);
            $order->update_meta_data('_tpma_city', $city);
            $order->update_meta_data('_tpma_street', $street);
        }
    }

    /**
     * 根據草稿寫入 regs 與訂單 meta。
     */
    public static function process_order_from_draft($order_id) {
        error_log("TPMA Debug: process_order_from_draft called for order ID: {$order_id}");

        if (!$order_id || !function_exists('WC')) {
            error_log("TPMA Debug: process_order_from_draft - Invalid order ID or WC not loaded.");
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("TPMA Debug: process_order_from_draft - Order object not found for ID: {$order_id}");
            return;
        }

        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (empty($draft) || empty($draft['course_id']) || empty($draft['learners'])) {
            error_log("TPMA Debug: process_order_from_draft - Draft data missing or incomplete. Draft: " . print_r($draft, true));
            return;
        }
        error_log("TPMA Debug: process_order_from_draft - Draft data found: " . print_r($draft, true));

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');
        $reg_no = TPMA_CR_DB::generate_reg_no('A');
        $class_date = sanitize_text_field($draft['class_date'] ?? '');
        $course_id  = intval($draft['course_id']);
        $session_datetime = $draft['session_datetime'] ?? '';

        foreach ($draft['learners'] as $learner) {
            $insert = array(
                'reg_no'        => $reg_no,
                'created_at'    => current_time('mysql'),
                'course_id'     => $course_id,
                'class_date'    => $class_date,
                'student_name'  => sanitize_text_field($learner['student_name'] ?? ''),
                'department'    => sanitize_text_field($learner['department'] ?? ''),
                'job_title'     => sanitize_text_field($learner['job_title'] ?? ''),
                'mobile'        => sanitize_text_field($learner['mobile'] ?? ''),
                'emails'        => sanitize_email($learner['emails'] ?? ''),
                'contact_name'  => $order->get_billing_first_name(),
                'contact_email' => $order->get_billing_email(),
                'company_name'  => $order->get_billing_company(),
                'tax_id'        => $order->get_meta('_billing_vat_id', true),
                'phone'         => $order->get_billing_phone(),
                'receipt_type'  => $order->get_meta('_tpma_receipt_type', true) ?: 'electronic',
                'address'       => $order->get_shipping_address_1(),
                'receiver'      => $order->get_shipping_first_name(),
                'source'        => $draft['source'] ?? '',
                'note'          => $draft['note'] ?? '',
                'remit_amount'  => intval($draft['remit_amount_per_learner'] ?? 0),
                'status'        => 'pending',
                'payment_status'=> $order->get_status(),
                'woocommerce_order_id' => $order_id,
            );
            $wpdb->insert($regs_table, $insert);

            if (!$wpdb->insert_id) {
                error_log("TPMA Debug: process_order_from_draft - INSERT FAILED for reg_no {$reg_no}: " . $wpdb->last_error);
            } else {
                error_log("TPMA Debug: process_order_from_draft - Inserted registration ID: {$wpdb->insert_id} for reg_no {$reg_no}");
            }
        }

        $order->update_meta_data('_tpma_reg_no', $reg_no);
        $order->update_meta_data('_tpma_course_id', $course_id);
        $order->update_meta_data('_tpma_session_datetime', $session_datetime);
        $order->update_meta_data('_tpma_learner_count', intval($draft['total_learners'] ?? 0));
        $order->save();
        error_log("TPMA Debug: process_order_from_draft - Order meta updated for order ID: {$order_id}");

        WC()->session->set('tpma_reg_draft', null);
        error_log("TPMA Debug: process_order_from_draft - Cleared tpma_reg_draft from session.");
    }

    /**
     * 課程商品保持可購買狀態。
     */
    public static function force_tpma_product_purchasable($purchasable, $product) {
        if (!$product) {
            return $purchasable;
        }
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (empty($draft) || empty($draft['total_learners'])) {
            return $purchasable;
        }
        list($pid) = self::resolve_registration_product();
        if ($pid && intval($product->get_id()) === intval($pid)) {
            return true;
        }
        return $purchasable;
    }

    /**
     * 草稿存在時允許訪客結帳。
     */
    public static function allow_guest_checkout_for_tpma($is_required) {
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (!empty($draft) && !empty($draft['total_learners'])) {
            return false;
        }
        return $is_required;
    }

    private static function is_tpma_reg_cart() {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        list($pid) = self::resolve_registration_product();
        if (!$pid) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (intval($item['product_id']) === intval($pid) && !empty($item['tpma_reg_draft'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 結帳頁快速帶入第一位學員。
     */
    public static function render_auto_fill_controls() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        $first_name = $draft['learners'][0]['student_name'] ?? '';
        $first_email = $draft['learners'][0]['emails'] ?? '';
        if ($first_name === '' && $first_email === '') {
            return;
        }
        ?>
        <style>
            .tpma-hide-lastname { display: none !important; }
        </style>
        <div class="tpma-autofill" style="margin-bottom:10px;">
            <label><input type="checkbox" id="tpma-fill-first-learner"> 套用第一位學員</label>
        </div>
        <script>
            (function() {
                var cb = document.getElementById('tpma-fill-first-learner');
                if (!cb) return;
                var name = <?php echo wp_json_encode($first_name); ?>;
                var email = <?php echo wp_json_encode($first_email); ?>;
                cb.addEventListener('change', function() {
                    var n = document.getElementById('billing_first_name');
                    var ln = document.getElementById('billing_last_name');
                    var em = document.getElementById('billing_email');
                    if (cb.checked) {
                        if (n && name) n.value = name;
                        if (ln && name) ln.value = name;
                        if (em && email) em.value = email;
                    }
                });
            })();
        </script>
        <?php
    }

    /**
     * Format class datetime using the shared standard formatter.
     */
    private static function format_class_datetime($datetime, $duration_minutes = 0) {
        if (!class_exists('TPMA_CR_DateTime')) {
            return '';
        }
        return TPMA_CR_DateTime::format_range($datetime, $duration_minutes);
    }
}
