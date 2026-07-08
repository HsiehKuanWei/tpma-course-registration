<?php

/*

Plugin Name: TPMA Course & Registration

Description: 課程資料庫與報名資料庫，提供外部表單、前端管理介面與匯入工具。

Version: 1.9.1

Author: TPMA

*/



if (!defined('ABSPATH')) {

    exit;

}



/**

 * 常數：只在主檔定義一次

 */

if (!defined('TPMA_CR_VERSION')) {

    define('TPMA_CR_VERSION', '1.9.1');

}

if (!defined('TPMA_CR_PATH')) {

    define('TPMA_CR_PATH', plugin_dir_path(__FILE__));

}

if (!defined('TPMA_CR_URL')) {

    define('TPMA_CR_URL', plugin_dir_url(__FILE__));

}



/**

 * 載入類別

 */

require_once TPMA_CR_PATH . 'includes/class-tpma-db.php';

require_once TPMA_CR_PATH . 'includes/class-tpma-rest-public.php';

require_once TPMA_CR_PATH . 'includes/class-tpma-rest-admin.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-mail-dispatcher.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-cr-mailer-registration.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-import.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-cr-dependencies.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-cr-settings.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-woo-shared.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-admin-woo-service.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-thankyou-view.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-special-product.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-tutor-bridge.php';
require_once TPMA_CR_PATH . 'includes/class-tpma-course-access.php';
// WooCommerce 整合已移至獨立插件 tpma-woo-fields，這裡不再載入舊版：
// require_once TPMA_CR_PATH . 'includes/class-tpma-woo-service.php';
// require_once TPMA_CR_PATH . 'includes/class-tpma-woocommerce-integration.php';

// 啟用特殊商品流程（放在 plugins_loaded 以確保 Woo 已載入）
add_action('plugins_loaded', function () {
    if (class_exists('TPMA_CR_Dependencies')) {
        TPMA_CR_Dependencies::init();
    }
    if (class_exists('TPMA_CR_Settings')) {
        TPMA_CR_Settings::init();
    }
    if (class_exists('TPMA_Woo_Special_Product')
        && (!class_exists('TPMA_CR_Dependencies') || TPMA_CR_Dependencies::has_woocommerce())
    ) {
        TPMA_Woo_Special_Product::init();
    }

    // Tutor LMS integration (optional — silently skipped when Tutor is absent)
    if (class_exists('TPMA_Tutor_Bridge')) {
        TPMA_Tutor_Bridge::init();
    }
    if (class_exists('TPMA_Course_Access')) {
        TPMA_Course_Access::init();
    }
}, 12);



/**

 * 啟用：建立 / 更新資料表

 */

register_activation_hook(__FILE__, array('TPMA_CR_DB', 'on_activate'));



/**

 * 每日排程：清理過期場次（只標記 session 不啟用，不刪課程）

 */

add_action('init', function () {

    if (!wp_next_scheduled('tpma_daily_cleanup')) {

        wp_schedule_event(time(), 'daily', 'tpma_daily_cleanup');

    }

});

add_action('tpma_daily_cleanup', array('TPMA_CR_DB', 'cleanup_old_sessions'));
add_action('tpma_daily_cleanup', function () {
    if (class_exists('TPMA_CR_DB')) {
        TPMA_CR_DB::backfill_registration_session_ids(500);
    }
});



/**

 * 註冊 REST Routes

 */

add_action('rest_api_init', array('TPMA_CR_REST_Public', 'register_routes'));

add_action('rest_api_init', array('TPMA_CR_REST_Admin', 'register_routes'));



/**

 * 匯入處理 (admin_post)

 */

add_action('admin_post_tpma_import', array('TPMA_CR_Import', 'handle_import'));



/**

 * Shortcodes：前端管理頁（有權限檢查）

 * [tpma_reg_admin]     報名管理

 * [tpma_course_admin]  課程管理

 * [tpma_import_admin]  匯入工具

 */

add_shortcode('tpma_reg_admin', array('TPMA_CR_REST_Admin', 'shortcode_reg_admin'));

add_shortcode('tpma_course_admin', array('TPMA_CR_REST_Admin', 'shortcode_course_admin'));

