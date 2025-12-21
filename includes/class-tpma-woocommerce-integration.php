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
        add_action('woocommerce_checkout_before_customer_details', ['TPMA_CR_Woo_Service', 'render_auto_fill_controls'], 1);
        add_action('woocommerce_checkout_process', ['TPMA_CR_Woo_Service', 'validate_checkout_fields']);
        add_action('woocommerce_checkout_create_order', ['TPMA_CR_Woo_Service', 'save_checkout_fields'], 10, 2);
        add_filter('woocommerce_checkout_fields', ['TPMA_CR_Woo_Service', 'add_checkout_fields']);
        add_filter('woocommerce_is_purchasable', ['TPMA_CR_Woo_Service', 'force_tpma_product_purchasable'], 10, 2);
        add_filter('woocommerce_checkout_registration_required', ['TPMA_CR_Woo_Service', 'allow_guest_checkout_for_tpma'], 10, 1);

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
        add_action('woocommerce_order_status_changed', [self::class, 'update_registration_payment_status'], 10, 4);

        // Force BACS orders to start at "pending" instead of "on-hold".
        add_filter('woocommerce_bacs_process_payment_order_status', function($status, $order) {
            return 'pending';
        }, 10, 2);
    }

    /**
     * Sync WooCommerce order items to TPMA registrations table if they are course products.
     *
     * @param int $order_id The ID of the order.
     * @param array $data The order data.
     * @param WC_Order $order The order object.
     */
public static function sync_order_to_registrations($order_id, $data, $order) {
    global $wpdb;

    // === 0) 取得 order 物件（保險：有些情境 $order 可能不是 WC_Order） ===
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
    }
    if (!$order) {
        error_log("TPMA Integration: sync_order_to_registrations() order not found: {$order_id}");
        return;
    }

    // === 1) Idempotency：如果訂單已被任一路徑處理過，就直接跳過 ===
    // (A) draft 流程會寫入 _tpma_reg_no / _tpma_learner_count 等 meta，代表已寫 regs
    $draft_reg_no      = $order->get_meta('_tpma_reg_no', true);
    $draft_learner_cnt = $order->get_meta('_tpma_learner_count', true);

    // (B) 共用層旗標：只要存在就視為已履約（不管是 draft 或 sync）
    $already_written = $order->get_meta('_tpma_regs_written', true);

    if (!empty($draft_reg_no) || !empty($draft_learner_cnt) || !empty($already_written)) {
        // 表示已由 draft 流程或其他流程寫入過 regs，避免「雙寫」
        return;
    }

    // === 2) 設定 ===
    $regs_table      = TPMA_CR_DB::table('regs');
    $course_sync_tag = 'course_sync'; // 用商品 tag 判定要不要同步成 regs（你的既有規則）

    $inserted_reg_nos = [];
    $did_any_insert   = false;

    // === 3) 逐品項處理：只有具備 course_sync tag 的商品才同步 ===
    foreach ($order->get_items() as $item_id => $item) {

        // --- 3.1 item-level idempotency：同一品項已同步過就略過 ---
        // 這是為了防止 hook 被重跑、或後台改狀態、或外掛重覆觸發
        $item_synced = wc_get_order_item_meta($item_id, '_tpma_regs_synced', true);
        if (!empty($item_synced)) {
            continue;
        }

        $product_id = $item->get_product_id();
        if (!$product_id) {
            continue;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            continue;
        }

        // --- 3.2 檢查是否有 course_sync tag ---
        if (!has_term($course_sync_tag, 'product_tag', $product_id)) {
            continue;
        }

        // --- 3.3 組成 regs 需要的資料（注意：這條路徑是「純 Woo fallback」所以用 billing 資料當單一學員） ---
        $student_name  = trim((string) $order->get_billing_first_name());
        $student_email = $order->get_billing_email();
        $company_name  = $order->get_billing_company();
        $tax_id        = $order->get_meta('_billing_vat_id'); // 你原本的假設
        $phone         = $order->get_billing_phone();
        $address       = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $receiver      = trim((string) $order->get_shipping_first_name());

        // --- 3.4 course_id 對應（先用商品 meta，沒有才用課程名稱 fallback） ---
        $course_id = $product->get_meta('_tpma_course_id', true);

        if (empty($course_id)) {
            $tpma_course = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM " . TPMA_CR_DB::table('courses') . " WHERE course_name = %s LIMIT 1",
                    $product->get_name()
                )
            );

            if ($tpma_course) {
                $course_id = $tpma_course->id;
            } else {
                error_log("TPMA Integration: No TPMA course_id found for product ID {$product_id} (order {$order_id})");
                // 不要標記 synced，因為沒成功寫入 regs
                continue;
            }
        }

        // --- 3.5 寫入 regs ---
        $reg_no = TPMA_CR_DB::generate_reg_no();

        $wpdb->insert(
            $regs_table,
            [
                'reg_no'               => $reg_no,
                'created_at'           => current_time('mysql'),
                'course_id'            => (int) $course_id,
                'student_name'         => $student_name,
                'emails'               => $student_email,
                'company_name'         => $company_name,
                'tax_id'               => $tax_id,
                'phone'                => $phone,
                'address'              => $address,
                'receiver'             => $receiver,
                'woocommerce_order_id' => (int) $order_id,
                'payment_status'       => $order->get_status(),
                'status'               => 'pending',
            ],
            [
                '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'
            ]
        );

        if ($wpdb->last_error) {
            error_log("TPMA Integration: Error inserting registration for order {$order_id}, item {$item_id}: " . $wpdb->last_error);
            // 不要標記 synced，因為沒成功寫入 regs
            continue;
        }

        // --- 3.6 成功後：標記此 item 已同步（item-level 防重）並記錄 reg_no ---
        wc_update_order_item_meta($item_id, '_tpma_regs_synced', 1);
        wc_update_order_item_meta($item_id, '_tpma_reg_no', $reg_no);
        wc_update_order_item_meta($item_id, '_tpma_course_id', (int) $course_id);

        $inserted_reg_nos[] = $reg_no;
        $did_any_insert     = true;
    }

    // === 4) 若本次確實有同步成功，寫入 order-level 旗標（order-level 防重） ===
    if ($did_any_insert) {
        // 這個旗標是為了未來抽離成共用 Woo Bridge 時可以通用：
        // 只要看到 _tpma_regs_written，就知道該訂單已被履約（避免任何重跑造成重複寫入）
        $order->update_meta_data('_tpma_regs_written', 'sync');

        // 可選：保留這次同步產生的 reg_no 清單，方便追查
        $order->update_meta_data('_tpma_reg_nos', $inserted_reg_nos);

        // 方便後台追查：這是「fallback sync」產生的
        $order->update_meta_data('_tpma_regs_written_source', 'course_sync_tag');

        $order->save();
    }
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
