<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TPMA Tutor Bridge
 *
 * Bridges TPMA Course Registration with Tutor LMS:
 *  - Course sync: TPMA course-admin → Tutor course post (1:1)
 *  - Enrollment: auto-enroll virtual/real WP users after order processed
 *  - Magic Link: expiring token-based auto-login (expires at class_date + N days)
 *  - Quiz sync: Tutor quiz attempt result → TPMA test_score
 *  - Course completion gate: require quiz pass before awarding certificate
 *  - Certificate callback: update TPMA certificate_id; email is admin-triggered
 *  - Pre-class reminder: cron-based email N days before class
 *
 * Graceful degradation: all methods check is_active() and silently no-op
 * when Tutor LMS is not installed or integration is disabled.
 */
class TPMA_Tutor_Bridge {

    public const MEET_SETTINGS_SCOPE = 'https://www.googleapis.com/auth/meetings.space.settings';
    private const MEET_SERVICE_USER_META = '_tpma_google_meet_service_user_id';

    /** @var bool|null Cached detection result. */
    private static $tutor_active = null;

    /** @var array<int,array> Per-request magic URL cache keyed by reg_id. */
    private static $token_url_cache = [];

    /** @var array<int,array> Per-request cached topic resource lookups keyed by tutor course ID. */
    private static $course_topic_resource_cache = [];

    /** @var bool Prevent save_post recursion during bridge writes. */
    private static $syncing_course = false;

    // ──────────────────────────────────────────────────────────
    // Bootstrap
    // ──────────────────────────────────────────────────────────

    public static function init(): void {
        if (!self::is_active()) {
            return;
        }

        // Magic link auto-login — must fire before template rendering
        add_action('init', [__CLASS__, 'handle_magic_token'], 99);

        // Enrollment: priority 11 fires after regs are written at priority 10
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_order_processed'], 11, 1);

        // Cancel / refund → expire enrollments + tokens
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_order_cancelled'], 10, 1);
        add_action('woocommerce_order_status_refunded',  [__CLASS__, 'on_order_cancelled'], 10, 1);

        // Quiz result → update test_score
        add_action('tutor_quiz_attempt_ended', [__CLASS__, 'on_quiz_attempt_ended'], 20, 1);
        add_action('tutor_quiz/attempt_ended', [__CLASS__, 'on_quiz_attempt_ended'], 20, 1);
        add_action('tutor_quiz/start/before', [__CLASS__, 'guard_quiz_start'], 5, 2);
        add_action('save_post_tutor_quiz', [__CLASS__, 'enforce_qualifying_quiz_settings'], 40, 3);
        add_action('template_redirect', [__CLASS__, 'prepare_qualifying_quiz_request'], 4);
        add_filter('tutor_single_quiz/top', [__CLASS__, 'filter_qualifying_quiz_top'], 99, 1);
        add_filter('tutor_single_quiz/content', [__CLASS__, 'filter_qualifying_quiz_content'], 99, 1);
        add_filter('tutor_course_completed_percent', [__CLASS__, 'force_completed_progress_after_pass'], 99, 4);
        add_filter('tutor_course/single/complete_form', [__CLASS__, 'hide_tpma_complete_form_after_pass'], 99, 1);
        add_filter('tutor_course/single/complete_form', [__CLASS__, 'render_tpma_quiz_completion_notice'], 100, 1);
        add_filter('gettext', [__CLASS__, 'filter_tpma_quiz_completion_notice'], 20, 3);
        add_filter('tutor_course/single/start/button', [__CLASS__, 'filter_course_start_button'], 99, 2);

        // Course completion gate: require quiz pass
        add_filter('tutor_user_can_complete_course', [__CLASS__, 'filter_course_completion'], 10, 3);

        // Certificate issuance remains manual until the TPMA certificate workflow is finalized.

        // Tutor owns course description while integration is enabled.
        add_action('save_post_' . (function_exists('tutor') ? tutor()->course_post_type : 'courses'), [__CLASS__, 'on_tutor_course_saved'], 30, 3);

        // Session-specific Tutor content visibility and direct access.
        add_filter('tutor_course_topic_contents_args', [__CLASS__, 'filter_topic_contents_for_session'], 20, 1);
        add_filter('tutor_get_course_topics', [__CLASS__, 'filter_course_topics_for_access'], 20, 1);
        add_filter('tutor_course_contents_where_clause', [__CLASS__, 'exclude_session_recordings_from_progress'], 20, 2);
        add_action('pre_get_posts', [__CLASS__, 'filter_session_topics_query'], 20);
        add_action('template_redirect', [__CLASS__, 'protect_session_content'], 5);
        add_filter('tutor_lesson/single/video', [__CLASS__, 'protect_session_lesson_output'], 99, 1);
        add_filter('tutor_lesson/single/content', [__CLASS__, 'protect_session_lesson_output'], 99, 1);
        add_filter('tutor_lesson/single/attachments', [__CLASS__, 'protect_session_lesson_output'], 99, 1);
        add_filter('tutor/single/next_previous_pagination', [__CLASS__, 'filter_next_previous_pagination'], 99, 1);

        // Inject magic link variables into mail context
        add_filter('tpma_cr_mail_context', [__CLASS__, 'inject_mail_context'], 10, 3);

        // Pre-class reminders via existing daily cron hook
        add_action('tpma_cr_daily_cron', [__CLASS__, 'send_pre_class_reminders']);
        add_action('tpma_daily_cleanup', [__CLASS__, 'send_pre_class_reminders']);
        add_action('tpma_daily_cleanup', [__CLASS__, 'cleanup_expired_session_resources']);
        add_action('init', [__CLASS__, 'run_one_time_migrations'], 100);
    }

    // ──────────────────────────────────────────────────────────
    // Detection
    // ──────────────────────────────────────────────────────────

    public static function is_active(): bool {
        if (self::$tutor_active === null) {
            self::$tutor_active = class_exists('\TUTOR\Tutor')
                && (bool)(int)get_option('tpma_cr_tutor_enabled', 1);
        }
        return self::$tutor_active;
    }

    public static function refresh_active_state(): void {
        self::$tutor_active = null;
    }

    /**
     * Tutor Pro keeps Google credentials per WP login. Resolve the account
     * which owns a Meet before constructing its GoogleEvent client.
     */
    private static function get_google_meet_user_id(int $meet_post_id = 0): int {
        if ($meet_post_id > 0) {
            $stored_user_id = absint(get_post_meta($meet_post_id, self::MEET_SERVICE_USER_META, true));
            if ($stored_user_id > 0) {
                return $stored_user_id;
            }

            // Legacy events predate the ownership meta. Their post author is
            // the only reliable record of the Tutor Pro token that created it.
            $legacy_user_id = (int) get_post_field('post_author', $meet_post_id);
            if ($legacy_user_id > 0) {
                return $legacy_user_id;
            }
        }

        return class_exists('TPMA_CR_Settings')
            ? TPMA_CR_Settings::get_tutor_meet_shared_user()
            : 0;
    }

    private static function get_google_meet_authorization_message(int $user_id, int $meet_post_id = 0): string {
        if ($user_id <= 0) {
            return '尚未完成 Google Meet 共用授權。請任一網站管理員至「設定 → TPMA Course Registration IDs」執行「授權／更新共用 Meet」。';
        }

        $user = get_user_by('id', $user_id);
        $name = $user ? $user->display_name : ('ID ' . $user_id);
        if ($meet_post_id > 0 && !get_post_meta($meet_post_id, self::MEET_SERVICE_USER_META, true)) {
            return '此既有 Meet 的原建立帳號「' . $name . '」尚未授權或授權已失效。請以該帳號登入並重新授權。';
        }
        return 'Google Meet 共用授權已失效。請任一網站管理員至「設定 → TPMA Course Registration IDs」重新執行「授權／更新共用 Meet」。';
    }

    /**
     * Return the non-public directory used to preserve Tutor Pro's OAuth files
     * when a host-level cleanup removes uploads/tutor-json.
     */
    private static function get_google_meet_auth_backup_directory(): string {
        if (defined('TPMA_MEET_AUTH_BACKUP_DIR')) {
            $configured = trim((string) constant('TPMA_MEET_AUTH_BACKUP_DIR'));
            if ($configured !== '') {
                return rtrim($configured, '/\\');
            }
        }

        $document_root = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
        if ($document_root !== '') {
            return dirname(rtrim($document_root, '/\\')) . DIRECTORY_SEPARATOR . '.tpma-meet-auth';
        }

        return dirname(rtrim((string) ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . '.tpma-meet-auth';
    }

    /**
     * Resolve the live Tutor Pro files and their protected backup counterparts.
     * OAuth file contents and filesystem locations must never be stored in WP options.
     */
    private static function get_google_meet_auth_file_paths(int $user_id): array {
        $user = get_user_by('id', $user_id);
        $upload = wp_upload_dir();
        $basedir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        $login = $user ? (string) ($user->user_login ?? '') : '';
        $backup_directory = self::get_google_meet_auth_backup_directory();
        if ($basedir === '' || $login === '' || $backup_directory === '') {
            return array();
        }

        $prefix = md5($login);
        $live_directory = rtrim($basedir, '/\\') . DIRECTORY_SEPARATOR . 'tutor-json';
        return array(
            'live_directory'   => $live_directory,
            'backup_directory' => $backup_directory,
            'credential'       => array(
                'live'   => $live_directory . DIRECTORY_SEPARATOR . $prefix . '-credential.json',
                'backup' => $backup_directory . DIRECTORY_SEPARATOR . $prefix . '-credential.json',
            ),
            'token'            => array(
                'live'   => $live_directory . DIRECTORY_SEPARATOR . $prefix . '-token.json',
                'backup' => $backup_directory . DIRECTORY_SEPARATOR . $prefix . '-token.json',
            ),
        );
    }

    /**
     * Atomically write a sensitive OAuth file without exposing its contents or
     * path in diagnostics.
     */
    private static function write_google_meet_auth_file(string $destination, string $contents): bool {
        $temporary = $destination . '.tmp-' . uniqid('', true);
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0600);

        if (!@rename($temporary, $destination)) {
            // Windows cannot always replace an existing target atomically. This
            // fallback keeps local development and cPanel/Linux behavior aligned.
            if (!@unlink($destination) || !@rename($temporary, $destination)) {
                @unlink($temporary);
                return false;
            }
        }
        @chmod($destination, 0600);
        return true;
    }

    /**
     * Copy a sensitive OAuth file without exposing its contents or path in logs.
     */
    private static function copy_google_meet_auth_file(string $source, string $destination): bool {
        $contents = @file_get_contents($source);
        return $contents !== false && self::write_google_meet_auth_file($destination, $contents);
    }

    /**
     * Read the durable refresh token before Tutor Pro attempts a token refresh.
     */
    private static function get_google_meet_refresh_token(int $user_id): string {
        $paths = self::get_google_meet_auth_file_paths($user_id);
        if (empty($paths) || !is_readable($paths['token']['live'])) {
            return '';
        }
        $token = json_decode((string) file_get_contents($paths['token']['live']), true);
        return is_array($token) && !empty($token['refresh_token']) && is_string($token['refresh_token'])
            ? $token['refresh_token']
            : '';
    }

    /**
     * Tutor Pro overwrites its token file with a refresh response that normally
     * has no refresh_token. Put the still-valid durable token back before the
     * replacement GoogleEvent client is constructed.
     */
    public static function preserve_google_meet_refresh_token(int $user_id, string $refresh_token): bool {
        if ($refresh_token === '') {
            return false;
        }
        $paths = self::get_google_meet_auth_file_paths($user_id);
        if (empty($paths) || !is_readable($paths['token']['live'])) {
            return false;
        }
        $token = json_decode((string) file_get_contents($paths['token']['live']), true);
        if (!is_array($token) || !empty($token['refresh_token'])) {
            return false;
        }
        $token['refresh_token'] = $refresh_token;
        $encoded = json_encode($token);
        return is_string($encoded) && self::write_google_meet_auth_file($paths['token']['live'], $encoded);
    }

    /**
     * Update the protected copy after OAuth authorization or a token refresh.
     * This method deliberately copies both files as a matching credential/token pair.
     *
     * @return true|WP_Error
     */
    public static function sync_google_meet_auth_backup(int $user_id = 0) {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        $paths = self::get_google_meet_auth_file_paths($user_id);
        if (empty($paths)) {
            return new WP_Error('meet_backup_unavailable', '無法準備 Google Meet 授權檔的受保護備份。');
        }
        foreach (array('credential', 'token') as $type) {
            if (!is_readable($paths[$type]['live'])) {
                return new WP_Error('meet_backup_source_missing', 'Google Meet 授權檔不完整，無法更新受保護備份。');
            }
        }
        if (!is_dir($paths['backup_directory']) && !wp_mkdir_p($paths['backup_directory'])) {
            return new WP_Error('meet_backup_write_failed', '無法建立 Google Meet 授權檔的受保護備份。');
        }
        @chmod($paths['backup_directory'], 0700);

        foreach (array('credential', 'token') as $type) {
            if (!self::copy_google_meet_auth_file($paths[$type]['live'], $paths[$type]['backup'])) {
                return new WP_Error('meet_backup_write_failed', '無法更新 Google Meet 授權檔的受保護備份。');
            }
        }
        return true;
    }

    /**
     * Restore only missing Tutor Pro files. Existing live files always win so
     * a current authorization can never be replaced by an older backup.
     *
     * @return array<int,string>|WP_Error Restored file labels, or an error.
     */
    private static function restore_google_meet_auth_files(int $user_id) {
        $paths = self::get_google_meet_auth_file_paths($user_id);
        if (empty($paths)) {
            return new WP_Error('meet_auth_restore_unavailable', '無法檢查 Google Meet 授權檔。');
        }
        if (!is_dir($paths['live_directory']) && !wp_mkdir_p($paths['live_directory'])) {
            return new WP_Error('meet_auth_restore_failed', '無法建立 Tutor Google Meet 授權檔目錄。');
        }

        $restored = array();
        foreach (array('credential' => 'credential', 'token' => 'token') as $type => $label) {
            if (is_readable($paths[$type]['live'])) {
                continue;
            }
            if (!is_readable($paths[$type]['backup'])) {
                continue;
            }
            if (!self::copy_google_meet_auth_file($paths[$type]['backup'], $paths[$type]['live'])) {
                return new WP_Error('meet_auth_restore_failed', '無法從受保護備份還原 Google Meet 授權檔。');
            }
            $restored[] = $label;
        }
        return $restored;
    }

    /**
     * Tutor Pro stores each WP user's Google files in uploads/tutor-json.
     * Check their presence before GoogleEvent hides the distinction behind a
     * generic authorization failure.
     */
    private static function get_google_meet_auth_file_diagnostic(int $user_id): array {
        $paths = self::get_google_meet_auth_file_paths($user_id);
        if (empty($paths)) {
            return array();
        }

        if (!file_exists($paths['credential']['live'])) {
            return array(
                'code'   => 'meet_credential_missing',
                'reason' => 'Google Meet credential 檔遺失，且受保護備份中也找不到可還原的檔案',
            );
        }
        if (!file_exists($paths['token']['live'])) {
            return array(
                'code'   => 'meet_token_missing',
                'reason' => 'Google Meet token 檔遺失，且受保護備份中也找不到可還原的檔案',
            );
        }
        return array();
    }

    private static function record_google_meet_diagnostic(string $operation, bool $valid, string $code, string $reason): void {
        if (class_exists('TPMA_CR_Settings')) {
            TPMA_CR_Settings::record_tutor_meet_diagnostic($operation, $valid, $code, $reason);
        }
    }

    /**
     * Run a live authorization check for the shared Meet account.
     * The client factory records the safe result for the settings diagnostics.
     */
    public static function get_google_meet_authorization_status(): array {
        $client = self::create_google_meet_client(0, '設定頁檢查');
        if (is_wp_error($client)) {
            $record = class_exists('TPMA_CR_Settings')
                ? TPMA_CR_Settings::get_tutor_meet_status()
                : array();
            return array(
                'valid'  => false,
                'code'   => (string) ($record['code'] ?? $client->get_error_code()),
                'reason' => (string) ($record['reason'] ?? 'Google Meet 共用授權目前無法使用。'),
            );
        }
        return array(
            'valid'  => true,
            'code'   => 'authorized',
            'reason' => 'Google Meet 共用授權可使用。',
        );
    }

    private static function create_google_meet_client(int $meet_post_id = 0, string $operation = 'Google Meet 操作') {
        $client_class = '\\TutorPro\\GoogleMeet\\GoogleEvent\\GoogleEvent';
        if (!class_exists($client_class)) {
            self::record_google_meet_diagnostic($operation, false, 'meet_unavailable', 'Tutor Pro Google Meet 模組未啟用');
            return new WP_Error('meet_unavailable', 'Tutor Pro Google Meet 模組未啟用', array('status' => 503));
        }

        $meet_user_id = self::get_google_meet_user_id($meet_post_id);
        if ($meet_user_id <= 0) {
            self::record_google_meet_diagnostic($operation, false, 'meet_shared_authorization_required', '尚未完成 Google Meet 共用授權');
            return new WP_Error('meet_shared_authorization_required', self::get_google_meet_authorization_message(0, $meet_post_id), array('status' => 503));
        }
        if ($meet_user_id > 0 && (!get_user_by('id', $meet_user_id) || !user_can($meet_user_id, 'manage_options'))) {
            self::record_google_meet_diagnostic($operation, false, 'meet_shared_authorization_invalid', '共用授權帳號不存在或已失去管理員權限');
            return new WP_Error('meet_shared_authorization_invalid', 'Google Meet 共用授權帳號不存在或已失去網站管理員權限。請任一網站管理員重新執行共用授權。', array('status' => 503));
        }

        $restore_result = self::restore_google_meet_auth_files($meet_user_id);
        if (is_wp_error($restore_result)) {
            self::record_google_meet_diagnostic($operation, false, 'meet_auth_restore_failed', '無法從受保護備份還原 Google Meet 授權檔');
            return new WP_Error('meet_auth_restore_failed', self::get_google_meet_authorization_message($meet_user_id, $meet_post_id), array('status' => 503));
        }
        if (!empty($restore_result)) {
            self::record_google_meet_diagnostic($operation, true, 'meet_auth_restored', '已從受保護備份自動還原 Google Meet ' . implode(' 與 ', $restore_result) . ' 檔');
        }

        $file_diagnostic = self::get_google_meet_auth_file_diagnostic($meet_user_id);
        if (!empty($file_diagnostic)) {
            $diagnostic_code = (string) ($file_diagnostic['code'] ?? 'meet_auth_file_missing');
            $diagnostic_reason = (string) ($file_diagnostic['reason'] ?? 'Google Meet 授權檔遺失');
            self::record_google_meet_diagnostic($operation, false, $diagnostic_code, $diagnostic_reason);
            return new WP_Error($diagnostic_code, self::get_google_meet_authorization_message($meet_user_id, $meet_post_id), array('status' => 503));
        }

        $refresh_token_before_client = self::get_google_meet_refresh_token($meet_user_id);
        $current_user_id = get_current_user_id();
        try {
            if ($meet_user_id > 0 && $meet_user_id !== $current_user_id) {
                wp_set_current_user($meet_user_id);
            }
            $client = new $client_class();

            if (self::preserve_google_meet_refresh_token($meet_user_id, $refresh_token_before_client)) {
                self::record_google_meet_diagnostic($operation, true, 'meet_refresh_token_preserved', 'Tutor 刷新 Google token 時遺漏 refresh token，已自動保留');
            }

            // Tutor Pro writes a refreshed token, but its GoogleEvent::save_token()
            // returns false afterwards. The first client therefore has no Calendar
            // service even though the replacement token is already on disk. Build
            // it once more so the new token is loaded; a genuinely invalid token
            // still fails the validation below.
            if (!method_exists($client, 'is_app_permitted') || !$client->is_app_permitted() || empty($client->service)) {
                $client = new $client_class();
            }
        } catch (Throwable $e) {
            self::record_google_meet_diagnostic($operation, false, 'meet_client_unavailable', '無法建立 Tutor Google Meet 授權用戶端');
            return new WP_Error('meet_client_unavailable', '無法建立 Tutor Google Meet 授權用戶端：' . $e->getMessage(), array('status' => 503));
        } finally {
            if ($meet_user_id > 0 && $meet_user_id !== $current_user_id) {
                wp_set_current_user($current_user_id);
            }
        }

        if (!method_exists($client, 'is_app_permitted') || !$client->is_app_permitted() || empty($client->service)) {
            $error_code = 'meet_unauthorized';
            $reason = 'Google token 無法使用或無法刷新';
            if (self::get_google_meet_refresh_token($meet_user_id) === '') {
                $error_code = 'meet_refresh_token_missing';
                $reason = 'Google token 缺少 refresh token，必須重新執行共用 Meet 授權';
            }
            self::record_google_meet_diagnostic($operation, false, $error_code, $reason);
            return new WP_Error($error_code, self::get_google_meet_authorization_message($meet_user_id, $meet_post_id), array('status' => 503));
        }

        $backup_result = self::sync_google_meet_auth_backup($meet_user_id);
        if (is_wp_error($backup_result)) {
            self::record_google_meet_diagnostic($operation, true, 'meet_backup_sync_failed', 'Google Meet 共用授權可使用，但受保護備份無法更新');
            return $client;
        }

        self::record_google_meet_diagnostic($operation, true, 'authorized', 'Google Meet 共用授權可使用。');
        return $client;
    }

    private static function persist_google_meet_user(int $meet_post_id): void {
        $meet_user_id = self::get_google_meet_user_id($meet_post_id);
        if ($meet_post_id > 0 && $meet_user_id > 0) {
            update_post_meta($meet_post_id, self::MEET_SERVICE_USER_META, $meet_user_id);
        }
    }

    // ──────────────────────────────────────────────────────────
    // Course Sync: TPMA → Tutor
    // ──────────────────────────────────────────────────────────

    /**
     * Sync one TPMA course into a Tutor LMS course post.
     * Called by REST Admin after admin_save_course() succeeds.
     *
     * @param int $tpma_course_id  ID in wp_tpma_courses.
     * @return int  Tutor course post ID, or 0 on failure / Tutor inactive.
     */
    public static function sync_course(int $tpma_course_id, bool $force_content = false): int {
        if (!self::is_active()) {
            return 0;
        }

        global $wpdb;
        $courses_table = TPMA_CR_DB::table('courses');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $tpma_course_id),
            ARRAY_A
        );
        if (!$row) {
            return 0;
        }

