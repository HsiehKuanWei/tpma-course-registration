<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class TPMA_WooCommerce_Integration {

    public static function init() {
        // Frontend checkout/cart helpers
        add_action('woocommerce_before_calculate_totals', ['TPMA_CR_Woo_Service', 'apply_cart_price']);
        // Render inside #order_review (right column), but outside the fragments that Woo refreshes via AJAX
        // (review-order table + payment). This prevents duplicate output when the order review is refreshed.
        add_action('woocommerce_checkout_order_review', ['TPMA_CR_Woo_Service', 'render_checkout_summary'], 5);

        // =========================
        // ★ TPMA 報名單：改成「提交訂單（待匯款）」而不是線上結帳付款
        // =========================

        // 1) 只保留離線付款方式（BACS），避免導去線上付款
        add_filter('woocommerce_available_payment_gateways', function($gateways){
            if (!is_checkout() || is_wc_endpoint_url()) return $gateways;
            if (!WC()->cart) return $gateways;

            $has_tpma = false;
            foreach (WC()->cart->get_cart() as $item) {
                if (!empty($item['tpma_reg_draft'])) { $has_tpma = true; break; }
            }
            if (!$has_tpma) return $gateways;

            // 只保留 bacs（銀行轉帳）
            foreach ($gateways as $id => $gw) {
                if ($id !== 'bacs') unset($gateways[$id]);
            }
            return $gateways;
        }, 20);

        // 2) 預設使用 bacs（Woo 對 bacs 通常會把訂單設為 on-hold）
        add_filter('woocommerce_default_gateway', function($default){
            if (!WC()->cart) return $default;
            foreach (WC()->cart->get_cart() as $item) {
                if (!empty($item['tpma_reg_draft'])) return 'bacs';
            }
            return $default;
        });

        // 3) 把下單按鈕改成「提交訂單」
        add_filter('woocommerce_order_button_text', function($text){
            if (!WC()->cart) return $text;
            foreach (WC()->cart->get_cart() as $item) {
                if (!empty($item['tpma_reg_draft'])) return '提交訂單';
            }
            return $text;
        });
        
        add_action('woocommerce_checkout_before_customer_details', ['TPMA_CR_Woo_Service', 'render_auto_fill_controls'], 1);
        add_action('woocommerce_checkout_process', ['TPMA_CR_Woo_Service', 'validate_checkout_fields']);
        add_action('woocommerce_checkout_create_order', ['TPMA_CR_Woo_Service', 'save_checkout_fields'], 10, 2);
        add_filter('woocommerce_checkout_fields', ['TPMA_CR_Woo_Service', 'add_checkout_fields']);
        add_filter('woocommerce_is_purchasable', ['TPMA_CR_Woo_Service', 'force_tpma_product_purchasable'], 10, 2);
        add_filter('woocommerce_checkout_registration_required', ['TPMA_CR_Woo_Service', 'allow_guest_checkout_for_tpma'], 10, 1);

        /* 4) 保險：TPMA 報名單建單後強制狀態 on-hold（待匯款）
        add_action('woocommerce_checkout_order_processed', function($order_id){
            $order = wc_get_order($order_id);
            if (!$order) return;

            $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
                || (bool)$order->get_meta('_tpma_reg_no', true);

            if ($is_tpma && $order->get_status() !== 'on-hold') {
                $order->update_status('on-hold', 'TPMA：提交訂單（待匯款）', true);
            }
        }, 11);*/

        // Order creation hooks
        // ★ NEW：先把 draft 存進 order meta（避免後續 session 被清掉拿不到）
        add_action('woocommerce_checkout_order_processed', [self::class, 'stash_draft_to_order_meta'], 8, 1);

        add_action('woocommerce_checkout_order_processed', ['TPMA_CR_Woo_Service', 'process_order_from_draft'], 9, 1);
        add_action('woocommerce_checkout_order_processed', [self::class, 'sync_order_to_registrations'], 10, 3);

        // ★ NEW：建單完成後寄兩種信（學員資料信 / 訂單資料信）
        add_action('woocommerce_checkout_order_processed', [self::class, 'send_tpma_mails_after_order_created'], 12, 1);

        // ★ NEW：只針對 TPMA 報名單，關閉 Woo 內建寄信（避免重複寄）
        add_filter('woocommerce_email_enabled_new_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_completed_order', [self::class, 'maybe_disable_woo_emails_for_tpma'], 10, 2);

        // Order status updates
        add_action(
            'woocommerce_order_status_changed',
            [self::class, 'update_registration_payment_status'],
            10,
            4
        );
        // ★ TPMA 報名單：BACS 建單時狀態改為 pending（待付款）
        add_filter('woocommerce_bacs_process_payment_order_status', function($status, $order) {
            if (!$order instanceof WC_Order) return $status;

            $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
                || (bool)$order->get_meta('_tpma_reg_no', true);

            return $is_tpma ? 'on-hold' : $status;
        }, 10, 2);

        // ★ NEW：TPMA thankyou 視圖（獨立檔案，只初始化一次）
        if (class_exists('TPMA_CR_Thankyou_View')) {
            TPMA_CR_Thankyou_View::init();
        }


        
    }

   
    /**
     * Sync WooCommerce order items to TPMA registrations table if they are course products.
     *
     * @param int $order_id The ID of the order.
     * @param array $data The order data.
     * @param WC_Order $order The order object.
     */
public static function sync_order_to_registrations($order_id, $data, $order) {
    // 保險：有些情境 $order 可能不是 WC_Order
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
    }
    if (!$order) {
        error_log("TPMA Integration: sync_order_to_registrations() order not found: {$order_id}");
        return;
    }

    // 已由 draft 流程寫入 regs 或已有旗標者，直接跳過
    $draft_reg_no      = $order->get_meta('_tpma_reg_no', true);
    $draft_learner_cnt = $order->get_meta('_tpma_learner_count', true);
    $already_written   = $order->get_meta('_tpma_regs_written', true);

    if (!empty($draft_reg_no) || !empty($draft_learner_cnt) || !empty($already_written)) {
        return;
    }

    // 若不是 TPMA 報名單（沒有 draft/reg_no），也不做任何事（避免純 Woo 訂單誤寫 regs）
    // 你可以視需求改成：純 Woo 課程商品要不要自動產生 regs（目前依你的目標流程：不要）
    return;
}

