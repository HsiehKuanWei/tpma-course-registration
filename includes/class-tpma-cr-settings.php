<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Settings {
    const OPTION_SPECIAL_PRODUCT_ID = 'tpma_cr_special_product_id';
    const OPTION_VIRTUAL_USER_ROLE = 'tpma_cr_virtual_user_role';
    const OPTION_AUTO_COURSE_MAIL_ENABLED = 'tpma_cr_auto_course_mail_enabled';

    public static function init() {
        add_filter('tpma_special_product_id', array(__CLASS__, 'filter_special_product_id'), 10, 1);
        add_filter('tpma_cr_registration_product_id', array(__CLASS__, 'filter_registration_product_id'), 10, 1);

        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'register_menu'));
            add_action('admin_init', array(__CLASS__, 'handle_meet_settings_callback'), 1);
            add_action('admin_post_tpma_cr_save_settings', array(__CLASS__, 'handle_save'));
            add_action('admin_post_tpma_cr_authorize_meet_settings', array(__CLASS__, 'handle_authorize_meet_settings'));
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

    public static function is_auto_course_mail_enabled(): bool {
        return (bool) (int) get_option(self::OPTION_AUTO_COURSE_MAIL_ENABLED, 0);
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
        update_option(self::OPTION_AUTO_COURSE_MAIL_ENABLED, isset($_POST['tpma_cr_auto_course_mail_enabled']) ? 1 : 0, false);

        // Save Tutor integration settings
        self::save_tutor_settings();

        self::set_notice('TPMA Course Registration ID 設定已儲存。');
        wp_safe_redirect(self::get_page_url());
        exit;
    }

    public static function handle_authorize_meet_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('tpma_cr_authorize_meet_settings');

        $client_class = '\\TutorPro\\GoogleMeet\\GoogleEvent\\GoogleEvent';
        if (!class_exists($client_class) || !class_exists('TPMA_Tutor_Bridge')) {
            self::set_notice('Tutor Pro Google Meet 模組未啟用。', 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        try {
            $google = new $client_class();
            if (empty($google->client)) {
                throw new Exception('無法建立 Google 授權用戶端。');
            }
            $google->client->addScope(TPMA_Tutor_Bridge::MEET_SETTINGS_SCOPE);
            if (method_exists($google->client, 'setIncludeGrantedScopes')) {
                $google->client->setIncludeGrantedScopes(true);
            }
            $state = wp_generate_password(48, false, false);
            set_transient(self::get_meet_oauth_state_key(), $state, 10 * MINUTE_IN_SECONDS);
            $google->client->setState($state);
            wp_redirect($google->get_consent_screen_url());
            exit;
        } catch (Throwable $e) {
            delete_transient(self::get_meet_oauth_state_key());
            self::set_notice('無法開始 Meet 權限授權：' . $e->getMessage(), 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }
    }

    private static function get_meet_oauth_state_key(): string {
        return 'tpma_cr_meet_oauth_state_' . get_current_user_id();
    }

    public static function handle_meet_settings_callback() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $tab  = sanitize_key(wp_unslash($_GET['tab'] ?? ''));
        if ($page !== 'google-meet' || $tab !== 'set-api') return;

        $expected_state = (string) get_transient(self::get_meet_oauth_state_key());
        if ($expected_state === '') return;

        $returned_state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        if ($returned_state === '' || !hash_equals($expected_state, $returned_state)) {
            delete_transient(self::get_meet_oauth_state_key());
            self::set_notice('Meet 權限授權失敗：OAuth state 驗證不符，請重新執行授權。', 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }
        delete_transient(self::get_meet_oauth_state_key());

        $oauth_error = sanitize_text_field(wp_unslash($_GET['error'] ?? ''));
        if ($oauth_error !== '') {
            self::set_notice('Meet 權限授權遭 Google 拒絕：' . $oauth_error, 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if ($code === '') {
            self::set_notice('Meet 權限授權失敗：Google 未回傳 authorization code。', 'error');
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        $client_class = '\\TutorPro\\GoogleMeet\\GoogleEvent\\GoogleEvent';
        try {
            if (!class_exists($client_class)) throw new Exception('Tutor Pro Google Meet 模組未啟用。');
            $google = new $client_class();
            if (empty($google->client)) throw new Exception('無法建立 Google 授權用戶端。');
            $google->client->addScope(TPMA_Tutor_Bridge::MEET_SETTINGS_SCOPE);

            $token_path = trailingslashit($google->upload_dir) . $google->username . '-token.json';
            $before_hash = is_readable($token_path) ? (string) hash_file('sha256', $token_path) : '';
            $google->save_token($code);
            clearstatcache(true, $token_path);
            $after_hash = is_readable($token_path) ? (string) hash_file('sha256', $token_path) : '';
            $saved = $after_hash !== '' && ($before_hash === '' || !hash_equals($before_hash, $after_hash));
            $token = $saved ? json_decode((string) file_get_contents($token_path), true) : array();
            if (!$saved || !is_array($token) || empty($token['access_token']) || !empty($token['error'])) {
                throw new Exception('Tutor 無法儲存新的 Google access token。');
            }

            update_option(self::OPTION_TUTOR_MEET_SHARED_USER, get_current_user_id(), false);
            self::set_notice('Google Meet 共用授權已更新，所有管理員現在都可建立與管理 Meet。');
        } catch (Throwable $e) {
            self::set_notice('Meet 權限授權失敗：' . $e->getMessage(), 'error');
        }
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

        self::render_tutor_settings_section();

        submit_button('儲存設定');
        echo '</form>';

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
    const OPTION_TUTOR_MEET_SHARED_USER   = 'tpma_cr_tutor_meet_shared_user';
    const OPTION_MAGIC_LINK_EXTRA_DAYS    = 'tpma_cr_magic_link_extra_days';
    const OPTION_LIVE_ACCESS_DAYS_BEFORE  = 'tpma_cr_live_access_days_before';
    const OPTION_LIVE_ACCESS_DAYS_AFTER   = 'tpma_cr_live_access_days_after';

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

    /**
     * Return the WP user that most recently completed the site-wide Google
     * Meet authorization. The value is internal; administrators never select
     * it manually.
     */
    public static function get_tutor_meet_shared_user(): int {
        return absint(get_option(self::OPTION_TUTOR_MEET_SHARED_USER, 0));
    }

    public static function get_magic_link_extra_days(): int {
        return max(1, absint(get_option(self::OPTION_MAGIC_LINK_EXTRA_DAYS, 15)));
    }

    public static function save_tutor_settings(): void {
        $was_enabled = (bool)(int)get_option(self::OPTION_TUTOR_ENABLED, 1);
        $enabled     = isset($_POST['tpma_cr_tutor_enabled']) ? 1 : 0;
        $instructor  = absint(wp_unslash($_POST['tpma_cr_tutor_default_instructor'] ?? 0));
        $extra_days  = max(1, absint(wp_unslash($_POST['tpma_cr_magic_link_extra_days'] ?? 15)));
        $days_before = max(1, absint(wp_unslash($_POST['tpma_cr_live_access_days_before'] ?? 7)));
        $days_after  = max(1, absint(wp_unslash($_POST['tpma_cr_live_access_days_after'] ?? 15)));

        update_option(self::OPTION_TUTOR_ENABLED,            $enabled,    false);
        update_option(self::OPTION_TUTOR_DEFAULT_INSTRUCTOR, $instructor, false);
        update_option(self::OPTION_MAGIC_LINK_EXTRA_DAYS,    $extra_days, false);
        update_option(self::OPTION_LIVE_ACCESS_DAYS_BEFORE,  $days_before, false);
        update_option(self::OPTION_LIVE_ACCESS_DAYS_AFTER,   $days_after, false);

        if (class_exists('TPMA_Tutor_Bridge')) {
            TPMA_Tutor_Bridge::refresh_active_state();
            if (!$was_enabled && $enabled) {
                TPMA_Tutor_Bridge::push_all_course_content_from_tpma();
            }
        }
    }

    public static function render_tutor_settings_section(): void {
        $tutor_present = class_exists('\TUTOR\Tutor');
        $enabled       = (bool)(int)get_option(self::OPTION_TUTOR_ENABLED, 1);
        $instructor    = self::get_tutor_default_instructor();
        $extra_days    = self::get_magic_link_extra_days();
        $days_before   = max(1, absint(get_option(self::OPTION_LIVE_ACCESS_DAYS_BEFORE, 7)));
        $days_after    = max(1, absint(get_option(self::OPTION_LIVE_ACCESS_DAYS_AFTER, 15)));
        $auto_mail     = self::is_auto_course_mail_enabled();

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

        echo '<tr>';
        echo '<th scope="row">課程通知自動寄發</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="tpma_cr_auto_course_mail_enabled" value="1"' . checked($auto_mail, true, false) . '> 啟用課程通知自動寄發</label>';
        echo '<p class="description">預設關閉。只控制訂單狀態更新、cron 等自動觸發的課前提醒與錄播開放通知；後台手動批次寄信不受此開關影響。</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr><th scope="row">Google Meet 共用授權</th><td>';
        $authorize_url = wp_nonce_url(
            admin_url('admin-post.php?action=tpma_cr_authorize_meet_settings'),
            'tpma_cr_authorize_meet_settings'
        );
        echo '<a class="button" href="' . esc_url($authorize_url) . '">授權／更新共用 Meet</a>';
        echo '<p class="description">首次使用或 Google 授權失效時，任一網站管理員執行一次即可。成功後全站共用該 Google Calendar 授權，其他管理員不需再授權。再次授權會改用本次登入的 Google 帳號；TPMA 建立 Meet 後會將存取類型設為「開放」，Google Workspace 管理政策仍可能限制此設定。</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="tpma_cr_live_access_days_before">直播課前開放</label></th><td>';
        echo '<input type="number" min="1" max="90" class="small-text" id="tpma_cr_live_access_days_before" name="tpma_cr_live_access_days_before" value="' . esc_attr((string)$days_before) . '"> 天';
        echo '<p class="description">課程頁、講義與 Meet 最早開放時間，預設課前 7 天。</p></td></tr>';
        echo '<tr><th scope="row"><label for="tpma_cr_live_access_days_after">直播課後保留</label></th><td>';
        echo '<input type="number" min="1" max="365" class="small-text" id="tpma_cr_live_access_days_after" name="tpma_cr_live_access_days_after" value="' . esc_attr((string)$days_after) . '"> 天';
        echo '<p class="description">課程頁、講義與測驗於場次結束後保留的天數，預設 15 天。</p></td></tr>';

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

        self::render_mail_event_diagnostics_table();
    }

    protected static function render_mail_event_diagnostics_table(): void {
        echo '<h3>for Tutor 寄件事件診斷</h3>';
        if (!class_exists('TPMA_CR_Mail_Dispatcher')) {
            echo '<p class="description">尚未載入 TPMA_CR_Mail_Dispatcher，無法產生診斷。</p>';
            return;
        }

        $rows = TPMA_CR_Mail_Dispatcher::get_mail_event_diagnostics();
        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr>';
        echo '<th>事件</th><th>觸發方式</th><th>模板</th><th>路由</th><th>收件來源</th><th>目前是否自動寄</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $event_key => $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html($event_key) . '</code><br><span class="description">' . esc_html((string)($row['label'] ?? '')) . '</span></td>';
            echo '<td>' . esc_html((string)($row['trigger'] ?? '')) . '</td>';
            $template_note = !empty($row['route_matched']) ? '路由指定' : '預設建議';
            echo '<td>' . (!empty($row['template_exists']) ? '存在' : '<strong style="color:#b32d2e;">缺少</strong>') . '<br><code>' . esc_html((string)($row['template_summary'] ?? ($row['template_key'] ?? ''))) . '</code><br><span class="description">' . esc_html($template_note) . '</span></td>';
            echo '<td>' . (!empty($row['route_matched']) ? '已命中' : '<strong style="color:#b32d2e;">未命中</strong>') . '<br><span class="description">' . esc_html((string)($row['route_summary'] ?? '')) . '</span></td>';
            echo '<td>' . (!empty($row['recipient_valid']) ? '合法' : '<strong style="color:#b32d2e;">需檢查</strong>') . '<br><span class="description">' . esc_html((string)($row['recipient_summary'] ?? '')) . '</span></td>';
            echo '<td>' . (!empty($row['auto_active']) ? '<strong style="color:#008a20;">會自動寄</strong>' : '不自動寄') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