        $course_name      = (string)$row['course_name'];
        $intro            = (string)$row['intro'];
        $outline          = (string)$row['outline'];
        $duration_minutes = (int)$row['duration_minutes'];
        $lecturer_code    = (string)$row['lecturer_code'];
        $existing_id      = (int)($row['tutor_course_id'] ?? 0);

        $instructor_id    = self::get_instructor_wp_user_id($lecturer_code);
        $post_author      = $instructor_id > 0 ? $instructor_id : (int)get_current_user_id();

        $course_post_type = function_exists('tutor') ? tutor()->course_post_type : 'courses';

        $post_content = self::build_sectioned_content($intro, $outline);

        if ($existing_id > 0 && get_post_type($existing_id) === $course_post_type) {
            // ── Update existing ──
            $update = [
                'ID'           => $existing_id,
                'post_title'   => $course_name,
                'post_author'  => $post_author,
                'post_status'  => 'publish',
            ];
            if ($force_content) {
                $update['post_content'] = $post_content;
            }
            self::$syncing_course = true;
            $result = wp_update_post($update, true);
            self::$syncing_course = false;

            if (is_wp_error($result)) {
                error_log('TPMA Tutor Bridge: wp_update_post failed for ID ' . $existing_id . ': ' . $result->get_error_message());
                return 0;
            }
            $tutor_course_id = $existing_id;
        } else {
            // ── Create new ──
            self::$syncing_course = true;
            $tutor_course_id = wp_insert_post([
                'post_type'    => $course_post_type,
                'post_title'   => $course_name,
                'post_content' => $post_content,
                'post_author'  => $post_author,
                'post_status'  => 'publish',
                'meta_input'   => [
                    '_tutor_course_product_id' => '',   // no WC product — TPMA handles payment
                    '_tutor_is_public_course'  => 'no',
                    '_tpma_course_id'          => $tpma_course_id,
                ],
            ], true);
            self::$syncing_course = false;

            if (is_wp_error($tutor_course_id) || $tutor_course_id <= 0) {
                $msg = is_wp_error($tutor_course_id) ? $tutor_course_id->get_error_message() : 'unknown';
                error_log('TPMA Tutor Bridge: wp_insert_post failed: ' . $msg);
                return 0;
            }

        }

        // Set course duration meta (HH:MM:SS format that Tutor expects)
        $hours = (int)floor($duration_minutes / 60);
        $mins  = $duration_minutes % 60;
        update_post_meta($tutor_course_id, '_course_duration', sprintf('%02d:%02d:00', $hours, $mins));
        update_post_meta($tutor_course_id, '_tpma_course_id', $tpma_course_id);

        // Persist link back to TPMA
        $wpdb->update(
            $courses_table,
            ['tutor_course_id' => $tutor_course_id],
            ['id'              => $tpma_course_id],
            ['%d'],
            ['%d']
        );

