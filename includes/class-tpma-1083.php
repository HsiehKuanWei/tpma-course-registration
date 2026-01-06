<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 商品 1083 專用模組：TPMA 報名流程（草稿/價格/欄位/摘要）。
 */
class TPMA_Woo_Special_1083 {
    /**
     * 目標商品 ID（預設 1083，可透過 filter 覆蓋）。
     */
    const PRODUCT_ID = 1083;

    public static function init() {
        // 專用欄位/驗證/儲存
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'filter_checkout_fields'], 50, 1);
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_checkout_fields'], 50);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_checkout_fields'], 50, 2);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_marker'], 15);

        // 下單後 regs 寫入（內含虛擬帳號）
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'process_order_from_draft'], 10, 1);

        // 價格與摘要
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_price'], 5);
        add_action('woocommerce_checkout_order_review', [__CLASS__, 'render_checkout_summary'], 5);

        // 商品可購買/訪客結帳
        add_filter('woocommerce_is_purchasable', [__CLASS__, 'force_tpma_product_purchasable'], 10, 2);
        add_filter('woocommerce_checkout_registration_required', [__CLASS__, 'allow_guest_checkout_for_tpma'], 10, 1);

        // 付款限制、按鈕文案、導向、email 控制
        add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'filter_payment_gateways'], 99);
        add_filter('woocommerce_default_gateway', [__CLASS__, 'filter_default_gateway']);
        add_filter('woocommerce_order_button_text', [__CLASS__, 'filter_order_button_text']);
        add_filter('woocommerce_get_checkout_url', [__CLASS__, 'filter_checkout_url_for_tpma'], 20);
        add_filter('woocommerce_email_enabled_customer_completed_order', [__CLASS__, 'maybe_disable_woo_emails'], 99, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [__CLASS__, 'maybe_disable_woo_emails'], 99, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [__CLASS__, 'maybe_disable_woo_emails'], 99, 2);
        add_filter('woocommerce_email_enabled_new_order', [__CLASS__, 'maybe_disable_woo_emails'], 99, 2);
        add_filter('woocommerce_bacs_process_payment_order_status', [__CLASS__, 'filter_bacs_status'], 10, 2);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'send_tpma_mails_after_order_created'], 12, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'send_tpma_mails_after_order_completed'], 10, 1);
    }

    /* --------- 判斷/設定 --------- */

    protected static function cart_is_tpma_only(): bool {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        $target_id = apply_filters('tpma_special_product_id', self::PRODUCT_ID);
        $cart = WC()->cart->get_cart();
        if (empty($cart)) return false;
        foreach ($cart as $item) {
            $pid = intval($item['product_id'] ?? 0);
            if ($pid !== intval($target_id)) {
                return false;
            }
        }
        return true;
    }

    protected static function is_target_product_enabled(): bool {
        $pid = apply_filters('tpma_special_product_id', self::PRODUCT_ID);
        return intval($pid) > 0;
    }

    /* --------- 草稿 & 加入購物車 --------- */

    public static function build_draft($course_id, $session_id, $learners, $source = '', $note = '') {
        if (!self::is_target_product_enabled()) {
            return new WP_Error('tpma_special_disabled', 'TPMA 1083 特殊流程未啟用', array('status' => 500));
        }

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
        foreach ((array)$learners as $learner) {
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

        return array(
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
    }

    public static function add_to_cart_from_draft($draft) {
        if (!self::is_target_product_enabled()) {
            return new WP_Error('tpma_special_disabled', 'TPMA 1083 特殊流程未啟用', array('status' => 500));
        }

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

        // 確保 session 寫回
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
     * 下單後 regs 寫入（移植自舊版）。
     */
    public static function process_order_from_draft($order_id) {
        if (!self::is_target_product_enabled()) {
            return false;
        }
        if (!$order_id || !function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // 防重入
        if ($order->get_meta('_tpma_regs_written', true) === 'yes') {
            return;
        }

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        // 若 DB 已有同 order_id regs，也視為已寫入
        $already = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(1) FROM {$regs_table} WHERE woocommerce_order_id = %d", (int)$order_id)
        );
        if ($already > 0) {
            $order->update_meta_data('_tpma_regs_written', 'yes');
            $order->save();
            return;
        }

        // 取 draft：優先 order meta，其次 session
        $draft = null;
        $draft_json = $order->get_meta('_tpma_reg_draft_json', true);
        if ($draft_json) {
            $decoded = json_decode($draft_json, true);
            if (is_array($decoded)) $draft = $decoded;
        }
        if (!$draft) {
            $draft = (WC()->session) ? WC()->session->get('tpma_reg_draft') : null;
        }

        if (empty($draft) || empty($draft['course_id']) || empty($draft['session_id']) || empty($draft['learners']) || !is_array($draft['learners'])) {
            return;
        }

        $course_id   = (int)$draft['course_id'];
        $session_id  = (int)$draft['session_id'];
        $class_date  = sanitize_text_field($draft['class_date'] ?? '');
        $sess_dt     = sanitize_text_field($draft['session_datetime'] ?? '');
        $amount_each = (int)($draft['amount_each'] ?? $draft['remit_amount_per_learner'] ?? 0);

        $payer_user_id = (int)$order->get_customer_id();
        $has_member    = $payer_user_id > 0;

        $inserted_ids = [];
        $reg_nos      = [];

        foreach ($draft['learners'] as $i => $learner) {
            // 產生 reg_no
            $reg_no = '';
            $try = 8;
            while ($try-- > 0) {
                $candidate = TPMA_CR_DB::generate_reg_no('A');

                $insert = array(
                    'reg_no'               => $candidate,
                    'created_at'           => current_time('mysql'),
                    'course_id'            => $course_id,
                    'class_date'           => $class_date,

                    'student_name'         => sanitize_text_field($learner['student_name'] ?? ''),
                    'department'           => sanitize_text_field($learner['department'] ?? ''),
                    'job_title'            => sanitize_text_field($learner['job_title'] ?? ''),
                    'mobile'               => sanitize_text_field($learner['mobile'] ?? ''),
                    'emails'               => sanitize_email($learner['emails'] ?? ''),

                    'contact_name'         => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'contact_email'        => sanitize_email($order->get_billing_email()),
                    'company_name'         => sanitize_text_field($order->get_billing_company()),
                    'tax_id'               => sanitize_text_field($order->get_meta('_billing_vat_id', true)),
                    'phone'                => sanitize_text_field($order->get_billing_phone()),
                    'receipt_type'         => sanitize_text_field($order->get_meta('_tpma_receipt_type', true) ?: 'electronic'),

                    'address'              => sanitize_text_field($order->get_shipping_address_1()),
                    'receiver'             => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),

                    'source'               => sanitize_text_field($draft['source'] ?? ''),
                    'note'                 => sanitize_textarea_field($draft['note'] ?? ''),
                    'remit_amount'         => $amount_each,

                    'status'               => 'cert_pending',
                    'payment_status'       => $order->get_status(),
                    'woocommerce_order_id' => (int)$order_id,
                );

                $ok = $wpdb->insert($regs_table, $insert);

                if ($ok) {
                    $reg_no = $candidate;
                    break;
                }

                $err = (string)$wpdb->last_error;
                if (stripos($err, 'Duplicate') !== false && stripos($err, 'reg_no') !== false) {
                    usleep(20000);
                    continue;
                }
                break;
            }

            if (!$reg_no) {
                continue;
            }

            $rid = (int)$wpdb->insert_id;
            $inserted_ids[] = $rid;
            $reg_nos[] = $reg_no;

            // 決定 wp_user_id：會員→綁會員；否則→虛擬
            $wp_user_id = 0;
            $is_virtual = 0;
            if ($has_member) {
                $wp_user_id = $payer_user_id;
                $is_virtual = 0;
            } else {
                $wp_user_id = self::ensure_virtual_user($reg_no, sanitize_text_field($learner['student_name'] ?? ''));
                $is_virtual = $wp_user_id ? 1 : 0;
            }

            if ($wp_user_id) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$regs_table}
                        SET wp_user_id = %d, is_virtual_user = %d
                        WHERE id = %d",
                        $wp_user_id, $is_virtual, $rid
                    )
                );
            }

            // 把 reg_no 回填到 draft.learners[i]
            $draft['learners'][$i]['reg_no'] = $reg_no;
            $draft['learners'][$i]['reg_id'] = $rid;
        }

        if (!empty($inserted_ids)) {
            $order->update_meta_data('_tpma_reg_ids', wp_json_encode($inserted_ids, JSON_UNESCAPED_UNICODE));
            $order->update_meta_data('_tpma_regs_written', 'yes');
            $order->update_meta_data('_tpma_reg_draft_json', wp_json_encode($draft, JSON_UNESCAPED_UNICODE));
            $order->update_meta_data('_tpma_course_id', $course_id);
            $order->update_meta_data('_tpma_session_id', $session_id);
            $order->update_meta_data('_tpma_session_datetime', $sess_dt);
            $order->update_meta_data('_tpma_learner_count', count($draft['learners']));
            $order->save();
        }

        if (WC()->session) {
            WC()->session->set('tpma_reg_draft', null);
        }
    }

    /* --------- 欄位與驗證 --------- */

    public static function filter_checkout_fields($fields) {
        if (!self::cart_is_tpma_only()) {
            return $fields;
        }
        // 1083 專用欄位覆蓋/新增
        $fields['billing']['tpma_receipt_type'] = array(
            'type'     => 'select',
            'required' => true,
            'label'    => '收據類型',
            'options'  => array(
                ''           => '請選擇',
                'electronic' => '電子收據',
                'paper'      => '紙本收據',
            ),
            'priority' => 100,
        );

        if (isset($fields['billing']['billing_company'])) {
            $fields['billing']['billing_company']['required'] = true;
        }
        if (isset($fields['billing']['billing_vat_id'])) {
            $fields['billing']['billing_vat_id']['required'] = true;
        }
        if (isset($fields['billing']['billing_last_name'])) {
            $fields['billing']['billing_last_name']['required'] = false;
            $fields['billing']['billing_last_name']['type'] = 'hidden';
            $fields['billing']['billing_last_name']['label'] = '';
        }

        // 隱藏手機欄位（由本模組決定不顯示）
        if (isset($fields['billing']['tpma_mobile'])) {
            unset($fields['billing']['tpma_mobile']);
        }
        return $fields;
    }

    public static function validate_checkout_fields() {
        if (!self::cart_is_tpma_only()) {
            return;
        }
        if (empty($_POST['tpma_receipt_type'])) {
            wc_add_notice('請選擇收據類型', 'error');
        }
        if (empty($_POST['billing_company'])) {
            wc_add_notice('請填寫公司抬頭', 'error');
        }
        if (empty($_POST['billing_vat_id'])) {
            wc_add_notice('請填寫統一編號', 'error');
        }
    }

    public static function save_checkout_fields($order, $data) {
        if (!self::cart_is_tpma_only()) {
            return;
        }
        $receipt_type = sanitize_text_field($_POST['tpma_receipt_type'] ?? '');
        if ($receipt_type !== '') {
            $order->update_meta_data('_tpma_receipt_type', $receipt_type);
        }

        // 保存 draft JSON 到訂單，避免 session 丟失
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (!empty($draft)) {
            $order->update_meta_data('_tpma_reg_draft_json', wp_json_encode($draft, JSON_UNESCAPED_UNICODE));
        }
    }

    /* --------- 價格/摘要/購買限制 --------- */

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

    public static function allow_guest_checkout_for_tpma($is_required) {
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (!empty($draft) && !empty($draft['total_learners'])) {
            return false;
        }
        return $is_required;
    }

    public static function filter_payment_gateways($gateways) {
        if (!self::cart_is_tpma_only()) {
            return $gateways;
        }
        return isset($gateways['bacs']) ? ['bacs' => $gateways['bacs']] : $gateways;
    }

    public static function filter_default_gateway($default) {
        if (!self::cart_is_tpma_only()) return $default;
        return 'bacs';
    }

    public static function filter_order_button_text($text) {
        if (!self::cart_is_tpma_only()) return $text;
        return '提交訂單';
    }

    public static function filter_checkout_url_for_tpma($url) {
        if (!self::cart_is_tpma_only()) return $url;
        $custom = self::get_custom_checkout_url();
        return $custom ? $custom : $url;
    }

    public static function maybe_disable_woo_emails($enabled, $order) {
        if (!$order instanceof WC_Order) return $enabled;
        if (!self::is_tpma_order($order)) return $enabled;
        return false;
    }

    public static function filter_bacs_status($status, $order) {
        if (!$order instanceof WC_Order) return $status;
        return self::is_tpma_order($order) ? 'on-hold' : $status;
    }

    public static function send_tpma_mails_after_order_created($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_tpma_order($order)) return;
        if ($order->get_meta('_tpma_mail_sent', true) === 'yes') return;
        if (class_exists('TPMA_CR_Mail_Dispatcher')) {
            TPMA_CR_Mail_Dispatcher::send_after_order_created($order);
        }
        $order->update_meta_data('_tpma_mail_sent', 'yes');
        $order->save();
    }

    public static function send_tpma_mails_after_order_completed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_tpma_order($order)) return;
        if ($order->get_meta('_tpma_completed_mail_sent', true) === 'yes') return;

        if (class_exists('TPMA_CR_Mail_Dispatcher')) {
            if (method_exists('TPMA_CR_Mail_Dispatcher', 'send_after_order_completed')) {
                TPMA_CR_Mail_Dispatcher::send_after_order_completed($order);
            } else {
                TPMA_CR_Mail_Dispatcher::send_after_order_created($order);
            }
        }

        $order->update_meta_data('_tpma_completed_mail_sent', 'yes');
        $order->save();
    }

    /**
     * 並行測試用：命中 1083 時在 console 顯示專用覆蓋。
     */
    public static function enqueue_marker() {
        if (!function_exists('is_checkout') || !function_exists('is_cart')) {
            return;
        }
        if (!is_checkout() && !is_cart()) {
            return;
        }
        if (!self::cart_is_tpma_only()) {
            return;
        }
        wp_register_script('tpma-woo-special-marker', '', [], defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null, true);
        wp_enqueue_script('tpma-woo-special-marker');
        wp_add_inline_script('tpma-woo-special-marker', 'console.log("[TPMA Woo] using NEW plugin (1083 special)");');
    }

    /* --------- Helper functions --------- */

    protected static function is_tpma_order($order) {
        if (!$order instanceof WC_Order) {
            return false;
        }
        return (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true)
            || (bool)$order->get_meta('_tpma_reg_ids', true)
            || (int)$order->get_meta('_tpma_course_id', true) > 0;
    }

    protected static function resolve_registration_product() {
        $product_id = intval(get_option('tpma_cr_wc_product_id', 0));
        if (!$product_id) {
            $product_id = self::PRODUCT_ID; // 預設 ID，可在 option 覆蓋
        }
        $product_id = intval(apply_filters('tpma_cr_registration_product_id', $product_id));
        if (!$product_id) {
            return array(0, null);
        }
        return array($product_id, wc_get_product($product_id));
    }

    protected static function prepare_product_for_registration($product, $unit_price) {
        if (!$product) {
            return $product;
        }
        $price = floatval($unit_price);
        $product->set_regular_price($price);
        $product->set_sale_price('');
        $product->set_price($price);
        if (method_exists($product, 'set_stock_status')) {
            $product->set_stock_status('instock');
        }
        return $product;
    }

    protected static function with_temp_product_overrides($product_id, $unit_price, $callback) {
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

    protected static function get_wc_notices_error_string() {
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
            } elseif ($err instanceof WP_Error) {
                $parts[] = wp_strip_all_tags($err->get_error_message());
            } elseif (is_string($err)) {
                $parts[] = wp_strip_all_tags($err);
            }
        }
        return implode('、', array_filter($parts));
    }

    protected static function ensure_wc_session_ready() {
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

    protected static function format_class_datetime($datetime, $duration_minutes = 0) {
        if (!class_exists('TPMA_CR_DateTime')) {
            return '';
        }
        return TPMA_CR_DateTime::format_range($datetime, $duration_minutes);
    }

    /**
     * 自訂 checkout URL，沿用舊版邏輯。
     */
    protected static function get_custom_checkout_url(): string {
        $page_id = (int) get_option('tpma_cr_custom_checkout_page_id', 0);
        if ($page_id > 0) {
            $u = get_permalink($page_id);
            if ($u) return $u;
        }

        $p = get_page_by_path('tpma-checkout');
        if ($p && !empty($p->ID)) {
            $u = get_permalink($p->ID);
            if ($u) return $u;
        }

        $p = get_page_by_path('tpma-order');
        if ($p && !empty($p->ID)) {
            $u = get_permalink($p->ID);
            if ($u) return $u;
        }

        return '';
    }

    /**
     * 建立虛擬帳號（無會員時用）
     */
    protected static function ensure_virtual_user($reg_no, $display_name = '') {
        $reg_no = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$reg_no);
        if ($reg_no === '') return 0;

        $login = 'tpma_' . strtolower($reg_no);
        $email = 'tpma_' . strtolower($reg_no) . '@noemail.tw-pma.org.tw';

        $u = get_user_by('login', $login);
        if ($u && !is_wp_error($u)) {
            return (int)$u->ID;
        }

        if (email_exists($email)) {
            $email = 'tpma_' . strtolower($reg_no) . '_' . time() . '@noemail.tw-pma.org.tw';
        }

        $uid = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => wp_generate_password(20, true, true),
            'user_email'   => $email,
            'display_name' => $display_name ? $display_name : $login,
            'role'         => 'um_custom_role_2',
        ]);

        if (is_wp_error($uid)) {
            return 0;
        }

        update_user_meta((int)$uid, 'tpma_virtual_user', 1);
        update_user_meta((int)$uid, 'tpma_virtual_reg_no', $reg_no);

        return (int)$uid;
    }
}

