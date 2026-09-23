<?php
/**
 * Formal completion certificate lifecycle.
 *
 * Certificate numbers are immutable and intentionally separate from Tutor's
 * certificate_id hash stored on the registration record.
 */
defined('ABSPATH') || exit;

class TPMA_CR_Certificate_Service {
    const STATUS_PENDING = 'pending';
    const STATUS_GENERATED = 'generated';
    const STATUS_SENT = 'sent';

    public static function init(): void {
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'on_order_completed'), 30, 1);
    }

    public static function on_order_completed($order_id): void {
        global $wpdb;
        $ids = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . TPMA_CR_DB::table('regs') . ' WHERE woocommerce_order_id=%d',
            (int) $order_id
        ));
        foreach ($ids as $registration_id) {
            self::maybe_issue_for_registration((int) $registration_id);
        }
    }

    /** Record the first passing attempt only, then allocate the formal certificate. */
    public static function record_quiz_pass(int $registration_id, string $passed_at = '') {
        global $wpdb;
        if ($registration_id <= 0) return new WP_Error('tpma_certificate_registration_invalid', '報名資料無效。');
        $passed_at = self::normalize_datetime($passed_at);
        if ($passed_at === '') $passed_at = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . TPMA_CR_DB::table('regs') . " SET certificate_passed_at=COALESCE(certificate_passed_at, %s) WHERE id=%d",
            $passed_at, $registration_id
        ));
        return self::maybe_issue_for_registration($registration_id);
    }

    /**
     * Allocate and produce a certificate for every learner who has passed.
     *
     * Payment is deliberately not a numbering gate. It only controls whether a
     * successful delivery may also mark the registration as completed.
     */
    public static function maybe_issue_for_registration(int $registration_id, bool $allow_legacy_score = false) {
        $registration = self::get_registration($registration_id);
        if (!$registration) return new WP_Error('tpma_certificate_registration_not_found', '找不到報名資料。');
        if (in_array((string) $registration['status'], array('cancelled', 'refunded'), true)) {
            return new WP_Error('tpma_certificate_registration_inactive', '已取消或退款的報名不可發證。');
        }
        if (empty($registration['certificate_passed_at']) && (!$allow_legacy_score || trim((string) ($registration['test_score'] ?? '')) === '')) {
            return new WP_Error('tpma_certificate_not_passed', '測驗尚未通過。');
        }
        $order_id = (int) ($registration['woocommerce_order_id'] ?? 0);
        $order = function_exists('wc_get_order') && $order_id > 0 ? wc_get_order($order_id) : false;
        $order_completed = $order && $order->get_status() === 'completed';

        global $wpdb;
        $certificate = self::get_for_registration($registration_id);
        // An unpaid certificate may already have been delivered. A later Woo
        // completed event is the point at which it becomes a completed course.
        if ($certificate && !empty($certificate['sent_at'])) {
            if ($order_completed) self::mark_registration_completed($registration_id);
            else self::mark_registration_postpay($registration_id);
            return $certificate;
        }

        // Formal completion happens only after delivery and payment. Until then
        // the learner remains 待發證, regardless of whether payment is pending.
        $wpdb->update(TPMA_CR_DB::table('regs'), array('status' => 'cert_pending'), array('id' => $registration_id), array('%s'), array('%d'));
        if (!$certificate || trim((string) ($certificate['serial'] ?? '')) === '') {
            $certificate = self::create_record($registration);
            if (is_wp_error($certificate)) return $certificate;
        }
        if (empty($certificate['generated_file']) || $certificate['status'] === self::STATUS_PENDING) {
            $rendered = self::render_current_pdf((int) $certificate['id']);
            if (is_wp_error($rendered)) return $rendered;
            $certificate = self::get((int) $certificate['id']);
        }
        if ($certificate && empty($certificate['sent_at'])) {
            $wpdb->update(TPMA_CR_DB::table('regs'), array('status' => 'cert_ready'), array('id' => $registration_id), array('%s'), array('%d'));
        }
        // Auto-mail never sends an unpaid order; a manager may still choose to
        // send it manually after acknowledging the warning in the admin UI.
        if ($order_completed && self::auto_send_enabled() && class_exists('TPMA_CR_Mail_Dispatcher')) {
            TPMA_CR_Mail_Dispatcher::send_certificate_email($order, $registration, array());
        }
        return $certificate;
    }

    /** Manual recovery for a passed learner whose automatic issue hook was missed. */
    public static function allocate_for_registration(int $registration_id) {
        $registration = self::get_registration($registration_id);
        if (!$registration) return new WP_Error('tpma_certificate_registration_not_found', '找不到報名資料。');
        if (empty($registration['certificate_passed_at']) && trim((string) ($registration['test_score'] ?? '')) === '') return new WP_Error('tpma_certificate_not_passed', '尚無測驗成績，不可人工配號。');
        if (in_array((string) $registration['status'], array('cancelled', 'refunded'), true)) return new WP_Error('tpma_certificate_registration_inactive', '已取消或退款的報名不可人工配號。');
        $existing = self::get_for_registration($registration_id);
        if ($existing && trim((string) ($existing['serial'] ?? '')) !== '') return new WP_Error('tpma_certificate_already_allocated', '此報名已配置正式證書編號。');
        // Pre-launch attempts may not expose their first passing timestamp.
        // An administrator confirms those score-bearing rows through the
        // explicit manual allocation operation; no automatic issue uses this.
        return self::maybe_issue_for_registration($registration_id, true);
    }

    /**
     * Recover passed attempts completed before the certificate feature was live.
     * This writes only the verified first-pass timestamp and leaves allocation to
     * the admin's 準證書 queue; it never silently consumes a serial number.
     */
    public static function backfill_legacy_passes(int $limit = 500): int {
        if (!class_exists('TPMA_Tutor_Bridge')) return 0;
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        $rows = (array) $wpdb->get_results(
            "SELECT r.id FROM " . TPMA_CR_DB::table('regs') . " r
             LEFT JOIN " . TPMA_CR_DB::table('certificates') . " cert ON cert.registration_id=r.id
             WHERE (cert.serial IS NULL OR cert.serial='') AND (r.certificate_passed_at IS NULL OR r.certificate_passed_at='')
               AND COALESCE(r.test_score, '')<>'' AND r.status NOT IN ('cancelled', 'refunded')
             ORDER BY r.id ASC LIMIT {$limit}",
            ARRAY_A
        );
        $updated = 0;
        foreach ($rows as $row) {
            $registration_id = (int) ($row['id'] ?? 0);
            $passed_at = TPMA_Tutor_Bridge::get_first_passing_attempt_at($registration_id);
            if ($passed_at === '') continue;
            $result = $wpdb->update(TPMA_CR_DB::table('regs'), array(
                'certificate_passed_at' => $passed_at, 'status' => 'cert_pending',
            ), array('id' => $registration_id), array('%s', '%s'), array('%d'));
            if ($result !== false) $updated++;
        }
        return $updated;
    }

    public static function get($certificate_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPMA_CR_DB::table('certificates') . ' WHERE id=%d', (int) $certificate_id), ARRAY_A);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public static function get_for_registration(int $registration_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPMA_CR_DB::table('certificates') . ' WHERE registration_id=%d', $registration_id), ARRAY_A);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** Return the registration and course context required by certificate administration. */
    public static function get_registration_for_certificate(int $registration_id) {
        return self::get_registration($registration_id);
    }

    public static function get_effective_file(int $certificate_id) {
        $certificate = self::get($certificate_id);
        if (!$certificate || empty($certificate['generated_file'])) return new WP_Error('tpma_certificate_file_missing', '證書 PDF 尚未產生。');
        $dir = self::private_dir();
        if (is_wp_error($dir)) return $dir;
        $path = wp_normalize_path(trailingslashit($dir) . ltrim((string) $certificate['generated_file'], '/\\'));
        if (!is_readable($path)) return new WP_Error('tpma_certificate_file_unreadable', '證書 PDF 檔案不存在或無法讀取。');
        return $path;
    }

    public static function mark_sent(int $certificate_id) {
        global $wpdb;
        $certificate = self::get($certificate_id);
        if (!$certificate) return new WP_Error('tpma_certificate_not_found', '找不到證書。');
        $registration = self::get_registration((int) $certificate['registration_id']);
        $mark_completed = $registration && self::registration_order_is_completed($registration);
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->update(TPMA_CR_DB::table('certificates'), array(
            'status' => self::STATUS_SENT, 'sent_at' => current_time('mysql'),
            'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql'),
        ), array('id' => $certificate_id), array('%s', '%s', '%d', '%s'), array('%d'));
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tpma_certificate_mark_sent_failed', '無法更新證書寄送狀態。');
        }
        if ($mark_completed) {
            $registration_updated = self::mark_registration_completed((int) $certificate['registration_id']);
            if ($registration_updated === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tpma_certificate_mark_registration_completed_failed', '證書已寄出，但無法標記報名為已結訓。');
            }
        } else {
            $registration_updated = self::mark_registration_postpay((int) $certificate['registration_id']);
            if ($registration_updated === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tpma_certificate_mark_registration_postpay_failed', '證書已寄出，但無法標記報名為課後付款。');
            }
        }
        $wpdb->query('COMMIT');
        return self::get($certificate_id);
    }

    public static function render_current_pdf(int $certificate_id) {
        if (!function_exists('tpma_mpdf_create') || !function_exists('tpma_mpdf_is_available') || !tpma_mpdf_is_available()) {
            return new WP_Error('tpma_certificate_mpdf_unavailable', 'TPMA mPDF Service 目前無法使用。');
        }
        $certificate = self::get($certificate_id);
        if (!$certificate) return new WP_Error('tpma_certificate_not_found', '找不到證書。');
        $dir = self::private_dir();
        if (is_wp_error($dir)) return $dir;
        $path = trailingslashit($dir) . sanitize_file_name($certificate['serial'] . '.pdf');
        $snapshot = self::complete_snapshot($certificate);
        $result = self::render_pdf($snapshot, $path);
        if (is_wp_error($result) || !self::is_valid_pdf($path)) {
            return is_wp_error($result) ? $result : new WP_Error('tpma_certificate_pdf_missing', '證書 PDF 輸出失敗。');
        }
        global $wpdb;
        $fields = array(
            'generated_file' => basename($path),
            'status' => empty($certificate['sent_at']) ? self::STATUS_GENERATED : self::STATUS_SENT,
            'generated_at' => current_time('mysql'),
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        );
        // Repair incomplete historical import snapshots before their first delivery only.
        if (empty($certificate['sent_at']) && wp_json_encode($snapshot) !== wp_json_encode((array) $certificate['snapshot'])) {
            $fields['snapshot'] = wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        }
        // Regenerating an already delivered file must not undo completion status.
        $wpdb->update(TPMA_CR_DB::table('certificates'), $fields, array('id' => $certificate_id));
        if (empty($certificate['sent_at'])) {
            $wpdb->update(TPMA_CR_DB::table('regs'), array('status' => 'cert_ready'), array('id' => (int) $certificate['registration_id']), array('%s'), array('%d'));
        }
        return $path;
    }

    /** Import/update pre-existing, never-sent certificate serials. */
    public static function import_serial(string $reg_no, string $serial) {
        global $wpdb;
        $registration = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . TPMA_CR_DB::table('regs') . ' WHERE reg_no=%s ORDER BY id ASC LIMIT 1', trim($reg_no)), ARRAY_A);
        if (!is_array($registration)) return new WP_Error('tpma_certificate_import_registration_missing', '找不到報名編號。');
        $registration = self::get_registration((int) $registration['id']);
        if (!$registration) return new WP_Error('tpma_certificate_import_registration_missing', '找不到報名資料。');
        $serial = strtoupper(trim($serial));
        $year = self::course_year($registration);
        if (is_wp_error($year)) return $year;
        $expected_prefix = str_pad((string) ($year - 1911), 3, '0', STR_PAD_LEFT) . 'A';
        if (!preg_match('/^' . preg_quote($expected_prefix, '/') . '\\d{6}$/', $serial)) {
            return new WP_Error('tpma_certificate_import_serial_invalid', '證書編號須為該課程年度的 ' . $expected_prefix . ' 六碼流水號。');
        }
        $certificate = self::get_for_registration((int) $registration['id']);
        if ($certificate && !empty($certificate['sent_at'])) return new WP_Error('tpma_certificate_import_sent_locked', '已寄出的證書不可由匯入覆寫。');
        $owner = $wpdb->get_var($wpdb->prepare('SELECT registration_id FROM ' . TPMA_CR_DB::table('certificates') . ' WHERE serial=%s', $serial));
        if ($owner && (int) $owner !== (int) $registration['id']) return new WP_Error('tpma_certificate_import_serial_used', '此證書編號已用於其他報名。');
        $snapshot = self::build_snapshot($registration, $serial);
        if (is_wp_error($snapshot)) return $snapshot;
        $now = current_time('mysql');
        if ($certificate) {
            $updated = $wpdb->update(TPMA_CR_DB::table('certificates'), array('serial' => $serial, 'snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'updated_by' => get_current_user_id(), 'updated_at' => $now), array('id' => (int) $certificate['id']), array('%s', '%s', '%d', '%s'), array('%d'));
            return $updated === false ? new WP_Error('tpma_certificate_import_update_failed', '更新既有證書失敗。') : 'updated';
        }
        $inserted = $wpdb->insert(TPMA_CR_DB::table('certificates'), array(
            'registration_id' => (int) $registration['id'], 'serial' => $serial, 'status' => self::STATUS_PENDING,
            'snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'passed_at' => $registration['certificate_passed_at'] ?: null,
            'issued_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ), array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'));
        return $inserted ? 'created' : new WP_Error('tpma_certificate_import_create_failed', '建立既有證書失敗。');
    }

    private static function create_record(array $registration) {
        global $wpdb;
        $year = self::course_year($registration);
        if (is_wp_error($year)) return $year;
        $roc_year = str_pad((string) ($year - 1911), 3, '0', STR_PAD_LEFT);
        $lock = 'tpma_certificate_serial_' . $roc_year;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== 1) return new WP_Error('tpma_certificate_serial_lock_failed', '證書流水號配置忙碌，請稍後再試。');
        try {
            $existing = self::get_for_registration((int) $registration['id']);
            if ($existing && trim((string) ($existing['serial'] ?? '')) !== '') return $existing;
            $prefix = $roc_year . 'A';
            $last = $wpdb->get_var($wpdb->prepare('SELECT serial FROM ' . TPMA_CR_DB::table('certificates') . ' WHERE serial LIKE %s ORDER BY serial DESC LIMIT 1', $prefix . '%'));
            $sequence = ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\\d{6})$/', $last, $m)) ? (int) $m[1] + 1 : 1;
            if ($sequence > 999999) return new WP_Error('tpma_certificate_serial_limit', '本年度證書流水號已達上限。');
            $serial = $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $snapshot = self::build_snapshot($registration, $serial);
            if (is_wp_error($snapshot)) return $snapshot;
            $now = current_time('mysql');
            if ($existing) {
                $updated = $wpdb->update(TPMA_CR_DB::table('certificates'), array(
                    'serial' => $serial, 'status' => self::STATUS_PENDING,
                    'snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    'generated_file' => null, 'passed_at' => $registration['certificate_passed_at'] ?: null,
                    'issued_at' => $now, 'updated_by' => get_current_user_id(), 'updated_at' => $now,
                ), array('id' => (int) $existing['id']), array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'), array('%d'));
                return $updated === false ? new WP_Error('tpma_certificate_repair_failed', '無法補正未編號的證書主檔。') : self::get((int) $existing['id']);
            }
            $inserted = $wpdb->insert(TPMA_CR_DB::table('certificates'), array(
                'registration_id' => (int) $registration['id'], 'serial' => $serial, 'status' => self::STATUS_PENDING,
                'snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'passed_at' => $registration['certificate_passed_at'], 'issued_at' => $now,
                'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
            ), array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'));
            return $inserted ? self::get((int) $wpdb->insert_id) : new WP_Error('tpma_certificate_create_failed', '建立證書主檔失敗。');
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function get_registration(int $registration_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT r.*, c.course_name, s.session_datetime FROM ' . TPMA_CR_DB::table('regs') . ' r LEFT JOIN ' . TPMA_CR_DB::table('courses') . ' c ON c.id=r.course_id LEFT JOIN ' . TPMA_CR_DB::table('sessions') . ' s ON s.id=r.session_id WHERE r.id=%d', $registration_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Whether a linked Woo order has reached the only payment state that completes training. */
    public static function registration_order_is_completed(array $registration): bool {
        $order_id = (int) ($registration['woocommerce_order_id'] ?? 0);
        $order = $order_id > 0 && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        return $order && $order->get_status() === 'completed';
    }

    /** Project the registration status only; caller owns any surrounding transaction. */
    private static function mark_registration_completed(int $registration_id) {
        global $wpdb;
        return $wpdb->update(TPMA_CR_DB::table('regs'), array('status' => 'completed'), array('id' => $registration_id), array('%s'), array('%d'));
    }

    private static function mark_registration_postpay(int $registration_id) {
        global $wpdb;
        return $wpdb->update(TPMA_CR_DB::table('regs'), array('status' => 'postpay'), array('id' => $registration_id), array('%s'), array('%d'));
    }

    private static function course_year(array $registration) {
        $date = trim((string) ($registration['session_datetime'] ?? $registration['class_date'] ?? ''));
        $timestamp = strtotime($date);
        return $timestamp ? (int) wp_date('Y', $timestamp) : new WP_Error('tpma_certificate_course_date_missing', '課程日期缺失，無法配置證書編號。');
    }

    private static function build_snapshot(array $registration, string $serial) {
        $year = self::course_year($registration);
        if (is_wp_error($year)) return $year;
        $course_date = trim((string) ($registration['session_datetime'] ?? $registration['class_date'] ?? ''));
        // 證書底部日期固定採課程當日；測驗通過日只作資格與管理紀錄使用。
        $passed_at = $course_date;
        return array(
            'serial' => $serial, 'company_name' => (string) ($registration['company_name'] ?? ''), 'job_title' => (string) ($registration['job_title'] ?? ''),
            'student_name' => (string) ($registration['student_name'] ?? ''), 'course_name' => (string) ($registration['course_name'] ?? ''),
            'course_date' => $course_date, 'course_date_roc' => self::roc_date($course_date), 'passed_at' => $passed_at, 'passed_at_roc' => self::roc_date($passed_at),
        );
    }

    private static function render_html(array $snapshot): string {
        $background = TPMA_CR_PATH . 'docs/證書.png';
        $background_uri = is_readable($background) ? self::local_file_uri($background) : '';
        $name = esc_html((string) ($snapshot['student_name'] ?? ''));
        $company = esc_html((string) ($snapshot['company_name'] ?? ''));
        $title = esc_html((string) ($snapshot['job_title'] ?? ''));
        return '<html><head><meta charset="utf-8"><style>@page{size:A4 portrait;margin:0}body{font-family:tpma_dikai,sans-serif;margin:0}.page{width:210mm;height:297mm;position:relative;overflow:hidden}.bg{position:absolute;top:0;left:0;width:210mm;height:297mm}.content{position:absolute;top:50mm;left:35mm;right:35mm;bottom:0}h1{text-align:center;font-size:30pt;letter-spacing:3mm;margin:0 0 20mm}.serial{text-align:right;font-size:14pt}.body{font-size:16pt;line-height:1.75;margin-top:5mm}.center{text-align:center}.chair{position:absolute;bottom:87mm;left:45mm;font-size:16pt}.date{position:absolute;bottom:32mm;left:0;right:0;font-size:16pt;text-align:justify}</style></head><body><div class="page"><img class="bg" src="' . esc_attr($background_uri) . '" /><div class="content"><h1>結訓證書</h1><div class="serial">證書編號：' . esc_html((string) ($snapshot['serial'] ?? '')) . '</div><div class="body">' . $company . ' ' . $title . ' ' . $name . '<br>於中華民國' . esc_html((string) ($snapshot['course_date_roc'] ?? '')) . '<br>參加社團法人台灣專案管理學會(TPMA)<br>辦理上市櫃董事進修課程<br>「' . esc_html((string) ($snapshot['course_name'] ?? '')) . '」<br>共3小時，經考核通過結訓。<br>(課程時數符合上市上櫃公司董事進修推行要點共3小時)<br><div class="center">特頒此證，以茲證明</div></div><div class="chair">理事長</div><div class="date">中華民國' . esc_html((string) ($snapshot['passed_at_roc'] ?? '')) . '</div></div></div></body></html>';
    }

    /** Render one fixed A4 page: native image background plus mPDF HTML CJK text layers. */
    private static function render_pdf(array $snapshot, string $path) {
        $mpdf = tpma_mpdf_create(self::pdf_config());
        if (is_wp_error($mpdf)) return $mpdf;
        $background = TPMA_CR_PATH . 'docs/證書.png';
        if (!is_readable($background)) return new WP_Error('tpma_certificate_background_missing', '找不到證書底圖。');
        try {
            // mPDF's documented setting: justify every line immediately before <br>.
            $mpdf->justifyB4br = true;
            // Official defaults cap CJK character spacing at 2pt.  Certificate
            // lines intentionally span the full 140mm demo text area.
            $mpdf->jSWord = 0;
            $mpdf->jSmaxChar = 0;
            $mpdf->AddPage();
            $mpdf->Image(self::local_file_uri($background), 0, 0, 210, 297, 'png');
            $mpdf->SetTextColor(0, 0, 0, 100);
            // mPDF's low-level Text() path drops some UTF-8/CJK glyphs.  The
            // receipt service uses WriteFixedPosHTML for this same reason.
            self::draw_html_text($mpdf, 35, 45, 140, 16, '結訓證書', 30, 'center', 'letter-spacing:3mm;');
            // The stored serial remains just YYYA######; this is the printed formal form.
            self::draw_html_text($mpdf, 35, 68, 140, 8, '證書編號：台專證字' . (string) ($snapshot['serial'] ?? '') . '號', 14, 'right');
            $identity = trim(implode(' ', array_filter(array((string) ($snapshot['company_name'] ?? ''), (string) ($snapshot['job_title'] ?? ''), (string) ($snapshot['student_name'] ?? '')))));
            $identity_length = function_exists('mb_strlen') ? mb_strlen($identity, 'UTF-8') : strlen($identity);
            $identity_size = $identity_length > 20 ? max(10.0, 18.0 * 20 / $identity_length) : 18.0;
            self::draw_html_text($mpdf, 35, 81, 140, 9, $identity, $identity_size);
            self::draw_html_lines($mpdf, 35, 94, 140, 31, array(
                '於中華民國' . (string) ($snapshot['course_date_roc'] ?? '') . '，',
                '參加社團法人台灣專案管理學會(TPMA)',
                '辦理上市櫃董事進修課程',
            ), 16, true);
            $course_name = (string) ($snapshot['course_name'] ?? '');
            $course_length = function_exists('mb_strlen') ? mb_strlen($course_name, 'UTF-8') : strlen($course_name);
            $course_size = $course_length > 20 ? max(10.0, 18.0 * 20 / $course_length) : 18.0;
            self::draw_html_lines($mpdf, 35, 125, 140, 8, array('「' . $course_name . '」'), $course_size, true);
            self::draw_html_text($mpdf, 35, 138, 140, 9, '共3小時，經考核通過結訓。', 16);
            self::draw_html_text($mpdf, 35, 148, 140, 8, '(課程時數符合上市上櫃公司董事進修推行要點共3小時)', 16, 'center');
            self::draw_html_text($mpdf, 35, 158, 140, 9, '特頒此證，以茲證明', 16, 'center');
            self::draw_html_text($mpdf, 80, 205, 35, 9, '理事長', 16);
            self::draw_html_lines($mpdf, 35, 255, 140, 9, array('中華民國' . (string) ($snapshot['passed_at_roc'] ?? '')), 16, true);
            $destination = class_exists('\\Mpdf\\Output\\Destination') ? \Mpdf\Output\Destination::FILE : 'F';
            $mpdf->Output(wp_normalize_path($path), $destination);
        } catch (Throwable $e) { return new WP_Error('tpma_certificate_pdf_render_failed', $e->getMessage()); }
        return true;
    }

    /** Use mPDF's HTML renderer, which embeds UTF-8/CJK glyphs correctly. */
    private static function draw_html_text($mpdf, float $x, float $y, float $width, float $height, string $text, float $size, string $align = 'left', string $extra_style = ''): void {
        $justify_style = $align === 'justify' ? 'display:inline-block; width:100%; text-align-last:justify;' : '';
        $style = sprintf(
            'margin:0; padding:0; width:100%%; height:100%%; overflow:visible; white-space:nowrap; font-family:tpma_dikai; font-size:%Fpt; line-height:1.75; text-align:%s;%s%s',
            $size,
            in_array($align, array('center', 'right', 'justify'), true) ? $align : 'left',
            $justify_style,
            $extra_style
        );
        $html = '<div style="' . $style . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
        $mpdf->WriteFixedPosHTML($html, $x, $y, $width, $height, 'visible');
    }

    /** Use mPDF's documented justifyB4br behaviour for demo-style distributed lines. */
    private static function draw_html_lines($mpdf, float $x, float $y, float $width, float $height, array $lines, float $size, bool $trailing_break = false): void {
        $content = implode('<br>', array_map(static function ($line) {
            return htmlspecialchars((string) $line, ENT_QUOTES, 'UTF-8');
        }, $lines));
        if ($trailing_break) $content .= '<br>';
        $style = sprintf(
            'margin:0; padding:0; display:inline-block; width:100%%; height:100%%; overflow:visible; white-space:normal; font-family:tpma_dikai; font-size:%Fpt; line-height:1.75; text-align:justify;',
            $size
        );
        $mpdf->WriteFixedPosHTML('<div style="' . $style . '">' . $content . '</div>', $x, $y, $width, $height, 'visible');
    }

    /** Fill only missing fields in old, pre-fix import snapshots from the current registration context. */
    private static function complete_snapshot(array $certificate): array {
        $snapshot = (array) ($certificate['snapshot'] ?? array());
        $registration = self::get_registration((int) ($certificate['registration_id'] ?? 0));
        if (!$registration) return $snapshot;
        $fresh = self::build_snapshot($registration, (string) ($certificate['serial'] ?? ''));
        if (is_wp_error($fresh)) return $snapshot;
        foreach (array('serial', 'company_name', 'job_title', 'student_name', 'course_name', 'course_date', 'course_date_roc', 'passed_at', 'passed_at_roc') as $key) {
            if (trim((string) ($snapshot[$key] ?? '')) === '' && trim((string) ($fresh[$key] ?? '')) !== '') $snapshot[$key] = $fresh[$key];
        }
        // The bottom date rule changed from pass date to course date; amend unsent historical previews as well.
        $snapshot['passed_at'] = $fresh['passed_at'];
        $snapshot['passed_at_roc'] = $fresh['passed_at_roc'];
        return $snapshot;
    }

    private static function pdf_config(): array {
        $font = apply_filters('tpma_cr_certificate_font_file', TPMA_CR_PATH . 'assets/fonts/kaiu.ttf');
        if (!is_readable($font) && is_readable('C:/Windows/Fonts/kaiu.ttf')) $font = 'C:/Windows/Fonts/kaiu.ttf';
        $config = array('format' => 'A4', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0);
        if (is_readable($font)) $config += array(
            'default_font' => 'tpma_dikai',
            'fontDir' => array(dirname($font)),
            'fontdata' => array('tpma_dikai' => array('R' => basename($font), 'B' => basename($font))),
            // Do not let mPDF replace DFKai-SB per character; that causes missing glyphs in native Text().
            'autoScriptToLang' => false, 'autoLangToFont' => false,
        );
        return $config;
    }

    private static function private_dir() {
        $base = class_exists('TPMA_CR_Receipt_Service') ? TPMA_CR_Receipt_Service::private_dir() : new WP_Error('tpma_certificate_storage_unavailable', '收據私有儲存服務未載入。');
        if (is_wp_error($base)) return $base;
        $dir = trailingslashit(dirname($base)) . 'tpma-certificates';
        return wp_mkdir_p($dir) && is_writable($dir) ? wp_normalize_path($dir) : new WP_Error('tpma_certificate_storage_unavailable', '無法建立或寫入證書私有目錄。');
    }

    private static function auto_send_enabled(): bool { return class_exists('TPMA_CR_Settings') && TPMA_CR_Settings::is_auto_certificate_mail_enabled(); }
    private static function local_file_uri(string $path): string { $path = wp_normalize_path($path); return preg_match('/^[A-Za-z]:\//', $path) ? 'file:///' . $path : 'file://' . $path; }
    private static function is_valid_pdf(string $path): bool { return is_readable($path) && filesize($path) > 1024 && substr((string) file_get_contents($path, false, null, 0, 5), 0, 5) === '%PDF-'; }
    private static function normalize_datetime(string $value): string { $timestamp = strtotime($value); return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : ''; }
    private static function roc_date(string $value): string { $timestamp = strtotime($value); return $timestamp ? (string) ((int) wp_date('Y', $timestamp) - 1911) . '年' . wp_date('m月d日', $timestamp) : ''; }
    private static function hydrate(array $row): array { $row['snapshot'] = is_array($row['snapshot']) ? $row['snapshot'] : (json_decode((string) $row['snapshot'], true) ?: array()); return $row; }
}
