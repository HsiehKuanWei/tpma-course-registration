<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class TPMA_WooCommerce_Integration {

    public static function init() {
        // Hook into WooCommerce order processing
        add_action('woocommerce_checkout_order_processed', [self::class, 'sync_order_to_registrations'], 10, 3);
        // Hook into WooCommerce order status changes for payment status updates
        add_action('woocommerce_order_status_changed', [self::class, 'update_registration_payment_status'], 10, 4);
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
        $regs_table = TPMA_CR_DB::table('regs');
        $course_sync_tag = 'course_sync'; // The product tag to identify course products

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            // Check if the product has the 'course_sync' tag
            if (has_term($course_sync_tag, 'product_tag', $product_id)) {
                // This is a course product, create a registration entry
                $student_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $student_email = $order->get_billing_email();
                $company_name = $order->get_billing_company();
                $tax_id = $order->get_meta('_billing_vat_id'); // Assuming VAT ID is used for tax_id
                $phone = $order->get_billing_phone();
                $address = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();
                $receiver = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();

                // Try to get course_id from product meta or a default if not explicitly linked
                // For now, we'll use a placeholder or try to map it.
                // This part needs refinement based on how courses are linked to WC products.
                $course_id = $product->get_meta('_tpma_course_id', true); // Custom field to link WC product to TPMA course
                if (empty($course_id)) {
                    // Fallback: try to find a TPMA course by product name
                    $tpma_course = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id FROM " . TPMA_CR_DB::table('courses') . " WHERE course_name = %s LIMIT 1",
                            $product->get_name()
                        )
                    );
                    if ($tpma_course) {
                        $course_id = $tpma_course->id;
                    } else {
                        // If no course_id found, skip or log error
                        error_log("TPMA Integration: No TPMA course_id found for product ID " . $product_id);
                        continue;
                    }
                }

                // Generate a registration number
                $reg_no = TPMA_CR_DB::generate_reg_no();

                $wpdb->insert(
                    $regs_table,
                    [
                        'reg_no'             => $reg_no,
                        'created_at'         => current_time('mysql'),
                        'course_id'          => $course_id,
                        'student_name'       => $student_name,
                        'emails'             => $student_email,
                        'company_name'       => $company_name,
                        'tax_id'             => $tax_id,
                        'phone'              => $phone,
                        'address'            => $address,
                        'receiver'           => $receiver,
                        'woocommerce_order_id' => $order_id,
                        'payment_status'     => $order->get_status(), // Initial payment status from WooCommerce
                        'status'             => 'pending', // Default TPMA status
                    ],
                    [
                        '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'
                    ]
                );

                if ($wpdb->last_error) {
                    error_log("TPMA Integration: Error inserting registration for order ID {$order_id}: " . $wpdb->last_error);
                }
            }
        }
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
