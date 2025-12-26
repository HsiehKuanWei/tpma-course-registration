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

    // ✅ 結帳欄位（你已在 TPMA_CR_Woo_Service 內處理）
    add_action('woocommerce_checkout_before_customer_details', ['TPMA_CR_Woo_Service', 'render_auto_fill_controls'], 1);
    add_action('woocommerce_checkout_process', ['TPMA_CR_Woo_Service', 'validate_checkout_fields']);
    add_action('woocommerce_checkout_create_order', ['TPMA_CR_Woo_Service', 'save_checkout_fields'], 10, 2);
    add_filter('woocommerce_checkout_fields', ['TPMA_CR_Woo_Service', 'add_checkout_fields']);
    add_filter('woocommerce_form_field', ['TPMA_CR_Woo_Service', 'tpma_wrap_checkout_groups'], 20, 4);


    // ✅ 修正：這行原本被你寫成 ... 會直接 fatal（500）
    add_filter('woocommerce_is_purchasable', ['TPMA_CR_Woo_Service', 'force_tpma_product_purchasable'], 10, 2);
    add_filter('woocommerce_checkout_registration_required', ['TPMA_CR_Woo_Service', 'allow_guest_checkout_for_tpma'], 10, 1);

    // ✅ 「建立帳號？」文字改成「加入TPMA會員?」
    add_filter('woocommerce_form_field_args', function($args, $key, $value){
        if (!self::cart_is_tpma_registration_only()) return $args;
        if ($key === 'createaccount') {
            $args['label'] = '加入TPMA會員?';
        }
        return $args;
    }, 10, 3);

    

    // ✅ 讓縣市/行政區下拉具備文字搜尋 + 載入你的地址互動 JS
    add_action('wp_enqueue_scripts', function(){
        if (!function_exists('is_checkout') || !is_checkout()) return;
        if (!self::cart_is_tpma_registration_only()) return;

        // Woo 內建 selectWoo（=select2），讓下拉可文字搜尋
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('selectWoo');

        // 你的地址互動 JS（路徑用 ../ 方式，避免這支檔不在外掛根目錄時找不到）
        $js_url = plugins_url('../assets/js/public/tpma-woo-address.js', __FILE__);
        wp_enqueue_script('tpma-woo-address', $js_url, ['jquery', 'selectWoo'], '20251226_fix_init', true);

        // 嘗試讀 taiwan_districts.json（路徑不對也不會 fatal）
        $districts = [];
        $candidates = [
            // 常見：外掛根目錄
            dirname(__DIR__) . '/taiwan_districts.json',
            // 常見：外掛根目錄 assets/json
            dirname(__DIR__) . '/assets/json/taiwan_districts.json',
            // 若你真的放在 includes 同層
            __DIR__ . '/taiwan_districts.json',
            __DIR__ . '/assets/json/taiwan_districts.json',
        ];

        foreach ($candidates as $p) {
            if (file_exists($p)) {
                $raw = @file_get_contents($p);
                $tmp = json_decode($raw, true);
                if (is_array($tmp) && !empty($tmp)) {
                    $districts = $tmp;
                    break;
                }
            }
        }

        wp_localize_script('tpma-woo-address', 'TPMA_WOO_ADDR', [
            'districts' => $districts,
            'selectors' => [
                'zip'   => '#tpma_postcode',
                'state' => '#tpma_state',
                'city'  => '#tpma_city',
            ],
        ]);
    

        wp_add_inline_script('tpma-woo-address', <<<JS
        jQuery(function($){

        function fixCreateAccountLabel(){
            var \$cb = $('#createaccount');
            if (!\$cb.length) return;

            // 1) 最常見：input 被 label 包住
            var \$wrapLabel = \$cb.closest('label');
            if (\$wrapLabel.length) {
            // 把 input 留下，其餘文字全部清掉，改成我們要的字
            var \$input = \$wrapLabel.find('input#createaccount').first();
            \$wrapLabel.empty().append(\$input).append(' 加入TPMA會員?');
            return;
            }

            // 2) label 用 for 指向 input
            var \$forLabel = $('label[for="createaccount"]');
            if (\$forLabel.length) {
            \$forLabel.text('加入TPMA會員?');
            return;
            }

            // 3) 兜底：全頁找包含「建立帳號」的 label/span（只改帳號區塊附近）
            $('.woocommerce-account-fields, #customer_details').find('label, span').each(function(){
            var t = $(this).text();
            if (t && (t.indexOf('建立帳號') !== -1 || t.indexOf('Create an account') !== -1)) {
                $(this).text('加入TPMA會員?');
            }
            });
        }

        // 首次載入先改一次
        fixCreateAccountLabel();

        // Woo 結帳 AJAX 更新後會把 DOM 覆蓋掉，所以每次更新都再改一次
        $(document.body).on('updated_checkout wc_fragments_loaded updated_cart_totals', function(){
            fixCreateAccountLabel();
        });

        });
        JS);
    }, 20);

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
    add_filter('woocommerce_email_enabled_customer_processing_order', function($enabled, $order){
        if (!$order instanceof WC_Order) return $enabled;
        $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true);
        return $is_tpma ? false : $enabled;
    }, 99, 2);

    add_filter('woocommerce_email_enabled_customer_on_hold_order', function($enabled, $order){
        if (!$order instanceof WC_Order) return $enabled;
        $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true);
        return $is_tpma ? false : $enabled;
    }, 99, 2);

    add_filter('woocommerce_email_enabled_new_order', function($enabled, $order){
        if (!$order instanceof WC_Order) return $enabled;
        $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true);
        return $is_tpma ? false : $enabled;
    }, 99, 2);


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

    public static function tpma_filter_createaccount_label($args, $key, $value) {
        if (!self::cart_is_tpma_registration_only()) return $args;

        if ($key === 'createaccount') {
            $args['label'] = '加入TPMA會員?';
        }
        return $args;
    }

    private static function load_taiwan_districts_data(): array {
        // 依你的實際放置位置自動嘗試（你可以把 JSON 放到其中任一路徑）
        $candidates = array(
            // 1) 與本檔同層（你現在這支檔就在 includes 也可能）
            plugin_dir_path(__FILE__) . 'taiwan_districts.json',
            // 2) 外掛根目錄
            dirname(plugin_dir_path(__FILE__)) . 'taiwan_districts.json',
            // 3) assets/json
            plugin_dir_path(__FILE__) . 'assets/json/taiwan_districts.json',
            dirname(plugin_dir_path(__FILE__)) . 'assets/json/taiwan_districts.json',
        );

        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                $raw = file_get_contents($path);
                $data = json_decode($raw, true);
                if (is_array($data) && !empty($data)) return $data;
            }
        }
        return array();
    }

    public static function enqueue_tpma_checkout_assets() {
        if (!function_exists('is_checkout') || !is_checkout()) return;
        if (!self::cart_is_tpma_registration_only()) return;

        // 讓縣市/行政區變成可搜尋下拉（selectWoo）
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('selectWoo');

        $districts = self::load_taiwan_districts_data();

        wp_enqueue_script(
            'tpma-woo-address',
            plugin_dir_url(__FILE__) . 'assets/js/public/tpma-woo-address.js',
            array('jquery', 'selectWoo'),
            '20251226_3',
            true
        );

        wp_localize_script('tpma-woo-address', 'TPMA_WOO_ADDR', array(
            'districts' => $districts,
            'selectors' => array(
                'zip'   => '#tpma_postcode',
                'state' => '#tpma_state',
                'city'  => '#tpma_city',
            ),
        ));
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

    public static function send_tpma_mails_after_order_completed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // 只處理 TPMA
        $is_tpma = (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true);
        if (!$is_tpma) return;

        // 避免重複寄
        if ($order->get_meta('_tpma_completed_mail_sent', true) === 'yes') return;

        if (class_exists('TPMA_CR_Mail_Dispatcher')) {
            // ✅ 這裡建議你在 Mail Dispatcher 實作一個「完成通知」對應模板
            // 例如：TPMA_CR_Mail_Dispatcher::send_after_order_completed($order);
            if (method_exists('TPMA_CR_Mail_Dispatcher', 'send_after_order_completed')) {
                TPMA_CR_Mail_Dispatcher::send_after_order_completed($order);
            } else {
                // 保底：如果你暫時還沒做 completed 專用模板，可先沿用已存在的流程
                TPMA_CR_Mail_Dispatcher::send_after_order_created($order);
            }
        }

        $order->update_meta_data('_tpma_completed_mail_sent', 'yes');
        $order->save();
    }
        
}



TPMA_WooCommerce_Integration::init();
