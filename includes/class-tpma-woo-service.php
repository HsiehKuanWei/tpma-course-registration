<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TPMA_CR_Woo_Shared')) {
    require_once __DIR__ . '/class-tpma-woo-shared.php';
}

/**
 * 共用的 WooCommerce 報名處理服務：建立草稿、加車、商品處理 */
class TPMA_CR_Woo_Service {

    /**
     * 確保 WC session / cart 已初始化
     */
    public static function ensure_wc_session_ready() {
        return TPMA_CR_Woo_Shared::ensure_wc_session_ready();
    }

    /**
     * 依據課程/場次/學員資料組裝草稿與金額
     */
    public static function build_draft($course_id, $session_id, $learners_raw, $source = '', $note = '') {
        return TPMA_CR_Woo_Shared::build_draft($course_id, $session_id, $learners_raw, $source, $note, array(
            'messages' => array(
                'no_tpma_db'      => 'TPMA 資料庫模組尚未載入',
                'session_required' => '需先排定上課時間後才能報名',
                'no_learners'      => '請至少填寫一位學員',
            ),
        ));
    }

    public static function tpma_wrap_checkout_groups($field_html, $key, $args, $value) {
        if (class_exists('TPMA_Woo_Common_Fields') && method_exists('TPMA_Woo_Common_Fields', 'wrap_form_field_groups')) {
            return TPMA_Woo_Common_Fields::wrap_form_field_groups($field_html, $key, $args, $value);
        }
        return $field_html;
    }


    /**
     * 透過草稿加入購物車並回傳 checkout URL
     */
    public static function add_to_cart_from_draft($draft) {
        return TPMA_CR_Woo_Shared::add_to_cart_from_draft($draft, array(
            'default_product_id' => 1083,
            'messages' => array(
                'product_not_found'  => 'WooCommerce 課程商品不存在',
                'product_invalid'    => '課程商品請設定為「簡單商品」類型',
                'product_status'     => '課程商品狀態需為上架或私人（目前狀態：%s）',
                'add_to_cart_failed' => '加入購物車失敗',
            ),
        ));
    }

    /**
     * 取得報名用的 WooCommerce 商品（可透過 option 或 filter 覆寫），並回傳 [商品ID, 商品物件]
     */
    public static function resolve_registration_product() {
        return TPMA_CR_Woo_Shared::resolve_registration_product(1083);
    }

    /**
     * 為報名流程設定商品的即時售價與庫存狀態（不寫回資料庫）
     */
    public static function prepare_product_for_registration($product, $unit_price) {
        return TPMA_CR_Woo_Shared::prepare_product_for_registration($product, $unit_price);
    }

    /**
     * 暫時強制指定商品可購買且有價格，避免被 Woo 驗證擋下
     */
    public static function with_temp_product_overrides($product_id, $unit_price, $callback) {
        return TPMA_CR_Woo_Shared::with_temp_product_overrides($product_id, $unit_price, $callback);
    }

    /**
     * 把 WooCommerce error notices 串成一行，方便回傳 REST 錯誤訊息
     */
    public static function get_wc_notices_error_string() {
        return TPMA_CR_Woo_Shared::get_wc_notices_error_string();
    }

    /**
     * 建立 WooCommerce 訂單並回寫到註冊紀錄。
     *
     * @param array $context 訂單建立所需的上下文資料。
     * @return array|WP_Error [\'order\' => WC_Order, \'order_id\' => int]
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
/*
        if (!empty($shared['note'])) {
            $order->add_order_note(sanitize_textarea_field($shared['note']));
        }
*/
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
        if (class_exists('TPMA_Woo_Common_Fields') && method_exists('TPMA_Woo_Common_Fields', 'filter_checkout_fields')) {
            return TPMA_Woo_Common_Fields::filter_checkout_fields($fields);
        }
        return $fields;
    }

    /**
     * 結帳欄位驗證。
     */
    public static function validate_checkout_fields() {
        if (class_exists('TPMA_Woo_Common_Fields') && method_exists('TPMA_Woo_Common_Fields', 'validate_checkout_fields')) {
            TPMA_Woo_Common_Fields::validate_checkout_fields();
        }
    }

    /**
     * 儲存自訂欄位。
     */
    public static function save_checkout_fields($order, $data) {
        if (class_exists('TPMA_Woo_Common_Fields') && method_exists('TPMA_Woo_Common_Fields', 'save_checkout_fields')) {
            TPMA_Woo_Common_Fields::save_checkout_fields($order, $data);
        }
    }

    protected static function ensure_virtual_user($reg_no, $display_name = '')
    {
        return TPMA_CR_Woo_Shared::ensure_virtual_user($reg_no, $display_name, true);
    }


    /**
     * 根據草稿寫入 regs 與訂單 meta。
     * 規則：
     * - regs.status：TPMA 報名/發證狀態（不與 Woo 同步）
     * - regs.payment_status：Woo 付款狀態（會隨 Woo 狀態變更而更新）
     * - 同一張訂單只能寫入一次 regs（防止重複觸發）
     */
    public static function process_order_from_draft($order_id)
    {
        TPMA_CR_Woo_Shared::process_order_from_draft($order_id, array(
            'logger' => function($msg) { error_log($msg); },
            'clear_session_keys' => array('tpma_reg_draft'),
            'log_virtual_user' => true,
        ));
    }



    /**
     * 強制 TPMA 商品可購買。
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
        <div class="tpma-autofill">
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
        return TPMA_CR_Woo_Shared::format_class_datetime($datetime, $duration_minutes);
    }


}
