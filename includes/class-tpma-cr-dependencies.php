<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Dependencies {
    public static function init() {
        if (is_admin()) {
            add_action('admin_notices', array(__CLASS__, 'render_admin_notices'));
        }
    }

    public static function has_woocommerce() {
        return class_exists('WooCommerce') && function_exists('WC');
    }

    public static function has_tpma_woo_fields() {
        return class_exists('TPMA_Woo_Facade') || defined('TPMA_WOO_NEW_LOADED');
    }

    public static function get_missing_messages() {
        $messages = array();

        if (!self::has_woocommerce()) {
            $messages[] = 'WooCommerce 未啟用，TPMA Course Registration 的結帳、建單、訂單同步與特殊商品專用流程已停用。';
        }

        if (!self::has_tpma_woo_fields()) {
            $messages[] = 'tpma-woo-fields 未啟用，TPMA 公開報名的 checkout-init / 加車流程已停用。';
        }

        return $messages;
    }

    public static function render_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        foreach (self::get_missing_messages() as $message) {
            echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
        }
    }
}
