<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared Woo helpers for TPMA registration flow (draft/cart/regs).
 */
class TPMA_CR_Woo_Shared
{
    /**
     * Sanitize learner emails while preserving comma/semicolon separators.
     */
    private static function sanitize_emails_raw($raw) {
        $text = sanitize_text_field($raw ?? '');
        if ($text === '') return '';
        // Normalize full-width separators/spaces to half-width for consistency.
        $text = str_replace(array('，', '；'), array(',', ';'), $text);
        return trim($text);
    }
    /**
     * Ensure WC session/cart is ready.
     */
    public static function ensure_wc_session_ready() {
        if (!function_exists('WC')) {
            return new WP_Error('no_woocommerce', 'WooCommerce 尚未啟用', array('status' => 500));
        }
        wc_load_cart();

        if (is_null(WC()->session)) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        $cart = WC()->cart;
        if (!$cart) {
            return new WP_Error('no_cart', '購物車初始化失敗', array('status' => 500));
        }

        return $cart;
    }

    /**
     * Build draft payload from course/session/learners.
     */
    public static function build_draft($course_id, $session_id, $learners_raw, $source = '', $note = '', array $opts = array()) {
        $messages = isset($opts['messages']) && is_array($opts['messages']) ? $opts['messages'] : array();
        $messages = array_merge(array(
            'no_tpma_db'      => 'TPMA 資料庫尚未初始化',
            'course_not_found' => '課程不存在',
            'session_required' => '請選擇上課場次',
            'no_learners'      => '請填寫學員資料',
        ), $messages);

        if (!class_exists('TPMA_CR_DB')) {
            return new WP_Error('no_tpma_db', $messages['no_tpma_db'], array('status' => 500));
        }

        global $wpdb;
        $courses_table   = TPMA_CR_DB::table('courses');
        $sessions_table  = TPMA_CR_DB::table('sessions');
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $course = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $course_id)
        );
        if (!$course) {
            return new WP_Error('course_not_found', $messages['course_not_found'], array('status' => 404));
        }

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d AND course_id = %d", $session_id, $course_id)
        );
        if (!$session || empty($session->session_datetime)) {
            return new WP_Error('session_required', $messages['session_required'], array('status' => 400));
        }

        $duration_minutes   = intval($course->duration_minutes ?? 0);
        $hours              = $duration_minutes / 60;
        $base_remit_amount  = (int) round($hours * 1000);
        $remit_amount_per_learner = $base_remit_amount;

        $clean_learners = array();
        foreach ((array)$learners_raw as $learner) {
            $name = sanitize_text_field($learner['student_name'] ?? '');
            if ($name === '') continue;
            $clean_learners[] = array(
                'student_name' => $name,
                'department'   => sanitize_text_field($learner['department'] ?? ''),
                'job_title'    => sanitize_text_field($learner['job_title'] ?? ''),
                'mobile'       => sanitize_text_field($learner['mobile'] ?? ''),
                'emails'       => self::sanitize_emails_raw($learner['emails'] ?? ''),
            );
        }

        $total_learners = count($clean_learners);
        if ($total_learners === 0) {
            return new WP_Error('no_learners', $messages['no_learners'], array('status' => 400));
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

    /**
     * Resolve WooCommerce registration product ID & object.
     */
    public static function resolve_registration_product($default_product_id = 1083) {
        $product_id = intval(get_option('tpma_cr_wc_product_id', 0));
        if (!$product_id) {
            $product_id = (int) $default_product_id;
        }
        $product_id = intval(apply_filters('tpma_cr_registration_product_id', $product_id));
        if (!$product_id) {
            return array(0, null);
        }
        return array($product_id, wc_get_product($product_id));
    }

    public static function prepare_product_for_registration($product, $unit_price) {
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
            } elseif ($err instanceof WP_Error) {
                $parts[] = wp_strip_all_tags($err->get_error_message());
            } elseif (is_string($err)) {
                $parts[] = wp_strip_all_tags($err);
            }
        }
        return implode('?', array_filter($parts));
    }

    /**
     * Add draft to cart and return checkout URL.
     */
    public static function add_to_cart_from_draft($draft, array $opts = array()) {
        $default_product_id = isset($opts['default_product_id']) ? (int) $opts['default_product_id'] : 1083;
        $before_add = isset($opts['before_add']) && is_callable($opts['before_add']) ? $opts['before_add'] : null;
        $messages = isset($opts['messages']) && is_array($opts['messages']) ? $opts['messages'] : array();
        $messages = array_merge(array(
            'product_not_found'  => 'WooCommerce 商品不存在',
            'product_invalid'    => '商品類型不正確',
            'product_status'     => '商品狀態不可購買(狀態:%s)',
            'add_to_cart_failed' => '加入購物車失敗',
        ), $messages);

        $cart = self::ensure_wc_session_ready();
        if (is_wp_error($cart)) {
            return $cart;
        }

        list($product_id, $product) = self::resolve_registration_product($default_product_id);
        if (!$product) {
            return new WP_Error('wc_product_not_found', $messages['product_not_found'], array('status' => 500));
        }
        if (!$product->is_type('simple')) {
            return new WP_Error('wc_product_invalid', $messages['product_invalid'], array('status' => 500));
        }
        $allowed_statuses = apply_filters('tpma_cr_wc_product_allowed_statuses', array('publish', 'private'));
        $status = $product->get_status();
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('wc_product_status', sprintf($messages['product_status'], esc_html($status)), array('status' => 500));
        }

        $product = self::prepare_product_for_registration($product, $draft['remit_amount_per_learner'] ?? 0);

        // 存入 session（供 checkout/regs 使用）
        WC()->session->set('tpma_reg_draft', $draft);
        if ($before_add) {
            call_user_func($before_add, $cart, $draft);
        }

        // 清除舊的草稿商品
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
            return new WP_Error('add_to_cart_failed', $reason ?: $messages['add_to_cart_failed'], array('status' => 500));
        }

        // 固化 session/cookies
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
     * Ensure virtual user for registrations.
     */
    public static function ensure_virtual_user($reg_no, $display_name = '', $log_on_error = false) {
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
            if ($log_on_error) {
                error_log('TPMA Debug: ensure_virtual_user failed: ' . $uid->get_error_message());
            }
            return 0;
        }

        update_user_meta((int)$uid, 'tpma_virtual_user', 1);
        update_user_meta((int)$uid, 'tpma_virtual_reg_no', $reg_no);

        return (int)$uid;
    }

    /**
     * Process order -> regs, shared core.
     */
    public static function process_order_from_draft($order_id, array $opts = array()) {
        $logger = isset($opts['logger']) && is_callable($opts['logger']) ? $opts['logger'] : null;
        $clear_session_keys = isset($opts['clear_session_keys']) && is_array($opts['clear_session_keys'])
            ? $opts['clear_session_keys']
            : array('tpma_reg_draft');
        $log_virtual_user = !empty($opts['log_virtual_user']);

        $log = function($msg) use ($logger) {
            if ($logger) {
                call_user_func($logger, $msg);
            }
        };

        $log("TPMA Debug: process_order_from_draft called for order ID: {$order_id}");

        if (!$order_id || !function_exists('WC')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        if ($order->get_meta('_tpma_regs_written', true) === 'yes') {
            $log("TPMA Debug: process_order_from_draft - already written (meta) for order {$order_id}");
            return;
        }

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        $already = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(1) FROM {$regs_table} WHERE woocommerce_order_id = %d", (int)$order_id)
        );
        if ($already > 0) {
            $order->update_meta_data('_tpma_regs_written', 'yes');
            $order->save();
            $log("TPMA Debug: process_order_from_draft - already exist in DB for order {$order_id}, mark meta and skip.");
            return;
        }

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
            $log("TPMA Debug: process_order_from_draft - Draft missing/incomplete. Draft: " . print_r($draft, true));
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
                    'emails'               => self::sanitize_emails_raw($learner['emails'] ?? ''),

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

                $log("TPMA Debug: process_order_from_draft - INSERT FAILED: {$err}");
                break;
            }

            if (!$reg_no) {
                $log("TPMA Debug: process_order_from_draft - give up generating reg_no.");
                continue;
            }

            $rid = (int)$wpdb->insert_id;
            $inserted_ids[] = $rid;
            $reg_nos[] = $reg_no;

            $wp_user_id = 0;
            $is_virtual = 0;

            if ($has_member) {
                $wp_user_id = $payer_user_id;
                $is_virtual = 0;
            } else {
                $wp_user_id = self::ensure_virtual_user($reg_no, sanitize_text_field($learner['student_name'] ?? ''), $log_virtual_user);
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
            foreach ($clear_session_keys as $key) {
                WC()->session->set($key, null);
            }
        }

        $log("TPMA Debug: process_order_from_draft - done for order {$order_id}, inserted=" . count($inserted_ids));
    }

    public static function get_custom_checkout_url(): string {
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

    public static function format_class_datetime($datetime, $duration_minutes = 0) {
        if (!class_exists('TPMA_CR_DateTime')) {
            return '';
        }
        return TPMA_CR_DateTime::format_range($datetime, $duration_minutes);
    }
}
