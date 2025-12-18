<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce helpers for admin REST endpoints.
 * 把 Woo 訂單讀取/同步的邏輯集中處理，避免 REST controller 過度耦合。
 */
class TPMA_CR_Admin_Woo_Service
{
    /**
     * 讀取 rows 中涉及的 Woo 訂單，並將 Woo 資訊覆蓋回傳。
     *
     * @param array $rows regs 查詢結果（含 woocommerce_order_id）
     * @return array
     */
    public static function enrich_regs_with_orders(array $rows)
    {
        if (empty($rows) || !function_exists('wc_get_order')) {
            return $rows;
        }

        $orders_map = array();
        $order_ids  = array();
        foreach ($rows as $r) {
            if (!empty($r['woocommerce_order_id'])) {
                $order_ids[] = (int) $r['woocommerce_order_id'];
            }
        }
        $order_ids = array_values(array_unique(array_filter($order_ids)));

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                continue;
            }
            $orders_map[$oid] = array(
                'status'             => $order->get_status(),
                'total'              => $order->get_total(),
                'contact_name'       => $order->get_billing_first_name(),
                'contact_email'      => $order->get_billing_email(),
                'company_name'       => $order->get_billing_company(),
                'phone'              => $order->get_billing_phone(),
                'address'            => trim(implode(' ', array_filter([
                    $order->get_billing_postcode(),
                    $order->get_billing_state(),
                    $order->get_billing_city(),
                    $order->get_billing_address_1(),
                    $order->get_billing_address_2(),
                ], function($v){ return $v !== null && $v !== ''; }))),
                'receiver'           => $order->get_shipping_first_name(),
                'receipt_type'       => $order->get_meta('_tpma_receipt_type', true),
                'tax_id'             => $order->get_meta('_billing_vat_id', true),
                'remit_amount_total' => $order->get_meta('_tpma_remit_amount_total', true),
                'remit_paid_at'      => $order->get_meta('_tpma_remit_paid_at', true),
                'remit_account'      => $order->get_meta('_tpma_remit_account', true),
            );
        }

        foreach ($rows as &$r) {
            $oid = !empty($r['woocommerce_order_id']) ? (int) $r['woocommerce_order_id'] : 0;
            if ($oid && isset($orders_map[$oid])) {
                $o = $orders_map[$oid];
                $r['payment_status']     = $o['status']; // 用 Woo 狀態
                $r['order_status']       = $o['status'];
                $r['order_total']        = $o['total'];
                $r['contact_name']       = $o['contact_name'];
                $r['contact_email']      = $o['contact_email'];
                $r['company_name']       = $o['company_name'];
                $r['phone']              = $o['phone'];
                $r['address']            = $o['address'];
                $r['receiver']           = $o['receiver'];
                $r['receipt_type']       = $o['receipt_type'] !== '' ? $o['receipt_type'] : $r['receipt_type'];
                $r['tax_id']             = $o['tax_id'] !== '' ? $o['tax_id'] : $r['tax_id'];
                $r['remit_amount_total'] = $o['remit_amount_total'];
                $r['remit_paid_at']      = $o['remit_paid_at'] ?: $r['remit_paid_at'];
                $r['remit_account']      = $o['remit_account'] ?: $r['remit_account'];
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * 欄位映射表（payload key -> Woo 欄位）
     */
    private static function get_field_map()
    {
        return array(
            'contact_name'  => array('type' => 'billing', 'field' => 'first_name'),
            'contact_email' => array('type' => 'billing', 'field' => 'email'),
            'company_name'  => array('type' => 'billing', 'field' => 'company'),
            'phone'         => array('type' => 'billing', 'field' => 'phone'),
            'address'       => array('type' => 'billing', 'field' => 'address_1'),
            'receiver'      => array('type' => 'shipping', 'field' => 'first_name'),
            'receipt_type'  => array('type' => 'meta',    'field' => '_tpma_receipt_type'),
            'tax_id'        => array('type' => 'meta',    'field' => '_billing_vat_id'),
        );
    }

    /**
     * 只同步 Woo 欄位，不處理金額。
     *
     * @return array ['has_change'=>bool]
     */
    public static function update_order_fields($order, array $payload)
    {
        if (!$order) {
            return array('has_change' => false);
        }
        $has_change   = false;
        $field_map    = self::get_field_map();
        foreach ($field_map as $payload_key => $info) {
            if (!isset($payload[$payload_key])) {
                continue;
            }
            $val = sanitize_text_field($payload[$payload_key]);
            $has_change = true;
            if ($info['type'] === 'billing') {
                $addr = $order->get_address('billing');
                $addr[$info['field']] = $val;
                $order->set_address($addr, 'billing');
            } elseif ($info['type'] === 'shipping') {
                $addr = $order->get_address('shipping');
                $addr[$info['field']] = $val;
                $order->set_address($addr, 'shipping');
            } elseif ($info['type'] === 'meta') {
                $order->update_meta_data($info['field'], $val);
            }
        }
        return array('has_change' => $has_change);
    }

    /**
     * 同步 remit_amount 並回寫 Woo 總額。
     *
     * @return array|WP_Error ['has_change'=>bool]
     */
    public static function sync_remit_amount($order, $regs_table, $new_amount)
    {
        if (!$order || !function_exists('wc_get_order')) {
            return array('has_change' => false);
        }
        global $wpdb;

        $order_id = $order->get_id();
        $woo_status = $order->get_status();
        $can_touch_woo_total = in_array($woo_status, array('pending', 'processing'), true);

        if ($order_id <= 0) {
            return new WP_Error('no_order', '找不到對應的 Woo 訂單，無法同步金額', array('status' => 400));
        }
        if (!$can_touch_woo_total) {
            return new WP_Error('order_locked', '訂單狀態不允許改金額（僅 pending / processing 可改）', array('status' => 400));
        }

        // 同步 regs remit_amount
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$regs_table} SET remit_amount = %d WHERE woocommerce_order_id = %d",
                $new_amount,
                $order_id
            )
        );

        // 重新計算總額 = 每人金額 * 人數
        $learner_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$regs_table} WHERE woocommerce_order_id = %d",
                $order_id
            )
        );
        $order_total = $new_amount * max(1, $learner_count);

        foreach ($order->get_items() as $item_id => $item) {
            $item->set_subtotal($order_total);
            $item->set_total($order_total);
            $item->save();
            break; // 只有一個商品
        }

        $order->set_total($order_total);
        $order->calculate_totals(false);

        return array('has_change' => true);
    }

    /**
     * 同步 Woo 訂單欄位與金額。
     *
     * @param WC_Order|null $order
     * @param array         $payload  前端傳入的欄位資料
     * @param string        $regs_table regs 資料表名稱（處理 remit_amount 更新時需要）
     * @return array|WP_Error ['has_change'=>bool]
     */
    public static function apply_order_updates($order, array $payload, $regs_table)
    {
        if (!$order || !function_exists('wc_get_order')) {
            return array('has_change' => false);
        }
        $has_change = false;

        // 欄位同步
        $field_result = self::update_order_fields($order, $payload);
        $has_change = $has_change || !empty($field_result['has_change']);

        // remit_amount 特別處理：同步 regs 並重算 Woo 總額
        if (isset($payload['remit_amount'])) {
            $sync = self::sync_remit_amount($order, $regs_table, (int) sanitize_text_field($payload['remit_amount']));
            if (is_wp_error($sync)) {
                return $sync;
            }
            $has_change = $has_change || !empty($sync['has_change']);
        }

        return array('has_change' => $has_change);
    }
}
