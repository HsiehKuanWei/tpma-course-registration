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
            add_action('admin_post_tpma_cr_backfill_tutor_enrollments', array(__CLASS__, 'handle_backfill_tutor_enrollments'));
            add_action('admin_post_tpma_cr_repair_unsafe_learner_binding', array(__CLASS__, 'handle_repair_unsafe_learner_binding'));
            add_action('admin_post_tpma_cr_repair_all_unsafe_learner_bindings', array(__CLASS__, 'handle_bulk_repair_unsafe_learner_bindings'));
            add_action('admin_post_tpma_cr_scan_quiz_score_sync', array(__CLASS__, 'handle_scan_quiz_score_sync'));
            add_action('admin_post_tpma_cr_resync_safe_quiz_scores', array(__CLASS__, 'handle_resync_safe_quiz_scores'));
            add_action('admin_post_tpma_cr_manual_rebind_quiz_score', array(__CLASS__, 'handle_manual_rebind_quiz_score'));
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
            $google->client->setAccessType('offline');
            if (method_exists($google->client, 'setPrompt')) {
                $google->client->setPrompt('consent');
            }
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

    /** Build missing Tutor enrollments for registrations created before the bridge existed. */
    public static function handle_backfill_tutor_enrollments() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('tpma_cr_backfill_tutor_enrollments');

        if (!class_exists('TPMA_Tutor_Bridge')) {
            self::set_notice('Tutor 整合未載入，無法補建既有學員權限。', 'error');
        } else {
            $result = TPMA_Tutor_Bridge::backfill_legacy_enrollments();
            if (is_wp_error($result)) {
                self::set_notice($result->get_error_message(), 'error');
            } else {
                self::set_notice(sprintf(
                    '既有學員 Tutor 權限補建完成：已檢查 %d 筆、補建 %d 筆、跳過 %d 筆、失敗 %d 筆。',
                    (int) ($result['scanned'] ?? 0),
                    (int) ($result['enrolled'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                    (int) ($result['failed'] ?? 0)
                ), !empty($result['failed']) ? 'error' : 'success');
            }
        }

        wp_safe_redirect(self::get_page_url());
        exit;
    }

    /** Create a dedicated virtual learner for one registration that is bound to staff. */
    public static function handle_repair_unsafe_learner_binding() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        $registration_id = absint(wp_unslash($_POST['registration_id'] ?? 0));
        check_admin_referer('tpma_cr_repair_unsafe_learner_binding_' . $registration_id);
        if (!class_exists('TPMA_Course_Access')) {
            self::set_notice('課程入口模組未載入，無法修正帳號綁定。', 'error');
        } else {
            $result = TPMA_Course_Access::repair_unsafe_learner_binding($registration_id);
            if (is_wp_error($result)) {
                self::set_notice($result->get_error_message(), 'error');
            } else {
                self::set_notice('學員帳號已改綁專用帳號，並已同步處理 Tutor 課程權限。');
            }
        }
        wp_safe_redirect(self::get_page_url());
        exit;
    }

    /** Repair every registration that is currently bound to a privileged account. */
    public static function handle_bulk_repair_unsafe_learner_bindings() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('tpma_cr_repair_all_unsafe_learner_bindings');

        $result = self::bulk_repair_unsafe_learner_bindings();
        if (is_wp_error($result)) {
            self::set_notice($result->get_error_message(), 'error');
        } else {
            self::set_bulk_unsafe_repair_result($result);
            self::set_notice(sprintf(
                '批次修正完成：成功 %d 筆、略過 %d 筆、失敗 %d 筆。',
                (int) ($result['success'] ?? 0),
                (int) ($result['skipped'] ?? 0),
                count((array) ($result['failed'] ?? array()))
            ), !empty($result['failed']) ? 'error' : 'success');
        }

        wp_safe_redirect(self::get_page_url());
        exit;
    }

    /** Run the established single-registration repair logic for current unsafe bindings. */
    public static function bulk_repair_unsafe_learner_bindings() {
        if (!class_exists('TPMA_Course_Access')) {
            return new WP_Error('course_access_unavailable', '課程入口模組未載入，無法批次修正帳號綁定。');
        }

        $summary = array(
            'scanned' => 0,
            'success' => 0,
            'skipped' => 0,
            'failed'  => array(),
        );
        $bindings = (array) TPMA_Course_Access::get_unsafe_learner_bindings();
        $summary['scanned'] = count($bindings);

        foreach ($bindings as $binding) {
            $registration_id = absint($binding['id'] ?? 0);
            $failure = array(
                'registration_id' => $registration_id,
                'reg_no'          => (string) ($binding['reg_no'] ?? $registration_id),
                'student_name'    => (string) ($binding['student_name'] ?? ''),
            );
            if ($registration_id <= 0) {
                $failure['message'] = '報名資料 ID 無效。';
                $summary['failed'][] = $failure;
                continue;
            }

            $result = TPMA_Course_Access::repair_unsafe_learner_binding($registration_id);
            if (!is_wp_error($result)) {
                $summary['success']++;
                continue;
            }

            if ($result->get_error_code() === 'binding_not_unsafe') {
                $summary['skipped']++;
                continue;
            }

            $failure['message'] = $result->get_error_message();
            $summary['failed'][] = $failure;
        }

        return $summary;
    }

    /** Scan Tutor attempts that have not populated TPMA test_score; this action never changes data. */
    public static function handle_scan_quiz_score_sync(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('tpma_cr_scan_quiz_score_sync');

        if (!class_exists('TPMA_Tutor_Bridge')) {
            self::set_notice('Tutor 整合未載入，無法掃描測驗成績同步。', 'error');
        } else {
            $result = TPMA_Tutor_Bridge::scan_quiz_score_sync_issues();
            if (is_wp_error($result)) {
                self::set_notice($result->get_error_message(), 'error');
            } else {
                self::set_quiz_score_sync_scan_result($result);
                self::set_notice(sprintf(
                    '測驗成績同步掃描完成：檢查 %d 筆 Tutor 已完成作答，發現 %d 筆需處理作答。',
                    (int) ($result['scanned'] ?? 0),
                    count((array) ($result['issues'] ?? array()))
                ));
            }
        }

        wp_safe_redirect(self::get_page_url());
        exit;
    }

    /** Re-sync only score records whose attempt context and learner identity are already unambiguous. */
    public static function handle_resync_safe_quiz_scores(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('tpma_cr_resync_safe_quiz_scores');
        if (!class_exists('TPMA_Tutor_Bridge')) {
            self::set_notice('Tutor 整合未載入，無法重新同步測驗成績。', 'error');
        } else {
            $result = TPMA_Tutor_Bridge::resync_safe_quiz_scores();
            if (is_wp_error($result)) {
                self::set_notice($result->get_error_message(), 'error');
            } else {
                $failed = (array) ($result['failed'] ?? array());
                self::set_notice(sprintf(
                    '安全成績重同步完成：可處理 %d 筆、成功 %d 筆、失敗 %d 筆。',
                    (int) ($result['eligible'] ?? 0),
                    (int) ($result['success'] ?? 0),
                    count($failed)
                ), empty($failed) ? 'success' : 'error');
                $scan = TPMA_Tutor_Bridge::scan_quiz_score_sync_issues();
                if (!is_wp_error($scan)) self::set_quiz_score_sync_scan_result($scan);
            }
        }
        wp_safe_redirect(self::get_page_url());
        exit;
    }

    /** Apply an administrator-confirmed historical attempt-to-registration override. */
    public static function handle_manual_rebind_quiz_score(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        $attempt_id = absint(wp_unslash($_POST['attempt_id'] ?? 0));
        $target_registration_id = absint(wp_unslash($_POST['target_registration_id'] ?? 0));
        check_admin_referer('tpma_cr_manual_rebind_quiz_score_' . $attempt_id);
        if (!class_exists('TPMA_Tutor_Bridge')) {
            self::set_notice('Tutor 整合未載入，無法人工覆寫測驗成績。', 'error');
        } else {
            $result = TPMA_Tutor_Bridge::manually_rebind_quiz_score($attempt_id, $target_registration_id);
            if (is_wp_error($result)) {
                self::set_notice($result->get_error_message(), 'error');
            } else {
                self::set_notice(sprintf(
                    '已將作答 #%d 指定給目標報名，並寫入成績 %s%%。原對應報名的成績已重新計算。',
                    (int) ($result['attempt_id'] ?? $attempt_id),
                    (string) ($result['score'] ?? '')
                ));
                $scan = TPMA_Tutor_Bridge::scan_quiz_score_sync_issues();
                if (!is_wp_error($scan)) self::set_quiz_score_sync_scan_result($scan);
            }
        }
        wp_safe_redirect(self::get_page_url());
        exit;
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
            $previous_token = is_readable($token_path) ? json_decode((string) file_get_contents($token_path), true) : array();
            $previous_refresh_token = is_array($previous_token) && !empty($previous_token['refresh_token'])
                ? (string) $previous_token['refresh_token']
                : '';
            $google->save_token($code);
            if ($previous_refresh_token !== '') {
                TPMA_Tutor_Bridge::preserve_google_meet_refresh_token(get_current_user_id(), $previous_refresh_token);
            }
            clearstatcache(true, $token_path);
            $after_hash = is_readable($token_path) ? (string) hash_file('sha256', $token_path) : '';
            $saved = $after_hash !== '' && ($before_hash === '' || !hash_equals($before_hash, $after_hash));
            $token = $saved ? json_decode((string) file_get_contents($token_path), true) : array();
            if (!$saved || !is_array($token) || empty($token['access_token']) || !empty($token['error'])) {
                throw new Exception('Tutor 無法儲存新的 Google access token。');
            }

            update_option(self::OPTION_TUTOR_MEET_SHARED_USER, get_current_user_id(), false);
            $backup_result = TPMA_Tutor_Bridge::sync_google_meet_auth_backup(get_current_user_id());
            if (is_wp_error($backup_result)) {
                self::set_notice('Google Meet 共用授權已更新，但受保護備份無法建立。請確認網站上層資料夾可寫入後，再執行一次「授權／更新共用 Meet」。', 'error');
            } else {
                self::set_notice('Google Meet 共用授權已更新，受保護還原檔也已同步。所有管理員現在都可建立與管理 Meet。');
            }
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

    private static function set_bulk_unsafe_repair_result(array $result): void {
        set_transient(
            'tpma_cr_bulk_unsafe_repair_result_' . get_current_user_id(),
            $result,
            10 * MINUTE_IN_SECONDS
        );
    }

    private static function get_bulk_unsafe_repair_result(): ?array {
        $key = 'tpma_cr_bulk_unsafe_repair_result_' . get_current_user_id();
        $result = get_transient($key);
        if ($result) {
            delete_transient($key);
        }
        return is_array($result) ? $result : null;
    }

    private static function set_quiz_score_sync_scan_result(array $result): void {
        set_transient(
            'tpma_cr_quiz_score_sync_scan_' . get_current_user_id(),
            $result,
            10 * MINUTE_IN_SECONDS
        );
    }

    private static function get_quiz_score_sync_scan_result(): ?array {
        $key = 'tpma_cr_quiz_score_sync_scan_' . get_current_user_id();
        $result = get_transient($key);
        if ($result) {
            delete_transient($key);
        }
        return is_array($result) ? $result : null;
    }

    // ──────────────────────────────────────────────────────────
    // Tutor LMS Integration Settings
    // ──────────────────────────────────────────────────────────

    const OPTION_TUTOR_ENABLED            = 'tpma_cr_tutor_enabled';
    const OPTION_TUTOR_DEFAULT_INSTRUCTOR = 'tpma_cr_tutor_default_instructor';
    const OPTION_TUTOR_MEET_SHARED_USER   = 'tpma_cr_tutor_meet_shared_user';
    const OPTION_TUTOR_MEET_DIAGNOSTICS   = 'tpma_cr_tutor_meet_diagnostics';
    const OPTION_TUTOR_MEET_STATUS        = 'tpma_cr_tutor_meet_status';
    const OPTION_MAGIC_LINK_EXTRA_DAYS    = 'tpma_cr_magic_link_extra_days';
    const OPTION_LIVE_ACCESS_DAYS_BEFORE  = 'tpma_cr_live_access_days_before';
    const OPTION_LIVE_ACCESS_DAYS_AFTER   = 'tpma_cr_live_access_days_after';

    private const TUTOR_MEET_DIAGNOSTIC_LIMIT = 30;

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

    /**
     * Return the latest safe authorization result recorded by the Tutor bridge.
     */
    public static function get_tutor_meet_status(): array {
        $status = get_option(self::OPTION_TUTOR_MEET_STATUS, array());
        return is_array($status) ? $status : array();
    }

    /**
     * Return the newest first, capped diagnostic history for shared Meet auth.
     */
    public static function get_tutor_meet_diagnostics(): array {
        $records = get_option(self::OPTION_TUTOR_MEET_DIAGNOSTICS, array());
        return is_array($records)
            ? array_slice($records, 0, self::TUTOR_MEET_DIAGNOSTIC_LIMIT)
            : array();
    }

    /**
     * Save a display-safe Meet diagnostic result without storing OAuth secrets.
     */
    public static function record_tutor_meet_diagnostic(string $operation, bool $valid, string $code, string $reason): void {
        $entry = array(
            'checked_at' => current_time('mysql'),
            'operation'  => substr(sanitize_text_field($operation), 0, 80),
            'valid'      => $valid ? 1 : 0,
            'code'       => substr(sanitize_key($code), 0, 80),
            'reason'     => substr(sanitize_text_field($reason), 0, 250),
        );

        update_option(self::OPTION_TUTOR_MEET_STATUS, $entry, false);

        $records = self::get_tutor_meet_diagnostics();
        $latest  = $records[0] ?? array();
        if (
            $operation === '設定頁檢查'
            && (int) ($latest['valid'] ?? -1) === $entry['valid']
            && (string) ($latest['code'] ?? '') === $entry['code']
            && (string) ($latest['reason'] ?? '') === $entry['reason']
        ) {
            return;
        }

        array_unshift($records, $entry);
        update_option(
            self::OPTION_TUTOR_MEET_DIAGNOSTICS,
            array_slice($records, 0, self::TUTOR_MEET_DIAGNOSTIC_LIMIT),
            false
        );
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

        $meet_status = class_exists('TPMA_Tutor_Bridge')
            ? TPMA_Tutor_Bridge::get_google_meet_authorization_status()
            : array('valid' => false, 'code' => 'bridge_unavailable', 'reason' => 'Tutor 整合未啟用');
        $meet_status_record = self::get_tutor_meet_status();
        $meet_diagnostics   = self::get_tutor_meet_diagnostics();
        $meet_shared_user   = get_user_by('id', self::get_tutor_meet_shared_user());
        $meet_valid         = !empty($meet_status['valid']);
        $meet_status_color  = $meet_valid ? '#0a7a2f' : '#b32d2e';
        $meet_status_text   = $meet_valid ? '目前有效' : '目前失效';
        $meet_checked_at    = (string) ($meet_status_record['checked_at'] ?? '');

        echo '<tr><th scope="row">Google Meet 共用授權</th><td>';
        echo '<p style="margin:0 0 6px;color:' . esc_attr($meet_status_color) . ';font-weight:600;">' . esc_html($meet_status_text) . '</p>';
        echo '<p class="description" style="margin-top:0;">' . esc_html((string) ($meet_status['reason'] ?? '尚未檢查 Google Meet 共用授權。')) . '</p>';
        echo '<p class="description">共用授權帳號：' . esc_html($meet_shared_user ? $meet_shared_user->display_name : '尚未設定') . '；最近檢查：' . esc_html($meet_checked_at !== '' ? $meet_checked_at : '尚未檢查') . '</p>';
        $authorize_url = wp_nonce_url(
            admin_url('admin-post.php?action=tpma_cr_authorize_meet_settings'),
            'tpma_cr_authorize_meet_settings'
        );
        echo '<a class="button" href="' . esc_url($authorize_url) . '">授權／更新共用 Meet</a>';
        echo '<p class="description">首次使用或 Google 授權失效時，任一網站管理員執行一次即可。成功後全站共用該 Google Calendar 授權，其他管理員不需再授權。再次授權會改用本次登入的 Google 帳號；TPMA 建立 Meet 後會將存取類型設為「開放」，Google Workspace 管理政策仍可能限制此設定。</p>';
        echo '<details style="margin-top:12px;"><summary>查看診斷紀錄（最近 ' . esc_html((string) count($meet_diagnostics)) . ' 筆）</summary>';
        if (empty($meet_diagnostics)) {
            echo '<p class="description">尚無診斷紀錄。</p>';
        } else {
            echo '<table class="widefat striped" style="margin-top:8px;max-width:900px;"><thead><tr><th>時間</th><th>操作</th><th>結果</th><th>原因</th></tr></thead><tbody>';
            foreach ($meet_diagnostics as $meet_diagnostic) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($meet_diagnostic['checked_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($meet_diagnostic['operation'] ?? '')) . '</td>';
                echo '<td>' . esc_html(!empty($meet_diagnostic['valid']) ? '有效' : '失效') . '</td>';
                echo '<td>' . esc_html((string) ($meet_diagnostic['reason'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</details>';
        echo '</td></tr>';

        $backfill_url = wp_nonce_url(
            admin_url('admin-post.php?action=tpma_cr_backfill_tutor_enrollments'),
            'tpma_cr_backfill_tutor_enrollments'
        );
        echo '<tr><th scope="row">既有學員 Tutor 權限</th><td>';
        echo '<a class="button button-secondary" href="' . esc_url($backfill_url) . '" onclick="return confirm(\'將補建上線前既有報名但尚未建立 Tutor 課程權限的學員。已存在的 enrollment 不會重複建立。是否繼續？\');">補建既有學員 Tutor 權限</a>';
        echo '<p class="description">只處理有學員帳號、已連結 Tutor 課程、未取消／退款且缺少 Tutor enrollment 的報名資料。此操作可重複執行，已建立的學員會自動跳過。</p>';
        echo '</td></tr>';

        $unsafe_bindings = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::get_unsafe_learner_bindings() : array();
        $bulk_repair_result = self::get_bulk_unsafe_repair_result();
        echo '<tr><th scope="row">學員考試帳號安全檢查</th><td>';
        if ($bulk_repair_result) {
            $bulk_failed = (array) ($bulk_repair_result['failed'] ?? array());
            $bulk_class = empty($bulk_failed) ? 'notice-success' : 'notice-warning';
            echo '<div class="notice inline ' . esc_attr($bulk_class) . '"><p>' . esc_html(sprintf(
                '本次批次處理：檢查 %d 筆、成功 %d 筆、略過 %d 筆、失敗 %d 筆。',
                (int) ($bulk_repair_result['scanned'] ?? 0),
                (int) ($bulk_repair_result['success'] ?? 0),
                (int) ($bulk_repair_result['skipped'] ?? 0),
                count($bulk_failed)
            )) . '</p>';
            if (!empty($bulk_failed)) {
                echo '<ul style="list-style:disc;margin:0 0 10px 28px;">';
                foreach ($bulk_failed as $failure) {
                    $label = trim((string) ($failure['reg_no'] ?? '') . ' ' . (string) ($failure['student_name'] ?? ''));
                    echo '<li>' . esc_html(($label !== '' ? $label . '：' : '') . (string) ($failure['message'] ?? '修正失敗。')) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }
        if (empty($unsafe_bindings)) {
            echo '<p style="color:#008a20;font-weight:600;margin:0;">目前所有有效報名均已綁定專用學員帳號。</p>';
        } else {
            echo '<p style="color:#b32d2e;font-weight:600;">發現 ' . esc_html((string)count($unsafe_bindings)) . ' 筆報名未綁定專用學員帳號（可能是付款人、承辦人、一般會員、管理員或課程作者）。入口與測驗已拒絕使用這些帳號；可一鍵批次修正，或逐筆建立專用學員帳號並修正。</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px;">';
            echo '<input type="hidden" name="action" value="tpma_cr_repair_all_unsafe_learner_bindings">';
            wp_nonce_field('tpma_cr_repair_all_unsafe_learner_bindings');
            echo '<button type="submit" class="button button-primary" onclick="return confirm(\'將修正全部目前未使用專用學員帳號的報名。每筆會建立專用學員帳號並同步 Tutor 權限；失敗項目不會中斷其他資料。是否繼續？\');">一鍵修正全部 ' . esc_html((string) count($unsafe_bindings)) . ' 筆帳號錯綁紀錄</button>';
            echo '</form>';
            echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>報名編號</th><th>學員</th><th>課程</th><th>目前綁定帳號</th><th>操作</th></tr></thead><tbody>';
            foreach ($unsafe_bindings as $binding) {
                $registration_id = (int)($binding['id'] ?? 0);
                $account = trim((string)($binding['account_login'] ?? '') . ' ' . (string)($binding['account_name'] ?? ''));
                echo '<tr><td>' . esc_html((string)($binding['reg_no'] ?? $registration_id)) . '</td>';
                echo '<td>' . esc_html((string)($binding['student_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($binding['course_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html($account !== '' ? $account : '找不到使用者') . '</td><td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="tpma_cr_repair_unsafe_learner_binding">';
                echo '<input type="hidden" name="registration_id" value="' . esc_attr((string)$registration_id) . '">';
                wp_nonce_field('tpma_cr_repair_unsafe_learner_binding_' . $registration_id);
                echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'將為此學員建立專用帳號、改綁報名並調整 Tutor 權限。是否繼續？\');">建立專用學員帳號並修正</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</td></tr>';

        $quiz_score_scan = self::get_quiz_score_sync_scan_result();
        echo '<tr><th scope="row">測驗成績同步檢查</th><td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
        echo '<input type="hidden" name="action" value="tpma_cr_scan_quiz_score_sync">';
        wp_nonce_field('tpma_cr_scan_quiz_score_sync');
        echo '<button type="submit" class="button button-secondary">掃描未同步測驗成績</button>';
        echo '</form>';
        echo '<p class="description">掃描以每一筆 Tutor 完成作答為單位，不會把同一帳號代考的多筆作答合併。表格會分開顯示實際作答帳號與目前對應報名；已正確對應專用學員帳號的作答可一鍵重同步，其餘資料必須由管理員指定目標報名後才會覆寫。</p>';
        if ($quiz_score_scan) {
            $quiz_score_issues = (array) ($quiz_score_scan['issues'] ?? array());
            echo '<div class="notice inline ' . esc_attr(empty($quiz_score_issues) ? 'notice-success' : 'notice-warning') . '"><p>' . esc_html(sprintf(
                '本次掃描：檢查 %d 筆 Tutor 已完成作答，發現 %d 筆需處理作答。',
                (int) ($quiz_score_scan['scanned'] ?? 0),
                count($quiz_score_issues)
            )) . '</p></div>';
            if (empty($quiz_score_issues)) {
                echo '<p style="color:#008a20;font-weight:600;">沒有發現需要處理的 Tutor 已完成作答。</p>';
            } else {
                $safe_score_issues = array_values(array_filter($quiz_score_issues, static function($issue) {
                    return class_exists('TPMA_Tutor_Bridge')
                        && TPMA_Tutor_Bridge::is_safe_quiz_score_resync_reason((string) ($issue['reason_code'] ?? ''));
                }));
                if (!empty($safe_score_issues)) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px;">';
                    echo '<input type="hidden" name="action" value="tpma_cr_resync_safe_quiz_scores">';
                    wp_nonce_field('tpma_cr_resync_safe_quiz_scores');
                    echo '<button type="submit" class="button button-primary" onclick="return confirm(\'只會重同步已驗證為同一報名、同一專用學員帳號的 ' . esc_attr((string) count($safe_score_issues)) . ' 筆成績；不會處理歸屬不明的作答。是否繼續？\');">一鍵重新同步 ' . esc_html((string) count($safe_score_issues)) . ' 筆可安全處理成績</button>';
                    echo '</form>';
                }
                echo '<table class="widefat striped" style="max-width:1500px"><thead><tr><th>作答 ID</th><th>實際 Tutor 作答帳號</th><th>目前對應報名</th><th>目前對應學員</th><th>課程</th><th>完成作答</th><th>判定原因</th><th>修正</th></tr></thead><tbody>';
                $targets_by_course = array();
                foreach ($quiz_score_issues as $issue) {
                    $attempt = trim((string) ($issue['attempt_ended_at'] ?? ''));
                    $attempt_status = trim((string) ($issue['attempt_status'] ?? ''));
                    $attempt_id = (int) ($issue['attempt_id'] ?? 0);
                    $reason_code = (string) ($issue['reason_code'] ?? '');
                    if ($attempt_status !== '') {
                        $attempt .= ($attempt !== '' ? '／' : '') . $attempt_status;
                    }
                    $attempt_user_id = (int) ($issue['attempt_user_id'] ?? 0);
                    $attempt_user = trim((string) ($issue['attempt_user_display'] ?? ''));
                    if ($attempt_user === '') {
                        $attempt_user = $attempt_user_id > 0 ? '帳號 #' . $attempt_user_id : '未記錄帳號';
                    }
                    $registration_label = trim((string) ($issue['reg_no'] ?? ''));
                    if ($registration_label === '') {
                        $registration_label = (int) ($issue['registration_id'] ?? 0) > 0
                            ? '報名 #' . (int) $issue['registration_id']
                            : '未指定';
                    }
                    echo '<tr>';
                    echo '<td>#' . esc_html((string) $attempt_id) . '</td>';
                    echo '<td>' . esc_html($attempt_user) . ($attempt_user_id > 0 ? '<br><span class="description">WP User #' . esc_html((string) $attempt_user_id) . '</span>' : '') . '</td>';
                    echo '<td>' . esc_html($registration_label) . '</td>';
                    echo '<td>' . esc_html((string) ($issue['student_name'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($issue['course_name'] ?? '')) . '</td>';
                    echo '<td>' . esc_html($attempt !== '' ? $attempt : '已找到完成作答') . '</td>';
                    echo '<td><strong>' . esc_html((string) ($issue['reason'] ?? '未能判定原因。')) . '</strong></td>';
                    echo '<td>';
                    if (class_exists('TPMA_Tutor_Bridge') && TPMA_Tutor_Bridge::is_safe_quiz_score_resync_reason($reason_code)) {
                        echo '<span style="color:#008a20;font-weight:600;">可由上方一鍵安全重同步</span>';
                    } elseif ($attempt_id > 0 && class_exists('TPMA_Tutor_Bridge') && TPMA_Tutor_Bridge::is_manual_quiz_score_rebind_reason($reason_code)) {
                        $tutor_course_id = (int) ($issue['tutor_course_id'] ?? 0);
                        if (!array_key_exists($tutor_course_id, $targets_by_course)) {
                            $targets_by_course[$tutor_course_id] = TPMA_Tutor_Bridge::get_quiz_score_rebind_targets($tutor_course_id);
                        }
                        $targets = (array) $targets_by_course[$tutor_course_id];
                        if (empty($targets)) {
                            echo '<span style="color:#b32d2e;">找不到可指定的專用學員報名。</span>';
                        } else {
                            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                            echo '<input type="hidden" name="action" value="tpma_cr_manual_rebind_quiz_score">';
                            echo '<input type="hidden" name="attempt_id" value="' . esc_attr((string) $attempt_id) . '">';
                            wp_nonce_field('tpma_cr_manual_rebind_quiz_score_' . $attempt_id);
                            echo '<label class="screen-reader-text" for="tpma-quiz-target-' . esc_attr((string) $attempt_id) . '">目標報名</label>';
                            echo '<select id="tpma-quiz-target-' . esc_attr((string) $attempt_id) . '" name="target_registration_id" required style="max-width:250px;margin:0 0 6px;">';
                            echo '<option value="">選擇正確學員／報名</option>';
                            foreach ($targets as $target) {
                                $label = trim((string) ($target['reg_no'] ?? '') . '｜' . (string) ($target['student_name'] ?? ''));
                                echo '<option value="' . esc_attr((string) ($target['registration_id'] ?? 0)) . '">' . esc_html($label) . '</option>';
                            }
                            echo '</select><br>';
                            echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'確認此作答屬於所選學員？系統會把作答對應與成績轉移到所選報名，原對應報名的成績將重新計算。\');">指定學員並覆寫成績</button>';
                            echo '</form>';
                        }
                    } else {
                        echo '<span class="description">需先修正測驗設定或完成人工批改後再掃描。</span>';
                    }
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
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
