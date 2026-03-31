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
 *  - Certificate callback: update TPMA certificate_id + send email
 *  - Pre-class reminder: cron-based email N days before class
 *
 * Graceful degradation: all methods check is_active() and silently no-op
 * when Tutor LMS is not installed or integration is disabled.
 */
class TPMA_Tutor_Bridge {

    /** @var bool|null Cached detection result. */
    private static $tutor_active = null;

    /** @var array<int,array> Per-request magic URL cache keyed by reg_id. */
    private static $token_url_cache = [];

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

        // Course completion gate: require quiz pass
        add_filter('tutor_user_can_complete_course', [__CLASS__, 'filter_course_completion'], 10, 3);

        // Course completion → cert_ready status + certificate email
        add_action('tutor_course_complete_after', [__CLASS__, 'on_course_completed'], 20, 2);

        // Inject magic link variables into mail context
        add_filter('tpma_cr_mail_context', [__CLASS__, 'inject_mail_context'], 10, 3);

        // Pre-class reminders via existing daily cron hook
        add_action('tpma_cr_daily_cron', [__CLASS__, 'send_pre_class_reminders']);
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
    public static function sync_course(int $tpma_course_id): int {
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

        // Build post content: intro + outline section
        $post_content = '';
        if (trim($intro) !== '') {
            $post_content .= wp_kses_post($intro);
        }
        if (trim($outline) !== '') {
            if ($post_content !== '') {
                $post_content .= "\n\n";
            }
            $post_content .= '<h3>課程大綱</h3>' . wp_kses_post($outline);
        }

        if ($existing_id > 0 && get_post_type($existing_id) === $course_post_type) {
            // ── Update existing ──
            $result = wp_update_post([
                'ID'           => $existing_id,
                'post_title'   => $course_name,
                'post_content' => $post_content,
                'post_author'  => $post_author,
                'post_status'  => 'publish',
            ], true);

            if (is_wp_error($result)) {
                error_log('TPMA Tutor Bridge: wp_update_post failed for ID ' . $existing_id . ': ' . $result->get_error_message());
                return 0;
            }
            $tutor_course_id = $existing_id;
        } else {
            // ── Create new ──
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

            if (is_wp_error($tutor_course_id) || $tutor_course_id <= 0) {
                $msg = is_wp_error($tutor_course_id) ? $tutor_course_id->get_error_message() : 'unknown';
                error_log('TPMA Tutor Bridge: wp_insert_post failed: ' . $msg);
                return 0;
            }

            // Create default Topic + Lesson so instructor has a starting structure
            self::create_default_course_structure($tutor_course_id, $course_name, $intro, $outline);
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

    /**
     * Create a default Topic + Lesson structure for a brand-new Tutor course.
     */
    private static function create_default_course_structure(
        int $tutor_course_id,
        string $course_name,
        string $intro,
        string $outline
    ): void {
        $topic_id = wp_insert_post([
            'post_type'    => 'topics',
            'post_title'   => $course_name,
            'post_content' => wp_kses_post($outline),
            'post_parent'  => $tutor_course_id,
            'post_status'  => 'publish',
            'post_author'  => (int)get_post_field('post_author', $tutor_course_id),
            'menu_order'   => 0,
        ]);

        if (!$topic_id || is_wp_error($topic_id)) {
            return;
        }

        wp_insert_post([
            'post_type'    => 'tutor_lesson',
            'post_title'   => '課程內容：' . $course_name,
            'post_content' => wp_kses_post($intro ?: $course_name),
            'post_parent'  => $topic_id,
            'post_status'  => 'publish',
            'post_author'  => (int)get_post_field('post_author', $tutor_course_id),
            'menu_order'   => 0,
        ]);
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

        $uid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wp_user_id FROM {$tbl}
                 WHERE (lecturer_code = %s OR lecturers_code = %s)
                 AND wp_user_id IS NOT NULL AND wp_user_id > 0
                 LIMIT 1",
                $lecturer_code,
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
            self::generate_all_tokens_for_registration($reg_id, $wp_user_id, $tutor_course_id, $class_date);
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

        $types = ['course', 'quiz', 'certificate'];
        if (self::get_course_meet_link($tutor_course_id)) {
            $types[] = 'meet';
        }

        $urls = [];
        foreach ($types as $type) {
            $raw = self::create_token($reg_id, $wp_user_id, $tutor_course_id, $type, $expires_at);
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
        string $expires_at
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
        $target_url = self::build_target_url($target_type, $tutor_course_id);

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

        $user = get_user_by('id', (int)$row->wp_user_id);
        if (!$user) {
            wp_die(esc_html__('找不到對應的使用者帳號。', 'tpma-cr'), 404);
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

        if (!$target_url || ($target_host && $target_host !== $site_host)) {
            $target_url = home_url('/');
        }

        wp_safe_redirect(esc_url_raw($target_url));
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // Quiz result sync
    // ──────────────────────────────────────────────────────────

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

        $wp_user_id      = (int)($attempt['user_id'] ?? 0);
        $tutor_course_id = (int)($attempt['course_id'] ?? 0);
        $earned_marks    = (float)($attempt['earned_marks'] ?? 0);
        $total_marks     = (float)($attempt['total_marks'] ?? 0);

        if (!$wp_user_id) {
            return;
        }

        $score_pct = ($total_marks > 0) ? round(($earned_marks / $total_marks) * 100, 1) : 0.0;
        $score_str = $score_pct . '%';

        $courses_table = TPMA_CR_DB::table('courses');
        $regs_table    = TPMA_CR_DB::table('regs');

        // Find TPMA course ID via Tutor course ID
        $tpma_course_id = $tutor_course_id
            ? (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$courses_table} WHERE tutor_course_id = %d LIMIT 1",
                $tutor_course_id
            ))
            : 0;

        if ($tpma_course_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$regs_table}
                 SET    test_score = %s
                 WHERE  wp_user_id = %d AND course_id = %d",
                $score_str, $wp_user_id, $tpma_course_id
            ));
        } else {
            // Fall back: update by user only (last N regs)
            $wpdb->query($wpdb->prepare(
                "UPDATE {$regs_table}
                 SET    test_score = %s
                 WHERE  wp_user_id = %d
                 ORDER  BY id DESC
                 LIMIT  5",
                $score_str, $wp_user_id
            ));
        }
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

