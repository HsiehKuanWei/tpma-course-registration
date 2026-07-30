<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Central entitlement policy and order-level learner portal. */
class TPMA_Course_Access {
    const COOKIE = 'tpma_portal_session';
    const SESSION_TTL = 43200;
    const SESSION_MIN_TTL = 28800;
    const SESSION_EXTRA_TTL = 7200;

    public static function init(): void {
        add_action('init', array(__CLASS__, 'handle_portal'), 98);
        add_action('wp_logout', array(__CLASS__, 'clear_portal_selection_on_logout'), 10, 1);
        add_action('wp_footer', array(__CLASS__, 'render_identity_bar'));
        add_action('tutor_quiz/start/after', array(__CLASS__, 'map_quiz_attempt'), 10, 3);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'maybe_send_access_event_for_order'), 20, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_send_access_event_for_order'), 20, 1);
        add_action('admin_post_tpma_material_download', array(__CLASS__, 'download_material'));
        add_action('save_post_tutor_lesson', array(__CLASS__, 'protect_lesson_materials'), 40, 3);
        add_filter('tutor/posts/attachments', array(__CLASS__, 'filter_attachment_url'));
        add_action('admin_init', array(__CLASS__, 'migrate_existing_materials_once'));
    }

    public static function payment_is_eligible(string $status, bool $is_postpay): bool {
        $status = strtolower(preg_replace('/^wc-/', '', $status));
        if (in_array($status, array('cancelled', 'refunded', 'failed', 'trash'), true)) return false;
        return in_array($status, array('processing', 'completed'), true) || ($status === 'on-hold' && $is_postpay);
    }

    /** Earliest time a recorded learner may receive or use course access. */
    public static function recorded_access_starts_at(array $session, int $days_before = 7): int {
        $session_start = strtotime((string)($session['session_datetime'] ?? ''));
        $recording_from = strtotime((string)($session['recording_available_from'] ?? ''));
        if (!$session_start || !$recording_from) return 0;

        $live_access_start = $session_start - max(1, $days_before) * DAY_IN_SECONDS;
        return min($live_access_start, $recording_from);
    }

    public static function resource_window_allows(array $session, string $access_mode, string $resource, string $now = '', int $days_before = 7, int $days_after = 15): bool {
        $mode = sanitize_key($access_mode);
        $resource = sanitize_key($resource);
        $delivery = sanitize_key((string)($session['delivery_mode'] ?? 'live'));
        if (!in_array($mode, array('live', 'recorded'), true)) return false;
        if ($delivery !== 'hybrid' && $mode !== $delivery) return false;
        if ($mode === 'live' && $resource === 'recording') return false;
        $now_ts = strtotime($now !== '' ? $now : current_time('mysql'));
        if (!$now_ts) return false;

        $start = strtotime((string)($session['session_datetime'] ?? ''));
        if (!$start) return false;
        $end = $start + max(1, (int)($session['duration_minutes'] ?? 180)) * MINUTE_IN_SECONDS;
        // Meet 是場次點名入口，錄播與混合場次也必須沿用直播時間窗。
        if ($resource === 'meet') return $now_ts >= $start - max(1, $days_before) * DAY_IN_SECONDS && $now_ts <= $end;

        if ($mode === 'recorded') {
            $from = self::recorded_access_starts_at($session, $days_before);
            $until = strtotime((string)($session['recording_available_until'] ?? ''));
            return $from && $until && $now_ts >= $from && $now_ts <= $until;
        }

        if ($resource === 'quiz') return $now_ts >= $end - (30 * MINUTE_IN_SECONDS) && $now_ts <= $end + max(1, $days_after) * DAY_IN_SECONDS;
        return $now_ts >= $start - max(1, $days_before) * DAY_IN_SECONDS && $now_ts <= $end + max(1, $days_after) * DAY_IN_SECONDS;
    }

    public static function registration_session_has_ended(array $row, string $now = ''): bool {
        $start = strtotime((string)($row['session_datetime'] ?? ''));
        if (!$start) {
            return false;
        }
        $duration = max(1, (int)($row['duration_minutes'] ?? 180));
        $now_ts = strtotime($now !== '' ? $now : current_time('mysql'));
        if (!$now_ts) {
            return false;
        }
        return $now_ts > ($start + $duration * MINUTE_IN_SECONDS);
    }

    public static function evaluate_registration(int $registration_id, string $resource = 'course', string $now = '', bool $ignore_time_window = false): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, s.delivery_mode, s.session_datetime, s.recording_available_from, s.recording_available_until,
                    c.duration_minutes, c.tutor_course_id
             FROM " . TPMA_CR_DB::table('regs') . " r
             JOIN " . TPMA_CR_DB::table('sessions') . " s ON s.id = r.session_id
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = r.course_id
             WHERE r.id = %d LIMIT 1", $registration_id
        ), ARRAY_A);
        if (!$row) return array('allowed' => false, 'reason' => 'registration_not_found');
        if ((string)$row['status'] === 'cancelled') return array('allowed' => false, 'reason' => 'registration_cancelled');
        $order = !empty($row['woocommerce_order_id']) && function_exists('wc_get_order') ? wc_get_order((int)$row['woocommerce_order_id']) : null;
        $payment = $order ? $order->get_status() : (string)$row['payment_status'];
        $postpay = $order ? $order->get_meta('_tpma_post_course_payment', true) === 'yes' : (string)$row['status'] === 'postpay';
        if (!self::payment_is_eligible($payment, $postpay)) return array('allowed' => false, 'reason' => 'payment_required');
        $mode = sanitize_key((string)($row['access_mode'] ?: 'live'));
        $before = max(1, (int)get_option('tpma_cr_live_access_days_before', 7));
        $after = max(1, (int)get_option('tpma_cr_live_access_days_after', 15));
        if (!$ignore_time_window && !self::resource_window_allows($row, $mode, $resource, $now, $before, $after)) {
            return array('allowed' => false, 'reason' => 'outside_access_window', 'mode' => $mode);
        }
        return array('allowed' => true, 'reason' => '', 'mode' => $mode, 'registration' => $row);
    }

    public static function current_registration_id(): int {
        $session = self::read_portal_session();
        return (int)($session['registration_id'] ?? 0);
    }

    /** A portal link must never authenticate a site manager or course author. */
    public static function learner_login_error(int $user_id, int $tutor_course_id = 0): string {
        $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
        if (!$user) return 'learner_account_missing';
        if (user_can($user->ID, 'manage_options')) return 'learner_account_privileged';
        if ($tutor_course_id > 0 && (int)get_post_field('post_author', $tutor_course_id) === (int)$user->ID) {
            return 'learner_account_course_author';
        }
        return '';
    }

    public static function learner_login_is_safe(int $user_id, int $tutor_course_id = 0): bool {
        return self::learner_login_error($user_id, $tutor_course_id) === '';
    }

    /** A TPMA learner portal may only authenticate the dedicated account created for that registration. */
    public static function learner_binding_error(array $registration): string {
        if (empty($registration['is_virtual_user'])) {
            return 'learner_account_not_isolated';
        }
        return self::learner_login_error(
            (int) ($registration['wp_user_id'] ?? 0),
            (int) ($registration['tutor_course_id'] ?? 0)
        );
    }

    public static function current_user_can_resource(string $resource, int $session_id = 0): bool {
        $reg_id = self::current_registration_id();
        if ($reg_id > 0) {
            $result = self::evaluate_registration($reg_id, $resource);
            if (empty($result['allowed'])) return false;
            $registration = (array) ($result['registration'] ?? array());
            if (self::learner_binding_error($registration) !== '') return false;
            if (get_current_user_id() !== (int) ($registration['wp_user_id'] ?? 0)) return false;
            return $session_id <= 0 || (int)($result['registration']['session_id'] ?? 0) === $session_id;
        }
        return false;
    }

    public static function protect_lesson_materials($post_id, $post, $update): void {
        if (wp_is_post_revision($post_id) || get_post_type($post_id) !== 'tutor_lesson') return;
        $topic_id = (int)get_post_field('post_parent', $post_id);
        $course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
        if ($course_id <= 0 || !(int)get_post_meta($course_id, '_tpma_course_id', true)) return;
        $ids = array_map('absint', (array)get_post_meta($post_id, '_tutor_attachments', true));
        $upload = wp_upload_dir();
        $root = trailingslashit($upload['basedir']) . 'tpma-protected';
        if (!wp_mkdir_p($root)) return;
        if (!file_exists($root . '/.htaccess')) file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
        if (!file_exists($root . '/index.php')) file_put_contents($root . '/index.php', "<?php exit;\n");
        foreach ($ids as $attachment_id) {
            $file = get_attached_file($attachment_id);
            if (!$file || !is_file($file) || strpos(wp_normalize_path($file), '/tpma-protected/') !== false) continue;
            $dir = $root . '/' . $attachment_id;
            if (!wp_mkdir_p($dir)) continue;
            $target = $dir . '/' . wp_basename($file);
            if (!@rename($file, $target)) continue;
            update_attached_file($attachment_id, $target);
            update_post_meta($attachment_id, '_tpma_protected_material', 1);
            update_post_meta($attachment_id, '_tpma_lesson_id', (int)$post_id);
        }
    }

    public static function migrate_existing_materials_once(): void {
        if ((bool)get_option('tpma_cr_materials_protected_v1', false)) return;
        global $wpdb;
        $course_ids = $wpdb->get_col("SELECT tutor_course_id FROM " . TPMA_CR_DB::table('courses') . " WHERE tutor_course_id IS NOT NULL AND tutor_course_id>0");
        $all_protected = true;
        foreach ((array)$course_ids as $course_id) {
            $topic_ids = get_posts(array('post_type'=>'topics','post_parent'=>(int)$course_id,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
            foreach ((array)$topic_ids as $topic_id) {
                $lesson_ids = get_posts(array('post_type'=>'tutor_lesson','post_parent'=>(int)$topic_id,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
                foreach ((array)$lesson_ids as $lesson_id) {
                    self::protect_lesson_materials((int)$lesson_id, get_post($lesson_id), true);
                    foreach ((array)get_post_meta((int)$lesson_id, '_tutor_attachments', true) as $attachment_id) {
                        if (!(int)get_post_meta((int)$attachment_id, '_tpma_protected_material', true)) $all_protected = false;
                    }
                }
            }
        }
        if ($all_protected) update_option('tpma_cr_materials_protected_v1', 1, false);
    }

    public static function filter_attachment_url($data) {
        $attachment_id = (int)($data['id'] ?? $data['ID'] ?? 0);
        if ($attachment_id <= 0 || !(int)get_post_meta($attachment_id, '_tpma_protected_material', true)) return $data;
        $lesson_id = (int)get_post_meta($attachment_id, '_tpma_lesson_id', true);
        $data['url'] = wp_nonce_url(admin_url('admin-post.php?action=tpma_material_download&attachment_id=' . $attachment_id . '&lesson_id=' . $lesson_id), 'tpma_material_' . $attachment_id);
        return $data;
    }

    public static function download_material(): void {
        $attachment_id = absint($_GET['attachment_id'] ?? 0);
        $lesson_id = absint($_GET['lesson_id'] ?? 0);
        if (!$attachment_id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'tpma_material_' . $attachment_id)) {
            wp_die('下載連結無效。', '無法下載講義', array('response'=>403));
        }
        $topic_id = $lesson_id > 0 ? (int)get_post_field('post_parent', $lesson_id) : 0;
        $session_id = $topic_id > 0 ? (int)get_post_meta($topic_id, '_tpma_session_id', true) : 0;
        if (!self::current_user_can_resource('material', $session_id)) wp_die('目前學員沒有此講義的下載權限。', '無法下載講義', array('response'=>403));
        $file = get_attached_file($attachment_id);
        if (!$file || !is_file($file) || !(int)get_post_meta($attachment_id, '_tpma_protected_material', true)) wp_die('找不到講義檔案。', '找不到檔案', array('response'=>404));
        nocache_headers();
        header('Content-Type: ' . (get_post_mime_type($attachment_id) ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode(wp_basename($file)) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public static function get_or_create_portal_url(int $order_id, bool $regenerate = false): string {
        if ($order_id <= 0) return '';
        global $wpdb;
        $table = TPMA_CR_DB::table('portal_tokens');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", $order_id), ARRAY_A);
        if ($row && !$regenerate && strtotime((string)$row['expires_at']) >= time()) {
            $raw = self::decrypt((string)$row['encrypted_token']);
            if ($raw !== '') return add_query_arg('tpma_portal_token', $raw, home_url('/'));
        }
        if ($row) $wpdb->update($table, array('revoked_at' => current_time('mysql')), array('id' => (int)$row['id']), array('%s'), array('%d'));
        $raw = bin2hex(random_bytes(32));
        $expires = self::order_access_expiry($order_id);
        $wpdb->insert($table, array(
            'order_id' => $order_id,
            'token_hash' => hash('sha256', $raw),
            'encrypted_token' => self::encrypt($raw),
            'expires_at' => $expires,
            'created_at' => current_time('mysql'),
        ), array('%d','%s','%s','%s','%s'));
        return add_query_arg('tpma_portal_token', $raw, home_url('/'));
    }

    private static function order_access_expiry(int $order_id): string {
        global $wpdb;
        $until = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CASE WHEN COALESCE(r.access_mode,'live') = 'recorded' THEN s.recording_available_until
                    ELSE DATE_ADD(DATE_ADD(s.session_datetime, INTERVAL c.duration_minutes MINUTE), INTERVAL %d DAY) END)
             FROM " . TPMA_CR_DB::table('regs') . " r
             JOIN " . TPMA_CR_DB::table('sessions') . " s ON s.id=r.session_id
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id WHERE r.woocommerce_order_id=%d",
            max(1, (int)get_option('tpma_cr_live_access_days_after', 15)), $order_id
        ));
        return $until && strtotime($until) > time() ? (string)$until : date('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
    }

    public static function revoke_order(int $order_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . TPMA_CR_DB::table('portal_tokens') . " SET revoked_at=%s WHERE order_id=%d AND revoked_at IS NULL", current_time('mysql'), $order_id));
    }

    public static function maybe_send_access_event_for_order(int $order_id): void {
        if ($order_id <= 0 || !class_exists('TPMA_CR_Mail_Dispatcher') || !function_exists('wc_get_order')) return;
        $auto_enabled = class_exists('TPMA_CR_Settings')
            ? TPMA_CR_Settings::is_auto_course_mail_enabled()
            : (bool)(int)get_option('tpma_cr_auto_course_mail_enabled', 0);
        if (!$auto_enabled) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        global $wpdb;
        $regs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TPMA_CR_DB::table('regs') . " WHERE woocommerce_order_id=%d AND COALESCE(status,'')<>'cancelled' ORDER BY id", $order_id), ARRAY_A);
        $groups = array();
        foreach ((array)$regs as $reg) {
            $result = self::evaluate_registration((int)$reg['id'], 'course');
            if (empty($result['allowed'])) continue;
            if (self::registration_session_has_ended((array)($result['registration'] ?? array()))) continue;
            $event = 'course_access';
            $key = $event . ':' . (int)$reg['session_id'] . ':' . sanitize_key((string)($result['mode'] ?? 'live'));
            if (!isset($groups[$key])) $groups[$key] = array('event' => $event, 'regs' => array());
            $groups[$key]['regs'][] = $reg;
        }
        foreach ($groups as $group) {
            TPMA_CR_Mail_Dispatcher::send_course_access_event_for_regs($group['event'], $order, $group['regs']);
        }
    }

    public static function handle_portal(): void {
        if (!empty($_GET['tpma_portal_token'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
            $rate_key = 'tpma_portal_rate_' . md5($ip);
            $attempts = (int)get_transient($rate_key);
            if ($attempts >= 10) wp_die('嘗試次數過多，請稍後再試。', '無法進入課程', array('response'=>429));
            set_transient($rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);
            $raw = sanitize_text_field(wp_unslash($_GET['tpma_portal_token']));
            $token = self::validate_token($raw);
            if (!$token) wp_die('此課程入口無效或已過期。', '無法進入課程', array('response' => 403));
            delete_transient($rate_key);
            self::start_portal_session((int)$token['id'], (int)$token['order_id']);
            wp_clear_auth_cookie();
            wp_set_current_user(0);
            wp_safe_redirect(add_query_arg('tpma_portal', '1', home_url('/')));
            exit;
        }
        if (empty($_GET['tpma_portal'])) return;
        $session = self::read_portal_session();
        if (!$session) wp_die('課程入口工作階段已過期，請重新開啟通知信中的連結。', '工作階段已過期', array('response' => 403));
        if (!empty($_GET['switch']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $switch_nonce = sanitize_text_field(wp_unslash($_GET['tpma_switch_nonce'] ?? ''));
            if ($switch_nonce === '' || empty($session['switch_nonce']) || !hash_equals((string)$session['switch_nonce'], $switch_nonce)) {
                wp_die('切換學員連結已失效，請重新整理課程頁後再試。', '無法切換學員', array('response' => 403));
            }
            $session['registration_id'] = 0;
            self::refresh_portal_session(self::cookie_value(), $session);
            wp_clear_auth_cookie();
            wp_set_current_user(0);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tpma_registration_id'])) {
            $select_nonce = sanitize_text_field(wp_unslash($_POST['tpma_select_nonce'] ?? ''));
            if ($select_nonce === '' || empty($session['select_nonce']) || !hash_equals((string)$session['select_nonce'], $select_nonce)) {
                wp_die('選擇學員連結已失效，請重新整理頁面後再試。', '無法選擇學員', array('response' => 403));
            }
            $reg_id = absint(wp_unslash($_POST['tpma_registration_id']));
            $candidate = self::candidate_for_order((int)$session['order_id'], $reg_id);
            if (!$candidate) {
                self::audit((int)$session['order_id'], $reg_id, 'selection_denied');
                wp_die('所選學員目前沒有課程權限。', '無法選擇學員', array('response' => 403));
            }
            $session['registration_id'] = $reg_id;
            self::refresh_portal_session(self::cookie_value(), $session);
            $user = get_user_by('id', (int)$candidate['wp_user_id']);
            if (!$user || self::learner_binding_error($candidate) !== '') {
                $session['registration_id'] = 0;
                self::refresh_portal_session(self::cookie_value(), $session);
                wp_clear_auth_cookie();
                wp_set_current_user(0);
                self::audit((int)$session['order_id'], $reg_id, 'unsafe_account_binding');
                wp_die('此學員的課程帳號綁定異常，已拒絕登入。請聯絡主辦單位修正學員帳號後再試。', '無法進入課程', array('response' => 403));
            }
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            do_action('wp_login', $user->user_login, $user);
            self::audit((int)$session['order_id'], $reg_id, 'selected');
            $course_url = !empty($candidate['tutor_course_id']) ? get_permalink((int)$candidate['tutor_course_id']) : home_url('/');
            wp_safe_redirect($course_url ?: home_url('/'));
            exit;
        }
        self::render_selector((int)$session['order_id'], $session);
        exit;
    }

    private static function validate_token(string $raw): ?array {
        if (strlen($raw) !== 64 || !ctype_xdigit($raw)) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TPMA_CR_DB::table('portal_tokens') . " WHERE token_hash=%s AND revoked_at IS NULL LIMIT 1", hash('sha256', $raw)), ARRAY_A);
        return $row && strtotime((string)$row['expires_at']) >= time() ? $row : null;
    }

    private static function candidate_for_order(int $order_id, int $reg_id): ?array {
        foreach (self::get_candidates($order_id) as $candidate) if ((int)$candidate['id'] === $reg_id && !empty($candidate['access_allowed'])) return $candidate;
        return null;
    }

    private static function get_candidates(int $order_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, c.course_name, c.tutor_course_id FROM " . TPMA_CR_DB::table('regs') . " r
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id
             WHERE r.woocommerce_order_id=%d AND COALESCE(r.status,'')<>'cancelled' ORDER BY r.student_name,r.id", $order_id
        ), ARRAY_A);
        return array_values(array_map(static function($row) {
            $result = self::evaluate_registration((int)$row['id'], 'course');
            $row['account_error'] = self::learner_binding_error($row);
            $row['access_allowed'] = !empty($result['allowed']) && $row['account_error'] === '';
            $row['access_reason'] = $row['account_error'] !== '' ? $row['account_error'] : (string)($result['reason'] ?? '');
            return $row;
        }, (array)$rows));
    }

    private static function render_selector(int $order_id, array $session = array()): void {
        $candidates = self::get_candidates($order_id);
        status_header(200);
        nocache_headers();
        echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>選擇學員</title>';
        echo '<style>body{margin:0;background:#f3f7f6;color:#17342f;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.tpma-portal{max-width:680px;margin:8vh auto;padding:24px}.tpma-card{background:#fff;border:1px solid #d7e4e1;border-radius:16px;padding:28px;box-shadow:0 12px 36px rgba(23,52,47,.1)}h1{font-size:26px;margin:0 0 8px}p{color:#526b66}.person{display:block;border:1px solid #cbded9;border-radius:10px;padding:14px;margin:10px 0;cursor:pointer}.person:has(input:checked){border-color:#087f6d;background:#edf9f6}.person input{margin-right:10px}.meta{font-size:13px;color:#637b76;margin-left:26px}.btn{width:100%;border:0;border-radius:10px;background:#087f6d;color:#fff;font-weight:700;padding:13px;margin-top:14px;cursor:pointer}.btn:focus,.person:focus-within{outline:3px solid #91d9cc;outline-offset:2px}@media(max-width:720px){.tpma-portal{margin:0;padding:16px}.tpma-card{padding:20px}}</style></head><body><main class="tpma-portal"><section class="tpma-card"><h1>請選擇使用者</h1><p>此入口包含本訂單內的課程學員。請選擇目前要進入課程或應考的人員。</p>';
        if (!$candidates) {
            echo '<p role="alert">目前沒有已開放課程權限的學員。</p>';
        } else {
            $session = self::ensure_portal_switch_nonce($session);
            echo '<form method="post" action="' . esc_url(add_query_arg('tpma_portal', '1', home_url('/'))) . '">';
            echo '<input type="hidden" name="tpma_select_nonce" value="' . esc_attr((string)($session['select_nonce'] ?? '')) . '">';
            $first_enabled = true;
            foreach ($candidates as $index => $row) {
                $meta = implode('｜', array_filter(array((string)($row['company_name'] ?? ''), (string)($row['department'] ?? ''), (string)($row['course_name'] ?? ''))));
                $enabled = !empty($row['access_allowed']);
                echo '<label class="person"><input required type="radio" name="tpma_registration_id" value="' . esc_attr((string)$row['id']) . '"' . ($enabled && $first_enabled ? ' checked' : '') . ($enabled ? '' : ' disabled') . '><strong>' . esc_html((string)$row['student_name']) . '</strong>';
                if ($enabled && $first_enabled) $first_enabled = false;
                if ($meta !== '') echo '<div class="meta">' . esc_html($meta) . '</div>';
                if (!$enabled) echo '<div class="meta">' . esc_html(self::reason_label((string)$row['access_reason'])) . '</div>';
                echo '</label>';
            }
            echo '<button class="btn" type="submit"' . ($first_enabled ? ' disabled' : '') . '>進入課程</button></form>';
        }
        echo '</section></main></body></html>';
    }

    private static function reason_label(string $reason): string {
        $labels = array(
            'payment_required'=>'付款成立或標記課後付款後才可進入。',
            'outside_access_window'=>'目前不在課程開放期間。',
            'registration_cancelled'=>'此報名已取消。',
            'registration_not_found'=>'找不到報名資料。',
            'learner_account_missing'=>'找不到對應的學員帳號，請聯絡主辦單位修正。',
            'learner_account_not_isolated'=>'此報名尚未綁定專用學員帳號，為避免承辦或付款帳號代為作答，已拒絕登入。',
            'learner_account_privileged'=>'此報名誤綁網站管理員帳號，已拒絕登入。',
            'learner_account_course_author'=>'此報名誤綁課程管理帳號，已拒絕登入。',
        );
        return $labels[$reason] ?? '目前無法進入課程。';
    }

    private static function start_portal_session(int $token_id, int $order_id): void {
        $id = bin2hex(random_bytes(24));
        $session = array('token_id'=>$token_id,'order_id'=>$order_id,'registration_id'=>0);
        self::refresh_portal_session($id, $session);
        $_COOKIE[self::COOKIE] = $id;
    }

    private static function cookie_value(): string {
        return isset($_COOKIE[self::COOKIE]) ? preg_replace('/[^a-f0-9]/', '', (string)wp_unslash($_COOKIE[self::COOKIE])) : '';
    }

    private static function read_portal_session(): ?array {
        $id = self::cookie_value();
        if ($id === '') return null;
        $session = get_transient('tpma_portal_' . $id);
        if (!is_array($session)) return null;
        global $wpdb;
        $valid = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . TPMA_CR_DB::table('portal_tokens') . " WHERE id=%d AND revoked_at IS NULL AND expires_at >= %s", (int)$session['token_id'], current_time('mysql')));
        if (!$valid) return null;
        $session = self::ensure_portal_switch_nonce($session);
        self::refresh_portal_session($id, $session);
        self::ensure_portal_user_session($session);
        return $session;
    }

    private static function ensure_portal_switch_nonce(array $session): array {
        $switch_nonce = (string)($session['switch_nonce'] ?? '');
        if (!preg_match('/^[a-f0-9]{32,64}$/', $switch_nonce)) {
            $session['switch_nonce'] = bin2hex(random_bytes(24));
        }
        $select_nonce = (string)($session['select_nonce'] ?? '');
        if (!preg_match('/^[a-f0-9]{32,64}$/', $select_nonce)) {
            $session['select_nonce'] = bin2hex(random_bytes(24));
        }
        return $session;
    }

    private static function portal_session_ttl(array $session = array()): int {
        $ttl = max(self::SESSION_TTL, self::SESSION_MIN_TTL);
        $reg_id = (int)($session['registration_id'] ?? 0);
        if ($reg_id > 0) {
            global $wpdb;
            $minutes = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT c.duration_minutes FROM " . TPMA_CR_DB::table('regs') . " r
                 JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id
                 WHERE r.id=%d LIMIT 1",
                $reg_id
            ));
            if ($minutes > 0) {
                $ttl = max($ttl, $minutes * MINUTE_IN_SECONDS + self::SESSION_EXTRA_TTL);
            }
        }
        return $ttl;
    }

    private static function refresh_portal_session(string $id, array $session): void {
        if ($id === '') return;
        $session = self::ensure_portal_switch_nonce($session);
        $ttl = self::portal_session_ttl($session);
        set_transient('tpma_portal_' . $id, $session, $ttl);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $id, array(
                'expires'  => time() + $ttl,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
        $_COOKIE[self::COOKIE] = $id;
    }

    /** Prevent a normal WordPress logout from being immediately undone by portal auto-login. */
    public static function clear_portal_selection_on_logout(int $user_id = 0): void {
        $id = self::cookie_value();
        if ($id === '') return;

        $session = get_transient('tpma_portal_' . $id);
        if (!is_array($session)) return;

        $registration_id = (int)($session['registration_id'] ?? 0);
        if ($registration_id <= 0) return;

        $session['registration_id'] = 0;
        self::refresh_portal_session($id, $session);
        self::audit((int)($session['order_id'] ?? 0), $registration_id, 'logged_out');
    }

    private static function ensure_portal_user_session(array $session): void {
        $reg_id = (int)($session['registration_id'] ?? 0);
        $order_id = (int)($session['order_id'] ?? 0);
        if ($reg_id <= 0 || $order_id <= 0) return;
        $candidate = self::candidate_for_order($order_id, $reg_id);
        if (!$candidate || empty($candidate['wp_user_id']) || self::learner_binding_error($candidate) !== '') {
            wp_clear_auth_cookie();
            wp_set_current_user(0);
            return;
        }
        $user_id = (int)$candidate['wp_user_id'];
        if (get_current_user_id() === $user_id) return;
        $user = get_user_by('id', $user_id);
        if (!$user) return;
        wp_set_current_user($user_id);
        if (!headers_sent()) {
            wp_set_auth_cookie($user_id, true);
        }
    }

    /** Return active registrations that would not authenticate a dedicated safe learner account. */
    public static function get_unsafe_learner_bindings(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT r.id, r.reg_no, r.student_name, r.wp_user_id, r.tutor_enrolled_id, r.is_virtual_user,
                    c.course_name, c.tutor_course_id
             FROM " . TPMA_CR_DB::table('regs') . " r
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id = r.course_id
             WHERE r.wp_user_id IS NOT NULL AND r.wp_user_id > 0
               AND COALESCE(r.status, '') NOT IN ('cancelled', 'refunded')
               AND COALESCE(r.payment_status, '') NOT IN ('cancelled', 'wc-cancelled', 'refunded')
             ORDER BY r.id DESC",
            ARRAY_A
        );
        $unsafe = array();
        foreach ((array)$rows as $row) {
            $reason = self::learner_binding_error($row);
            if ($reason === '') continue;
            $user = get_user_by('id', (int)$row['wp_user_id']);
            $row['account_error'] = $reason;
            $row['account_login'] = $user ? (string)$user->user_login : '';
            $row['account_name'] = $user ? (string)$user->display_name : '';
            $unsafe[] = $row;
        }
        return $unsafe;
    }

    /** Replace one unsafe binding with a dedicated virtual learner and reconcile Tutor enrollment. */
    public static function repair_unsafe_learner_binding(int $registration_id) {
        if ($registration_id <= 0 || !class_exists('TPMA_CR_Woo_Shared')) {
            return new WP_Error('invalid_registration', '無法修正此學員帳號綁定。');
        }
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, c.tutor_course_id FROM {$regs_table} r
             JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id WHERE r.id=%d LIMIT 1",
            $registration_id
        ), ARRAY_A);
        if (!$row) return new WP_Error('registration_not_found', '找不到報名資料。');
        $old_user_id = (int)$row['wp_user_id'];
        $tutor_course_id = (int)$row['tutor_course_id'];
        if (self::learner_binding_error($row) === '') {
            return new WP_Error('binding_not_unsafe', '此報名目前已綁定安全的專用學員帳號，不需修正。');
        }
        $new_user_id = (int)TPMA_CR_Woo_Shared::ensure_virtual_user((string)$row['reg_no'], (string)$row['student_name'], true);
        if ($new_user_id <= 0 || self::learner_binding_error(array('wp_user_id' => $new_user_id, 'is_virtual_user' => 1, 'tutor_course_id' => $tutor_course_id)) !== '') {
            return new WP_Error('learner_account_create_failed', '無法建立安全的專用學員帳號。');
        }
        $updated = $wpdb->update($regs_table, array('wp_user_id'=>$new_user_id, 'is_virtual_user'=>1), array('id'=>$registration_id), array('%d','%d'), array('%d'));
        if ($updated === false) return new WP_Error('binding_update_failed', '學員帳號綁定更新失敗。');

        $new_enrollment_id = 0;
        if ($tutor_course_id > 0 && class_exists('TPMA_Tutor_Bridge')) {
            $new_enrollment_id = TPMA_Tutor_Bridge::enroll_learner($new_user_id, $tutor_course_id, $registration_id);
            if ($new_enrollment_id <= 0) {
                $wpdb->update($regs_table, array('wp_user_id'=>$old_user_id, 'is_virtual_user'=>(int)$row['is_virtual_user']), array('id'=>$registration_id), array('%d','%d'), array('%d'));
                return new WP_Error('tutor_enrollment_failed', '專用學員帳號已建立，但 Tutor 權限建立失敗，未變更原綁定。');
            }
        }

        $remaining = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$regs_table} r JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id
             WHERE r.wp_user_id=%d AND c.tutor_course_id=%d AND r.id<>%d
               AND COALESCE(r.status, '') NOT IN ('cancelled', 'refunded')
               AND COALESCE(r.payment_status, '') NOT IN ('cancelled', 'wc-cancelled', 'refunded')",
            $old_user_id, $tutor_course_id, $registration_id
        ));
        if ($remaining === 0 && (int)$row['tutor_enrolled_id'] > 0 && get_post_status((int)$row['tutor_enrolled_id'])) {
            wp_update_post(array('ID'=>(int)$row['tutor_enrolled_id'], 'post_status'=>'cancel'));
        }
        return array('new_user_id'=>$new_user_id, 'new_enrollment_id'=>$new_enrollment_id);
    }

    public static function render_identity_bar(): void {
        $session = self::read_portal_session();
        $reg_id = (int)($session['registration_id'] ?? 0);
        if ($reg_id <= 0) return;
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT student_name FROM " . TPMA_CR_DB::table('regs') . " WHERE id=%d", $reg_id));
        if (!$name) return;
        $url = add_query_arg(array(
            'tpma_portal' => '1',
            'switch' => '1',
            'tpma_switch_nonce' => (string)($session['switch_nonce'] ?? ''),
        ), home_url('/'));
        echo '<aside style="position:fixed;right:16px;bottom:16px;z-index:99999;background:#17342f;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.22)">目前學員：' . esc_html($name) . ' <a style="color:#9de5d8;margin-left:8px" href="' . esc_url($url) . '">切換學員</a></aside>';
    }

    public static function map_quiz_attempt($quiz_id, $user_id, $attempt_id): void {
        $reg_id = self::current_registration_id();
        $access = $reg_id > 0 ? self::evaluate_registration($reg_id, 'quiz') : array('allowed' => false);
        if ($reg_id <= 0 || empty($access['allowed'])) return;
        $registration = (array) ($access['registration'] ?? array());
        if (self::learner_binding_error($registration) !== '' || (int) $user_id !== (int) ($registration['wp_user_id'] ?? 0)) {
            self::audit((int) ($registration['woocommerce_order_id'] ?? 0), $reg_id, 'quiz_identity_denied');
            return;
        }
        $session = self::read_portal_session();
        global $wpdb;
        $topic_id = (int)get_post_field('post_parent', (int)$quiz_id);
        $tutor_course_id = $topic_id > 0 ? (int)get_post_field('post_parent', $topic_id) : 0;
        $reg_course_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT c.tutor_course_id FROM " . TPMA_CR_DB::table('regs') . " r JOIN " . TPMA_CR_DB::table('courses') . " c ON c.id=r.course_id WHERE r.id=%d",
            $reg_id
        ));
        if ($tutor_course_id <= 0 || $tutor_course_id !== $reg_course_id) return;
        $wpdb->replace(TPMA_CR_DB::table('quiz_contexts'), array(
            'attempt_id'=>(int)$attempt_id,'registration_id'=>$reg_id,'order_id'=>(int)$session['order_id'],
            'session_id'=>(int)$wpdb->get_var($wpdb->prepare("SELECT session_id FROM " . TPMA_CR_DB::table('regs') . " WHERE id=%d", $reg_id)),
            'created_at'=>current_time('mysql'),
        ), array('%d','%d','%d','%d','%s'));
    }

    private static function audit(int $order_id, int $reg_id, string $event): void {
        global $wpdb;
        $wpdb->insert(TPMA_CR_DB::table('portal_audit'), array('order_id'=>$order_id,'registration_id'=>$reg_id,'event_key'=>$event,'ip_hash'=>hash('sha256',(string)($_SERVER['REMOTE_ADDR'] ?? '')),'created_at'=>current_time('mysql')), array('%d','%d','%s','%s','%s'));
    }

    private static function encrypt(string $raw): string {
        $key = hash('sha256', wp_salt('auth'), true); $iv = random_bytes(16);
        $cipher = openssl_encrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    private static function decrypt(string $encoded): string {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) <= 16) return '';
        return (string)openssl_decrypt(substr($data,16), 'aes-256-cbc', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($data,0,16));
    }
}
