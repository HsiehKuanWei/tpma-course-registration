<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TPMA_CR_Woo_Shared')) {
    require_once __DIR__ . '/class-tpma-woo-shared.php';
}

/**
 * 特殊商品專用模組：TPMA 報名流程（草稿/價格/欄位/摘要）。
 */
class TPMA_Woo_Special_Product {
    /**
     * 舊站相容常數，執行期不應直接依賴。
     */
    const PRODUCT_ID = 0;
    const LEGACY_PRODUCT_ID = 1083;
    const REG_TIMEOUT_SECONDS = 1800;

    protected static function get_target_product_id(): int {
        if (class_exists('TPMA_CR_Settings') && method_exists('TPMA_CR_Settings', 'get_special_product_id')) {
            return (int) TPMA_CR_Settings::get_special_product_id();
        }
        return (int) apply_filters('tpma_special_product_id', 0);
    }

    public static function init() {
        // 專用欄位/驗證/儲存
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'filter_checkout_fields'], 999, 1);
        add_action('woocommerce_checkout_process', [__CLASS__, 'prime_invoice_type_for_tpma'], 1);
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_checkout_fields'], 50);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'prime_invoice_type_for_tpma'], 1, 2);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_checkout_fields'], 50, 2);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_marker'], 15);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles'], 16);
        add_filter('woocommerce_form_field', [__CLASS__, 'wrap_form_field_groups'], 18, 4);
        add_filter('body_class', [__CLASS__, 'filter_body_class'], 20, 1);
        add_filter('tpma_common_required_fields', [__CLASS__, 'filter_common_required_fields'], 20, 2);

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
        add_filter('tpma_order_email_viewable_product_ids', [__CLASS__, 'filter_email_viewable_product_ids'], 10, 2);
        add_filter('tpma_is_tpma_order', [__CLASS__, 'filter_tpma_order'], 10, 2);
        add_filter('tpma_woo_fields_allow_order_pay_retry', [__CLASS__, 'filter_allow_order_pay_retry'], 10, 2);
        add_filter('tpma_woo_fields_allow_card_retry', [__CLASS__, 'filter_allow_card_retry'], 10, 2);

        // 禁止混車 + 中斷流程自動清空
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'enforce_no_mixed_cart'], 10, 3);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'enforce_no_mixed_cart_on_cart']);
        add_action('template_redirect', [__CLASS__, 'maybe_abort_tpma_flow'], 9);

        // 發票外掛整合：1083 訂單不開立發票（僅在發票模組存在時啟用）
        self::register_opay_invoice_guards();
    }

    protected static function register_opay_invoice_guards() {
        if (!self::is_opay_invoice_module_available()) {
            return;
        }

        // 經典結帳：在發票外掛儲存欄位後覆寫為 tax_exempt。
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'mark_order_opay_tax_exempt'], 99, 2);
        // Block Checkout：Store API 更新訂單時同樣標記。
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'mark_order_opay_tax_exempt_block'], 99, 2);

        // 保險：付款/狀態切換前先標記，避免任何路徑被自動開立。
        add_action('woocommerce_payment_complete', [__CLASS__, 'mark_order_opay_tax_exempt_by_id'], 1, 1);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'mark_order_opay_tax_exempt_by_id'], 1, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'mark_order_opay_tax_exempt_by_id'], 1, 1);

        // 手動開立/重試攔截。
        add_action('admin_post_opay_invoice_manual', [__CLASS__, 'intercept_opay_manual_issue_request'], 1);
        add_action('woocommerce_order_action_opay_invoice_issue', [__CLASS__, 'intercept_opay_order_action_issue'], 1, 1);
        add_action('woocommerce_order_action_opay_invoice_retry', [__CLASS__, 'intercept_opay_order_action_retry'], 1, 1);
    }

    protected static function is_opay_invoice_module_available(): bool {
        return class_exists('WC_OPay_Invoice_Settings')
            && class_exists('WC_OPay_Invoice_Checkout_Fields')
            && class_exists('WC_OPay_Invoice_Admin_Actions');
    }

    public static function mark_order_opay_tax_exempt($order, $data = null) {
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!self::order_has_target_product($order)) {
            return;
        }
        self::apply_opay_tax_exempt_meta($order);
    }

    public static function mark_order_opay_tax_exempt_block($order, $request) {
        self::mark_order_opay_tax_exempt($order, null);
    }

    public static function mark_order_opay_tax_exempt_by_id($order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        self::mark_order_opay_tax_exempt($order, null);
        $order->save();
    }

    public static function intercept_opay_manual_issue_request() {
        if (!self::is_opay_invoice_module_available()) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $opay_action = sanitize_key($_GET['opay_action'] ?? '');
        if (!in_array($opay_action, array('issue', 'retry'), true)) {
            return;
        }

        if (defined('WC_OPAY_NONCE_ORDER_ACTION')) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
            if ('' === $nonce || !wp_verify_nonce($nonce, WC_OPAY_NONCE_ORDER_ACTION)) {
                return;
            }
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || !self::order_has_target_product($order)) {
            return;
        }

        self::apply_opay_tax_exempt_meta($order);
        $order->add_order_note('[電子發票] 已阻擋手動開立：特殊商品不開立發票。');
        $order->save();
        self::set_opay_admin_notice('error', '此訂單包含特殊商品，不可開立發票。');

        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }

    public static function intercept_opay_order_action_issue($order) {
        self::intercept_opay_order_action_common($order, 'issue');
    }

    public static function intercept_opay_order_action_retry($order) {
        self::intercept_opay_order_action_common($order, 'retry');
    }

    protected static function intercept_opay_order_action_common($order, $action) {
        if (!self::is_opay_invoice_module_available()) {
            return;
        }
        if (!$order instanceof WC_Order || !self::order_has_target_product($order)) {
            return;
        }

        self::apply_opay_tax_exempt_meta($order);
        $order->add_order_note('[電子發票] 已阻擋手動開立：特殊商品不開立發票。');
        $order->save();

        if ('issue' === $action) {
            remove_action('woocommerce_order_action_opay_invoice_issue', ['WC_OPay_Invoice_Admin_Actions', 'wc_action_issue'], 10);
        } elseif ('retry' === $action) {
            remove_action('woocommerce_order_action_opay_invoice_retry', ['WC_OPay_Invoice_Admin_Actions', 'wc_action_retry'], 10);
        }

        self::set_opay_admin_notice('error', '此訂單包含特殊商品，不可開立發票。');
    }

    protected static function apply_opay_tax_exempt_meta($order) {
        $meta_key = defined('WC_OPAY_META_INVOICE_TYPE') ? WC_OPAY_META_INVOICE_TYPE : '_opay_invoice_type';
        $order->update_meta_data($meta_key, 'tax_exempt');
    }

    protected static function set_opay_admin_notice($type, $message) {
        set_transient('opay_invoice_admin_notice', array($type, $message), 60);
    }

    /**
     * 特殊商品專用：若通用欄位模組未作用，仍包裝區塊與群組（h3 + div）。
     * 若通用模組已掛載 wrap，直接沿用，避免重複包裹。
     */
    public static function wrap_form_field_groups($field_html, $key, $args, $value) {
        // 若通用模組已提供 wrap，避免重複
        if (class_exists('TPMA_Woo_Common_Fields')) {
            return $field_html;
        }
        if (!self::cart_is_tpma_only()) {
            return $field_html;
        }

        static $section_open = false;

        if ($key === 'tpma_heading_contact' || $key === 'tpma_heading_address') {
            $label = isset($args['label']) ? esc_html($args['label']) : '';
            $html = '';
            if ($section_open) {
                $html .= '</div>';
            }
            $html .= '<div class="tpma-checkout-section"><h3 class="tpma-checkout-title">' . $label . '</h3>';
            $section_open = true;
            return $html;
        }

        if ($key === 'tpma_postcode') {
            $field_html = '<div class="tpma-checkout-group tpma-checkout-group--address">' . $field_html;
        }
        if ($key === 'tpma_street') {
            $field_html .= '</div>';
        }
        if ($key === 'tpma_phone_area') {
            $field_html = '<div class="tpma-checkout-group tpma-checkout-group--phone">' . $field_html;
        }
        if ($key === 'tpma_phone_ext') {
            $field_html .= '</div>';
        }

        // 在地址尾收斂區塊
        if ($key === 'tpma_street' && $section_open) {
            $field_html .= '</div>';
            $section_open = false;
        }

        return $field_html;
    }

    /**
     * 1083：預先補齊發票類型，避免被通用驗證誤判為必填。
     */
    public static function prime_invoice_type_for_tpma($order = null, $data = null) {
        if (!self::cart_is_tpma_only()) {
            return;
        }

        // 舊版 TPMA 欄位（相容舊資料）
        if (empty($_POST['tpma_invoice_type'])) {
            $_POST['tpma_invoice_type'] = 'na';
        }

        // O'Pay 發票模組欄位（1083 會在後續流程標記為 tax_exempt）
        if (empty($_POST['opay_invoice_type'])) {
            $_POST['opay_invoice_type'] = 'personal';
        }
        if (empty($_POST['opay_carrier_type'])) {
            $_POST['opay_carrier_type'] = 'none';
        }
    }

    /* --------- 判斷/設定 --------- */

    protected static function cart_is_tpma_only(): bool {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        $cart = WC()->cart->get_cart();
        if (empty($cart)) return false;
        foreach ($cart as $item) {
            if (!self::cart_item_matches_target_product($item)) {
                return false;
            }
        }
        return true;
    }

    protected static function cart_has_tpma_product(): bool {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (self::cart_item_matches_target_product($item)) {
                return true;
            }
        }
        return false;
    }

    protected static function cart_has_non_tpma_product(): bool {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $vid = intval($item['variation_id'] ?? 0);
            if (($pid || $vid) && !self::cart_item_matches_target_product($item)) {
                return true;
            }
        }
        return false;
    }

    protected static function get_recognized_product_ids(): array {
        $ids = array();

        $target_id = self::get_target_product_id();
        if ($target_id > 0) {
            $ids[] = $target_id;
        }

        list($registration_product_id) = self::resolve_registration_product();
        if ($registration_product_id > 0) {
            $ids[] = (int) $registration_product_id;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids;
    }

    protected static function product_id_matches_tpma($product_id, $variation_id = 0, $parent_id = 0): bool {
        $recognized_ids = self::get_recognized_product_ids();
        if (empty($recognized_ids)) {
            return false;
        }

        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $parent_id = (int) $parent_id;

        return in_array($product_id, $recognized_ids, true)
            || in_array($variation_id, $recognized_ids, true)
            || in_array($parent_id, $recognized_ids, true);
    }

    protected static function cart_item_matches_target_product(array $item): bool {
        if (empty(self::get_recognized_product_ids())) {
            return false;
        }

        $product_id = intval($item['product_id'] ?? 0);
        $variation_id = intval($item['variation_id'] ?? 0);
        $product = $item['data'] ?? null;
        $parent_id = ($product instanceof WC_Product) ? intval($product->get_parent_id()) : 0;

        return self::product_id_matches_tpma($product_id, $variation_id, $parent_id);
    }

    protected static function is_target_product_enabled(): bool {
        $pid = self::get_target_product_id();
        return intval($pid) > 0;
    }

    /* --------- 草稿 & 加入購物車 --------- */

    public static function build_draft($course_id, $session_id, $learners, $source = '', $note = '') {
        if (!self::is_target_product_enabled()) {
            return new WP_Error('tpma_special_disabled', 'TPMA 特殊商品報名已停用', array('status' => 500));
        }
        return TPMA_CR_Woo_Shared::build_draft($course_id, $session_id, $learners, $source, $note);
    }

    public static function add_to_cart_from_draft($draft) {
        if (!self::is_target_product_enabled()) {
            return new WP_Error('tpma_special_disabled', 'TPMA 特殊商品報名已停用', array('status' => 500));
        }

        return TPMA_CR_Woo_Shared::add_to_cart_from_draft($draft, array(
            'default_product_id' => self::get_target_product_id(),
            'before_add' => function($cart, $draft) {
                if (WC()->session) {
                    WC()->session->set('tpma_reg_active', 1);
                    WC()->session->set('tpma_reg_started_at', time());
                }
            },
        ));
    }

    /**
     * 寫入 regs（共用流程）。
     */
    public static function process_order_from_draft($order_id) {
        if (!self::is_target_product_enabled()) {
            return false;
        }
        TPMA_CR_Woo_Shared::process_order_from_draft($order_id, array(
            'clear_session_keys' => array('tpma_reg_draft', 'tpma_reg_active', 'tpma_reg_started_at'),
        ));
    }

    /* --------- 欄位與驗證 --------- */
    public static function filter_checkout_fields($fields) {
        if (!self::cart_is_tpma_only()) {
            return $fields;
        }

        // 標題（沿用通用欄位，僅覆寫文案）
        if (isset($fields['billing']['tpma_heading_contact'])) {
            $fields['billing']['tpma_heading_contact']['label'] = '承辦人資訊';
        } else {
            $fields['billing']['tpma_heading_contact'] = array(
                'type'     => 'hidden',
                'label'    => '承辦人資訊',
                'priority' => 5,
            );
        }
        if (isset($fields['billing']['tpma_heading_address'])) {
            $fields['billing']['tpma_heading_address']['label'] = '收據寄送地址';
        } else {
            $fields['billing']['tpma_heading_address'] = array(
                'type'     => 'hidden',
                'label'    => '收據寄送地址',
                'priority' => 59,
            );
        }

        // 姓名欄位：1083 文案覆寫
        if (isset($fields['billing']['billing_first_name'])) {
            $fields['billing']['billing_first_name']['label'] = '承辦人姓名';
            $fields['billing']['billing_first_name']['placeholder'] = '承辦人姓名';
        }

        // 特殊商品專用：收據類型
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

        // 隱藏手機欄位（由 1083 流程決定不顯示）
        if (isset($fields['billing']['tpma_mobile'])) {
            unset($fields['billing']['tpma_mobile']);
        }

        // 1083：電話欄位必填（區碼/號碼）
        if (isset($fields['billing']['tpma_phone_area'])) {
            $fields['billing']['tpma_phone_area']['required'] = true;
        }
        if (isset($fields['billing']['tpma_phone_number'])) {
            $fields['billing']['tpma_phone_number']['required'] = true;
        }
        if (isset($fields['billing']['tpma_phone_ext'])) {
            $fields['billing']['tpma_phone_ext']['required'] = false;
            if (!empty($fields['billing']['tpma_phone_ext']['class']) && is_array($fields['billing']['tpma_phone_ext']['class'])) {
                $fields['billing']['tpma_phone_ext']['class'] = array_values(array_diff($fields['billing']['tpma_phone_ext']['class'], array('validate-required')));
            }
            if (!empty($fields['billing']['tpma_phone_ext']['custom_attributes']) && is_array($fields['billing']['tpma_phone_ext']['custom_attributes'])) {
                unset($fields['billing']['tpma_phone_ext']['custom_attributes']['required']);
                unset($fields['billing']['tpma_phone_ext']['custom_attributes']['aria-required']);
            }
        }

        // 調整部分欄位至「額外資訊」(order) 區塊
        if (!isset($fields['order']) || !is_array($fields['order'])) {
            $fields['order'] = array();
        }
        // 收據類型移至額外資訊
        if (isset($fields['billing']['tpma_receipt_type'])) {
            $fields['order']['tpma_receipt_type'] = $fields['billing']['tpma_receipt_type'];
            $fields['order']['tpma_receipt_type']['priority'] = 10;
            unset($fields['billing']['tpma_receipt_type']);
        }
        // 發票類型：1083 不顯示（由預設值補齊）
        if (isset($fields['billing']['tpma_invoice_type'])) {
            unset($fields['billing']['tpma_invoice_type']);
        }
        if (isset($fields['order']['tpma_invoice_type'])) {
            unset($fields['order']['tpma_invoice_type']);
        }
        // 確保公司/統編回到承辦人區塊（避免被通用模組移到額外資訊）
        foreach (array('billing_company' => 40, 'billing_vat_id' => 42) as $k => $p) {
            // 若被放到 order，移回 billing
            if (isset($fields['order'][$k])) {
                $fields['billing'][$k] = $fields['order'][$k];
                unset($fields['order'][$k]);
            }

            // 欄位不存在時補上（近期通用欄位已不再提供 billing_vat_id）
            if (!isset($fields['billing'][$k])) {
                if ('billing_company' === $k) {
                    $fields['billing'][$k] = array(
                        'type'     => 'text',
                        'label'    => '公司抬頭',
                        'required' => true,
                        'priority' => $p,
                        'class'    => array('form-row-wide'),
                    );
                } else {
                    $fields['billing'][$k] = array(
                        'type'     => 'text',
                        'label'    => '統一編號',
                        'required' => true,
                        'priority' => $p,
                        'class'    => array('form-row-wide'),
                        'custom_attributes' => array(
                            'maxlength' => '8',
                            'inputmode' => 'numeric',
                            'pattern'   => '[0-9]*',
                        ),
                    );
                }
            }

            $fields['billing'][$k]['priority'] = $p;
            $fields['billing'][$k]['required'] = true;
            $fields['billing'][$k]['placeholder'] = '';

            $classes = isset($fields['billing'][$k]['class']) && is_array($fields['billing'][$k]['class'])
                ? $fields['billing'][$k]['class']
                : array('form-row-wide');
            if (!in_array('validate-required', $classes, true)) {
                $classes[] = 'validate-required';
            }
            $fields['billing'][$k]['class'] = array_values(array_unique($classes));
        }
        return $fields;
    }

    public static function filter_common_required_fields($required, $checkout_fields) {
        if (!self::cart_is_tpma_only()) {
            return $required;
        }
        if (isset($required['tpma_invoice_type'])) {
            unset($required['tpma_invoice_type']);
        }
        return $required;
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

        $vat_id = preg_replace('/\D+/', '', (string) ($_POST['billing_vat_id'] ?? ''));
        if ($vat_id === '') {
            wc_add_notice('請填寫統一編號', 'error');
        } elseif (!preg_match('/^\d{8}$/', $vat_id)) {
            wc_add_notice('統一編號需為 8 碼數字', 'error');
        }
    }

    public static function save_checkout_fields($order, $data) {
        if (!self::cart_is_tpma_only()) {
            return;
        }
        $receipt_type = sanitize_text_field(wp_unslash($_POST['tpma_receipt_type'] ?? ''));
        if ($receipt_type !== '') {
            $order->update_meta_data('_tpma_receipt_type', $receipt_type);
        }
        // 1083：固定不開立發票，保留舊欄位為 na 供舊流程判斷
        $invoice_type = sanitize_text_field(wp_unslash($_POST['tpma_invoice_type'] ?? ''));
        if ($invoice_type === '') {
            $invoice_type = 'na';
        }
        $order->update_meta_data('_tpma_invoice_type', $invoice_type);

        // 統編資料由特殊商品專用模組自行保存，避免依賴其他欄位模組。
        $billing_company = sanitize_text_field(wp_unslash($_POST['billing_company'] ?? ''));
        if ($billing_company !== '') {
            $order->set_billing_company($billing_company);
        }

        $vat_id = preg_replace('/\D+/', '', (string) wp_unslash($_POST['billing_vat_id'] ?? ''));
        if ($vat_id === '') {
            $order->delete_meta_data('_billing_vat_id');
        } else {
            $order->update_meta_data('_billing_vat_id', $vat_id);
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
        $has_tpma_context = (!empty($draft) && !empty($draft['total_learners'])) || self::cart_has_tpma_product();
        if (!$has_tpma_context) {
            return $purchasable;
        }
        $parent_id = method_exists($product, 'get_parent_id') ? intval($product->get_parent_id()) : 0;
        if (self::product_id_matches_tpma($product->get_id(), 0, $parent_id)) {
            return true;
        }
        return $purchasable;
    }

    public static function allow_guest_checkout_for_tpma($is_required) {
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if ((!empty($draft) && !empty($draft['total_learners'])) || self::cart_has_tpma_product()) {
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
        if (!function_exists('tpma_mailer_boot') || !tpma_mailer_boot()) return $enabled;
        if (!class_exists('TPMA_CR_Mail_Dispatcher')) return $enabled;
        $flow_key = (string) $order->get_status();

        if ($flow_key === 'completed') {
            return TPMA_CR_Mail_Dispatcher::has_template_mapping($order, 'completed') ? false : $enabled;
        }

        if (TPMA_CR_Mail_Dispatcher::has_template_mapping($order, 'checkout_order_processed')) return false;
        return ($flow_key !== '' && TPMA_CR_Mail_Dispatcher::has_template_mapping($order, $flow_key)) ? false : $enabled;
    }

    public static function force_custom_mail_path_for_tpma($skip, $order, $flow_key) {
        if (!$order instanceof WC_Order) {
            return $skip;
        }
        if (!self::is_tpma_order($order)) {
            return $skip;
        }

        // TPMA 特殊課程不跳過 send_after_order_created，確保承辦人與學員都能走自訂模板。
        return false;
    }

    public static function filter_bacs_status($status, $order) {
        if (!$order instanceof WC_Order) return $status;
        return self::is_tpma_order($order) ? 'on-hold' : $status;
    }

    public static function filter_email_viewable_product_ids($product_ids, $order = null) {
        $product_ids = is_array($product_ids) ? $product_ids : array();
        $target_id = self::get_target_product_id();
        if ($target_id > 0) {
            $product_ids[] = $target_id;
        }
        return array_values(array_unique(array_filter(array_map('intval', $product_ids))));
    }

    public static function filter_tpma_order($is_tpma, $order) {
        if ($is_tpma || !$order instanceof WC_Order) {
            return $is_tpma;
        }
        return self::order_has_target_product($order);
    }

    public static function filter_allow_order_pay_retry($allowed, $order) {
        if (!$allowed || !$order instanceof WC_Order) {
            return $allowed;
        }
        return self::order_has_target_product($order) ? false : $allowed;
    }

    public static function filter_allow_card_retry($allowed, $order) {
        if (!$allowed || !$order instanceof WC_Order) {
            return $allowed;
        }
        return self::order_has_target_product($order) ? false : $allowed;
    }

    public static function send_tpma_mails_after_order_created($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_tpma_order($order)) return;
        if ($order->get_meta('_tpma_mail_sent', true) === 'yes') return;
        if (class_exists('TPMA_CR_Mail_Dispatcher')) {
            TPMA_CR_Mail_Dispatcher::send_after_order_created($order);
        }
    }

    public static function send_tpma_mails_after_order_completed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_tpma_order($order)) return;
        if ($order->get_meta('_tpma_completed_mail_sent', true) === 'yes') return;

        $sent = false;
        if (class_exists('TPMA_CR_Mail_Dispatcher')) {
            if (method_exists('TPMA_CR_Mail_Dispatcher', 'send_after_order_completed')) {
                $sent = (bool) TPMA_CR_Mail_Dispatcher::send_after_order_completed($order);
            } else {
                $sent = (bool) TPMA_CR_Mail_Dispatcher::send_after_order_created($order);
            }
        }

        if ($sent) {
            $order->update_meta_data('_tpma_completed_mail_sent', 'yes');
            $order->save();
        }
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

    /**
     * 隱藏預設 Woo 帳單標題（特殊商品單獨啟用時仍生效）。
     */
    public static function enqueue_styles() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!self::cart_is_tpma_only()) {
            return;
        }

        if (!defined('TPMA_WOO_NEW_LOADED')) {
            wp_register_style('tpma-woo-special-inline', false);
            wp_enqueue_style('tpma-woo-special-inline');
            wp_add_inline_style('tpma-woo-special-inline', '.woocommerce-billing-fields > h3{display:none!important;} .woocommerce-additional-fields #tpma_invoice_type_field{display:none!important;}');
        }

        // 特殊商品不開立發票：隱藏 O'Pay 前台發票欄位，避免與收據/統編流程衝突。
        if (self::is_opay_invoice_module_available()) {
            wp_register_style('tpma-woo-special-opay-inline', false);
            wp_enqueue_style('tpma-woo-special-opay-inline');
            wp_add_inline_style('tpma-woo-special-opay-inline', '.opay-invoice-fields{display:none!important;}');
        }
    }

    public static function filter_body_class($classes) {
        if (!function_exists('is_checkout') || !function_exists('is_cart')) {
            return $classes;
        }
        if (!is_checkout() && !is_cart()) {
            return $classes;
        }
        if (self::cart_is_tpma_only()) {
            $classes[] = 'tpma-checkout-special';
            $classes[] = 'tpma-checkout-1083';
        }
        return $classes;
    }

    /**
     * 禁止特殊商品與其他商品混車：一律先清空再加入。
     */
    public static function enforce_no_mixed_cart($passed, $product_id, $quantity) {
        if (!function_exists('WC') || !WC()->cart) {
            return $passed;
        }
        $pid = intval($product_id);
        $is_tpma_product = self::product_id_matches_tpma($pid);

        $has_tpma = self::cart_has_tpma_product();
        $has_non_tpma = self::cart_has_non_tpma_product();

        // 新加入的是特殊商品，購物車有其他商品 → 清空再加入
        if ($is_tpma_product && $has_non_tpma) {
            self::clear_tpma_cart_and_session(true, 'mixed_cart_adding_tpma');
        }

        // 新加入的是非特殊商品，購物車已有特殊商品 → 清空再加入
        if (!$is_tpma_product && $has_tpma) {
            self::clear_tpma_cart_and_session(true, 'mixed_cart_adding_non_tpma');
        }

        return $passed;
    }

    public static function enforce_no_mixed_cart_on_cart() {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        if (self::cart_has_tpma_product() && self::cart_has_non_tpma_product()) {
            self::clear_tpma_cart_and_session(true, 'mixed_cart_check_cart_items');
            wc_add_notice('特殊報名商品不可與其他商品同時結帳，購物車已清空。', 'notice');
        }
    }

    /**
     * 中斷流程清空：非 checkout/thankyou/order-pay/cart 或超過 30 分鐘即清除。
     */
    public static function maybe_abort_tpma_flow() {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (self::is_background_request()) {
            return;
        }
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        if (!in_array($request_method, array('GET', 'HEAD'), true)) {
            return;
        }
        if (self::is_non_page_request()) {
            return;
        }
        if (!function_exists('WC') || !WC()->session || !WC()->cart) {
            return;
        }

        $draft = WC()->session->get('tpma_reg_draft');
        $active = WC()->session->get('tpma_reg_active');

        if (empty($draft) && empty($active) && !self::cart_has_tpma_product()) {
            return;
        }

        $started = (int) WC()->session->get('tpma_reg_started_at');
        $expired = ($started > 0) && ((time() - $started) > self::REG_TIMEOUT_SECONDS);

        if (self::is_tpma_flow_page()) {
            if ($expired) {
                self::clear_tpma_cart_and_session(true, 'flow_expired');
            }
            return;
        }

        // 非流程頁即視為中斷
        self::clear_tpma_cart_and_session(true, 'left_tpma_flow');
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

    protected static function order_has_target_product($order): bool {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty(self::get_recognized_product_ids())) {
            return false;
        }

        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product_id = intval($item->get_product_id());
            $variation_id = intval($item->get_variation_id());
            $product = $item->get_product();
            $parent_id = ($product instanceof WC_Product) ? intval($product->get_parent_id()) : 0;
            if (self::product_id_matches_tpma($product_id, $variation_id, $parent_id)) {
                return true;
            }
        }

        return false;
    }

    protected static function is_tpma_flow_page(): bool {
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return true;
        }
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            return true;
        }
        $custom_checkout_page_id = (int) get_option('tpma_cr_custom_checkout_page_id', 0);
        if ($custom_checkout_page_id > 0 && function_exists('is_page') && is_page($custom_checkout_page_id)) {
            return true;
        }
        if (function_exists('is_page') && (is_page('tpma-checkout') || is_page('tpma-order'))) {
            return true;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri !== '') {
            $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
            $request_path = self::normalize_path_for_compare($request_path);

            $custom_checkout_url = self::get_custom_checkout_url();
            if ($custom_checkout_url !== '') {
                $custom_path = (string) wp_parse_url($custom_checkout_url, PHP_URL_PATH);
                $custom_path = self::normalize_path_for_compare($custom_path);
                if ($custom_path !== '' && $custom_path === $request_path) {
                    return true;
                }
            }

            if (in_array($request_path, array('/tpma-checkout', '/tpma-order'), true)) {
                return true;
            }
        }
        return false;
    }

    protected static function is_background_request(): bool {
        if ((defined('DOING_AJAX') && DOING_AJAX)
            || (defined('WC_DOING_AJAX') && WC_DOING_AJAX)
            || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return true;
        }

        $wc_ajax = '';
        if (isset($_REQUEST['wc-ajax'])) {
            $wc_ajax = sanitize_key(wp_unslash($_REQUEST['wc-ajax']));
        } elseif (function_exists('get_query_var')) {
            $wc_ajax = sanitize_key((string) get_query_var('wc-ajax'));
        }

        if ($wc_ajax !== '') {
            return true;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri !== ''
            && (strpos($request_uri, 'wc-ajax=') !== false
                || strpos($request_uri, 'admin-ajax.php') !== false
                || strpos($request_uri, '/wp-json/') !== false)) {
            return true;
        }

        return false;
    }

    protected static function is_non_page_request(): bool {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return false;
        }

        $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $request_path = self::normalize_path_for_compare($request_path);
        if ($request_path === '') {
            return false;
        }

        if ($request_path === '/favicon.ico' || $request_path === '/robots.txt' || $request_path === '/ads.txt') {
            return true;
        }

        $ext = strtolower(pathinfo($request_path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }

        return in_array($ext, array(
            'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'avif',
            'css', 'js', 'map', 'txt', 'xml', 'json', 'webmanifest',
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf', 'zip'
        ), true);
    }

    protected static function resolve_registration_product() {
        return TPMA_CR_Woo_Shared::resolve_registration_product(self::get_target_product_id());
    }

    protected static function get_custom_checkout_url(): string {
        return TPMA_CR_Woo_Shared::get_custom_checkout_url();
    }

    protected static function prepare_product_for_registration($product, $unit_price) {
        return TPMA_CR_Woo_Shared::prepare_product_for_registration($product, $unit_price);
    }

    protected static function with_temp_product_overrides($product_id, $unit_price, $callback) {
        return TPMA_CR_Woo_Shared::with_temp_product_overrides($product_id, $unit_price, $callback);
    }

    protected static function get_wc_notices_error_string() {
        return TPMA_CR_Woo_Shared::get_wc_notices_error_string();
    }

    protected static function ensure_wc_session_ready() {
        return TPMA_CR_Woo_Shared::ensure_wc_session_ready();
    }

    protected static function format_class_datetime($datetime, $duration_minutes = 0) {
        return TPMA_CR_Woo_Shared::format_class_datetime($datetime, $duration_minutes);
    }

    protected static function ensure_virtual_user($reg_no, $display_name = '') {
        return TPMA_CR_Woo_Shared::ensure_virtual_user($reg_no, $display_name, false);
    }

    protected static function clear_tpma_cart_and_session($clear_cart = true, string $reason = '') {
        if (!function_exists('WC')) {
            return;
        }
        if ($reason !== '') {
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $flags = array(
                'is_checkout' => function_exists('is_checkout') && is_checkout() ? '1' : '0',
                'is_cart' => function_exists('is_cart') && is_cart() ? '1' : '0',
                'is_order_received' => function_exists('is_order_received_page') && is_order_received_page() ? '1' : '0',
                'is_order_pay' => function_exists('is_checkout_pay_page') && is_checkout_pay_page() ? '1' : '0',
            );
            error_log('[TPMA Special] clearing cart/session: ' . $reason . ' method=' . $method . ' uri=' . $uri . ' flags=' . wp_json_encode($flags));
        }
        if ($clear_cart && WC()->cart) {
            WC()->cart->empty_cart();
        }
        if (WC()->session) {
            WC()->session->set('tpma_reg_draft', null);
            WC()->session->set('tpma_reg_active', null);
            WC()->session->set('tpma_reg_started_at', null);
        }
    }

    protected static function normalize_path_for_compare($path): string {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        $path = '/' . ltrim($path, '/');
        return rtrim($path, '/');
    }
}

if (!class_exists('TPMA_Woo_Special_1083')) {
    class_alias('TPMA_Woo_Special_Product', 'TPMA_Woo_Special_1083');
}