/**
 * ★ NEW：把 tpma_reg_draft 先存到 order meta，避免後續 session 被清掉拿不到
 */
public static function stash_draft_to_order_meta($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // 已存過就不重複存
    $existing = $order->get_meta('_tpma_reg_draft_json', true);
    if ($existing) return;

    // 從 session 取 draft
    $draft = (WC()->session) ? WC()->session->get('tpma_reg_draft') : null;
    if (!is_array($draft) || empty($draft)) return;

    $order->update_meta_data('_tpma_reg_draft_json', wp_json_encode($draft, JSON_UNESCAPED_UNICODE));
    $order->save();
}

/**
 * ★ NEW：Woo 建單後寄信（依 order meta 的 draft + order）
 */
public static function send_tpma_mails_after_order_created($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // 防重複
    if ($order->get_meta('_tpma_mail_sent', true) === 'yes') return;

    $draft_json = $order->get_meta('_tpma_reg_draft_json', true);
    $draft = $draft_json ? json_decode($draft_json, true) : null;
    if (!is_array($draft)) $draft = [];

    if (class_exists('TPMA_CR_Mail_Dispatcher')) {
        TPMA_CR_Mail_Dispatcher::send_after_order_created($order, $draft);
    }
}

/**
 * ★ NEW：只針對 TPMA 報名單關閉 Woo 內建寄信（避免重複寄）
 */
public static function maybe_disable_woo_emails_for_tpma($enabled, $order) {
    if (!$order instanceof WC_Order) return $enabled;

    // 判斷是否 TPMA 報名單：有 draft json 或你 draft 流程寫入的 reg_no 都算
    $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
           || (bool)$order->get_meta('_tpma_reg_no', true);

    if ($is_tpma) return false;

    return $enabled;
}


    /**
     * Update TPMA registration payment status when WooCommerce order status changes.
     *
     * @param int $order_id The ID of the order.
     * @param string $old_status The old status of the order.
     * @param string $new_status The new status of the order.
     * @param WC_Order $order The order object.
     */
    public static function update_registration_payment_status($order_id, $old_status, $new_status, $order) {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        // Update all TPMA registrations linked to this WooCommerce order
        $wpdb->update(
            $regs_table,
            ['payment_status' => $new_status],
            ['woocommerce_order_id' => $order_id],
            ['%s'],
            ['%d']
        );

        if ($wpdb->last_error) {
            error_log("TPMA Integration: Error updating payment status for order ID {$order_id}: " . $wpdb->last_error);
        }
    }
}

TPMA_WooCommerce_Integration::init();
