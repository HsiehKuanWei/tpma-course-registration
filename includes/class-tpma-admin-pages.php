<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Admin_Pages
{
    const PARENT_SLUG = 'tpma-cr-reports';
    const PARENT_TITLE = '上市櫃課程';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 9);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function register_menu(): void
    {
        add_menu_page(
            self::PARENT_TITLE,
            self::PARENT_TITLE,
            'manage_options',
            self::PARENT_SLUG,
            array(__CLASS__, 'render_report_page'),
            'dashicons-chart-bar',
            57
        );

        add_submenu_page(
            self::PARENT_SLUG,
            '統計報表',
            '統計報表',
            'manage_options',
            self::PARENT_SLUG,
            array(__CLASS__, 'render_report_page')
        );

        add_submenu_page(
            self::PARENT_SLUG,
            '舊資料匯入',
            '舊資料匯入',
            'manage_options',
            'tpma-cr-import',
            array(__CLASS__, 'render_import_page')
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $version = defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : '1';

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page === self::PARENT_SLUG) {
            $asset_version = $version . '-' . max(
                (int) @filemtime(TPMA_CR_PATH . 'assets/css/report-admin.css'),
                (int) @filemtime(TPMA_CR_PATH . 'assets/js/report-admin.js')
            );
            wp_enqueue_style('tpma-cr-admin-common', TPMA_CR_URL . 'assets/css/admin-common.css', array(), $version);
            wp_enqueue_style('tpma-cr-report-admin', TPMA_CR_URL . 'assets/css/report-admin.css', array('tpma-cr-admin-common'), $asset_version);
            wp_enqueue_script('tpma-cr-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js', array(), '4.4.7', true);
            wp_enqueue_script('tpma-cr-report-admin', TPMA_CR_URL . 'assets/js/report-admin.js', array('tpma-cr-chartjs'), $asset_version, true);
            wp_add_inline_script('tpma-cr-report-admin', 'window.TPMAReportAdminConfig = ' . wp_json_encode(array(
                'apiBase' => untrailingslashit(rest_url('tpma/v1')),
                'nonce'   => wp_create_nonce('wp_rest'),
            ), JSON_UNESCAPED_UNICODE) . ';', 'before');
        }

        if ($page === 'tpma-cr-import') {
            wp_enqueue_style('tpma-cr-admin-common', TPMA_CR_URL . 'assets/css/admin-common.css', array(), $version);
        }
    }

    public static function render_report_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', self::PARENT_TITLE, array('response' => 403));
        }
        include TPMA_CR_PATH . 'views/report-admin.php';
    }

    public static function render_import_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', self::PARENT_TITLE, array('response' => 403));
        }
        include TPMA_CR_PATH . 'views/import-admin.php';
    }
}