        return $tutor_course_id;
    }

    private static function build_sectioned_content(string $intro, string $outline): string {
        return '<h2>課程簡介</h2>' . wp_kses_post($intro)
            . "\n\n<h2>課程大綱</h2>" . wp_kses_post($outline);
    }

    private static function format_session_title(string $datetime, string $course_name): string {
        try {
            $dt = new DateTime($datetime, wp_timezone());
            return $dt->format('Y/m/d H:i') . '｜' . $course_name;
        } catch (Throwable $e) {
            return trim($datetime . '｜' . $course_name, '｜ ');
        }
    }

    private static function format_calendar_summary(string $course_name): string {
        $course_name = trim($course_name);
        return $course_name !== '' ? $course_name : 'TPMA 課程';
    }

    private static function sync_session_child_titles(int $topic_id, int $session_id, string $title): void {
        if ($topic_id <= 0 || $session_id <= 0 || $title === '') return;
        $lesson_type = function_exists('tutor') ? tutor()->lesson_post_type : 'tutor_lesson';
        $children = get_posts(array(
            'post_type'        => array($lesson_type, 'tutor-google-meet'),
            'post_parent'      => $topic_id,
            'post_status'      => array('publish','future','draft','private'),
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ((array) $children as $child_id) {
            update_post_meta((int) $child_id, '_tpma_session_id', $session_id);
            wp_update_post(array('ID' => (int) $child_id, 'post_title' => $title));
        }
    }

    /** Parse exactly one intro heading followed by exactly one outline heading. */
    private static function parse_sectioned_content(string $content): array {
        preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        $sections = array('課程簡介' => array(), '課程大綱' => array());
        foreach (($matches[0] ?? array()) as $index => $full) {
            $label = trim(wp_strip_all_tags(html_entity_decode((string) ($matches[2][$index][0] ?? ''), ENT_QUOTES, 'UTF-8')));
            if (isset($sections[$label])) {
                $sections[$label][] = array('start' => (int) $full[1], 'end' => (int) $full[1] + strlen((string) $full[0]));
            }
        }
        if (count($sections['課程簡介']) !== 1 || count($sections['課程大綱']) !== 1) {
            return array();
        }
        $intro_heading = $sections['課程簡介'][0];
        $outline_heading = $sections['課程大綱'][0];
        if ($intro_heading['start'] >= $outline_heading['start']) {
            return array();
        }
        return array(
            'intro'   => trim(substr($content, $intro_heading['end'], $outline_heading['start'] - $intro_heading['end'])),
            'outline' => trim(substr($content, $outline_heading['end'])),
        );
    }

    public static function on_tutor_course_saved(int $post_id, $post, bool $update): void {
        if (self::$syncing_course || !self::is_active() || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $tpma_course_id = (int) get_post_meta($post_id, '_tpma_course_id', true);
        if ($tpma_course_id <= 0 || !is_object($post)) {
            return;
        }
        $parts = self::parse_sectioned_content((string) $post->post_content);
        if (!$parts) {
            update_post_meta($post_id, '_tpma_content_sync_error', '內容必須各包含一個「課程簡介」與「課程大綱」標題，且簡介需在大綱之前。');
            return;
        }
        global $wpdb;
        $updated = $wpdb->update(
            TPMA_CR_DB::table('courses'),
            array('intro' => wp_kses_post($parts['intro']), 'outline' => wp_kses_post($parts['outline']), 'updated_at' => current_time('mysql')),
            array('id' => $tpma_course_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        if ($updated === false) {
            update_post_meta($post_id, '_tpma_content_sync_error', 'Tutor 內容解析成功，但回寫 TPMA 資料庫失敗。');
        } else {
            delete_post_meta($post_id, '_tpma_content_sync_error');
        }
    }

    /** Push TPMA content once when the integration transitions from disabled to enabled. */
    public static function push_all_course_content_from_tpma(): int {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM " . TPMA_CR_DB::table('courses') . " WHERE is_active = 1");
        $count = 0;
        foreach ((array) $ids as $id) {
            if (self::sync_course((int) $id, true) > 0) $count++;
        }
        return $count;
    }

    public static function run_one_time_migrations(): void {
        self::normalize_linked_course_content_once();
        self::cleanup_legacy_default_content_once();
    }

    /** Normalize the legacy one-heading format without overwriting edited content. */
    private static function normalize_linked_course_content_once(): void {
        if ((bool) get_option('tpma_cr_tutor_content_normalized_v1', false)) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, course_name, intro, outline, tutor_course_id FROM " . TPMA_CR_DB::table('courses') . " WHERE tutor_course_id IS NOT NULL",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $post_id = (int) ($row['tutor_course_id'] ?? 0);
            $post = $post_id ? get_post($post_id) : null;
            if (!$post || self::parse_sectioned_content((string) $post->post_content)) continue;
            $intro = (string) ($row['intro'] ?? '');
            $outline = (string) ($row['outline'] ?? '');
            $legacy = trim(($intro !== '' ? wp_kses_post($intro) . "\n\n" : '') . '<h3>課程大綱</h3>' . wp_kses_post($outline));
            $missing_intro_legacy = trim('<h3>課程大綱</h3>' . wp_kses_post($outline));
            $current = trim((string) $post->post_content);
            if ($current === '' || $current === $legacy || $current === $missing_intro_legacy) {
                self::$syncing_course = true;
                wp_update_post(array('ID' => $post_id, 'post_content' => self::build_sectioned_content($intro, $outline)));
                self::$syncing_course = false;
                delete_post_meta($post_id, '_tpma_content_sync_error');
            } else {
                update_post_meta($post_id, '_tpma_content_sync_error', '既有 Tutor 內容無法安全拆分，請加入「課程簡介」與「課程大綱」標題後重新儲存。');
            }
        }
        update_option('tpma_cr_tutor_content_normalized_v1', 1, false);
    }

    /** Trash only untouched topic/lesson pairs created by the legacy bridge. */
    private static function cleanup_legacy_default_content_once(): void {
        if ((bool) get_option('tpma_cr_tutor_legacy_content_cleaned_v1', false)) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT course_name, intro, outline, tutor_course_id FROM " . TPMA_CR_DB::table('courses') . " WHERE tutor_course_id IS NOT NULL",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $course_id = (int) ($row['tutor_course_id'] ?? 0);
            $topics = get_posts(array('post_type' => 'topics', 'post_parent' => $course_id, 'post_status' => 'publish', 'numberposts' => -1));
            foreach ((array) $topics as $topic) {
                if ((string) $topic->post_title !== (string) $row['course_name'] || trim((string) $topic->post_content) !== trim(wp_kses_post((string) $row['outline']))) continue;
                $lessons = get_posts(array('post_type' => 'tutor_lesson', 'post_parent' => $topic->ID, 'post_status' => 'publish', 'numberposts' => -1));
                foreach ((array) $lessons as $lesson) {
                    $expected = trim(wp_kses_post((string) ($row['intro'] ?: $row['course_name'])));
                    if ((string) $lesson->post_title === '課程內容：' . (string) $row['course_name'] && trim((string) $lesson->post_content) === $expected) {
                        wp_trash_post($lesson->ID);
                    }
                }
                $remaining = get_posts(array('post_type' => 'any', 'post_parent' => $topic->ID, 'post_status' => array('publish','future','draft'), 'numberposts' => 1, 'fields' => 'ids'));
                if (!$remaining) wp_trash_post($topic->ID);
            }
        }
        update_option('tpma_cr_tutor_legacy_content_cleaned_v1', 1, false);
    }

    /**
     * Return the Tutor course post ID linked to a TPMA course ID, or 0 if none.
     */
    public static function get_tutor_course_id(int $tpma_course_id): int {
        if (!$tpma_course_id) {
            return 0;
        }
        global $wpdb;
        return (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT tutor_course_id FROM " . TPMA_CR_DB::table('courses') . " WHERE id = %d",
                $tpma_course_id
            )
        );
    }

    /**
     * Resolve WP instructor user ID for a lecturer_code.
     * Falls back to the configured default instructor.
     */
    private static function get_instructor_wp_user_id(string $lecturer_code): int {
        if ($lecturer_code === '') {
            return (int)get_option('tpma_cr_tutor_default_instructor', 0);
        }

        global $wpdb;
        $tbl = TPMA_CR_DB::table('lecturers');

        $schema = TPMA_CR_DB::get_lecturer_schema();
        $code_col = trim((string) ($schema['code'] ?? ''));

        if ($code_col === '') {
            return (int)get_option('tpma_cr_tutor_default_instructor', 0);
        }

        $uid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wp_user_id FROM {$tbl}
                 WHERE {$code_col} = %s
                 AND wp_user_id IS NOT NULL AND wp_user_id > 0
                 LIMIT 1",
                $lecturer_code
            )
        );

        return ($uid && (int)$uid > 0)
            ? (int)$uid
            : (int)get_option('tpma_cr_tutor_default_instructor', 0);
    }

    /**
     * Get the Google Meet link stored in the Tutor Google Meet addon's post meta,
     * for a meet post nested under any topic of the given Tutor course.
     */
    public static function get_course_meet_link(int $tutor_course_id): string {
        if (!$tutor_course_id) {
            return '';
        }

        global $wpdb;
        $link = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.meta_value
             FROM   {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} gm ON gm.ID = pm.post_id
             INNER JOIN {$wpdb->posts} tp ON tp.ID = gm.post_parent
             WHERE  gm.post_type   = 'tutor-google-meet'
             AND    gm.post_status IN ('publish','future')
             AND    pm.meta_key    = 'tutor-google-meet-link'
             AND    tp.post_type   = 'topics'
             AND    tp.post_parent = %d
             ORDER  BY gm.post_date DESC
             LIMIT  1",
            $tutor_course_id
        ));

        return $link ? (string)$link : '';
    }

    public static function prepare_session_topic(int $session_id): int {
        if (!self::is_active() || $session_id <= 0) return 0;
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, c.course_name, c.tutor_course_id
             FROM " . TPMA_CR_DB::table('sessions') . " s
             INNER JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id
             WHERE s.id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session || empty($session['tutor_course_id'])) return 0;
        $topic_id = (int) ($session['tutor_topic_id'] ?? 0);
        $title = self::format_session_title((string) $session['session_datetime'], (string) $session['course_name']);
        if ($topic_id > 0 && get_post_type($topic_id) === 'topics' && get_post_status($topic_id) !== 'trash') {
            update_post_meta($topic_id, '_tpma_session_id', $session_id);
            wp_update_post(array('ID' => $topic_id, 'post_title' => $title));
            self::sync_session_child_titles($topic_id, $session_id, $title);
            self::reorder_course_topics((int) $session['tutor_course_id']);
            return $topic_id;
        }
        $topic_id = wp_insert_post(array(
            'post_type' => 'topics', 'post_parent' => (int) $session['tutor_course_id'],
            'post_title' => $title, 'post_status' => 'publish',
            'post_author' => (int) get_post_field('post_author', (int) $session['tutor_course_id']),
            'menu_order' => (int) get_post_field('menu_order', (int) $session['tutor_course_id']),
            'meta_input' => array('_tpma_session_id' => $session_id),
        ), true);
        if (is_wp_error($topic_id)) return 0;
        $wpdb->update(TPMA_CR_DB::table('sessions'), array('tutor_topic_id' => (int) $topic_id), array('id' => $session_id), array('%d'), array('%d'));
        self::sync_session_child_titles((int) $topic_id, $session_id, $title);
        self::reorder_course_topics((int) $session['tutor_course_id']);
        return (int) $topic_id;
    }

    public static function get_session_meet_link(int $session_id): string {
        if ($session_id <= 0) return '';
        global $wpdb;
        $meet_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT tutor_meet_post_id FROM " . TPMA_CR_DB::table('sessions') . " WHERE id = %d",
            $session_id
        ));
        return $meet_id > 0 ? (string) get_post_meta($meet_id, 'tutor-google-meet-link', true) : '';
    }

    public static function get_session_status(int $course_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, session_datetime, delivery_mode, tutor_topic_id, tutor_meet_post_id,
                    recording_available_from, recording_available_until, tutor_resources_cleaned_at
             FROM " . TPMA_CR_DB::table('sessions') . " WHERE course_id = %d ORDER BY session_datetime",
            $course_id
        ), ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['meet_url'] = self::get_session_meet_link((int) $row['id']);
            $topic_course_id = empty($row['tutor_resources_cleaned_at']) && !empty($row['tutor_topic_id']) ? (int) get_post_field('post_parent', (int) $row['tutor_topic_id']) : 0;
            $row['topic_edit_url'] = $topic_course_id > 0 ? admin_url('post.php?post=' . $topic_course_id . '&action=edit') : '';
            $row['candidates'] = self::find_meet_candidates((int) $row['id']);
        }
        unset($row);
        return (array) $rows;
    }

    public static function get_course_topic_resources(int $tutor_course_id): array {
        if ($tutor_course_id <= 0) return array();
        if (isset(self::$course_topic_resource_cache[$tutor_course_id])) {
            return self::$course_topic_resource_cache[$tutor_course_id];
        }

        global $wpdb;
        $topic_ids = get_posts(array(
            'post_type'   => 'topics',
            'post_parent' => $tutor_course_id,
            'post_status' => array('publish','draft','private'),
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            // This inventory query is called from our pre_get_posts callback.
            // Do not run the same callback again or WP_Query recurses until OOM.
            'tpma_skip_session_topic_filter' => true,
        ));

        $out = array();
        foreach ((array) $topic_ids as $topic_id) {
            if ((int) get_post_meta($topic_id, '_tpma_session_id', true) > 0) {
                continue;
            }

            $type = sanitize_key((string) get_post_meta($topic_id, '_tpma_resource_type', true));
            if (!in_array($type, array('general','recording','quiz'), true)) {
                $has_quiz = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_parent=%d AND post_type=%s AND post_status<>'trash'",
                    $topic_id,
                    function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz'
                ));
                $type = $has_quiz > 0 ? 'quiz' : 'general';
            }

            $out[] = array(
                'topic_id'      => (int) $topic_id,
                'title'         => (string) get_post_field('post_title', $topic_id),
                'resource_type' => $type,
            );
        }

        self::$course_topic_resource_cache[$tutor_course_id] = $out;
        return $out;
    }

    public static function save_course_topic_resources(int $tutor_course_id, array $resources): void {
        if ($tutor_course_id <= 0) return;
        foreach ($resources as $topic_id => $type) {
            $topic_id = absint($topic_id);
            $type = sanitize_key((string) $type);
            if ($topic_id <= 0 || !in_array($type, array('general','recording','quiz'), true)) continue;
            if (get_post_type($topic_id) !== 'topics' || (int) get_post_field('post_parent', $topic_id) !== $tutor_course_id) continue;
            if ((int) get_post_meta($topic_id, '_tpma_session_id', true) > 0) continue;
            update_post_meta($topic_id, '_tpma_resource_type', $type);
            if ($type === 'quiz') self::enforce_topic_quiz_settings($topic_id);
        }
        self::clear_course_topic_resource_cache($tutor_course_id);
        self::reorder_course_topics($tutor_course_id);
    }

    private static function get_topic_resource_type(int $topic_id): string {
        $type = sanitize_key((string) get_post_meta($topic_id, '_tpma_resource_type', true));
        if (in_array($type, array('general','recording','quiz'), true)) {
            return $type;
        }

        global $wpdb;
        $has_quiz = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_parent=%d AND post_type=%s AND post_status<>'trash'",
            $topic_id,
            function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz'
        ));

        return $has_quiz > 0 ? 'quiz' : 'general';
    }

    private static function clear_course_topic_resource_cache(int $tutor_course_id): void {
        unset(self::$course_topic_resource_cache[$tutor_course_id]);
    }

    private static function reorder_course_topics(int $tutor_course_id): void {
        if ($tutor_course_id <= 0) return;

        $topic_ids = get_posts(array(
            'post_type'        => 'topics',
            'post_parent'      => $tutor_course_id,
            'post_status'      => array('publish','draft','private','future'),
            'numberposts'      => -1,
            'fields'           => 'ids',
            'orderby'          => 'menu_order',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'tpma_skip_session_topic_filter' => true,
        ));
        if (empty($topic_ids)) return;

        global $wpdb;
        $original_index = array();
        $session_ids = array();
        foreach ((array) $topic_ids as $idx => $topic_id) {
            $topic_id = (int) $topic_id;
            $original_index[$topic_id] = (int) $idx;
            $session_id = (int) get_post_meta($topic_id, '_tpma_session_id', true);
            if ($session_id > 0) $session_ids[] = $session_id;
        }

        $session_datetimes = array();
        if (!empty($session_ids)) {
            $session_ids = array_values(array_unique(array_map('absint', $session_ids)));
            $rows = $wpdb->get_results(
                "SELECT id, session_datetime FROM " . TPMA_CR_DB::table('sessions') . " WHERE id IN (" . implode(',', $session_ids) . ")",
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $session_datetimes[(int) $row['id']] = (string) $row['session_datetime'];
            }
        }

        $rank = static function (int $topic_id) use ($session_datetimes, $original_index): array {
            $session_id = (int) get_post_meta($topic_id, '_tpma_session_id', true);
            if ($session_id > 0) {
                $datetime = $session_datetimes[$session_id] ?? '';
                $timestamp = $datetime !== '' ? strtotime($datetime) : PHP_INT_MAX;
                return array(20, $timestamp ?: PHP_INT_MAX, $original_index[$topic_id] ?? PHP_INT_MAX, $topic_id);
            }

            $priority = self::get_topic_resource_type($topic_id) === 'recording' ? 30 : 10;
            return array($priority, $original_index[$topic_id] ?? PHP_INT_MAX, 0, $topic_id);
        };

        usort($topic_ids, static function ($a, $b) use ($rank): int {
            return $rank((int) $a) <=> $rank((int) $b);
        });

        foreach (array_values($topic_ids) as $menu_order => $topic_id) {
            wp_update_post(array('ID' => (int) $topic_id, 'menu_order' => $menu_order));
        }

        self::clear_course_topic_resource_cache($tutor_course_id);
    }

    private static function find_meet_candidates(int $session_id): array {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, c.tutor_course_id, c.duration_minutes
             FROM " . TPMA_CR_DB::table('sessions') . " s
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id WHERE s.id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session || empty($session['tutor_course_id'])) return array();
        $start_dt = new DateTime((string) $session['session_datetime'], wp_timezone());
        $end_dt = clone $start_dt;
        $end_dt->modify('+' . max(1, (int) $session['duration_minutes']) . ' minutes');
        $start = $start_dt->format('Y-m-d H:i:s');
        $end = $end_dt->format('Y-m-d H:i:s');
        $meet_ids = get_posts(array('post_type' => 'tutor-google-meet', 'post_status' => array('publish','future'), 'numberposts' => -1, 'fields' => 'ids'));
        $candidates = array();
        foreach ((array) $meet_ids as $meet_id) {
            $parent = (int) get_post_field('post_parent', $meet_id);
            $course_parent = get_post_type($parent) === 'topics' ? (int) get_post_field('post_parent', $parent) : $parent;
            if ($course_parent !== (int) $session['tutor_course_id']) continue;
            if ((string) get_post_meta($meet_id, 'tutor-google-meet-start-datetime', true) !== $start
                || (string) get_post_meta($meet_id, 'tutor-google-meet-end-datetime', true) !== $end) continue;
            $bound_session = (int) get_post_meta($meet_id, '_tpma_session_id', true);
            if ($bound_session > 0 && $bound_session !== $session_id) continue;
            $candidates[] = array('id' => (int) $meet_id, 'title' => get_the_title($meet_id), 'url' => (string) get_post_meta($meet_id, 'tutor-google-meet-link', true));
        }
        return $candidates;
    }

    public static function create_or_link_session_meet(int $session_id, int $meet_post_id = 0) {
        if (!self::is_active()) return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, c.course_name, c.duration_minutes, c.tutor_course_id
             FROM " . TPMA_CR_DB::table('sessions') . " s
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id WHERE s.id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session) return new WP_Error('not_found', '找不到場次', array('status' => 404));
        if (!empty($session['tutor_resources_cleaned_at'])) return new WP_Error('session_resources_cleaned', '此過期場次的 Tutor 資源已清理，Google 日曆活動仍保留。請先重新啟用或改期後再建立 Meet。', array('status' => 409));
        $topic_id = self::prepare_session_topic($session_id);
        if (!$topic_id) return new WP_Error('topic_failed', '無法建立 Tutor 場次 Topic', array('status' => 500));

        if ($meet_post_id <= 0) {
            $candidates = self::find_meet_candidates($session_id);
            if (count($candidates) > 1) return new WP_Error('multiple_meets', '找到多筆相同時間的 Meet，請選擇要連結的項目', array('status' => 409, 'candidates' => $candidates));
            if (count($candidates) === 1) $meet_post_id = (int) $candidates[0]['id'];
        }

        if ($meet_post_id > 0) {
            if (get_post_type($meet_post_id) !== 'tutor-google-meet') return new WP_Error('invalid_meet', 'Meet post 無效', array('status' => 400));
            $meet_parent = (int) get_post_field('post_parent', $meet_post_id);
            $meet_course_id = get_post_type($meet_parent) === 'topics' ? (int) get_post_field('post_parent', $meet_parent) : $meet_parent;
            $bound_session = (int) get_post_meta($meet_post_id, '_tpma_session_id', true);
            if ($meet_course_id !== (int) $session['tutor_course_id'] || ($bound_session > 0 && $bound_session !== $session_id)) {
                return new WP_Error('meet_course_mismatch', 'Meet 不屬於此課程或已連結其他場次', array('status' => 409));
            }
            $meet_url = (string) get_post_meta($meet_post_id, 'tutor-google-meet-link', true);
            $client = self::create_google_meet_client($meet_post_id, '連結既有 Meet');
            if (is_wp_error($client)) return $client;
            $open_result = self::configure_meet_space_open($meet_url, $client);
            if (is_wp_error($open_result)) {
                self::record_google_meet_diagnostic('連結既有 Meet', false, 'meet_link_access_failed', 'Google Meet 開放權限設定失敗');
                return $open_result;
            }
            self::persist_google_meet_user($meet_post_id);
            wp_update_post(array(
                'ID' => $meet_post_id,
                'post_parent' => $topic_id,
                'post_title' => self::format_session_title((string) $session['session_datetime'], (string) $session['course_name']),
            ));
        } else {
            $meet_post_id = self::create_google_meet_for_session($session, $topic_id);
            if (is_wp_error($meet_post_id)) return $meet_post_id;
        }
        update_post_meta($meet_post_id, '_tpma_session_id', $session_id);
        self::sync_session_child_titles($topic_id, $session_id, self::format_session_title((string) $session['session_datetime'], (string) $session['course_name']));
        $wpdb->update(TPMA_CR_DB::table('sessions'), array('tutor_topic_id' => $topic_id, 'tutor_meet_post_id' => $meet_post_id), array('id' => $session_id), array('%d','%d'), array('%d'));
        self::regenerate_tokens_for_session($session_id);
        return array(
            'session_id' => $session_id,
            'topic_id' => $topic_id,
            'topic_edit_url' => admin_url('post.php?post=' . (int) get_post_field('post_parent', $topic_id) . '&action=edit'),
            'meet_post_id' => $meet_post_id,
            'meet_url' => self::get_session_meet_link($session_id),
        );
    }

    private static function create_google_meet_for_session(array $session, int $topic_id) {
        if (!class_exists('\\Google_Service_Calendar_Event')) return new WP_Error('meet_unavailable', 'Tutor Pro Google Meet 模組未啟用', array('status' => 503));
        $meet_service_user = self::get_google_meet_user_id();
        $client = self::create_google_meet_client(0, '建立 Meet');
        if (is_wp_error($client)) return $client;
        $tz_name = wp_timezone_string() ?: 'Asia/Taipei';
        if ($tz_name !== 'UTC' && strpos($tz_name, '/') === false) $tz_name = 'Asia/Taipei';
        $tz = new DateTimeZone($tz_name);
        $start = new DateTime((string) $session['session_datetime'], $tz);
        $end = clone $start;
        $end->modify('+' . max(1, (int) $session['duration_minutes']) . ' minutes');
        $title = self::format_session_title((string) $session['session_datetime'], (string) $session['course_name']);
        $event = new \Google_Service_Calendar_Event(array(
            'summary' => self::format_calendar_summary((string) $session['course_name']), 'description' => '',
            'start' => array('dateTime' => $start->format('c'), 'timeZone' => $tz_name),
            'end' => array('dateTime' => $end->format('c'), 'timeZone' => $tz_name),
            'attendees' => array(),
            'conferenceData' => array('createRequest' => array('requestId' => 'tpma_' . $session['id'] . '_' . wp_generate_uuid4())),
        ));
        try {
            $created = $client->service->events->insert($client->current_calendar, $event, array('conferenceDataVersion' => 1, 'sendUpdates' => 'none'));
        } catch (Throwable $e) {
            self::record_google_meet_diagnostic('建立 Meet', false, 'calendar_create_failed', 'Google Calendar 無法建立 Meet 活動');
            return new WP_Error('meet_create_failed', $e->getMessage(), array('status' => 502));
        }
        $ready_event = self::wait_for_calendar_conference($client, $created);
        if (is_wp_error($ready_event)) {
            try {
                $client->service->events->delete($client->current_calendar, $created->id, array('sendUpdates' => 'none'));
            } catch (Throwable $cleanup_error) {
                error_log('TPMA Tutor Bridge: failed to clean pending Google event: ' . $cleanup_error->getMessage());
            }
            self::record_google_meet_diagnostic('建立 Meet', false, 'conference_create_failed', 'Google Calendar 無法完成 Meet 會議空間建立');
            return $ready_event;
        }
        $created = $ready_event;
        $open_result = self::configure_meet_space_open((string) $created->hangoutLink, $client);
        if (is_wp_error($open_result)) {
            try {
                $client->service->events->delete($client->current_calendar, $created->id, array('sendUpdates' => 'none'));
            } catch (Throwable $cleanup_error) {
                error_log('TPMA Tutor Bridge: failed to clean Google event after Meet access error: ' . $cleanup_error->getMessage());
            }
            self::record_google_meet_diagnostic('建立 Meet', false, 'meet_access_update_failed', 'Google Meet 開放權限設定失敗');
            return $open_result;
        }
        $details = array(
            'id' => $created->id,
            'kind' => $created->kind,
            'event_type' => $created->eventType,
            'html_link' => $created->htmlLink,
            'organizer' => $created->organizer,
            'recurrence' => $created->recurrence,
            'reminders' => $created->reminders,
            'status' => $created->status,
            'transparency' => $created->transparency,
            'visibility' => $created->visibility,
            'meet_link' => $created->hangoutLink,
            'timezone' => $tz_name,
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $end->format('Y-m-d H:i:s'),
            'attendees' => 'No',
        );
        $meta_input = array(
            'tutor-google-meet-start-datetime' => $start->format('Y-m-d H:i:s'),
            'tutor-google-meet-end-datetime' => $end->format('Y-m-d H:i:s'),
            'tutor-google-meet-event-details' => wp_json_encode($details),
            'tutor-google-meet-link' => (string) $created->hangoutLink,
            '_tpma_session_id' => (int) $session['id'],
        );
        if ($meet_service_user > 0) {
            $meta_input[self::MEET_SERVICE_USER_META] = $meet_service_user;
        }
        $post_id = wp_insert_post(array(
            'post_type' => 'tutor-google-meet', 'post_parent' => $topic_id, 'post_status' => 'publish',
            'post_title' => $title, 'post_content' => '', 'post_author' => get_current_user_id(),
            'meta_input' => $meta_input,
        ), true);
        if (is_wp_error($post_id)) {
            try {
                $client->service->events->delete($client->current_calendar, $created->id, array('sendUpdates' => 'none'));
            } catch (Throwable $cleanup_error) {
                error_log('TPMA Tutor Bridge: failed to clean orphan Google event ' . $created->id . ': ' . $cleanup_error->getMessage());
            }
            return $post_id;
        }
        return (int) $post_id;
    }

    private static function wait_for_calendar_conference($client, $event) {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $conference = method_exists($event, 'getConferenceData') ? $event->getConferenceData() : null;
            $request = $conference && method_exists($conference, 'getCreateRequest') ? $conference->getCreateRequest() : null;
            $status_obj = $request && method_exists($request, 'getStatus') ? $request->getStatus() : null;
            $status = $status_obj && method_exists($status_obj, 'getStatusCode')
                ? strtolower((string) $status_obj->getStatusCode())
                : '';
            $meet_url = method_exists($event, 'getHangoutLink') ? (string) $event->getHangoutLink() : (string) ($event->hangoutLink ?? '');

            if ($status === 'success' || ($status === '' && $meet_url !== '')) return $event;
            if ($status === 'failure') {
                return new WP_Error('conference_create_failed', 'Google Calendar 無法完成 Meet 會議空間建立。', array('status' => 502));
            }
            if ($attempt === 5) break;
            sleep(1);
            try {
                $event = $client->service->events->get(
                    $client->current_calendar,
                    $event->id
                );
            } catch (Throwable $e) {
                return new WP_Error('conference_status_failed', '無法確認 Meet 建立狀態：' . $e->getMessage(), array('status' => 502));
            }
        }
        return new WP_Error('conference_create_timeout', 'Google Calendar 尚未完成 Meet 會議空間建立，請稍後再試。', array('status' => 504));
    }

    private static function configure_meet_space_open(string $meet_url, $google_event) {
        $path = trim((string) wp_parse_url($meet_url, PHP_URL_PATH), '/');
        if (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $path)) {
            return new WP_Error('meet_code_missing', 'Google Meet 已建立，但無法辨識會議代碼。', array('status' => 502));
        }
        if (empty($google_event->client)) {
            return new WP_Error('meet_client_missing', '無法取得 Google Meet 授權用戶端。', array('status' => 503));
        }

        $google_event->client->addScope(self::MEET_SETTINGS_SCOPE);
        if (method_exists($google_event, 'assign_token_to_client')) {
            $google_event->assign_token_to_client();
        }
        $token = $google_event->client->getAccessToken();
        $access_token = is_array($token) ? (string) ($token['access_token'] ?? '') : '';
        if ($access_token === '') {
            return new WP_Error('meet_settings_scope_required', 'Google Meet 開放權限尚未授權，請至「設定 → TPMA Course Registration IDs」執行授權。', array('status' => 403));
        }

        $space_name = self::resolve_meet_space_name($path, $access_token);
        if (is_wp_error($space_name)) return $space_name;
        $space_id = substr((string) $space_name, strlen('spaces/'));

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = wp_remote_request(
                'https://meet.googleapis.com/v2/spaces/' . rawurlencode($space_id) . '?updateMask=config.accessType',
                array(
                    'method'  => 'PATCH',
                    'timeout' => 15,
                    'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json'),
                    'body'    => wp_json_encode(array('config' => array('accessType' => 'OPEN'))),
                )
            );
            if (is_wp_error($response)) {
                return new WP_Error('meet_access_update_failed', 'Meet 開放權限設定失敗：' . $response->get_error_message(), array('status' => 502));
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) return true;
            if ($attempt < 4 && self::is_transient_meet_space_error($response)) {
                sleep($attempt);
                continue;
            }
            return self::build_google_api_error($response, 'Meet 開放權限設定失敗');
        }
        return new WP_Error('meet_access_update_failed', 'Meet 開放權限設定失敗。', array('status' => 502));
    }

    private static function resolve_meet_space_name(string $meeting_code, string $access_token) {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = wp_remote_request(
                'https://meet.googleapis.com/v2/spaces/' . rawurlencode($meeting_code),
                array(
                    'method'  => 'GET',
                    'timeout' => 15,
                    'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/json'),
                )
            );
            if (is_wp_error($response)) {
                return new WP_Error('meet_space_lookup_failed', 'Meet 會議空間查詢失敗：' . $response->get_error_message(), array('status' => 502));
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                $space = json_decode((string) wp_remote_retrieve_body($response), true);
                $name = is_array($space) ? (string) ($space['name'] ?? '') : '';
                if (preg_match('#^spaces/([A-Za-z0-9_-]+)$#', $name)) return $name;
                return new WP_Error('meet_space_name_missing', 'Google Meet 已回傳會議空間，但缺少可更新的 Space ID。', array('status' => 502));
            }
            if ($attempt < 4 && self::is_transient_meet_space_error($response)) {
                sleep($attempt);
                continue;
            }
            return self::build_google_api_error($response, 'Meet 會議空間查詢失敗');
        }
        return new WP_Error('meet_space_lookup_failed', 'Meet 會議空間查詢失敗。', array('status' => 502));
    }

    private static function is_transient_meet_space_error($response): bool {
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 404) return true;
        if ($status !== 403) return false;
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : array();
        $google_status = strtoupper((string) ($error['status'] ?? ''));
        $message = strtolower((string) ($error['message'] ?? ''));
        return $google_status === 'PERMISSION_DENIED'
            && (strpos($message, 'resource space') !== false || strpos($message, 'might not exist') !== false);
    }

    private static function build_google_api_error($response, string $context): WP_Error {
        $http_status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);
        $google_error = is_array($payload['error'] ?? null) ? $payload['error'] : array();
        $google_status = sanitize_text_field((string) ($google_error['status'] ?? ''));
        $google_message = sanitize_text_field((string) ($google_error['message'] ?? ''));
        $reason = '';

        $find_reason = static function ($value) use (&$find_reason): string {
            if (!is_array($value)) return '';
            if (!empty($value['reason']) && is_scalar($value['reason'])) {
                return sanitize_text_field((string) $value['reason']);
            }
            foreach ($value as $child) {
                $found = $find_reason($child);
                if ($found !== '') return $found;
            }
            return '';
        };
        $reason = $find_reason($google_error['details'] ?? array());

        if ($google_message === '' && $body !== '') {
            $google_message = sanitize_text_field(substr($body, 0, 500));
        }
        $redact = static function (string $value): string {
            $value = preg_replace('/(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization)\s*[:=]\s*[^\s,;]+/i', '$1=[已隱藏]', $value);
            $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [已隱藏]', $value);
            $value = preg_replace('/\btoken\s*[:=]\s*[^\s,;]+/i', 'token=[已隱藏]', $value);
            return trim(substr((string) $value, 0, 500));
        };
        $google_message = $redact($google_message);

        $diagnostics = array('HTTP ' . ($http_status ?: '未知'));
        if ($google_status !== '') $diagnostics[] = $google_status;
        if ($reason !== '') $diagnostics[] = $reason;

        $reason_upper = strtoupper($reason);
        if ($reason_upper === 'SERVICE_DISABLED') {
            $guidance = '請至此 OAuth 憑證所屬的 Google Cloud 專案啟用 Google Meet API。';
        } elseif ($reason_upper === 'ACCESS_TOKEN_SCOPE_INSUFFICIENT' || $reason_upper === 'INSUFFICIENT_AUTHENTICATION_SCOPES' || $http_status === 401) {
            $guidance = '請重新執行「授權 Meet 開放權限」，並確認已同意 meetings.space.settings 權限。';
        } elseif ($http_status === 403) {
            $guidance = '請確認 OAuth scope、Google Meet API 啟用狀態及 Google Workspace 管理政策。';
        } else {
            $guidance = '請依 Google 回傳內容檢查 API 與 OAuth 設定。';
        }

        $message = $context . '（' . implode(' / ', $diagnostics) . '）';
        if ($google_message !== '') $message .= '：' . $google_message;
        $message .= ' ' . $guidance;

        return new WP_Error(
            'meet_access_update_failed',
            $message,
            array(
                'status'        => in_array($http_status, array(400, 401, 403, 404, 409, 429), true) ? $http_status : 502,
                'google_status' => $google_status,
                'google_reason' => $reason,
            )
        );
    }

    public static function sync_session_resources(int $session_id) {
        if (!self::is_active()) return true;
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.is_active, s.delivery_mode, s.tutor_topic_id, s.tutor_meet_post_id,
                    s.tutor_resources_cleaned_at, s.session_datetime, c.duration_minutes
             FROM " . TPMA_CR_DB::table('sessions') . " s
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id
             WHERE s.id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session || empty($session['is_active'])) return true;

        $restored = self::restore_cleaned_session_resources($session_id, $session);
        if (is_wp_error($restored)) return $restored;

        $topic_id = self::prepare_session_topic($session_id);
        if ($topic_id <= 0) return new WP_Error('topic_failed', '無法自動建立 Tutor 場次章節。', array('status' => 500));

        if (!empty($restored['meet_post_id'])) {
            $synced = self::sync_session_meet_time($session_id, (string) $session['session_datetime'], (int) $session['duration_minutes']);
            if (is_wp_error($synced)) return $synced;
        }

        $meet_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT tutor_meet_post_id FROM " . TPMA_CR_DB::table('sessions') . " WHERE id = %d",
            $session_id
        ));
        if ($meet_id > 0 && get_post_type($meet_id) === 'tutor-google-meet' && get_post_status($meet_id) !== 'trash') {
            return array('session_id' => $session_id, 'topic_id' => $topic_id, 'meet_post_id' => $meet_id);
        }
        if ($meet_id > 0) {
            $wpdb->update(TPMA_CR_DB::table('sessions'), array('tutor_meet_post_id' => 0), array('id' => $session_id), array('%d'), array('%d'));
        }
        return self::create_or_link_session_meet($session_id);
    }

    private static function cleanup_session_meet(int $session_id, bool $delete_google_calendar = true) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT tutor_meet_post_id FROM " . TPMA_CR_DB::table('sessions') . " WHERE id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session) return true;
        $meet_id  = (int) ($session['tutor_meet_post_id'] ?? 0);
        if ($meet_id > 0) {
            $details = json_decode((string) get_post_meta($meet_id, 'tutor-google-meet-event-details', true), true);
            $event_id = (string) ($details['id'] ?? '');
            if ($delete_google_calendar && $event_id !== '') {
                $client = self::create_google_meet_client($meet_id, '刪除 Google Calendar 活動');
                if (is_wp_error($client)) return $client;
                try {
                    $client->service->events->delete($client->current_calendar, $event_id, array('sendUpdates' => 'none'));
                } catch (Throwable $e) {
                    if ((int) $e->getCode() !== 404) {
                        self::record_google_meet_diagnostic('刪除 Google Calendar 活動', false, 'calendar_delete_failed', 'Google Calendar 無法刪除 Meet 活動');
                        return new WP_Error('meet_delete_failed', 'Google 日曆事件移除失敗：' . $e->getMessage(), array('status' => 502));
                    }
                }
            }
        }

        if ($meet_id > 0 && get_post_status($meet_id) !== 'trash') wp_trash_post($meet_id);
        $wpdb->update(TPMA_CR_DB::table('sessions'), array('tutor_meet_post_id' => 0), array('id' => $session_id), array('%d'), array('%d'));
        return true;
    }

    public static function cleanup_session_resources(int $session_id, bool $preserve_google_calendar = false) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT tutor_topic_id FROM " . TPMA_CR_DB::table('sessions') . " WHERE id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session) return true;

        $topic_id = (int) ($session['tutor_topic_id'] ?? 0);
        $meet_cleanup = self::cleanup_session_meet($session_id, !$preserve_google_calendar);
        if (is_wp_error($meet_cleanup)) return $meet_cleanup;

        if ($topic_id > 0) {
            $children = get_posts(array('post_type' => 'any', 'post_parent' => $topic_id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'));
            foreach ((array) $children as $child_id) {
                if (get_post_status($child_id) !== 'trash') wp_trash_post((int) $child_id);
            }
            if (get_post_status($topic_id) !== 'trash') wp_trash_post($topic_id);
        }
        $updates = array('tutor_topic_id' => 0, 'tutor_meet_post_id' => 0);
        if ($preserve_google_calendar) {
            $updates['tutor_resources_cleaned_at'] = current_time('mysql');
        }
        $wpdb->update(
            TPMA_CR_DB::table('sessions'),
            $updates,
            array('id' => $session_id),
            $preserve_google_calendar ? array('%d', '%d', '%s') : array('%d', '%d'),
            array('%d')
        );
        return true;
    }

    /** Daily retention: keep Google Calendar, trash only Tutor-side session resources. */
    public static function cleanup_expired_session_resources(): void {
        if (!self::is_active()) return;
        global $wpdb;
        $sessions = $wpdb->get_col($wpdb->prepare(
            "SELECT s.id
             FROM " . TPMA_CR_DB::table('sessions') . " s
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id
             WHERE s.tutor_resources_cleaned_at IS NULL
               AND (COALESCE(s.tutor_topic_id, 0) > 0 OR COALESCE(s.tutor_meet_post_id, 0) > 0)
               AND DATE_ADD(DATE_ADD(s.session_datetime, INTERVAL c.duration_minutes MINUTE), INTERVAL 3 MONTH) <= %s",
            current_time('mysql')
        ));
        foreach ((array) $sessions as $session_id) {
            $result = self::cleanup_session_resources((int) $session_id, true);
            if (is_wp_error($result)) {
                error_log('TPMA Tutor Bridge: expired session cleanup failed for #' . (int) $session_id . ': ' . $result->get_error_message());
            }
        }
    }

    /** Restore trashed Tutor posts when an expired session is scheduled again. */
    private static function restore_cleaned_session_resources(int $session_id, array $session) {
        if (empty($session['tutor_resources_cleaned_at'])) return array('meet_post_id' => 0);
        global $wpdb;

        $topic_ids = get_posts(array(
            'post_type' => 'topics', 'post_status' => 'trash', 'numberposts' => 1, 'fields' => 'ids',
            'meta_key' => '_tpma_session_id', 'meta_value' => $session_id,
        ));
        $topic_id = !empty($topic_ids) ? (int) $topic_ids[0] : 0;
        if ($topic_id > 0) {
            wp_untrash_post($topic_id);
            $wpdb->update(TPMA_CR_DB::table('sessions'), array('tutor_topic_id' => $topic_id), array('id' => $session_id), array('%d'), array('%d'));
        }

        $topic_id = self::prepare_session_topic($session_id);
        if ($topic_id <= 0) return new WP_Error('topic_restore_failed', '無法還原 Tutor 場次章節', array('status' => 500));

        $meet_ids = get_posts(array(
            'post_type' => 'tutor-google-meet', 'post_status' => 'trash', 'numberposts' => 1, 'fields' => 'ids',
            'meta_key' => '_tpma_session_id', 'meta_value' => $session_id,
        ));
        $meet_id = !empty($meet_ids) ? (int) $meet_ids[0] : 0;
        if ($meet_id > 0) {
            wp_untrash_post($meet_id);
            wp_update_post(array('ID' => $meet_id, 'post_parent' => $topic_id));
            $wpdb->update(TPMA_CR_DB::table('sessions'), array('tutor_meet_post_id' => $meet_id), array('id' => $session_id), array('%d'), array('%d'));
        }
        $wpdb->update(
            TPMA_CR_DB::table('sessions'),
            array('tutor_resources_cleaned_at' => null),
            array('id' => $session_id),
            array('%s'),
            array('%d')
        );
        return array('meet_post_id' => $meet_id);
    }

    public static function sync_session_meet_time(int $session_id, string $new_datetime, int $duration_minutes) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.tutor_meet_post_id, s.tutor_topic_id, c.course_name
             FROM " . TPMA_CR_DB::table('sessions') . " s
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id WHERE s.id = %d",
            $session_id
        ), ARRAY_A);
        if (!$session) return new WP_Error('session_not_found', '找不到場次', array('status' => 404));
        $meet_id = (int) ($session['tutor_meet_post_id'] ?? 0);
        $topic_id = (int) ($session['tutor_topic_id'] ?? 0);
        $topic_title = self::format_session_title($new_datetime, (string) $session['course_name']);
        if ($meet_id <= 0) {
            if ($topic_id > 0) {
                wp_update_post(array('ID' => $topic_id, 'post_title' => $topic_title));
                self::sync_session_child_titles($topic_id, $session_id, $topic_title);
            }
            return true;
        }
        $details = json_decode((string) get_post_meta($meet_id, 'tutor-google-meet-event-details', true), true);
        $event_id = (string) ($details['id'] ?? '');
        if ($event_id === '') return new WP_Error('meet_sync_unavailable', '無法取得 Meet Calendar 事件', array('status' => 503));
        $client = self::create_google_meet_client($meet_id, '更新 Meet 時間');
        if (is_wp_error($client)) return $client;
        $tz_name = wp_timezone_string() ?: 'Asia/Taipei';
        if ($tz_name !== 'UTC' && strpos($tz_name, '/') === false) $tz_name = 'Asia/Taipei';
        $start = new DateTime($new_datetime, new DateTimeZone($tz_name));
        $end = clone $start; $end->modify('+' . max(1, $duration_minutes) . ' minutes');
        try {
            $event = $client->service->events->get($client->current_calendar, $event_id);
            if (method_exists($event, 'setSummary')) {
                $event->setSummary(self::format_calendar_summary((string) $session['course_name']));
            }
            $event->setStart(new \Google_Service_Calendar_EventDateTime(array('dateTime' => $start->format('c'), 'timeZone' => $tz_name)));
            $event->setEnd(new \Google_Service_Calendar_EventDateTime(array('dateTime' => $end->format('c'), 'timeZone' => $tz_name)));
            $client->service->events->update($client->current_calendar, $event_id, $event, array('sendUpdates' => 'none'));
        } catch (Throwable $e) {
            self::record_google_meet_diagnostic('更新 Meet 時間', false, 'calendar_update_failed', 'Google Calendar 無法更新 Meet 時間');
            return new WP_Error('meet_sync_failed', 'Meet 時間更新失敗：' . $e->getMessage(), array('status' => 502));
        }
        $details['start_datetime'] = $start->format('Y-m-d H:i:s');
        $details['end_datetime'] = $end->format('Y-m-d H:i:s');
        update_post_meta($meet_id, 'tutor-google-meet-start-datetime', $details['start_datetime']);
        update_post_meta($meet_id, 'tutor-google-meet-end-datetime', $details['end_datetime']);
        update_post_meta($meet_id, 'tutor-google-meet-event-details', wp_json_encode($details));
        wp_update_post(array('ID' => $meet_id, 'post_title' => $topic_title));
        if ($topic_id > 0) wp_update_post(array('ID' => $topic_id, 'post_title' => $topic_title));
        if ($topic_id > 0) self::sync_session_child_titles($topic_id, $session_id, $topic_title);
        return true;
    }

    private static function regenerate_tokens_for_session(int $session_id): void {
        global $wpdb;
        $reg_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . TPMA_CR_DB::table('regs') . " WHERE session_id = %d AND COALESCE(status,'') <> 'cancelled' AND COALESCE(payment_status,'') NOT IN ('cancelled','wc-cancelled','refunded','wc-refunded')",
            $session_id
        ));
        foreach ((array) $reg_ids as $reg_id) self::regenerate_magic_urls_for_reg((int) $reg_id);
    }

    private static function get_content_session_id(int $post_id): int {
        $session_id = (int) get_post_meta($post_id, '_tpma_session_id', true);
        if ($session_id > 0) return $session_id;
        $parent_id = (int) get_post_field('post_parent', $post_id);
        return $parent_id > 0 ? (int) get_post_meta($parent_id, '_tpma_session_id', true) : 0;
    }

    /** Staff preview uses the selected portal learner's entitlement when one exists. */
    private static function has_selected_portal_learner(): bool {
        return class_exists('TPMA_Course_Access') && TPMA_Course_Access::current_registration_id() > 0;
    }

    private static function user_can_bypass_learner_access(int $tutor_course_id, int $user_id = 0): bool {
        if (self::has_selected_portal_learner()) return false;
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        return $user_id > 0 && (user_can($user_id, 'manage_options') || (int)get_post_field('post_author', $tutor_course_id) === $user_id);
    }

    public static function user_can_access_session(int $session_id, int $user_id = 0): bool {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        if ($session_id <= 0 || $user_id <= 0) return false;
        if (!self::has_selected_portal_learner() && self::user_can_manage_session($session_id, $user_id)) return true;
        if (!class_exists('TPMA_Course_Access')) return false;
        $selected = TPMA_Course_Access::current_registration_id();
        if ($selected > 0) {
            $result = TPMA_Course_Access::evaluate_registration($selected, 'course');
            return !empty($result['allowed']) && (int)($result['registration']['session_id'] ?? 0) === $session_id;
        }
        return false;
    }

    private static function user_can_manage_session(int $session_id, int $user_id = 0): bool {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        if ($user_id <= 0) return false;
        if (user_can($user_id, 'manage_options')) return true;
        global $wpdb;
        $tutor_course_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT c.tutor_course_id FROM " . TPMA_CR_DB::table('sessions') . " s
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = s.course_id WHERE s.id = %d",
            $session_id
        ));
        return $tutor_course_id > 0 && (int)get_post_field('post_author', $tutor_course_id) === $user_id;
    }

    private static function user_can_manage_course(int $tutor_course_id, int $user_id = 0): bool {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        if ($tutor_course_id <= 0 || $user_id <= 0) return false;
        return user_can($user_id, 'manage_options') || (int) get_post_field('post_author', $tutor_course_id) === $user_id;
    }

    private static function is_recording_window_open(int $session_id): bool {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT recording_available_from, recording_available_until FROM " . TPMA_CR_DB::table('sessions') . " WHERE id = %d",
            $session_id
        ), ARRAY_A);
        if (!$row || empty($row['recording_available_from']) || empty($row['recording_available_until'])) return false;
        $now = current_time('mysql');
        return $now >= (string) $row['recording_available_from'] && $now <= (string) $row['recording_available_until'];
    }

    public static function filter_topic_contents_for_session(array $args): array {
        if (is_admin() && !wp_doing_ajax()) return $args;
        $topic_id = (int) ($args['post_parent'] ?? 0);
        $course_id = $topic_id > 0 ? (int) get_post_field('post_parent', $topic_id) : 0;
        if ($course_id <= 0 || (int) get_post_meta($course_id, '_tpma_course_id', true) <= 0) return $args;
        $session_id = $topic_id > 0 ? (int) get_post_meta($topic_id, '_tpma_session_id', true) : 0;
        if ($session_id <= 0) return self::hide_closed_shared_quiz_contents($args, $topic_id);
        if (!self::user_can_access_session($session_id)) {
            $args['post__in'] = array(0);
            return $args;
        }
        $args = self::filter_topic_content_args_by_access($args, $topic_id);
        return self::hide_closed_shared_quiz_contents($args, $topic_id);
    }

    private static function filter_topic_content_args_by_access(array $args, int $topic_id): array {
        if ($topic_id <= 0) return $args;
        $children = get_posts(array(
            'post_type'        => self::tutor_content_post_types(),
            'post_parent'      => $topic_id,
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        $blocked = array();
        foreach ((array) $children as $child_id) {
            if (!self::current_user_can_access_tutor_content((int) $child_id)) {
                $blocked[] = (int) $child_id;
            }
        }
        if ($blocked) {
            $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? array()), $blocked)));
        }
        return $args;
    }

    private static function tutor_content_post_types(): array {
        $lesson_type = function_exists('tutor') ? tutor()->lesson_post_type : 'tutor_lesson';
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        return array_values(array_unique(array($lesson_type, $quiz_type, 'tutor-google-meet')));
    }

    public static function filter_course_topics_for_access($topics) {
        if ((is_admin() && !wp_doing_ajax()) || !is_object($topics) || empty($topics->posts) || !is_array($topics->posts)) {
            return $topics;
        }

        $topics->posts = array_values(array_filter($topics->posts, static function($topic): bool {
            $topic_id = is_object($topic) ? (int)($topic->ID ?? 0) : (int)$topic;
            return self::topic_has_visible_content($topic_id);
        }));
        $topics->post_count = count($topics->posts);
        if (isset($topics->found_posts)) {
            $topics->found_posts = $topics->post_count;
        }
        return $topics;
    }

    private static function topic_has_visible_content(int $topic_id): bool {
        if ($topic_id <= 0) return false;
        $course_id = (int) get_post_field('post_parent', $topic_id);
        if ($course_id <= 0 || !(int) get_post_meta($course_id, '_tpma_course_id', true)) return true;
        $user_id = get_current_user_id();
        if (self::user_can_bypass_learner_access($course_id, $user_id)) return true;

        $session_id = (int) get_post_meta($topic_id, '_tpma_session_id', true);
        if ($session_id <= 0) {
            $resource = self::get_topic_resource_type($topic_id);
            return $resource === 'general'
                || (class_exists('TPMA_Course_Access') && TPMA_Course_Access::current_user_can_resource($resource));
        }

        $children = get_posts(array(
            'post_type'        => self::tutor_content_post_types(),
            'post_parent'      => $topic_id,
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ((array) $children as $child_id) {
            if (self::current_user_can_access_tutor_content((int) $child_id)) {
                return true;
            }
        }
        return false;
    }

    private static function hide_closed_shared_quiz_contents(array $args, int $topic_id): array {
        if ($topic_id <= 0) return $args;
        $course_id = (int) get_post_field('post_parent', $topic_id);
        if ($course_id <= 0 || !(int) get_post_meta($course_id, '_tpma_course_id', true)) return $args;
        $user_id = get_current_user_id();
        if (self::user_can_bypass_learner_access($course_id, $user_id)) return $args;
        $resource = self::get_topic_resource_type($topic_id);
        if ($resource === 'general') return $args;
        if (class_exists('TPMA_Course_Access') && TPMA_Course_Access::current_user_can_resource($resource)) return $args;
        $args['post__in'] = array(0);
        return $args;
    }

    private static function get_hidden_resource_topic_ids(int $course_id): array {
        if ($course_id <= 0) return array();
        $hidden = array();
        foreach (self::get_course_topic_resources($course_id) as $topic) {
            $resource = (string) $topic['resource_type'];
            if ($resource !== 'general' && (!class_exists('TPMA_Course_Access') || !TPMA_Course_Access::current_user_can_resource($resource))) {
                $hidden[] = (int) $topic['topic_id'];
            }
        }
        return $hidden;
    }

    public static function exclude_session_recordings_from_progress(array $conditions, int $course_id): array {
        if ($course_id <= 0 || (int) get_post_meta($course_id, '_tpma_course_id', true) <= 0) return $conditions;
        global $wpdb;
        $conditions[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} tpma_session_topic WHERE tpma_session_topic.post_id = topic.ID AND tpma_session_topic.meta_key = '_tpma_session_id')";
        return $conditions;
    }

    public static function filter_session_topics_query($query): void {
        if ((is_admin() && !wp_doing_ajax()) || !is_object($query)) return;
        if ($query->get('tpma_skip_session_topic_filter')) return;
        $post_type = $query->get('post_type');
        if ($post_type !== 'topics' && !(is_array($post_type) && in_array('topics', $post_type, true))) return;
        $user_id = get_current_user_id();
        if ($user_id > 0 && user_can($user_id, 'manage_options') && !self::has_selected_portal_learner()) return;
        $course_id = (int) $query->get('post_parent');
        if ($course_id <= 0 || (int) get_post_meta($course_id, '_tpma_course_id', true) <= 0) return;
        if (self::user_can_bypass_learner_access($course_id, $user_id)) return;
        global $wpdb;
        $allowed = array();
        $selected = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
        if ($selected > 0) {
            $result = TPMA_Course_Access::evaluate_registration($selected, 'course');
            if (!empty($result['allowed'])) $allowed[] = (int)$result['registration']['session_id'];
        }
        $query->set('post__not_in', array_values(array_unique(array_merge(
            (array) $query->get('post__not_in'),
            self::get_hidden_resource_topic_ids($course_id)
        ))));
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = array(
            'relation' => 'OR',
            array('key' => '_tpma_session_id', 'compare' => 'NOT EXISTS'),
            array('key' => '_tpma_session_id', 'value' => $allowed ?: array(0), 'compare' => 'IN', 'type' => 'NUMERIC'),
        );
        $query->set('meta_query', $meta_query);
    }

    public static function protect_session_content(): void {
        if (!is_singular()) return;
        $post_id = get_queried_object_id();
        $post_type = get_post_type($post_id);
        $lesson_type = function_exists('tutor') ? tutor()->lesson_post_type : 'tutor_lesson';
        $course_type = function_exists('tutor') ? tutor()->course_post_type : 'courses';
        if ($post_type === $course_type) {
            $tpma_course_id = (int)get_post_meta($post_id, '_tpma_course_id', true);
            if ($tpma_course_id <= 0 || self::user_can_bypass_learner_access($post_id)) return;
            $reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
            $result = $reg_id > 0 ? TPMA_Course_Access::evaluate_registration($reg_id, 'course') : array('allowed'=>false);
            if (empty($result['allowed']) || (int)($result['registration']['course_id'] ?? 0) !== $tpma_course_id) {
                wp_die('此課程尚未開放，或目前學員沒有存取權限。', '無法進入課程', array('response'=>403));
            }
            return;
        }
        if ($post_type !== $lesson_type && $post_type !== 'tutor-google-meet') return;
        $content_parent_id = (int) get_post_field('post_parent', $post_id);
        $content_course_id = get_post_type($content_parent_id) === 'topics'
            ? (int) get_post_field('post_parent', $content_parent_id) : $content_parent_id;
        if ($content_course_id <= 0 || (int) get_post_meta($content_course_id, '_tpma_course_id', true) <= 0) return;
        $session_id = self::get_content_session_id($post_id);
        if ($session_id <= 0 && $post_type === $lesson_type) {
            $topic_id = (int)get_post_field('post_parent', $post_id);
            $tutor_course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
            if (self::user_can_bypass_learner_access($tutor_course_id)) return;
            $resource = self::get_topic_resource_type($topic_id);
            $reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
            $result = $reg_id > 0 ? TPMA_Course_Access::evaluate_registration($reg_id, $resource) : array('allowed'=>false);
            if (empty($result['allowed']) || (int)($result['registration']['tutor_course_id'] ?? 0) !== $tutor_course_id) {
                wp_die('此課程內容未開放給目前選擇的學員。', '無法存取課程內容', array('response'=>403));
            }
            return;
        }
        if ($session_id <= 0) return;
        $resource = $post_type === $lesson_type ? 'recording' : 'meet';
        $allowed = (!self::has_selected_portal_learner() && self::user_can_manage_session($session_id))
            || (class_exists('TPMA_Course_Access') && TPMA_Course_Access::current_user_can_resource($resource, $session_id));
        if (!$allowed) wp_die('此內容僅開放給指定場次學員。', '無法存取場次內容', array('response' => 403));
    }

    public static function protect_session_lesson_output(string $html): string {
        $post_id = get_the_ID();
        $topic_id = $post_id ? (int) get_post_field('post_parent', $post_id) : 0;
        $tutor_course_id = $topic_id > 0 ? (int) get_post_field('post_parent', $topic_id) : 0;
        if ($tutor_course_id <= 0 || (int) get_post_meta($tutor_course_id, '_tpma_course_id', true) <= 0) return $html;
        $session_id = $post_id ? self::get_content_session_id((int) $post_id) : 0;
        if ($session_id <= 0) {
            if (self::user_can_bypass_learner_access($tutor_course_id)) return $html;
            $resource = self::get_topic_resource_type($topic_id);
            $reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
            $result = $reg_id > 0 ? TPMA_Course_Access::evaluate_registration($reg_id, $resource) : array('allowed'=>false);
            return !empty($result['allowed']) && (int)($result['registration']['tutor_course_id'] ?? 0) === $tutor_course_id
                ? $html : '<div class="tutor-alert tutor-warning">此課程內容目前未開放給您。</div>';
        }
        $allowed = (!self::has_selected_portal_learner() && self::user_can_manage_session($session_id))
            || (class_exists('TPMA_Course_Access') && TPMA_Course_Access::current_user_can_resource('recording', $session_id));
        return $allowed ? $html : '<div class="tutor-alert tutor-warning">此錄播目前未開放給您的場次。</div>';
    }

    private static function current_user_can_access_tutor_content(int $post_id): bool {
        if ($post_id <= 0) return true;
        $user_id = get_current_user_id();
        $post_type = get_post_type($post_id);
        $lesson_type = function_exists('tutor') ? tutor()->lesson_post_type : 'tutor_lesson';
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        if (!in_array($post_type, array($lesson_type, $quiz_type, 'tutor-google-meet'), true)) return true;

        $topic_id = (int)get_post_field('post_parent', $post_id);
        $course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
        if ($course_id <= 0 || !(int)get_post_meta($course_id, '_tpma_course_id', true)) return true;
        if (self::user_can_bypass_learner_access($course_id, $user_id)) return true;

        $reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
        if ($reg_id <= 0) return false;
        $session_id = self::get_content_session_id($post_id);
        $resource = $post_type === $quiz_type ? 'quiz' : ($post_type === 'tutor-google-meet' ? 'meet' : 'recording');
        if ($session_id <= 0) $resource = self::get_topic_resource_type($topic_id);
        $result = TPMA_Course_Access::evaluate_registration($reg_id, $resource);
        if (empty($result['allowed']) || (int)($result['registration']['tutor_course_id'] ?? 0) !== $course_id) return false;
        return $session_id <= 0 || (int)($result['registration']['session_id'] ?? 0) === $session_id;
    }

    public static function filter_next_previous_pagination(string $html): string {
        if ($html === '' || !class_exists('TPMA_Course_Access') || TPMA_Course_Access::current_registration_id() <= 0) return $html;
        $current_id = (int)get_queried_object_id();
        if ($current_id <= 0) $current_id = (int)get_the_ID();
        $post_type = get_post_type($current_id);
        $lesson_type = function_exists('tutor') ? tutor()->lesson_post_type : 'tutor_lesson';
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        if (!in_array($post_type, array($lesson_type, $quiz_type, 'tutor-google-meet'), true)) return $html;
        $topic_id = (int)get_post_field('post_parent', $current_id);
        $course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
        return $course_id > 0 && (int)get_post_meta($course_id, '_tpma_course_id', true) > 0 ? '' : $html;
    }

    private static function get_first_accessible_course_content(int $course_id): int {
        if ($course_id <= 0 || !(int)get_post_meta($course_id, '_tpma_course_id', true)) return 0;
        global $wpdb;
        $lesson_type = function_exists('tutor') ? tutor()->lesson_post_type : 'tutor_lesson';
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        $content_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT content.ID FROM {$wpdb->posts} topic
             JOIN {$wpdb->posts} content ON content.post_parent=topic.ID
             WHERE topic.post_parent=%d AND topic.post_type='topics' AND topic.post_status<>'trash'
               AND content.post_type IN (%s,%s,'tutor-google-meet') AND content.post_status='publish'
             ORDER BY topic.menu_order ASC, topic.ID ASC, content.menu_order ASC, content.ID ASC",
            $course_id,
            $lesson_type,
            $quiz_type
        ));
        foreach ((array)$content_ids as $content_id) {
            if (self::current_user_can_access_tutor_content((int)$content_id)) return (int)$content_id;
        }
        return 0;
    }

    private static function disabled_course_button(string $label): string {
        return '<button type="button" class="tutor-btn tutor-btn-primary tutor-btn-block" disabled aria-disabled="true">'
            . esc_html($label) . '</button>';
    }

    public static function filter_course_start_button(string $html, int $course_id): string {
        if ($html === '' || !(int)get_post_meta($course_id, '_tpma_course_id', true)) return $html;
        $user_id = get_current_user_id();
        if (self::user_can_bypass_learner_access($course_id, $user_id)) return $html;
        if (!class_exists('TPMA_Course_Access') || TPMA_Course_Access::current_registration_id() <= 0) return $html;

        $quiz_id = self::get_qualifying_quiz_for_course($course_id);
        if ($quiz_id > 0 && self::current_registration_has_passed_quiz($quiz_id, $user_id)) {
            return self::disabled_course_button('課程已完成');
        }
        $content_id = self::get_first_accessible_course_content($course_id);
        if ($content_id <= 0) return self::disabled_course_button('目前沒有可開啟的課程內容');
        $url = get_permalink($content_id);
        if (!$url) return self::disabled_course_button('目前沒有可開啟的課程內容');
        return (string)preg_replace('/\\bhref=(["\']).*?\\1/i', 'href="' . esc_url($url) . '"', $html, 1);
    }

    // ──────────────────────────────────────────────────────────
    // Enrollment
    // ──────────────────────────────────────────────────────────

    /**
     * Hook: woocommerce_checkout_order_processed (priority 11).
     * Fires after TPMA writes registration rows at priority 10.
     * Enrolls each learner in Tutor and generates Magic Link tokens.
     */
    public static function on_order_processed(int $order_id): void {
        if (!self::is_active()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        // Idempotency guard
        if ($order->get_meta('_tpma_tutor_enrolled', true) === 'yes') {
            return;
        }

        $reg_ids_json = $order->get_meta('_tpma_reg_ids', true);
        $reg_ids      = $reg_ids_json ? json_decode($reg_ids_json, true) : null;
        if (!is_array($reg_ids) || empty($reg_ids)) {
            return;
        }

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        foreach ($reg_ids as $reg_id) {
            $reg_id = (int)$reg_id;
            $reg    = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id = %d", $reg_id),
                ARRAY_A
            );
            if (!$reg) {
                continue;
            }

            $wp_user_id     = (int)$reg['wp_user_id'];
            $tpma_course_id = (int)$reg['course_id'];
            $class_date     = (string)$reg['class_date'];

            if (!$wp_user_id || !$tpma_course_id) {
                continue;
            }

            $tutor_course_id = self::get_tutor_course_id($tpma_course_id);
            if (!$tutor_course_id) {
                continue;
            }

            self::enroll_learner($wp_user_id, $tutor_course_id, $reg_id);
        }

        if (class_exists('TPMA_Course_Access')) {
            TPMA_Course_Access::get_or_create_portal_url($order_id);
            TPMA_Course_Access::maybe_send_access_event_for_order($order_id);
        }

        $order->update_meta_data('_tpma_tutor_enrolled', 'yes');
        $order->save();
    }

    /**
     * Enroll a WP user in a Tutor course, forcing enrollment status to 'completed'.
     * TPMA handles payment separately, so we bypass Tutor's payment gate.
     *
     * @return int  Enrolled post ID, or 0 on failure.
     */
    public static function enroll_learner(int $wp_user_id, int $tutor_course_id, int $registration_id = 0): int {
        if (!function_exists('tutor_utils')) {
            return 0;
        }

        $enrolled_id = tutor_utils()->do_enroll($tutor_course_id, 0, $wp_user_id);
        if (!$enrolled_id || is_wp_error($enrolled_id)) {
            return 0;
        }

        // Force status to 'completed' — Tutor pauses paid courses at 'pending'
        wp_update_post([
            'ID'          => (int)$enrolled_id,
            'post_status' => 'completed',
        ]);
        update_user_meta($wp_user_id, '_is_tutor_student', time());

        // Write enrolled ID back to TPMA registrations row
        if ($registration_id > 0) {
            global $wpdb;
            $wpdb->update(
                TPMA_CR_DB::table('regs'),
                ['tutor_enrolled_id' => (int)$enrolled_id],
                ['id'                => $registration_id],
                ['%d'],
                ['%d']
            );
        }

        return (int)$enrolled_id;
    }

    /**
     * Build missing Tutor enrollments for registrations created before the
     * TPMA-to-Tutor checkout bridge existed. Safe to run repeatedly: rows that
     * already store an enrollment ID are skipped, and Tutor's do_enroll() is
     * idempotent for any older row that has a missing stored ID.
     *
     * @return array|WP_Error
     */
    public static function backfill_legacy_enrollments() {
        if (!self::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用，無法補建既有學員權限。');
        }

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');
        $courses_table = TPMA_CR_DB::table('courses');
        $rows = $wpdb->get_results(
            "SELECT r.id, r.wp_user_id, r.tutor_enrolled_id, c.tutor_course_id
             FROM {$regs_table} r
             JOIN {$courses_table} c ON c.id = r.course_id
             WHERE r.wp_user_id IS NOT NULL AND r.wp_user_id > 0
               AND c.tutor_course_id IS NOT NULL AND c.tutor_course_id > 0
               AND COALESCE(c.is_active, 1) = 1
               AND COALESCE(r.status, '') NOT IN ('cancelled', 'refunded')
               AND COALESCE(r.payment_status, '') NOT IN ('cancelled', 'wc-cancelled', 'refunded', 'wc-refunded')",
            ARRAY_A
        );

        $result = array('scanned' => 0, 'enrolled' => 0, 'skipped' => 0, 'failed' => 0);
        foreach ((array) $rows as $row) {
            $result['scanned']++;
            $registration_id = (int) ($row['id'] ?? 0);
            $wp_user_id = (int) ($row['wp_user_id'] ?? 0);
            $tutor_course_id = (int) ($row['tutor_course_id'] ?? 0);
            if ($registration_id <= 0 || $wp_user_id <= 0 || $tutor_course_id <= 0) {
                $result['skipped']++;
                continue;
            }
            if (class_exists('TPMA_Course_Access') && !TPMA_Course_Access::learner_login_is_safe($wp_user_id, $tutor_course_id)) {
                $result['skipped']++;
                continue;
            }
            if ((int) ($row['tutor_enrolled_id'] ?? 0) > 0) {
                $result['skipped']++;
                continue;
            }

            $enrolled_id = self::enroll_learner($wp_user_id, $tutor_course_id, $registration_id);
            if ($enrolled_id > 0) {
                $result['enrolled']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /** Reconcile Tutor enrollment after an admin moves one registration to another TPMA course. */
    public static function transfer_registration_enrollment(int $registration_id, int $source_course_id, int $source_enrollment_id = 0) {
        if (!self::is_active()) return array('target_enrollment_id' => 0, 'source_enrollment_cancelled' => false);
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');
        $reg = $wpdb->get_row($wpdb->prepare(
            "SELECT id, course_id, wp_user_id FROM {$regs_table} WHERE id = %d",
            $registration_id
        ), ARRAY_A);
        if (!$reg) return new WP_Error('registration_not_found', '找不到已更新的報名資料', array('status' => 404));

        $target_course_id = (int) ($reg['course_id'] ?? 0);
        $target_tutor_id = self::sync_course($target_course_id);
        if ($target_tutor_id <= 0) return new WP_Error('target_tutor_sync_failed', '目標課程無法同步至 Tutor，已取消轉課。', array('status' => 503));

        $wp_user_id = (int) ($reg['wp_user_id'] ?? 0);
        $target_enrollment_id = 0;
        if ($wp_user_id > 0) {
            $target_enrollment_id = self::enroll_learner($wp_user_id, $target_tutor_id, $registration_id);
            if ($target_enrollment_id <= 0) {
                return new WP_Error('target_tutor_enrollment_failed', '目標 Tutor 課程註冊失敗，已取消轉課。', array('status' => 503));
            }
        }

        $source_enrollment_cancelled = false;
        if ($source_enrollment_id > 0 && $wp_user_id > 0 && $source_course_id > 0) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM {$regs_table}
                 WHERE course_id = %d AND wp_user_id = %d AND id <> %d
                   AND COALESCE(status, '') <> 'cancelled'
                   AND COALESCE(payment_status, '') NOT IN ('cancelled', 'wc-cancelled', 'refunded', 'wc-refunded')",
                $source_course_id,
                $wp_user_id,
                $registration_id
            ));
            if ($remaining === 0 && get_post_status($source_enrollment_id) && get_post_status($source_enrollment_id) !== 'cancel') {
                wp_update_post(array('ID' => $source_enrollment_id, 'post_status' => 'cancel'));
                $source_enrollment_cancelled = true;
            }
        }

        return array(
            'target_enrollment_id' => $target_enrollment_id,
            'source_enrollment_cancelled' => $source_enrollment_cancelled,
        );
    }

    public static function cancel_tutor_enrollment(int $enrollment_id): void {
        if ($enrollment_id > 0 && get_post_status($enrollment_id) && get_post_status($enrollment_id) !== 'cancel') {
            wp_update_post(array('ID' => $enrollment_id, 'post_status' => 'cancel'));
        }
    }

    /**
     * Hook: woocommerce_order_status_cancelled / woocommerce_order_status_refunded.
     * Cancels Tutor enrollments and expires magic tokens.
     */
    public static function on_order_cancelled(int $order_id): void {
        if (!self::is_active()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if (class_exists('TPMA_Course_Access')) {
            TPMA_Course_Access::revoke_order($order_id);
        }

        $reg_ids_json = $order->get_meta('_tpma_reg_ids', true);
        $reg_ids      = $reg_ids_json ? json_decode($reg_ids_json, true) : null;
        if (!is_array($reg_ids) || empty($reg_ids)) {
            return;
        }

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        foreach ($reg_ids as $reg_id) {
            $reg_id     = (int)$reg_id;
            $enrolled_id = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT tutor_enrolled_id FROM {$regs_table} WHERE id = %d", $reg_id)
            );

            if ($enrolled_id > 0) {
                wp_update_post(['ID' => $enrolled_id, 'post_status' => 'cancel']);
            }

            self::expire_tokens_for_registration($reg_id);
        }
    }

    // ──────────────────────────────────────────────────────────
    // Magic Tokens
    // ──────────────────────────────────────────────────────────

    /**
     * Generate all magic tokens for one registration (course, quiz, certificate, meet).
     * Existing tokens of the same type are replaced.
     */
    public static function generate_all_tokens_for_registration(
        int    $reg_id,
        int    $wp_user_id,
        int    $tutor_course_id,
        string $class_date
    ): array {
        $extra_days = max(1, (int)get_option('tpma_cr_magic_link_extra_days', 15));

        if ($class_date && strtotime($class_date)) {
            $expires_at = date('Y-m-d 23:59:59', strtotime("+{$extra_days} days", strtotime($class_date)));
        } else {
            $expires_at = date('Y-m-d 23:59:59', strtotime("+{$extra_days} days"));
        }

        global $wpdb;
        $session_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT session_id FROM " . TPMA_CR_DB::table('regs') . " WHERE id = %d",
            $reg_id
        ));
        $session_meet_url = self::get_session_meet_link($session_id);

        $types = ['course', 'quiz', 'certificate'];
        if ($session_meet_url !== '') {
            $types[] = 'meet';
        }

        $urls = [];
        foreach ($types as $type) {
            $target_url = $type === 'meet' ? $session_meet_url : '';
            $raw = self::create_token($reg_id, $wp_user_id, $tutor_course_id, $type, $expires_at, $target_url);
            if ($raw) {
                $urls[$type] = add_query_arg('tpma_token', $raw, home_url('/'));
            }
        }

        // Cache for the current request (used by inject_mail_context)
        self::$token_url_cache[$reg_id] = $urls;

        return $urls;
    }

    /**
     * Create (or replace) a single magic token.
     *
     * @return string  Raw 64-char hex token, or '' on DB failure.
     */
    public static function create_token(
        int    $reg_id,
        int    $wp_user_id,
        int    $tutor_course_id,
        string $target_type,
        string $expires_at,
        string $target_url_override = ''
    ): string {
        global $wpdb;
        $table = TPMA_CR_DB::table('magic_tokens');

        // Remove any existing token of the same type for this registration
        $wpdb->delete(
            $table,
            ['registration_id' => $reg_id, 'target_type' => $target_type],
            ['%d', '%s']
        );

        $raw        = bin2hex(random_bytes(32));     // 64-char hex
        $token_hash = hash('sha256', $raw);
        $target_url = $target_url_override !== '' ? esc_url_raw($target_url_override) : self::build_target_url($target_type, $tutor_course_id);

        $ok = $wpdb->insert($table, [
            'token_hash'      => $token_hash,
            'wp_user_id'      => $wp_user_id,
            'registration_id' => $reg_id,
            'tutor_course_id' => $tutor_course_id,
            'target_type'     => $target_type,
            'target_url'      => $target_url,
            'expires_at'      => $expires_at,
            'created_at'      => current_time('mysql'),
        ], ['%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']);

        return $ok ? $raw : '';
    }

    /**
     * Regenerate magic tokens for a registration and return URLs.
     * Used by admin "resend" and for mail context when cache is cold.
     *
     * @return array<string,string>  ['course' => url, 'quiz' => url, ...]
     */
    public static function regenerate_magic_urls_for_reg(int $reg_id): array {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        $reg = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id = %d", $reg_id),
            ARRAY_A
        );
        if (!$reg) {
            return [];
        }

        $wp_user_id     = (int)$reg['wp_user_id'];
        $tpma_course_id = (int)$reg['course_id'];
        $class_date     = (string)$reg['class_date'];

        if (!$wp_user_id || !$tpma_course_id) {
            return [];
        }

        $tutor_course_id = self::get_tutor_course_id($tpma_course_id);
        if (!$tutor_course_id) {
            return [];
        }

        $order_id = (int)($reg['woocommerce_order_id'] ?? 0);
        if ($order_id > 0 && class_exists('TPMA_Course_Access')) {
            $portal = TPMA_Course_Access::get_or_create_portal_url($order_id, false);
            return array('portal'=>$portal,'course'=>$portal,'quiz'=>$portal,'certificate'=>$portal,'meet'=>$portal);
        }

        return self::generate_all_tokens_for_registration($reg_id, $wp_user_id, $tutor_course_id, $class_date);
    }

    /**
     * Expire all tokens for a registration (e.g. on order cancellation).
     */
    public static function expire_tokens_for_registration(int $reg_id): void {
        global $wpdb;
        $wpdb->update(
            TPMA_CR_DB::table('magic_tokens'),
            ['expires_at' => current_time('mysql')],
            ['registration_id' => $reg_id],
            ['%s'],
            ['%d']
        );
        unset(self::$token_url_cache[$reg_id]);
    }

    /**
     * Return current token status for admin display.
     *
     * @return array<string, array{expires_at: string, is_expired: bool}>
     */
    public static function get_token_info_for_reg(int $reg_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT target_type, expires_at
             FROM   " . TPMA_CR_DB::table('magic_tokens') . "
             WHERE  registration_id = %d
             ORDER  BY created_at DESC",
            $reg_id
        ), ARRAY_A);

        $result = [];
        foreach ((array)$rows as $row) {
            $type = (string)$row['target_type'];
            if (!isset($result[$type])) {
                $result[$type] = [
                    'expires_at' => $row['expires_at'],
                    'is_expired' => strtotime($row['expires_at']) < time(),
                ];
            }
        }
        return $result;
    }

    /**
     * Resolve the redirect URL for a given target type.
     */
    private static function build_target_url(string $target_type, int $tutor_course_id): string {
        $course_url = $tutor_course_id ? (get_permalink($tutor_course_id) ?: home_url('/')) : home_url('/');

        switch ($target_type) {
            case 'course':
            case 'quiz':
                return $course_url;
            case 'certificate':
                return home_url('/dashboard/my-courses/');
            case 'meet':
                $link = self::get_course_meet_link($tutor_course_id);
                return $link ?: $course_url;
            default:
                return $course_url;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Magic Token validation + auto-login
    // ──────────────────────────────────────────────────────────

    /**
     * Hook: init (priority 99).
     * Handles ?tpma_token=... — validates the token and logs the user in.
     */
    public static function handle_magic_token(): void {
        if (empty($_GET['tpma_token'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['tpma_token']));

        // Format guard — must be 64-char lowercase hex
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            wp_die(esc_html__('無效的認証連結格式。', 'tpma-cr'), 400);
        }

        // IP-based rate limiting (10 attempts / 5 minutes)
        $ip       = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        $rate_key = 'tpma_ml_' . md5($ip);
        $attempts = (int)get_transient($rate_key);
        if ($attempts >= 10) {
            wp_die(esc_html__('嘗試次數過多，請稍等幾分鐘後再試。', 'tpma-cr'), 429);
        }
        set_transient($rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . TPMA_CR_DB::table('magic_tokens') . " WHERE token_hash = %s LIMIT 1",
            hash('sha256', $token)
        ));

        if (!$row) {
            wp_die(esc_html__('認証連結無效或已被使用。', 'tpma-cr'), 400);
        }

        if (strtotime($row->expires_at) < time()) {
            wp_die(esc_html__('此認証連結已過期，請聯絡主辦單位取得新連結。', 'tpma-cr'), 403);
        }

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT status, payment_status, wp_user_id FROM " . TPMA_CR_DB::table('regs') . " WHERE id = %d",
            (int) $row->registration_id
        ));
        if (!$registration
            || (int) $registration->wp_user_id !== (int) $row->wp_user_id
            || (string) $registration->status === 'cancelled'
            || in_array((string) $registration->payment_status, array('cancelled','wc-cancelled','refunded','wc-refunded'), true)) {
            self::expire_tokens_for_registration((int) $row->registration_id);
            wp_die(esc_html__('此報名已失效，無法使用認証連結。', 'tpma-cr'), 403);
        }

        $user = get_user_by('id', (int)$row->wp_user_id);
        if (!$user) {
            wp_die(esc_html__('找不到對應的使用者帳號。', 'tpma-cr'), 404);
        }
        if (class_exists('TPMA_Course_Access') && !TPMA_Course_Access::learner_login_is_safe((int)$user->ID, (int)$row->tutor_course_id)) {
            self::expire_tokens_for_registration((int)$row->registration_id);
            wp_die(esc_html__('此報名誤綁管理帳號，認証連結已停用。請聯絡主辦單位修正學員帳號。', 'tpma-cr'), 403);
        }

        // Authenticate
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false);
        do_action('wp_login', $user->user_login, $user);

        // Clear rate limiter on success
        delete_transient($rate_key);

        // Redirect — enforce same-site to prevent open redirect
        $target_url  = (string)$row->target_url;
        $site_host   = wp_parse_url(home_url(), PHP_URL_HOST);
        $target_host = $target_url ? wp_parse_url($target_url, PHP_URL_HOST) : '';

        $is_google_meet = (string) $row->target_type === 'meet' && strtolower((string) $target_host) === 'meet.google.com';
        if (!$target_url || ($target_host && $target_host !== $site_host && !$is_google_meet)) {
            $target_url = home_url('/');
        }
        if ($is_google_meet) {
            wp_redirect(esc_url_raw($target_url));
        } else {
            wp_safe_redirect(esc_url_raw($target_url));
        }
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // Quiz result sync
    // ──────────────────────────────────────────────────────────

    private static function is_qualifying_quiz(int $quiz_id): bool {
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        if ($quiz_id <= 0 || get_post_type($quiz_id) !== $quiz_type) return false;
        $topic_id = (int)get_post_field('post_parent', $quiz_id);
        if ($topic_id <= 0 || sanitize_key((string)get_post_meta($topic_id, '_tpma_resource_type', true)) !== 'quiz') return false;
        $course_id = (int)get_post_field('post_parent', $topic_id);
        return $course_id > 0 && (int)get_post_meta($course_id, '_tpma_course_id', true) > 0;
    }

    private static function enforce_topic_quiz_settings(int $topic_id): void {
        if ($topic_id <= 0 || sanitize_key((string)get_post_meta($topic_id, '_tpma_resource_type', true)) !== 'quiz') return;
        global $wpdb;
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        $quiz_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type=%s AND post_status<>'trash'",
            $topic_id,
            $quiz_type
        ));
        foreach ((array)$quiz_ids as $quiz_id) self::set_quiz_unlimited_attempts((int)$quiz_id);
    }

    private static function set_quiz_unlimited_attempts(int $quiz_id): void {
        if (!self::is_qualifying_quiz($quiz_id)) return;
        $options = get_post_meta($quiz_id, 'tutor_quiz_option', true);
        $options = is_array($options) ? $options : array();
        if (isset($options['attempts_allowed'], $options['feedback_mode'])
            && (int)$options['attempts_allowed'] === 0
            && (string)$options['feedback_mode'] === 'retry') return;
        $options['attempts_allowed'] = 0;
        $options['feedback_mode'] = 'retry';
        update_post_meta($quiz_id, 'tutor_quiz_option', $options);
    }

    public static function enforce_qualifying_quiz_settings($post_id, $post = null, $update = false): void {
        $post_id = (int)$post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id)) return;
        self::set_quiz_unlimited_attempts($post_id);
    }

    public static function prepare_qualifying_quiz_request(): void {
        if (!is_singular()) return;
        $quiz_id = (int)get_queried_object_id();
        if (self::is_qualifying_quiz($quiz_id)) self::set_quiz_unlimited_attempts($quiz_id);
    }

    public static function filter_qualifying_quiz_top(string $html): string {
        $quiz_id = (int)get_the_ID();
        if (!self::is_qualifying_quiz($quiz_id)) return $html;
        $html = (string)preg_replace('#<button\\b(?=[^>]*\\bskip-quiz-btn\\b)[^>]*>.*?</button>#is', '', $html);
        return '<style>.skip-quiz-btn,#tutor-quiz-skip-to-next,.tutor-quiz-answer-previous-btn,.tutor-next-btn,.tutor-quiz-questions-pagination{display:none!important}</style>' . $html;
    }

    private static function get_quiz_passing_grade(int $quiz_id): float {
        $grade = function_exists('tutor_utils') ? (float)tutor_utils()->get_quiz_option($quiz_id, 'passing_grade', 0) : 0.0;
        return $grade > 0 ? $grade : 80.0;
    }

    private static function get_registration_for_quiz(int $reg_id, int $quiz_id, int $user_id = 0): ?array {
        if ($reg_id <= 0 || !self::is_qualifying_quiz($quiz_id)) return null;
        $topic_id = (int)get_post_field('post_parent', $quiz_id);
        $course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
        global $wpdb;
        $sql = "SELECT r.*, c.tutor_course_id FROM " . TPMA_CR_DB::table('regs') . " r
                JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id
                WHERE r.id=%d AND c.tutor_course_id=%d";
        $args = array($reg_id, $course_id);
        if ($user_id > 0) {
            $sql .= " AND r.wp_user_id=%d";
            $args[] = $user_id;
        }
        $sql .= " LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);
        return $row ?: null;
    }

    private static function get_registration_best_quiz_score(int $reg_id, int $quiz_id): float {
        if ($reg_id <= 0 || $quiz_id <= 0) return 0.0;
        global $wpdb;
        $score = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX((a.earned_marks / NULLIF(a.total_marks, 0)) * 100)
             FROM {$wpdb->prefix}tutor_quiz_attempts a
             JOIN " . TPMA_CR_DB::table('quiz_contexts') . " qc ON qc.attempt_id=a.attempt_id
             WHERE qc.registration_id=%d AND a.quiz_id=%d AND a.total_marks>0
               AND COALESCE(a.attempt_status,'')<>'attempt_started'",
            $reg_id,
            $quiz_id
        ));
        return $score === null ? 0.0 : round((float)$score, 1);
    }

    private static function registration_has_passed_quiz(int $reg_id, int $quiz_id): bool {
        return self::get_registration_best_quiz_score($reg_id, $quiz_id) >= self::get_quiz_passing_grade($quiz_id);
    }

    private static function current_registration_has_passed_quiz(int $quiz_id, int $user_id = 0): bool {
        $reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();
        return self::get_registration_for_quiz($reg_id, $quiz_id, $user_id) !== null
            && self::registration_has_passed_quiz($reg_id, $quiz_id);
    }

    private static function get_qualifying_quiz_for_course(int $course_id): int {
        if ($course_id <= 0 || !(int)get_post_meta($course_id, '_tpma_course_id', true)) return 0;
        global $wpdb;
        $quiz_type = function_exists('tutor') ? tutor()->quiz_post_type : 'tutor_quiz';
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT q.ID FROM {$wpdb->posts} q
             JOIN {$wpdb->posts} topic ON topic.ID=q.post_parent
             JOIN {$wpdb->postmeta} resource ON resource.post_id=topic.ID
                AND resource.meta_key='_tpma_resource_type' AND resource.meta_value='quiz'
             WHERE q.post_type=%s AND q.post_status<>'trash' AND topic.post_parent=%d
             ORDER BY topic.menu_order ASC, q.menu_order ASC, q.ID ASC LIMIT 1",
            $quiz_type,
            $course_id
        ));
    }

    /**
     * Hook: tutor_quiz_attempt_ended.
     * Writes the quiz score back to TPMA registrations.test_score.
     *
     * @param int|object $attempt_id  Attempt ID (or attempt object depending on Tutor version).
     */
    public static function on_quiz_attempt_ended($attempt_id): void {
        if (!self::is_active()) {
            return;
        }

        $attempt_id = is_object($attempt_id) ? (int)($attempt_id->attempt_id ?? $attempt_id->id ?? 0) : (int)$attempt_id;
        if (!$attempt_id) {
            return;
        }

        global $wpdb;
        $attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$attempts_table}'")) {
            return;
        }

        $attempt = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$attempts_table} WHERE attempt_id = %d", $attempt_id),
            ARRAY_A
        );
        // Fallback for tables that use 'id' as PK
        if (!$attempt) {
            $attempt = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$attempts_table} WHERE id = %d", $attempt_id),
                ARRAY_A
            );
        }
        if (!$attempt) {
            return;
        }

        $wp_user_id = (int)($attempt['user_id'] ?? 0);
        $quiz_id = (int)($attempt['quiz_id'] ?? 0);

        if (!$wp_user_id || !self::is_qualifying_quiz($quiz_id)) {
            return;
        }

        $regs_table    = TPMA_CR_DB::table('regs');

        $mapped_reg_id = class_exists('TPMA_Course_Access') ? (int)$wpdb->get_var($wpdb->prepare(
            "SELECT registration_id FROM " . TPMA_CR_DB::table('quiz_contexts') . " WHERE attempt_id=%d", $attempt_id
        )) : 0;
        if ($mapped_reg_id <= 0) return;
        if (self::get_registration_for_quiz($mapped_reg_id, $quiz_id, $wp_user_id) === null) return;
        $best_score = self::get_registration_best_quiz_score($mapped_reg_id, $quiz_id);
        $wpdb->update($regs_table, array('test_score'=>$best_score . '%'), array('id'=>$mapped_reg_id), array('%s'), array('%d'));
    }

    public static function guard_quiz_start($quiz_id, $user_id): void {
        $topic_id = (int)get_post_field('post_parent', (int)$quiz_id);
        $course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
        if ($course_id <= 0 || !(int)get_post_meta($course_id, '_tpma_course_id', true)) return;
        if (self::user_can_bypass_learner_access($course_id, (int)$user_id)) return;
        self::set_quiz_unlimited_attempts((int)$quiz_id);
        if (!class_exists('TPMA_Course_Access') || !TPMA_Course_Access::current_user_can_resource('quiz')) {
            wp_die('此測驗尚未開放，或目前學員沒有應考權限。', '無法開始測驗', array('response'=>403));
        }
        if (self::current_registration_has_passed_quiz((int)$quiz_id, (int)$user_id)) {
            wp_die('您已通過測驗並完成本課程，不可再次應考。', '課程已完成', array('response'=>403));
        }
    }

    public static function force_completed_progress_after_pass($result, $course_id, $user_id, $get_stats) {
        $quiz_id = self::get_qualifying_quiz_for_course((int)$course_id);
        if ($quiz_id <= 0 || !self::current_registration_has_passed_quiz($quiz_id, (int)$user_id)) return $result;
        return $get_stats
            ? array('completed_percent'=>100, 'completed_count'=>1, 'total_count'=>1)
            : 100;
    }

    public static function filter_qualifying_quiz_content(string $html): string {
        $quiz_id = (int)get_the_ID();
        if (!self::is_qualifying_quiz($quiz_id)) return $html;
        self::set_quiz_unlimited_attempts($quiz_id);
        if (!self::current_registration_has_passed_quiz($quiz_id)) return $html;
        $html = (string)preg_replace('#<form\b[^>]*\bid=(["\'])tutor-start-quiz\1[^>]*>.*?</form>#is', '', $html);
        if (strpos($html, 'tpma-course-completed-notice') === false) {
            $notice = '<div class="tutor-alert tutor-success tpma-course-completed-notice" role="status"><strong>恭喜您已通過測驗，本課程已完成。</strong></div>';
            $html = $notice . $html;
        }
        return $html;
    }

    public static function hide_tpma_complete_form_after_pass(string $html): string {
        $post_id = (int)get_the_ID();
        $course_type = function_exists('tutor') ? tutor()->course_post_type : 'courses';
        $course_id = get_post_type($post_id) === $course_type
            ? $post_id
            : (int)get_post_field('post_parent', (int)get_post_field('post_parent', $post_id));
        $quiz_id = self::get_qualifying_quiz_for_course($course_id);
        $reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
        return $quiz_id > 0 && self::get_registration_for_quiz($reg_id, $quiz_id, get_current_user_id()) !== null ? '' : $html;
    }

    /**
     * Replace Tutor's generic single-quiz completion warning only in the
     * current TPMA learner context. Tutor calls sprintf() after gettext, so
     * the replacement intentionally does not contain Tutor's placeholders.
     */
    public static function filter_tpma_quiz_completion_notice(string $translation, string $text, string $domain): string {
        if ($domain !== 'tutor' || $text !== 'You have to pass %1$s %2$s to complete this course.' || !is_singular()) {
            return $translation;
        }
        $course_type = function_exists('tutor') ? tutor()->course_post_type : 'courses';
        $course_id = (int) get_queried_object_id();
        if ($course_id <= 0 || get_post_type($course_id) !== $course_type) {
            return $translation;
        }
        $quiz_id = self::get_qualifying_quiz_for_course($course_id);
        if ($quiz_id <= 0 || self::current_registration_has_passed_quiz($quiz_id)) {
            return $translation;
        }
        return '您必須通過隨堂測驗才能完成本課程。';
    }

    private static function current_registration_can_access_course_quiz(int $course_id): bool {
        if ($course_id <= 0 || !class_exists('TPMA_Course_Access')) {
            return false;
        }
        $reg_id = TPMA_Course_Access::current_registration_id();
        if ($reg_id <= 0) {
            return false;
        }
        $result = TPMA_Course_Access::evaluate_registration($reg_id, 'quiz');
        return !empty($result['allowed'])
            && (int)($result['registration']['tutor_course_id'] ?? 0) === $course_id;
    }

    /** Render a TPMA notice when Tutor's strict-mode notice is unavailable. */
    public static function render_tpma_quiz_completion_notice(string $html): string {
        if (!self::is_active()) {
            return $html;
        }
        $course_type = function_exists('tutor') ? tutor()->course_post_type : 'courses';
        $course_id = (int) get_the_ID();
        if ($course_id <= 0 || get_post_type($course_id) !== $course_type || !(int)get_post_meta($course_id, '_tpma_course_id', true)) {
            return $html;
        }
        $quiz_id = self::get_qualifying_quiz_for_course($course_id);
        if ($quiz_id <= 0 || self::current_registration_has_passed_quiz($quiz_id)) {
            return $html;
        }
        if (strpos($html, 'tpma-quiz-completion-notice') !== false) {
            return $html;
        }

        if (!self::current_registration_can_access_course_quiz($course_id)) {
            $message = '隨堂測驗尚未開放。';
        } elseif (strpos($html, 'complete_course_btn') === false) {
            // Tutor strict mode has already rendered its warning. The gettext
            // filter above supplies the requested TPMA wording for quiz-only
            // courses, while preserving non-quiz Tutor warnings.
            return $html;
        } else {
            $message = '您必須通過隨堂測驗才能完成本課程。';
        }

        return $html . '<div class="tutor-alert tutor-warning tutor-mt-28 tpma-quiz-completion-notice" role="status"><div class="tutor-alert-text"><span class="tutor-alert-icon tutor-fs-4 tutor-icon-circle-info tutor-mr-12"></span><span>' . esc_html($message) . '</span></div></div>';
    }

    // ──────────────────────────────────────────────────────────
    // Course completion gate + certificate
    // ──────────────────────────────────────────────────────────

    /**
     * Filter: tutor_user_can_complete_course.
     * Returns WP_Error to block completion when the quiz has not been passed.
     *
     * @param bool|WP_Error $can_complete
     * @param int           $course_id
     * @param int           $user_id
     */
    public static function filter_course_completion($can_complete, $course_id, $user_id) {
        if (!self::is_active()) {
            return $can_complete;
        }

        global $wpdb;
        // Only gate TPMA-managed courses
        $is_tpma = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . TPMA_CR_DB::table('courses') . " WHERE tutor_course_id = %d LIMIT 1",
            (int)$course_id
        ));
        if (!$is_tpma) {
            return $can_complete;
        }

        $quiz_id = self::get_qualifying_quiz_for_course((int)$course_id);
        if ($quiz_id <= 0) {
            return $can_complete;
        }
        if (!self::current_registration_has_passed_quiz($quiz_id, (int)$user_id)) {
            return new WP_Error(
                'tpma_quiz_not_passed',
                '您尚未通過課程測驗，請先完成測驗並達到及格標準，再申請發放證書。'
            );
        }
        return new WP_Error(
            'tpma_certificate_pending',
            '您已完成本課程；證書功能尚未開放，請等待主辦單位後續通知。'
        );
    }

    /**
     * Check whether a user has at least one passing quiz attempt in a Tutor course.
     */
    private static function has_user_passed_quiz_in_course(int $user_id, int $tutor_course_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            return true; // Cannot verify — allow completion
        }

        // Prefer explicit is_pass column (Tutor Pro)
        $has_is_pass = !empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'is_pass'"));
        if ($has_is_pass) {
            return (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM {$table} WHERE user_id = %d AND course_id = %d AND is_pass = 1",
                $user_id, $tutor_course_id
            )) > 0;
        }

        // Fallback: calculate from earned/total marks against Tutor's pass setting
        $pass_pct = 0.0;
        if (function_exists('tutor_utils')) {
            $pass_pct = (float)tutor_utils()->get_option('pass_mark_percentage', 80);
        }
        if ($pass_pct <= 0) {
            $pass_pct = 80.0;
        }

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$table}
             WHERE  user_id = %d
             AND    course_id = %d
             AND    attempt_status = 'attempt_ended'
             AND    total_marks > 0
             AND    (earned_marks / total_marks * 100) >= %f",
            $user_id, $tutor_course_id, $pass_pct
        )) > 0;
    }

    /**
     * Hook: tutor_course_complete_after($course_id, $user_id).
     * Updates TPMA registration status to cert_ready, stores certificate hash,
     * and regenerates magic tokens. Certificate emails are sent manually from admin.
     */
    public static function on_course_completed(int $course_id, int $user_id): void {
        if (!self::is_active()) {
            return;
        }

        global $wpdb;
        $courses_table = TPMA_CR_DB::table('courses');
        $regs_table    = TPMA_CR_DB::table('regs');

        $tpma_course_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$courses_table} WHERE tutor_course_id = %d LIMIT 1",
            $course_id
        ));
        if (!$tpma_course_id) {
            return;
        }

        $selected_reg_id = class_exists('TPMA_Course_Access') ? TPMA_Course_Access::current_registration_id() : 0;
        $regs = $selected_reg_id > 0 ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$regs_table} WHERE id=%d AND wp_user_id=%d AND course_id=%d LIMIT 1",
            $selected_reg_id, $user_id, $tpma_course_id
        ), ARRAY_A) : array();

        if (empty($regs)) {
            return;
        }

        $cert_hash = self::get_tutor_certificate_hash($course_id, $user_id);

        foreach ($regs as $reg) {
            $reg_id = (int)$reg['id'];

            $updates = ['status' => 'cert_ready'];
            if ($cert_hash) {
                $updates['certificate_id'] = $cert_hash;
            }
            $wpdb->update($regs_table, $updates, ['id' => $reg_id], array_fill(0, count($updates), '%s'), ['%d']);

            self::regenerate_magic_urls_for_reg($reg_id);

        }
    }

    /**
     * Retrieve the Tutor-generated certificate identifier stored in wp_comments meta.
     */
    private static function get_tutor_certificate_hash(int $tutor_course_id, int $user_id): string {
        global $wpdb;

        $comment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments}
             WHERE  comment_post_ID = %d
             AND    user_id         = %d
             AND    comment_type    = 'course_completed'
             ORDER  BY comment_ID DESC
             LIMIT  1",
            $tutor_course_id, $user_id
        ));
        if (!$comment_id) {
            return '';
        }

        $key = get_comment_meta((int)$comment_id, 'tutor_certificate_has_image', true);
        if ($key && is_string($key)) {
            return $key;
        }

        // Fallback: Tutor stores a unique hash as comment_content
        $content = $wpdb->get_var($wpdb->prepare(
            "SELECT comment_content FROM {$wpdb->comments} WHERE comment_ID = %d",
            (int)$comment_id
        ));
        return $content ? (string)$content : '';
    }

    // ──────────────────────────────────────────────────────────
    // Mail context injection
    // ──────────────────────────────────────────────────────────

    /**
     * Filter: tpma_cr_mail_context.
     * Appends magic link URLs and Tutor-specific variables to the mail context.
     *
     * @param array          $context
     * @param WC_Order|null  $order
     * @param array|null     $learner
     */
    public static function inject_mail_context(array $context, $order, $learner): array {
        if (!self::is_active()) {
            return $context;
        }

        $reg_id = (int)($context['student_reg_id'] ?? 0);
        if (!$reg_id) {
            return $context;
        }

        // Use cached URLs if available (generated during on_order_processed)
        if (!isset(self::$token_url_cache[$reg_id])) {
            self::$token_url_cache[$reg_id] = self::regenerate_magic_urls_for_reg($reg_id);
        }
        $urls = self::$token_url_cache[$reg_id];

        $context['magic_link_portal']      = $urls['portal']      ?? ($urls['course'] ?? '');

        return $context;
    }

    // ──────────────────────────────────────────────────────────
    // Pre-class reminder cron
    // ──────────────────────────────────────────────────────────

    /**
     * Hook: tpma_cr_daily_cron.
     * Sends pre_class_reminder emails to learners whose class is N days away.
     */
    public static function send_pre_class_reminders(): void {
        if (!self::is_active() || !class_exists('TPMA_CR_Mail_Dispatcher')) {
            return;
        }

        global $wpdb;
        $order_ids = $wpdb->get_col("SELECT DISTINCT woocommerce_order_id FROM " . TPMA_CR_DB::table('regs') . " WHERE woocommerce_order_id IS NOT NULL AND COALESCE(status,'')<>'cancelled'");
        foreach ((array)$order_ids as $order_id) {
            if (class_exists('TPMA_Course_Access')) TPMA_Course_Access::maybe_send_access_event_for_order((int)$order_id);
        }
    }
}
