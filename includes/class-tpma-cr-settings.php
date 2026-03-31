<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Settings {
    const OPTION_SPECIAL_PRODUCT_ID = 'tpma_cr_special_product_id';
    const OPTION_VIRTUAL_USER_ROLE = 'tpma_cr_virtual_user_role';

    public static function init() {
        add_filter('tpma_special_product_id', array(__CLASS__, 'filter_special_product_id'), 10, 1);
        add_filter('tpma_cr_registration_product_id', array(__CLASS__, 'filter_registration_product_id'), 10, 1);

        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'register_menu'));
            add_action('admin_post_tpma_cr_save_settings', array(__CLASS__, 'handle_save'));
        }
    }

    public static function get_default_special_product_id() {
        return 0;
    }

    public static function get_special_product_id() {
        $saved = absint(get_option(self::OPTION_SPECIAL_PRODUCT_ID, 0));
        return $saved > 0 ? $saved : self::get_default_special_product_id();
    }

    public static function get_registration_product_id() {
        $saved = absint(get_option('tpma_cr_wc_product_id', 0));
        return $saved > 0 ? $saved : self::get_special_product_id();
    }

    public static function get_virtual_user_role() {
        $saved = sanitize_key((string) get_option(self::OPTION_VIRTUAL_USER_ROLE, ''));
        if ($saved !== '' && self::role_exists($saved)) {
            return $saved;
        }
        return self::get_default_virtual_user_role();
    }

    public static function get_default_virtual_user_role() {
        return (string) get_option('default_role', 'subscriber');
    }

    public static function filter_special_product_id($default) {
        $saved = absint(get_option(self::OPTION_SPECIAL_PRODUCT_ID, 0));
        return $saved > 0 ? $saved : (int) $default;
    }

    public static function filter_registration_product_id($default) {
        $saved = absint(get_option('tpma_cr_wc_product_id', 0));
        return $saved > 0 ? $saved : (int) $default;
    }

    public static function register_menu() {
        add_options_page(
            'TPMA Course Registration IDs',
            'TPMA Course Registration IDs',
            'manage_options',
            'tpma-cr-settings',
            array(__CLASS__, 'render_page')
        );
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        check_admin_referer('tpma_cr_save_settings');

        $special_id = absint(wp_unslash($_POST['tpma_cr_special_product_id'] ?? 0));
        $registration_id = absint(wp_unslash($_POST['tpma_cr_wc_product_id'] ?? 0));
        $virtual_user_role = sanitize_key(wp_unslash($_POST['tpma_cr_virtual_user_role'] ?? ''));

        if ($special_id <= 0) {
            self::set_notice('特殊商品 ID 必須大於 0。', 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }
        if ($virtual_user_role === '' || !self::role_exists($virtual_user_role)) {
            self::set_notice('虛擬會員角色不存在。', 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        update_option(self::OPTION_SPECIAL_PRODUCT_ID, $special_id, false);
        if ($registration_id > 0) {
            update_option('tpma_cr_wc_product_id', $registration_id, false);
        } else {
            delete_option('tpma_cr_wc_product_id');
        }
        update_option(self::OPTION_VIRTUAL_USER_ROLE, $virtual_user_role, false);

        // Save Tutor integration settings
        self::save_tutor_settings();

        self::set_notice('TPMA Course Registration ID 設定已儲存。');
        wp_safe_redirect(self::get_page_url());
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = self::get_notice();

        echo '<div class="wrap">';
        echo '<h1>TPMA Course Registration IDs</h1>';
        if ($notice) {
            $class = $notice['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        echo '<p class="description" style="max-width:900px;">這頁是用來指定 TPMA 報名流程要綁定哪個 Woo 商品，以及建立報名用虛擬會員時要套用哪個 WordPress 角色。若你的站點不是舊站，不會自動帶入任何既有商品 ID，需在這裡手動指定。</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tpma_cr_save_settings');
        echo '<input type="hidden" name="action" value="tpma_cr_save_settings">';
        echo '<table class="form-table" role="presentation">';
        self::render_product_select_row('特殊商品', 'tpma_cr_special_product_id', self::get_special_product_id(), '這個商品會啟用 TPMA 特殊報名流程。包含：TPMA 專用 checkout 欄位與摘要、草稿建單/處理、可訪客結帳、付款方式與按鈕文案調整、訂單完成/建立通知信、部分電子發票阻擋、禁止與其他商品混車、thankyou / order-pay / 特殊頁面判斷等。');
        self::render_product_select_row('報名商品', 'tpma_cr_wc_product_id', self::get_registration_product_id(), '這是實際加入購物車與建立報名訂單時使用的 Woo 商品。若你的前台特殊流程與實際建單商品是同一個，這裡可選和「特殊商品」相同的值。');
        self::render_role_select_row('虛擬會員角色', 'tpma_cr_virtual_user_role', self::get_virtual_user_role(), '當報名資料需要建立虛擬會員帳號時，系統會把新帳號套用成這個 WordPress 角色。建議使用專門給報名流程的角色，避免與一般前台會員混用。');
        echo '</table>';
        submit_button('儲存設定');
        echo '</form>';

        self::render_tutor_settings_section();

        echo '</div>';
    }

    public static function get_product_options() {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
            return array();
        }

        $product_ids = wc_get_products(array(
            'status' => array('publish', 'private'),
            'limit'  => -1,
            'return' => 'ids',
            'orderby' => 'title',
            'order'   => 'ASC',
        ));

        $options = array();
        foreach ((array) $product_ids as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $title = get_the_title($product_id);
            if (!is_string($title) || $title === '') {
                $title = 'Untitled';
            }

            if ($product->is_type('variable')) {
                $children = array_map('intval', (array) $product->get_children());
                $children = array_values(array_filter($children));
                if (!empty($children)) {
                    $group = array();
                    foreach ($children as $child_id) {
                        $group[$child_id] = self::get_variation_label($child_id);
                    }
                    $options[] = array(
                        'type'    => 'group',
                        'label'   => $title . ' (#' . $product_id . ')',
                        'options' => $group,
                    );
                    continue;
                }
            }

            $options[] = array(
                'type'  => 'option',
                'id'    => $product_id,
                'label' => $title . ' (#' . $product_id . ')',
            );
        }

        return $options;
    }

    public static function get_role_options() {
        $roles = function_exists('get_editable_roles') ? get_editable_roles() : array();
        if (empty($roles) && function_exists('wp_roles')) {
            $wp_roles = wp_roles();
            $roles = $wp_roles ? (array) $wp_roles->roles : array();
        }
        return is_array($roles) ? $roles : array();
    }

    public static function role_exists($role_key) {
        $role_key = sanitize_key((string) $role_key);
        if ($role_key === '') {
            return false;
        }
        if (function_exists('wp_roles')) {
            $roles = wp_roles();
            return $roles && isset($roles->roles[$role_key]);
        }
        return false;
    }

    protected static function get_variation_label($variation_id) {
        $variation_id = (int) $variation_id;
        $label = '變體 (#' . $variation_id . ')';
        if (!function_exists('wc_get_product')) {
            return $label;
        }
        $variation = wc_get_product($variation_id);
        if (!$variation || !method_exists($variation, 'get_attributes')) {
            return $label;
        }
        $parts = array();
        foreach ($variation->get_attributes() as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        if (!empty($parts)) {
            $label .= ' - ' . implode(' / ', $parts);
        }
        return $label;
    }

    protected static function render_product_select_row($label, $name, $value, $description = '') {
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td>';
        echo '<select class="regular-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        echo '<option value="">請選擇 Woo 商品或變體</option>';
        foreach (self::get_product_options() as $entry) {
            if (!is_array($entry) || empty($entry['type'])) {
                continue;
            }
            if ('group' === $entry['type']) {
                echo '<optgroup label="' . esc_attr((string) $entry['label']) . '">';
                foreach ((array) $entry['options'] as $item_id => $item_label) {
                    $item_id = (int) $item_id;
                    echo '<option value="' . esc_attr((string) $item_id) . '"' . selected($value, $item_id, false) . '>' . esc_html($item_label) . '</option>';
                }
                echo '</optgroup>';
                continue;
            }
            $item_id = (int) ($entry['id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $item_id) . '"' . selected($value, $item_id, false) . '>' . esc_html((string) $entry['label']) . '</option>';
        }
        echo '</select>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td>';
        echo '</tr>';
    }

    protected static function render_role_select_row($label, $name, $value, $description = '') {
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td>';
        echo '<select class="regular-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        foreach (self::get_role_options() as $role_key => $role_data) {
            $role_name = isset($role_data['name']) ? $role_data['name'] : $role_key;
            echo '<option value="' . esc_attr($role_key) . '"' . selected($value, $role_key, false) . '>' . esc_html($role_name . ' (' . $role_key . ')') . '</option>';
        }
        echo '</select>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td>';
        echo '</tr>';
    }

    protected static function get_page_url() {
        return admin_url('options-general.php?page=tpma-cr-settings');
    }

    protected static function set_notice($message, $type = 'success') {
        set_transient(
            'tpma_cr_settings_notice_' . get_current_user_id(),
            array(
                'message' => (string) $message,
                'type'    => $type === 'error' ? 'error' : 'success',
            ),
            60
        );
    }

    protected static function get_notice() {
        $key = 'tpma_cr_settings_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
        }
        return is_array($notice) ? $notice : null;
    }

    // ──────────────────────────────────────────────────────────
    // Tutor LMS Integration Settings
    // ──────────────────────────────────────────────────────────

    const OPTION_TUTOR_ENABLED            = 'tpma_cr_tutor_enabled';
    const OPTION_TUTOR_DEFAULT_INSTRUCTOR = 'tpma_cr_tutor_default_instructor';
    const OPTION_MAGIC_LINK_EXTRA_DAYS    = 'tpma_cr_magic_link_extra_days';

    /**
     * Returns true when Tutor LMS is active AND the integration toggle is on.
     */
    public static function is_tutor_integration_enabled(): bool {
        if (!class_exists('\TUTOR\Tutor')) {
            return false;
        }
        return (bool)(int)get_option(self::OPTION_TUTOR_ENABLED, 1);
    }

    public static function get_tutor_default_instructor(): int {
        return absint(get_option(self::OPTION_TUTOR_DEFAULT_INSTRUCTOR, 0));
    }

    public static function get_magic_link_extra_days(): int {
        return max(1, absint(get_option(self::OPTION_MAGIC_LINK_EXTRA_DAYS, 15)));
    }

    public static function save_tutor_settings(): void {
        $enabled     = isset($_POST['tpma_cr_tutor_enabled']) ? 1 : 0;
        $instructor  = absint(wp_unslash($_POST['tpma_cr_tutor_default_instructor'] ?? 0));
        $extra_days  = max(1, absint(wp_unslash($_POST['tpma_cr_magic_link_extra_days'] ?? 15)));

        update_option(self::OPTION_TUTOR_ENABLED,            $enabled,    false);
        update_option(self::OPTION_TUTOR_DEFAULT_INSTRUCTOR, $instructor, false);
        update_option(self::OPTION_MAGIC_LINK_EXTRA_DAYS,    $extra_days, false);
    }

    public static function render_tutor_settings_section(): void {
        $tutor_present = class_exists('\TUTOR\Tutor');
        $enabled       = (bool)(int)get_option(self::OPTION_TUTOR_ENABLED, 1);
        $instructor    = self::get_tutor_default_instructor();
        $extra_days    = self::get_magic_link_extra_days();

        echo '<h2>Tutor LMS 整合設定</h2>';

        if (!$tutor_present) {
            echo '<p class="description" style="color:#999;">（未偵測到 Tutor LMS 插件，以下設定在 Tutor 啟用後生效。）</p>';
        }

        echo '<table class="form-table" role="presentation">';

        // Toggle
        echo '<tr>';
        echo '<th scope="row">啟用 Tutor 整合</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="tpma_cr_tutor_enabled" value="1"' . checked($enabled, true, false) . '> 啟用（課程同步、自動報名、Magic Link）</label>';
        echo '<p class="description">停用後 TPMA 報名功能仍正常運作，僅關閉 Tutor 相關功能。</p>';
        echo '</td>';
        echo '</tr>';

        // Default instructor
        echo '<tr>';
        echo '<th scope="row"><label for="tpma_cr_tutor_default_instructor">預設 Tutor 講師 User ID</label></th>';
        echo '<td>';
        echo '<input type="number" min="0" class="small-text" id="tpma_cr_tutor_default_instructor" name="tpma_cr_tutor_default_instructor" value="' . esc_attr((string)$instructor) . '">';
        if ($instructor > 0) {
            $u = get_user_by('id', $instructor);
            if ($u) {
                echo ' <span class="description">(' . esc_html($u->display_name) . ')</span>';
            }
        }
        echo '<p class="description">若講師未在 TPMA 講師管理中綁定 WP 使用者，同步至 Tutor 時使用此帳號作為課程作者。</p>';
        echo '</td>';
        echo '</tr>';

        // Magic link expiry days
        echo '<tr>';
        echo '<th scope="row"><label for="tpma_cr_magic_link_extra_days">Magic Link 有效天數</label></th>';
        echo '<td>';
        echo '<input type="number" min="1" max="365" class="small-text" id="tpma_cr_magic_link_extra_days" name="tpma_cr_magic_link_extra_days" value="' . esc_attr((string)$extra_days) . '">';
        echo ' 天（授課日起算）';
        echo '<p class="description">學員 Email 中的免登入連結有效期限 = 授課日期 + 此天數。預設 15 天。</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
    }
}