        if (!self::has_user_passed_quiz_in_course((int)$user_id, (int)$course_id)) {
            return new WP_Error(
                'tpma_quiz_not_passed',
                '您尚未通過課程測驗，請先完成測驗並達到及格標準，再申請發放證書。'
            );
        }

        return $can_complete;
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
     * regenerates magic tokens, and triggers the certificate_ready email.
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

        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$regs_table}
             WHERE  wp_user_id = %d AND course_id = %d
             ORDER  BY id DESC
             LIMIT  10",
            $user_id, $tpma_course_id
        ), ARRAY_A);

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

            // Refresh magic tokens so certificate link is up-to-date
            $reg_wp_user  = (int)$reg['wp_user_id'];
            $reg_date     = (string)$reg['class_date'];
            $extra_days   = max(1, (int)get_option('tpma_cr_magic_link_extra_days', 15));
            $expires_at   = $reg_date && strtotime($reg_date)
                ? date('Y-m-d 23:59:59', strtotime("+{$extra_days} days", strtotime($reg_date)))
                : date('Y-m-d 23:59:59', strtotime("+{$extra_days} days"));

            self::create_token($reg_id, $reg_wp_user, $course_id, 'certificate', $expires_at);

            // Send certificate_ready email
            if (class_exists('TPMA_CR_Mail_Dispatcher')) {
                $order = ($reg['woocommerce_order_id'] > 0 && function_exists('wc_get_order'))
                    ? wc_get_order((int)$reg['woocommerce_order_id'])
                    : null;
                if ($order) {
                    TPMA_CR_Mail_Dispatcher::send_certificate_email($order, $reg);
                }
            }
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

        $context['magic_link_course']      = $urls['course']      ?? '';
        $context['magic_link_quiz']        = $urls['quiz']        ?? '';
        $context['magic_link_certificate'] = $urls['certificate'] ?? '';
        $context['magic_link_meet']        = $urls['meet']        ?? '';

        // Extra Tutor context
        $tpma_course_id = (int)($context['course_id'] ?? 0);
        if ($tpma_course_id) {
            $tutor_course_id = self::get_tutor_course_id($tpma_course_id);
            if ($tutor_course_id) {
                $context['tutor_course_url'] = get_permalink($tutor_course_id) ?: '';
                $context['google_meet_url']  = self::get_course_meet_link($tutor_course_id);
            }
        }

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

        $days_before = max(1, (int)apply_filters('tpma_cr_reminder_days_before', 3));
        $target_date = date('Y-m-d', strtotime("+{$days_before} days"));

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$regs_table}
             WHERE  class_date     = %s
             AND    status        NOT IN ('cancelled','refunded')
             AND    payment_status IN ('processing','completed')",
            $target_date
        ), ARRAY_A);

        foreach ((array)$regs as $reg) {
            $woo_order_id = (int)$reg['woocommerce_order_id'];
            if (!$woo_order_id || !function_exists('wc_get_order')) {
                continue;
            }
            $order = wc_get_order($woo_order_id);
            if ($order) {
                TPMA_CR_Mail_Dispatcher::send_reminder_email($order, $reg);
            }
        }
    }
}
