<?php
/*
Plugin Name: TPMA Course & Registration
Description: 課程資料庫與報名資料庫，提供外部表單、前端管理介面與匯入工具。
Version: 1.5.0
Author: TPMA
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 常數：只在主檔定義一次
 */
if (!defined('TPMA_CR_VERSION')) {
    define('TPMA_CR_VERSION', '1.5.0');
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
require_once TPMA_CR_PATH . 'includes/class-tpma-import.php';

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