add_shortcode('tpma_import_admin', array('TPMA_CR_Import', 'shortcode_import_admin'));


/**
 * Frontend shortcodes
 *
 * [tpma_form]         公開報名表單
 * [tpma_course_list]  公開課程列表，form_url 可覆寫報名頁網址，api_base 可自訂 REST 位置
 */
function tpma_cr_get_registration_form_url()
{
    $saved = get_option('tpma_cr_registration_form_url', '');
    if (is_string($saved) && $saved !== '') {
        return esc_url_raw($saved);
    }

    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        's'              => '[tpma_form]',
    ));

    if (!empty($pages) && !empty($pages[0]->ID)) {
        $url = get_permalink($pages[0]->ID);
        if (is_string($url) && $url !== '') {
            return esc_url_raw($url);
        }
    }

    return esc_url_raw(TPMA_CR_URL . 'form.html');
}

function tpma_cr_shortcode_form($atts = array())
{
    $api_base    = rtrim(rest_url('tpma/v1'), '/');
    if (!empty($atts['api_base'])) {
        $api_base = esc_url_raw(rtrim($atts['api_base'], '/'));
    }
    $assets_base = TPMA_CR_URL;

    ob_start();
    include TPMA_CR_PATH . 'views/form-public.php';
    return ob_get_clean();
}
add_shortcode('tpma_form', 'tpma_cr_shortcode_form');

function tpma_cr_shortcode_course_list($atts = array())
{
    $atts = shortcode_atts(
        array(
            'form_url' => '',
            'api_base' => '',
        ),
        $atts
    );

    $api_base    = rtrim(rest_url('tpma/v1'), '/');
    $assets_base = TPMA_CR_URL;
    if (!empty($atts['api_base'])) {
        $api_base = esc_url_raw(rtrim($atts['api_base'], '/'));
    }
    $form_url = !empty($atts['form_url']) ? esc_url_raw($atts['form_url']) : tpma_cr_get_registration_form_url();

    ob_start();
    include TPMA_CR_PATH . 'views/list-public.php';
    return ob_get_clean();
}
add_shortcode('tpma_course_list', 'tpma_cr_shortcode_course_list');
add_shortcode('tpma_list', 'tpma_cr_shortcode_course_list');


// --- 允許特定網域的 CORS（給 REST API 用）---
/**
 * Frontend assets for Woo checkout.
 */
add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !function_exists('is_cart')) {
        return;
    }
    if (!is_checkout() && !is_cart()) {
        return;
    }
    if (defined('TPMA_WOO_NEW_LOADED') && is_checkout()) {
        return;
    }
    $woo_css_rel = 'tpma-woo-fields/assets/css/checkout.css';
    $woo_css_abs = rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $woo_css_rel;
    $use_woo_css = file_exists($woo_css_abs);
    $css_url = $use_woo_css ? plugins_url($woo_css_rel) : TPMA_CR_URL . 'assets/css/checkout.css';
    $css_ver = $use_woo_css
        ? (defined('TPMA_WOO_FIELDS_VERSION') ? TPMA_WOO_FIELDS_VERSION : @filemtime($woo_css_abs))
        : (defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null);
    wp_enqueue_style('tpma-cr-checkout', $css_url, array(), $css_ver);
}, 20);

add_action('rest_api_init', function () {
    // 允許的前端網域
    $allowed_origins = [
        'https://www.tw-pma.org.tw',
        'https://nas.tw-pma.org.tw',
    ];

    // 專門處理 REST 回應輸出（含 preflight）
    add_filter('rest_pre_serve_request', function ($value) use ($allowed_origins) {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        if (in_array($origin, $allowed_origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin'); // 避免快取錯亂
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
        }

        // 處理預檢請求 (OPTIONS)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // 上面已經送出允許的 header，這裡直接結束即可
            status_header(204); // No Content
            exit;
        }

        return $value;
    });
});



// Ensure schema updated after plugin load
add_action('plugins_loaded', function(){
    if (class_exists('TPMA_CR_DB')) {
        TPMA_CR_DB::ensure_schema_current();
        TPMA_CR_DB::backfill_registration_session_ids(100);
    }
});
