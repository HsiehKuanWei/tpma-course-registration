<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_WooCommerce_Integration {

    public static function init() {

        // ✅ 只在「購物車全部都是 TPMA 報名商品」時，改寫 checkout URL
        add_filter('woocommerce_get_checkout_url', [self::class, 'filter_checkout_url_for_tpma'], 20);

        // Frontend checkout/cart helpers
        add_action('woocommerce_before_calculate_totals', ['TPMA_CR_Woo_Service', 'apply_cart_price']);
        add_action('woocommerce_checkout_order_review', ['TPMA_CR_Woo_Service', 'render_checkout_summary'], 5);

        // TPMA checkout：只讓 TPMA draft 使用匯款（bacs）
        add_filter('woocommerce_available_payment_gateways', function($gateways){
            if (!self::cart_is_tpma_registration_only()) return $gateways;
            return isset($gateways['bacs']) ? ['bacs' => $gateways['bacs']] : $gateways;
        }, 99);

        add_filter('woocommerce_default_gateway', function($default){
            if (!self::cart_is_tpma_registration_only()) return $default;
            return 'bacs';
        });

        add_filter('woocommerce_order_button_text', function($text){
            if (!self::cart_is_tpma_registration_only()) return $text;
            return '提交訂單';
        });

        add_action('woocommerce_checkout_before_customer_details', ['TPMA_CR_Woo_Service', 'render_auto_fill_controls'], 1);
        add_action('woocommerce_checkout_process', ['TPMA_CR_Woo_Service', 'validate_checkout_fields']);
        add_action('woocommerce_checkout_create_order', ['TPMA_CR_Woo_Service', 'save_checkout_fields'], 10, 2);
        add_filter('woocommerce_checkout_fields', ['TPMA_CR_Woo_Service', 'add_checkout_fields']);
        add_filter('woocommerce_is_purchasable', ['TPMA_CR_Woo_Service', 'force_tpma_product_purchasable'], 10, 2);
        add_filter('woocommerce_checkout_registration_required', ['TPMA_CR_Woo_Service', 'allow_guest_checkout_for_tpma'], 10, 1);

        add_filter('woocommerce_bacs_process_payment_order_status', function($status, $order) {
            if (!$order instanceof WC_Order) return $status;

            $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
                || (bool)$order->get_meta('_tpma_reg_no', true);

            return $is_tpma ? 'on-hold' : $status;
        }, 10, 2);

        if (class_exists('TPMA_CR_Thankyou_View')) {
            TPMA_CR_Thankyou_View::init();
        }

        // 建單後：先寫 regs，再寄信
        add_action('woocommerce_checkout_order_processed', [self::class, 'sync_order_to_registrations'], 10, 3);
        add_action('woocommerce_checkout_order_processed', [self::class, 'send_tpma_mails_after_order_created'], 12, 1);

        // TPMA 報名單：關閉 Woo 內建 email（避免重複）
        add_filter('woocommerce_email_enabled_new_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);
    }

    /**
     * ✅ 取得 TPMA 報名商品 ID（與 TPMA_CR_Woo_Service 一致）
     */
    private static function get_tpma_registration_product_id(): int {
        if (class_exists('TPMA_CR_Woo_Service') && method_exists('TPMA_CR_Woo_Service', 'resolve_registration_product')) {
            list($pid, $pobj) = TPMA_CR_Woo_Service::resolve_registration_product();
            return intval($pid);
        }
        // 保底（理論上不會用到）
        return intval(get_option('tpma_cr_wc_product_id', 1083));
    }

    /**
     * ✅ 嚴格條件：購物車必須「全部都是 TPMA 報名商品」才回 true
     * - 避免影響其他商品
     * - 避免混車導向到自定義 checkout
     */
    private static function cart_is_tpma_registration_only(): bool {
        if (!function_exists('WC') || !WC()->cart) return false;

        $tpma_pid = self::get_tpma_registration_product_id();
        if ($tpma_pid <= 0) return false;

        $cart = WC()->cart->get_cart();
        if (empty($cart)) return false;

        foreach ($cart as $item) {
            $pid = intval($item['product_id'] ?? 0);

            // 有任何一個不是 TPMA 商品 → 直接 false（不導向、不鎖 gateway、不改按鈕）
            if ($pid !== $tpma_pid) return false;

            // 額外保險：TPMA 商品卻沒有 draft，也不視為 TPMA 流程
            if (empty($item['tpma_reg_draft'])) return false;
        }

        return true;
    }

    /**
     * ✅ 取得自定義訂單頁 URL：
     * 1) option: tpma_cr_custom_checkout_page_id
     * 2) slug: tpma-checkout
     * 3) slug: tpma-order
     */
    private static function get_custom_checkout_url(): string {
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
     * ✅ 核心：只有 TPMA 報名商品「單獨成車」才導向自定義訂單頁
     */
    public static function filter_checkout_url_for_tpma($url): string {
        if (!self::cart_is_tpma_registration_only()) return $url;

        $custom = self::get_custom_checkout_url();
        return $custom ? $custom : $url;
    }

    public static function sync_order_to_registrations($order_id, $posted_data, $order) {
        if (!class_exists('TPMA_CR_Woo_Service')) return;
        TPMA_CR_Woo_Service::process_order_from_draft($order_id);
    }

    public static function send_tpma_mails_after_order_created($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if ($order->get_meta('_tpma_mail_sent', true) === 'yes') return;

        if (class_exists('TPMA_CR_Mail_Dispatcher')) {
            TPMA_CR_Mail_Dispatcher::send_after_order_created($order);
        }
    }

    public static function maybe_disable_woo_emails_for_tpma($enabled, $order) {
        if (!$order instanceof WC_Order) return $enabled;

        $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true);

        return $is_tpma ? false : $enabled;
    }
}

TPMA_WooCommerce_Integration::init();
