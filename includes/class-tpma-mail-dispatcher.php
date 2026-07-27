<?php
if (!defined('ABSPATH')) exit;

class TPMA_CR_Mail_Dispatcher
{
    /**
     * 統一定義寄信模板預設鍵，所有流程都透過這裡取得。
     */
    private static function get_default_templates(): array
    {
        $defaults = array(
            'student'   => 'registration_notice',
            'order'     => 'registration_order',
            'completed' => 'registration_completed',
            // Tutor integration: course access magic link (sent after registration)
            'course_access'      => 'course_access',
            // Pre-class reminder N days before class (contains Meet link)
            'pre_class_reminder' => 'pre_class_reminder',
            // Recorded course opened notice
            'recorded_course_opened' => 'recorded_course_opened',
            // Quiz invitation (manually triggered by admin after class)
            'quiz_invitation'    => 'quiz_invitation',
            // Certificate ready (manually triggered after Tutor completion)
            'certificate_ready'  => 'certificate_ready',
            // Receipt notice (order-level, manually triggered)
            'receipt_notice'     => 'receipt_notice',
            // Other extension keys; keep admin remittance report
            'admin_remit_report' => 'admin_remit_report',
        );

        return apply_filters('tpma_cr_mail_default_templates', $defaults);
    }

    /**
     * 將 draft 的 mail_templates 與預設鍵合併，避免各處自行塞值。
     */
    private static function apply_default_templates(array $draft): array
    {
        $defaults = self::get_default_templates();
        $existing = is_array($draft['mail_templates'] ?? null) ? $draft['mail_templates'] : array();

        $draft['mail_templates'] = array_merge($defaults, $existing);

        return $draft;
    }

    /**
     * ✅ 目標：
     * - 寄信流程完全不依賴 session
     * - 只依賴 Woo order meta（_tpma_reg_draft_json、_tpma_reg_ids）與 DB
     * - learners_list 以純文字輸出（可讀、可換行）
     * - 明確列出可用模板變數（TPMA Mailer 後台顯示）
     * - 保留原有 REST 介面方法與 payload 形狀（避免前端壞掉）
     */

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * 統一日期時間顯示：YYYY/MM/DD（週） HH:MM~HH:MM
     */
    private static function format_class_datetime($session_datetime, $duration_minutes = 0): string
    {
        if (!$session_datetime) return '';

        $ts = strtotime(str_replace('T', ' ', (string)$session_datetime));
        if (!$ts) return (string)$session_datetime;

        $week = array('日','一','二','三','四','五','六');
        $date = date('Y/m/d', $ts);
        $w    = $week[(int) date('w', $ts)] ?? '';
        $from = date('H:i', $ts);

        $dur = intval($duration_minutes);
        if ($dur > 0) {
            $to = date('H:i', $ts + $dur * 60);
            return sprintf('%s（%s） %s~%s', $date, $w, $from, $to);
        }
        return sprintf('%s（%s） %s', $date, $w, $from);
    }

    /**
     * minutes -> hours（字串，整數不顯示小數）
     */
    private static function minutes_to_hours_str($minutes): string
    {
        $m = intval($minutes);
        if ($m <= 0) return '';
        $h = $m / 60.0;
        if (abs($h - round($h)) < 0.00001) return (string)intval(round($h));
        return number_format($h, 1);
    }

    /**
     * 將字串/陣列整理成 email 陣列（逗號/分號/空白分隔都可）
     */
    private static function normalize_emails($raw): array
    {
        $out = array();
        if (empty($raw)) return $out;

        $parts = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string)$raw);
        foreach ($parts as $e) {
            $e = trim((string)$e);
            if ($e && is_email($e)) $out[] = $e;
        }
        return array_values(array_filter($out));
    }

    /**
     * Collect order-level recipients: primary Woo billing email + extra TPMA contact emails.
     */
    private static function get_order_contact_recipients(WC_Order $order): array
    {
        $to = array();

        $billing_email = trim((string) $order->get_billing_email());
        if ($billing_email && is_email($billing_email)) {
            $to[] = sanitize_email($billing_email);
        }

        $extra = $order->get_meta('_tpma_contact_emails', true);
        $to = array_merge($to, self::normalize_emails($extra));

        return array_values(array_unique(array_filter($to, 'is_email')));
    }

    /**
     * 從 Mail Config 取得某模板的「副本/抄送」收件人設定（若有）
     * TPMA Mailer 後台目前使用的是 cfg.default_cc / cfg.default_bcc
     */
    /**
     * Extract recipient source key(s) from a route entry.
     * Supports both new `recipient_sources` (array) and legacy `recipient_source` (string).
     *
     * @param array $route Route configuration entry.
     * @return string[] Non-empty sanitized source keys.
     */
    private static function extract_route_sources(array $route): array
    {
        if (!empty($route['recipient_sources']) && is_array($route['recipient_sources'])) {
            return array_values(array_filter(array_map('sanitize_key', $route['recipient_sources'])));
        }

        if (!empty($route['recipient_source'])) {
            $key = sanitize_key((string) $route['recipient_source']);
            return $key !== '' ? array($key) : array();
        }

        return array();
    }

    private static function extract_route_template(array $route): string
    {
        return trim(sanitize_text_field((string) ($route['template_key'] ?? '')));
    }

    private static function get_existing_template_key_map(): array
    {
        if (!class_exists('TPMA_CR_Mail_Templates')) {
            return array();
        }

        $templates = TPMA_CR_Mail_Templates::get_all();
        if (!is_array($templates)) {
            return array();
        }

        $map = array();
        foreach ($templates as $key => $template) {
            if (!is_array($template)) {
                continue;
            }

            $has_subject = trim((string) ($template['subject'] ?? '')) !== '';
            $has_body = trim((string) ($template['body_html'] ?? '')) !== '';
            if (!$has_subject && !$has_body) {
                continue;
            }

            $raw_key = trim((string) $key);
            if ($raw_key === '') {
                continue;
            }

            $map[$raw_key] = $raw_key;
        }

        return $map;
    }

    private static function resolve_existing_template_key(string $template_key): string
    {
        $template_key = trim($template_key);
        if ($template_key === '') {
            return '';
        }

        $map = self::get_existing_template_key_map();
        if (isset($map[$template_key]) && $map[$template_key] !== '') {
            return (string) $map[$template_key];
        }

        return '';
    }

    private static function get_copy_recipients_from_config(string $template_key): array
    {
        $emails = array();

        if (class_exists('TPMA_CR_Mail_Config')) {
            $config  = TPMA_CR_Mail_Config::get_config();
            $tpl_cfg = $config['templates'][$template_key] ?? array();

            $candidates = array();

            foreach (array('default_cc', 'default_bcc') as $k) {
                if (!empty($tpl_cfg[$k])) $candidates[] = $tpl_cfg[$k];
            }

            foreach (array('cc','cc_emails','bcc','bcc_emails','copy_to','copy_emails','copies','copy') as $k) {
                if (!empty($tpl_cfg[$k])) $candidates[] = $tpl_cfg[$k];
            }

            foreach ($candidates as $raw) {
                $emails = array_merge($emails, self::normalize_emails($raw));
            }
        }

        return array_values(array_unique($emails));
    }

    private static function send_template_to_recipients(string $template_key, array $recipients, array $context): bool
    {
        $sent = false;
        $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $recipients), 'is_email')));

        foreach ($recipients as $to) {
            try {
                if (TPMA_Mailer::send_template($template_key, $to, array(
                    'reg_context' => $context,
                ))) {
                    $sent = true;
                }
            } catch (Throwable $e) {
                error_log('[TPMA CR Mail] route send failed template=' . $template_key . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    private static function get_route_recipients(array $route_sources, array $route_context): array
    {
        $recipients = array();
        foreach ($route_sources as $route_source) {
            $resolved = function_exists('tpma_mailer_resolve_recipients')
                ? tpma_mailer_resolve_recipients($route_source, $route_context)
                : array();
            $recipients = array_merge($recipients, $resolved);
        }

        return array_values(array_unique(array_filter(array_map('sanitize_email', $recipients), 'is_email')));
    }

    private static function get_primary_or_copy_recipients(array $primary_recipients, string $template_key): array
    {
        $primary_recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $primary_recipients), 'is_email')));
        if (!empty($primary_recipients)) {
            return $primary_recipients;
        }

        return self::get_copy_recipients_from_config($template_key);
    }

    private static function send_route_with_copy_fallback(string $template_key, array $primary_recipients, array $context): bool
    {
        $recipients = self::get_primary_or_copy_recipients($primary_recipients, $template_key);
        return self::send_template_to_recipients($template_key, $recipients, $context);
    }

    private static function send_route_copies_if_primary_sent(string $template_key, array $primary_recipients, array $context): bool
    {
        $primary_recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $primary_recipients), 'is_email')));
        if (empty($primary_recipients)) {
            return false;
        }

        $copies = array_diff(self::get_copy_recipients_from_config($template_key), $primary_recipients);
        return self::send_template_to_recipients($template_key, $copies, $context);
    }

    private static function notify_admin_unmatched_event(string $event_key, array $context = array(), ?WC_Order $order = null): void
    {
        if ($order instanceof WC_Order) {
            $meta_key = '_tpma_mailer_unmatched_' . sanitize_key($event_key);
            if ($order->get_meta($meta_key, true) === 'yes') {
                return;
            }
            $context['order'] = $order;
        }

        if (function_exists('tpma_mailer_notify_admin_unmatched_event')) {
            tpma_mailer_notify_admin_unmatched_event($event_key, $context);
        }

        if ($order instanceof WC_Order) {
            $order->update_meta_data($meta_key, 'yes');
            $order->save();
        }
    }

    private static function send_registration_notice_route(
        WC_Order $order,
        array $draft,
        array $route,
        array $base_ctx
    ): bool {
        $route_template = self::resolve_existing_template_key(self::extract_route_template($route));
        $route_sources = self::extract_route_sources($route);
        if ($route_template === '') {
            return false;
        }

        if (empty($route_sources)) {
            return self::send_route_with_copy_fallback($route_template, array(), $base_ctx);
        }

        $sent = false;
        foreach ($route_sources as $route_source) {
            if ($route_source === 'tpma_cr_learner') {
                $learners = is_array($draft['learners'] ?? null) ? $draft['learners'] : array();
                foreach ($learners as $learner) {
                    if (!is_array($learner)) {
                        continue;
                    }

                    $ctx_student = self::build_context($order, $draft, $learner);
                    $source_context = array(
                        'event_key'      => 'registration_notice',
                        'order'          => $order,
                        'draft'          => $draft,
                        'reg_context'    => $ctx_student,
                        'single_learner' => $learner,
                        'product_ids'    => self::get_order_product_ids($order),
                    );
                    $recipients = self::get_route_recipients(array($route_source), $source_context);
                    if (self::send_route_with_copy_fallback($route_template, $recipients, $ctx_student)) {
                        $sent = true;
                    }
                    if (self::send_route_copies_if_primary_sent($route_template, $recipients, $ctx_student)) {
                        $sent = true;
                    }
                }
                continue;
            }

            $source_context = array(
                'event_key'   => 'registration_notice',
                'order'       => $order,
                'draft'       => $draft,
                'reg_context' => $base_ctx,
                'product_ids' => self::get_order_product_ids($order),
            );
            $recipients = self::get_route_recipients(array($route_source), $source_context);
            if (self::send_route_with_copy_fallback($route_template, $recipients, $base_ctx)) {
                $sent = true;
            }
            if (self::send_route_copies_if_primary_sent($route_template, $recipients, $base_ctx)) {
                $sent = true;
            }
        }

        return $sent;
    }

    /**
     * learners_list：純文字、可換行、可讀
     * 例：
     * 1. 姓名：王小明（課員）｜Email：a@b.com｜報名編號：2025A12037｜RegID：64
     */
    private static function build_learners_list_text(array $learners): array
    {
        $lines = array();
        $idx = 1;

        foreach ($learners as $lr) {
            if (!is_array($lr)) continue;

            $name  = trim((string)($lr['student_name'] ?? ($lr['name'] ?? '')));
            $title = trim((string)($lr['job_title'] ?? ''));
            $email = trim((string)($lr['email'] ?? ($lr['student_email'] ?? ($lr['emails'] ?? ''))));
            $regno = trim((string)($lr['reg_no'] ?? ''));
            $regid = trim((string)($lr['reg_id'] ?? ($lr['id'] ?? '')));

            $parts = array();
            if ($regno) $parts[] = "報名編號：{$regno}";
            $parts[] = "姓名：{$name}" . ($title ? "（{$title}）" : "");
            if ($email) $parts[] = "Email：{$email}";
            
            $lines[] = $idx . '. ' . implode('｜', $parts);
            $idx++;
        }

        return array(
            'text'  => implode("\n", $lines),
            'count' => count($lines),
        );
    }

    // =========================================================
    // Draft loader (NO session)
    // =========================================================

    /**
     * ✅ 從 order meta / DB 還原 draft（完全不使用 session）
     * - 優先：_tpma_reg_draft_json
     * - learners 缺：用 _tpma_reg_ids 回查 registrations
     * - course/session 缺：用 order meta（若有）補最低限度欄位
     */
    private static function get_draft_from_order(WC_Order $order): array
    {
        global $wpdb;

        $draft = array();

        // 1) draft_json
        $draft_json = $order->get_meta('_tpma_reg_draft_json', true);
        if ($draft_json) {
            $decoded = json_decode($draft_json, true);
            if (is_array($decoded)) $draft = $decoded;
        }

        // 2) 若 learners 缺，用 reg_ids 回查 DB
        $has_learners = !empty($draft['learners']) && is_array($draft['learners']);
        if (!$has_learners) {
            $reg_ids_json = $order->get_meta('_tpma_reg_ids', true);
            $reg_ids = $reg_ids_json ? json_decode($reg_ids_json, true) : null;

            if (is_array($reg_ids) && !empty($reg_ids) && class_exists('TPMA_CR_DB')) {
                $regs_table = TPMA_CR_DB::table('regs');

                $ids = array_values(array_filter(array_map('intval', $reg_ids)));
                if (!empty($ids)) {
                    $in = implode(',', array_fill(0, count($ids), '%d'));
                    $sql = $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id IN ($in) ORDER BY id ASC", $ids);
                    $rows = $wpdb->get_results($sql, ARRAY_A);

                    // ✅ 2-1) 從 registrations 回填 course_id / session_id（避免 build_context 拿不到課程）
                    if (!empty($rows) && is_array($rows[0])) {
                        if (empty($draft['course_id']) && !empty($rows[0]['course_id'])) {
                            $draft['course_id'] = (int)$rows[0]['course_id'];
                        }
                        // 兼容不同欄位命名
                        $sid = $rows[0]['session_id'] ?? ($rows[0]['course_session_id'] ?? null);
                        if (empty($draft['session_id']) && !empty($sid)) {
                            $draft['session_id'] = (int)$sid;
                        }
                    }

                    $learners = array();
                    foreach ($rows as $r) {
                        $learners[] = array(
                            'reg_id'       => $r['id'] ?? '',
                            'reg_no'       => $r['reg_no'] ?? '',
                            'student_name' => $r['student_name'] ?? '',
                            'job_title'    => $r['job_title'] ?? '',
                            'email'        => $r['email'] ?? ($r['student_email'] ?? ''),
                        );
                    }
                    $draft['learners'] = $learners;
                }
            }
        }

        // 3) 最低限度補 course/session id（若 draft 沒帶）
        if (empty($draft['course_id'])) {
            $draft['course_id'] = $order->get_meta('_tpma_course_id', true);
        }
        if (empty($draft['session_id'])) {
            $draft['session_id'] = $order->get_meta('_tpma_session_id', true);
        }

        $draft = self::apply_default_templates($draft);

        return is_array($draft) ? $draft : array();
    }



    /**
     * lookup lecturer_name：courses.lecturer_code -> lecturers 表
     * 依目前資料表 schema 動態選欄位，避免查詢不存在欄位導致整段流程中斷。
     */
    private static function lookup_lecturer_name($lecturer_code): string
    {
        global $wpdb;

        $code = trim((string) $lecturer_code);
        if ($code === '' || !class_exists('TPMA_CR_DB')) {
            return '';
        }

        $tbl = TPMA_CR_DB::table('lecturers');
        $schema = TPMA_CR_DB::get_lecturer_schema();
        $code_col = trim((string) ($schema['code'] ?? ''));

        if ($code_col === '') {
            return '';
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tbl} WHERE {$code_col} = %s LIMIT 1", $code),
            ARRAY_A
        );

        if (!$row) {
            return '';
        }

        $name_col = trim((string) ($schema['name'] ?? ''));
        $title_col = trim((string) ($schema['title'] ?? ''));
        $name = $name_col !== '' ? trim((string) ($row[$name_col] ?? '')) : '';
        $title = $title_col !== '' ? trim((string) ($row[$title_col] ?? '')) : '';

        return trim($name . ($title ? ' ' . $title : ''));
    }


    // =========================================================
    // Send
    // =========================================================

    /**
     * 寄出信件（由 Woo 觸發）：
     * 1) 學員信：每一筆 learner 寄（即使 email 相同也不合併）
     * 2) 訂單信：每一筆 Woo 訂單寄 1 封
     *
     * ✅ 這裡「不依賴 session」：
     * - 若 $draft 未傳或不是 array，會自動從 order meta/DB 還原
     */
    public static function send_after_order_created(WC_Order $order, $draft = null)
    {
        if (!class_exists('TPMA_Mailer')) return;

        // 避免同一訂單重複寄送
        if ($order->get_meta('_tpma_mail_sent', true) === 'yes') {
            return;
        }

        $flow_key = self::normalize_flow_key($order->get_status() ?: 'on-hold');
        if (self::has_template_mapping($order, $flow_key)) {
            $default_skip = !self::is_tpma_order_like($order);
            if (apply_filters('tpma_mailer_skip_tpma_order_flow', $default_skip, $order, $flow_key)) {
                return;
            }
        }

        if (!is_array($draft)) {
            $draft = self::get_draft_from_order($order);
        }
        $draft = self::apply_default_templates(is_array($draft) ? $draft : array());

        $base_ctx = self::build_context($order, $draft);
        $route_context = array(
            'event_key'   => 'registration_notice',
            'order'       => $order,
            'draft'       => $draft,
            'reg_context' => $base_ctx,
            'product_ids' => self::get_order_product_ids($order),
        );
        $has_route_config = function_exists('tpma_mailer_has_event_route_config')
            ? tpma_mailer_has_event_route_config('registration_notice')
            : false;
        $routes = function_exists('tpma_mailer_get_event_routes_for_event')
            ? tpma_mailer_get_event_routes_for_event('registration_notice', $route_context)
            : array();

        if ($has_route_config && !empty($routes)) {
            $sent = false;
            foreach ($routes as $route) {
                if (self::send_registration_notice_route($order, $draft, $route, $base_ctx)) {
                    $sent = true;
                }
            }

            if ($sent) {
                $order->update_meta_data('_tpma_mail_sent', 'yes');
                $order->save();
                return true;
            }
            self::notify_admin_unmatched_event('registration_notice', array(
                'reason' => 'routes_matched_but_no_mail_sent',
            ), $order);
            return false;
        }

        if ($has_route_config) {
            self::notify_admin_unmatched_event('registration_notice', array(
                'reason' => 'event_triggered_but_no_route_matched',
            ), $order);
        }
        return false;
    }

    // =========================================================
    // build_context (template vars)
    // =========================================================

    /**
     * build_context：建立模板變數（寄信用）
     * - 優先使用 draft；缺就用 course_id / session_id 回查 DB
     * - class_date 統一顯示字串
     * - learners_list 為純文字（可換行）
     * - reg_no：學員信＝該學員 reg_no；訂單信＝reg_nos（多筆用逗號）
     * - Woo 訂單編號：order_id / order_number
     */
    private static function build_context(WC_Order $order, array $draft, array $learner = array()): array
    {
        global $wpdb;

        $order_id = $order->get_id();
        $date_created = $order->get_date_created();
        $order_date = $date_created ? wc_format_datetime($date_created, 'Y/m/d') : '';

        $course  = $draft['course'] ?? array();
        $session = $draft['session'] ?? array();

        // 補齊課程
        if ((empty($course) || !is_array($course)) && !empty($draft['course_id']) && class_exists('TPMA_CR_DB')) {
            $courses_table = TPMA_CR_DB::table('courses');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$courses_table} WHERE id = %d",
                intval($draft['course_id'])
            ), ARRAY_A);
            if (is_array($row)) $course = $row;
        }

        // 補齊場次
        if ((empty($session) || !is_array($session)) && !empty($draft['session_id']) && class_exists('TPMA_CR_DB')) {
            $sessions_table = TPMA_CR_DB::table('sessions');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$sessions_table} WHERE id = %d",
                intval($draft['session_id'])
            ), ARRAY_A);
            if (is_array($row)) $session = $row;
        }

        // 時數：duration_minutes -> course_hours
        $duration_minutes = intval($course['duration_minutes'] ?? ($draft['duration_minutes'] ?? 0));
        $course_hours = self::minutes_to_hours_str($duration_minutes);

        // 講師：courses.lecturer_code -> lecturers
        $lecturer_name = '';

        // ① course 已經 join 出講師名稱（最可靠）
        if (!empty($course['lecturer_name'])) {
            $lecturer_name = trim((string)$course['lecturer_name']);
        }

        // ② course / draft 有 lecturer_code，才 lookup
        if (!$lecturer_name) {
            $lecturer_code = $course['lecturer_code']
                ?? ($draft['lecturer_code'] ?? '');

            if ($lecturer_code) {
                $lecturer_name = self::lookup_lecturer_name($lecturer_code);
            }
        }

        // ③ 最後保底（舊 draft 或特殊情況）
        if (!$lecturer_name && !empty($draft['lecturer_name'])) {
            $lecturer_name = trim((string)$draft['lecturer_name']);
        }

        // class_date
        $session_dt = $draft['session_datetime'] ?? ($session['session_datetime'] ?? ($session['session_datetime'] ?? ($session['session_datetime'] ?? '')));
        if (!$session_dt) {
            $session_dt = $session['session_datetime'] ?? ($session['session_datetime'] ?? ($session['session_datetime'] ?? ''));
        }
        // 若你 sessions 表欄位叫 session_datetime
        if (!$session_dt && !empty($session['session_datetime'])) {
            $session_dt = $session['session_datetime'];
        }
        // 常見欄位：session_datetime
        if (!$session_dt && !empty($session['session_datetime'])) {
            $session_dt = $session['session_datetime'];
        }
        // 你專案目前用：session_datetime
        if (!$session_dt && !empty($session['session_datetime'])) {
            $session_dt = $session['session_datetime'];
        }
        // 最後保底
        if (!$session_dt && !empty($session['session_datetime'])) {
            $session_dt = $session['session_datetime'];
        }
        // 你 draft 也可能帶 session.session_datetime
        if (!$session_dt && !empty($draft['session']['session_datetime'])) {
            $session_dt = $draft['session']['session_datetime'];
        }

        $class_date_display = '';
        if ($session_dt) {
            $class_date_display = self::format_class_datetime($session_dt, $duration_minutes);
        } else {
            $cd = $draft['class_date'] ?? '';
            if ($cd) {
                $ts = strtotime((string)$cd);
                if ($ts) {
                    $weeknames = array('日','一','二','三','四','五','六');
                    $date_str  = date('Y/m/d', $ts);
                    $week_str  = $weeknames[(int) date('w', $ts)] ?? '';
                    $class_date_display = sprintf('%s（%s）', $date_str, $week_str);
                } else {
                    $class_date_display = (string)$cd;
                }
            }
        }

        // 金額
        $remit_amount = $order->get_total();
        $per_fee = $draft['remit_amount_per_learner'] ?? ($draft['student_fee'] ?? null); // 你 draft 若有
        if ($per_fee !== null && $per_fee !== '') {
            $per_fee = floatval($per_fee);
        } else {
            $per_fee = '';
        }

        // learners_list / reg_nos
        $learners = $draft['learners'] ?? array();
        $ll = self::build_learners_list_text(is_array($learners) ? $learners : array());

        $reg_nos = array();
        if (is_array($learners)) {
            foreach ($learners as $lr) {
                if (!is_array($lr)) continue;
                $rn = trim((string)($lr['reg_no'] ?? ''));
                if ($rn) $reg_nos[] = $rn;
            }
        }
        $reg_nos = array_values(array_unique($reg_nos));
        $reg_nos_text = implode(', ', $reg_nos);

        $invoice_type_raw = (string) $order->get_meta('_opay_invoice_type', true);
        if ($invoice_type_raw !== '') {
            $invoice_type_map = array(
                'personal'   => '二聯式（個人）',
                'company'    => '三聯式（公司）',
                'tax_exempt' => '免開發票',
            );
            $invoice_type_label = $invoice_type_map[$invoice_type_raw] ?? $invoice_type_raw;
        } else {
            $invoice_type_raw = (string) $order->get_meta('_tpma_invoice_type', true);
            if ($invoice_type_raw === '') {
                $invoice_type_raw = (string) $order->get_meta('_billing_tpma_invoice_type', true);
            }
            $invoice_type_map = array(
                'two'   => '二聯式',
                'three' => '三聯式',
                'na'    => '不適用',
            );
            $invoice_type_label = $invoice_type_map[$invoice_type_raw] ?? $invoice_type_raw;
        }
        $invoice_company = (string) $order->get_billing_company();
        $invoice_vat_id = (string) $order->get_meta('_billing_vat_id', true);
        if ($invoice_vat_id === '') {
            $invoice_vat_id = (string) $order->get_meta('_opay_tax_id', true);
        }
        $invoice_type_display = $invoice_type_label !== '' ? $invoice_type_label : '—';
        if ($invoice_type_raw === 'three' || $invoice_type_raw === 'company') {
            $invoice_type_display .= '（公司抬頭：' . ($invoice_company !== '' ? $invoice_company : '—')
                . '｜公司統編：' . ($invoice_vat_id !== '' ? $invoice_vat_id : '—') . '）';
        }

        $billing_address = self::build_address_text($order, 'billing');
        $shipping_address = self::build_address_text($order, 'shipping');
        $order_address = $shipping_address !== '' ? $shipping_address : $billing_address;

        $remit_date = (string) $order->get_meta('_tpma_remit_paid_at', true);
        if ($remit_date === '') {
            $remit_date = (string) $order->get_meta('_tpma_remit_date', true);
        }
        if ($remit_date === '') {
            $paid = $order->get_date_paid();
            if ($paid instanceof WC_DateTime) {
                $remit_date = $paid->date_i18n('Y-m-d');
            } else {
                $completed = $order->get_date_completed();
                if ($completed instanceof WC_DateTime) {
                    $remit_date = $completed->date_i18n('Y-m-d');
                }
            }
        }
        $remit_account = (string) $order->get_meta('_tpma_remit_account', true);
        $context = array_merge(
            array(
                // draft / registration
                'course_id'         => $draft['course_id'] ?? '',
                'session_id'        => $draft['session_id'] ?? '',
                'course_name'       => $course['course_name'] ?? ($draft['course_name'] ?? ''),
                'class_date'        => $class_date_display,
                'session_datetime'  => $session_dt ?: '',
                'lecturer_name'     => $lecturer_name,
                'course_hours'      => $course_hours,
                'duration_minutes'  => $duration_minutes,

                'learners'          => is_array($learners) ? $learners : array(),
                'learners_list'     => $ll['text'],
                'learners_count'    => $ll['count'],
                'reg_nos'           => $reg_nos_text,

                'source'            => $draft['source'] ?? '',
                'note'              => $draft['note'] ?? '',

                // 兼容你既有模板用法：
                // - 學員信：reg_no = 該學員 reg_no
                // - 訂單信：reg_no = reg_nos（多筆用逗號）
                'reg_no'            => $reg_nos_text,
            ),
            array(
                // order / woo
                'order_id'              => $order_id,
                'order_number'          => $order->get_order_number(),
                'order_date'            => $order_date,
                'order_status'          => $order->get_status(),
                'order_total'           => $order->get_total(),
                'currency'              => $order->get_currency(),

                'payment_method'        => $order->get_payment_method(),
                'payment_method_title'  => $order->get_payment_method_title(),
                'remit_date'            => $remit_date,
                'remit_account'         => $remit_account,
                'invoice_type'          => $invoice_type_label,
                'invoice_type_display'  => $invoice_type_display,
                'invoice_company'       => $invoice_company,
                'invoice_vat_id'        => $invoice_vat_id,

                // billing（保留你原本姓/名順序邏輯）
                'billing_name'          => trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()),
                'billing_email'         => $order->get_billing_email(),
                'contact_emails'        => (string) $order->get_meta('_tpma_contact_emails', true),
                'order_recipient_emails'=> implode(', ', self::get_order_contact_recipients($order)),
                'billing_phone'         => $order->get_billing_phone(),
                'billing_address'       => $billing_address,

                'shipping_name'         => trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name()),
                'shipping_address_1'    => $order->get_shipping_address_1(),
                'shipping_address_2'    => $order->get_shipping_address_2(),
                'shipping_city'         => $order->get_shipping_city(),
                'shipping_postcode'     => $order->get_shipping_postcode(),
                'shipping_address'      => $shipping_address,
                'order_address'         => $order_address,

            )
        );

        // 學員信：加上 per learner 變數（並讓 reg_no 指向該學員的 reg_no）
        if (!empty($learner)) {
            $student_name = $learner['student_name'] ?? ($learner['name'] ?? '');
            $job_title    = $learner['job_title'] ?? '';
            $student_email = $learner['email'] ?? ($learner['student_email'] ?? ($learner['emails'] ?? ''));

            $student_reg_no = (string)($learner['reg_no'] ?? '');
            $student_reg_id = (string)($learner['reg_id'] ?? ($learner['id'] ?? ''));

            $context['student_name'] = (string)$student_name;
            $context['job_title']    = (string)$job_title;
            $context['student_email'] = (string)$student_email;

            $context['student_reg_no'] = $student_reg_no;
            $context['student_reg_id'] = $student_reg_id;

            // ✅ 學員信的 {{reg_no}} 就是該學員 reg_no
            if ($student_reg_no !== '') {
                $context['reg_no'] = $student_reg_no;
            }

            // 每位學員費用（若 draft 有）
            $context['remit_amount_per_learner'] = $per_fee;
            $context['student_fee'] = $per_fee;
        } else {
            // 訂單信也提供每位學員費用（若 draft 有）
            $context['remit_amount_per_learner'] = $per_fee;
            $context['student_fee'] = $per_fee;
        }

        // remit_amount：訂單總額
        $context['remit_amount'] = $remit_amount;

        // Allow Tutor Bridge and Woo integrations to inject their owned URLs.
        return apply_filters('tpma_cr_mail_context', $context, $order, !empty($learner) ? $learner : null);
    }

    private static function build_address_text(WC_Order $order, string $type): string
    {
        if ($type === 'shipping') {
            $parts = array(
                (string) $order->get_shipping_postcode(),
                (string) $order->get_shipping_state(),
                (string) $order->get_shipping_city(),
                (string) $order->get_shipping_address_1(),
                (string) $order->get_shipping_address_2(),
            );
        } else {
            $parts = array(
                (string) $order->get_billing_postcode(),
                (string) $order->get_billing_state(),
                (string) $order->get_billing_city(),
                (string) $order->get_billing_address_1(),
                (string) $order->get_billing_address_2(),
            );
        }

        $parts = array_values(array_filter(array_map('trim', $parts)));
        return implode(' ', $parts);
    }

    // =========================================================
    // Available vars for TPMA Mailer admin page
    // =========================================================

    private static function get_available_vars(): array
    {
        if (function_exists('tpma_mailer_get_available_vars')) {
            return tpma_mailer_get_available_vars();
        }

        $vars = array(
            // 學員
            'student_name' => '學員姓名',
            'job_title' => '職稱',
            'student_email' => '學員 Email',
            'student_reg_no' => '學員報名編號',
            'student_reg_id' => '學員 RegID',

            // 兼容
            'reg_no' => '報名編號',
            'reg_nos' => '報名清單',

            // 課程
            'course_name' => '課程名稱',
            'lecturer_name' => '講師姓名',
            'class_date' => '課程日期',
            'course_hours' => '課程時數',

            // 訂單
            'order_id' => 'Woo 訂單ID',
            'order_number' => 'Woo 訂單顯示編號',
            'order_date' => '訂單日期',
            'order_total' => '訂單總額',
            'remit_amount' => '訂單總額（order_total）',
            'payment_method_title' => '付款方式',
            'invoice_type' => '發票類型',
            'invoice_type_display' => '發票類型（含抬頭/統編）',
            'invoice_company' => '公司抬頭',
            'invoice_vat_id' => '公司統編',
            'billing_name' => '帳單姓名（Woo 結帳填寫）',
            'billing_email' => '帳單 Email（Woo 結帳填寫）',
            'contact_emails' => '承辦人附加 Email（billing_email 以外）',
            'order_recipient_emails' => '訂單通知收件人清單（billing_email + contact_emails）',
            'billing_phone' => '帳單電話（Woo 結帳填寫）',
            'billing_address' => '帳單地址',
            'shipping_address' => '寄送地址',
            'order_address' => '帳單或寄送地址',

            // 匯款回報（thankyou 回報專用）
            'remit_date' => '匯款日期（thankyou 回報）',
            'remit_account' => '公司戶名或匯款帳號末五碼（thankyou 回報）',

            // 金額（每位）
            'remit_amount_per_learner' => '每位學員費用',
            'student_fee' => '每位學員費用（remit_amount_per_learner）',

            // 清單
            'learners_list' => '學員清單（純文字、可換行）',
            'learners_count' => '學員數',

        );

        return apply_filters('tpma_mailer_available_vars', $vars);
    }

    // =========================================================
    // Mailer assignments (products + flows)
    // =========================================================

    private static function normalize_flow_key(string $flow_key): string
    {
        $flow_key = strtolower(trim($flow_key));
        if (strpos($flow_key, 'wc-') === 0) {
            $flow_key = substr($flow_key, 3);
        }
        return sanitize_key($flow_key);
    }

    private static function get_mailer_products(): array
    {
        if (!function_exists('get_posts')) return array();

        $statuses = array('publish', 'private', 'draft', 'pending', 'future', 'trash');
        $post_ids = get_posts(array(
            'post_type'      => 'product',
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ));

        $products = array();
        foreach ((array)$post_ids as $pid) {
            $post = get_post($pid);
            if (!$post) continue;

            $status = $post->post_status ?: 'publish';
            $status_obj = get_post_status_object($status);
            $status_label = ($status_obj && !empty($status_obj->label)) ? $status_obj->label : $status;
            $note = $status !== 'publish' ? '狀態：' . $status_label : '';

            $products[] = array(
                'id'           => (int)$post->ID,
                'name'         => (string)$post->post_title,
                'status'       => (string)$status,
                'status_label' => (string)$status_label,
                'status_note'  => (string)$note,
            );
        }

        return $products;
    }

    private static function get_mailer_flows(): array
    {
        if (function_exists('tpma_mailer_get_events')) {
            return tpma_mailer_get_events();
        }

        $flows = array();

        if (function_exists('wc_get_order_statuses')) {
            foreach (wc_get_order_statuses() as $key => $label) {
                $flow_key = self::normalize_flow_key((string)$key);
                $flows[] = array(
                    'key'    => $flow_key,
                    'label'  => (string)$label,
                    'source' => 'woocommerce',
                    'note'   => $flow_key === 'on-hold' ? '訂單建立' : '',
                );
            }
        }

        $flows[] = array(
            'key'    => 'admin_remit_report',
            'label'  => '匯款回報通知',
            'source' => 'custom',
            'note'   => 'thankyou',
        );

        return $flows;
    }

    private static function normalize_assign($assign): array
    {
        if (function_exists('tpma_mailer_normalize_template_assign')) {
            return tpma_mailer_normalize_template_assign($assign);
        }

        $products = is_array($assign['products'] ?? null) ? $assign['products'] : array();
        $flows    = is_array($assign['flows'] ?? null) ? $assign['flows'] : array();

        $products = array_values(array_unique(array_filter(array_map('intval', $products))));
        $flows = array_values(array_unique(array_filter(array_map(function ($flow) {
            return self::normalize_flow_key((string)$flow);
        }, $flows))));

        return array(
            'products' => $products,
            'flows'    => $flows,
        );
    }

    private static function normalize_config_assignments(array $config): array
    {
        if (!isset($config['templates']) || !is_array($config['templates'])) {
            $config['templates'] = array();
        }

        foreach ($config['templates'] as $tpl_key => $tpl_cfg) {
            $assign = self::normalize_assign($tpl_cfg['assign'] ?? array());
            $config['templates'][$tpl_key]['assign'] = $assign;
        }

        unset($config['event_routes']);

        return $config;
    }

    private static function get_order_product_ids(WC_Order $order): array
    {
        $ids = array();
        foreach ($order->get_items() as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) continue;
            $pid = (int)$item->get_product_id();
            if ($pid) $ids[] = $pid;
        }
        return array_values(array_unique($ids));
    }

    private static function is_tpma_order_like(WC_Order $order): bool
    {
        if ((bool) $order->get_meta('_tpma_reg_draft_json', true)) return true;
        if ((bool) $order->get_meta('_tpma_reg_no', true)) return true;
        if ((bool) $order->get_meta('_tpma_reg_ids', true)) return true;
        if ((int) $order->get_meta('_tpma_course_id', true) > 0) return true;

        return (bool) apply_filters('tpma_is_tpma_order', false, $order);
    }

    public static function has_template_mapping(WC_Order $order, string $flow_key): bool
    {
        $flow_key = self::normalize_flow_key($flow_key);
        if ($flow_key === '') {
            return false;
        }

        $route_context = array(
            'order'       => $order,
            'product_ids' => self::get_order_product_ids($order),
        );

        return function_exists('tpma_mailer_has_enabled_event_routes')
            ? tpma_mailer_has_enabled_event_routes($flow_key, $route_context)
            : false;
    }

    public static function send_for_order_flow(WC_Order $order, string $flow_key, array $options = array()): bool
    {
        if (!class_exists('TPMA_Mailer')) return false;

        $opt = wp_parse_args($options, array(
            'skip_tpma' => true,
        ));
        if (!empty($opt['skip_tpma']) && self::is_tpma_order_like($order)) {
            return false;
        }

        $flow_key = self::normalize_flow_key($flow_key);
        if (!$flow_key) return false;

        $route_context = array(
            'event_key'   => $flow_key,
            'order'       => $order,
            'product_ids' => self::get_order_product_ids($order),
        );
        $has_route_config = function_exists('tpma_mailer_has_event_route_config')
            ? tpma_mailer_has_event_route_config($flow_key)
            : false;

        $routes = array();
        if (function_exists('tpma_mailer_get_event_routes_for_event')) {
            $routes = tpma_mailer_get_event_routes_for_event($flow_key, $route_context);
        }

        if ($has_route_config && empty($routes)) {
            self::notify_admin_unmatched_event($flow_key, array(
                'reason' => 'event_triggered_but_no_route_matched',
            ), $order);
            return false;
        }
        if (empty($routes)) {
            return false;
        }

        $sent_flag = '_tpma_mailer_sent_' . $flow_key;
        if ($order->get_meta($sent_flag, true) === 'yes') {
            return false;
        }

        $draft = self::get_draft_from_order($order);
        $ctx = self::build_context($order, is_array($draft) ? $draft : array());
        $route_context['draft'] = $draft;
        $route_context['reg_context'] = $ctx;

        $sent = false;
        foreach ($routes as $route) {
            $route_template = self::resolve_existing_template_key(self::extract_route_template($route));
            $route_sources = self::extract_route_sources($route);
            if ($route_template === '') {
                continue;
            }

            $handled_learner_route = false;
            if (in_array('tpma_cr_learner', $route_sources, true)) {
                $learners = is_array($draft['learners'] ?? null) ? $draft['learners'] : array();
                foreach ($learners as $learner) {
                    if (!is_array($learner)) {
                        continue;
                    }

                    $ctx_student = self::build_context($order, is_array($draft) ? $draft : array(), $learner);
                    $learner_context = $route_context;
                    $learner_context['single_learner'] = $learner;
                    $learner_context['reg_context'] = $ctx_student;

                    $learner_recipients = self::get_route_recipients(array('tpma_cr_learner'), $learner_context);
                    if (self::send_route_with_copy_fallback($route_template, $learner_recipients, $ctx_student)) {
                        $sent = true;
                    }
                    if (self::send_route_copies_if_primary_sent($route_template, $learner_recipients, $ctx_student)) {
                        $sent = true;
                    }
                }

                $handled_learner_route = true;
                $route_sources = array_values(array_diff($route_sources, array('tpma_cr_learner')));
            }

            if ($handled_learner_route && empty($route_sources)) {
                continue;
            }

            $all_recipients = self::get_route_recipients($route_sources, $route_context);
            if (self::send_route_with_copy_fallback($route_template, $all_recipients, $ctx)) {
                $sent = true;
            }
            if (self::send_route_copies_if_primary_sent($route_template, $all_recipients, $ctx)) {
                $sent = true;
            }
        }

        if ($sent) {
            $order->update_meta_data($sent_flag, 'yes');
            $order->save();
            return true;
        }

        self::notify_admin_unmatched_event($flow_key, array(
            'reason' => 'routes_matched_but_no_mail_sent',
        ), $order);
        return false;
    }

    // =========================================================
    // REST (保留原有介面；只加 available_vars，並維持 update_all/update_config)
    // =========================================================

    public static function get_mail_templates($request) {
        if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $templates = TPMA_CR_Mail_Templates::get_all();
        $config    = TPMA_CR_Mail_Config::get_config();
        $config    = self::normalize_config_assignments($config);

        return rest_ensure_response(array(
            'templates' => $templates,
            'config'    => $config,
            // ✅ 新增：讓 TPMA Mailer 後台顯示可用變數（不破壞既有 keys）
            'available_vars' => self::get_available_vars(),
            'available_var_groups' => function_exists('tpma_mailer_get_variable_groups') ? tpma_mailer_get_variable_groups() : array(),
            'recipient_sources' => function_exists('tpma_mailer_get_recipient_sources') ? tpma_mailer_get_recipient_sources() : array(),
            'products' => self::get_mailer_products(),
            'flows'    => self::get_mailer_flows(),
            'event_groups' => function_exists('tpma_mailer_get_event_groups') ? tpma_mailer_get_event_groups() : array(),
        ));
    }

    public static function save_mail_templates($request) {
        if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();

        $templates = isset($d['templates']) && is_array($d['templates']) ? $d['templates'] : array();
        $config    = isset($d['config']) && is_array($d['config']) ? $d['config'] : array();
        $config    = self::normalize_config_assignments($config);

        // ✅ 維持你原本的 method 名稱（update_all/update_config）
        TPMA_CR_Mail_Templates::update_all($templates);
        TPMA_CR_Mail_Config::update_config($config);

        return rest_ensure_response(array(
            'success'   => true,
            'templates' => $templates,
            'config'    => $config,
        ));
    }

    public static function preview_mail_template($request) {
        if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();

        $template_key = sanitize_text_field($d['template_key'] ?? '');
        $subject_raw  = (string) ($d['subject'] ?? '');
        $body_raw     = (string) ($d['body_html'] ?? '');
        $context      = is_array($d['context'] ?? null) ? $d['context'] : array();

        if (!$template_key) {
            return new WP_Error('invalid_template_key', '缺少 template_key', array('status' => 400));
        }

        // 簡單版變數替換（沿用你原本的行為）
        $replace = array();
        $raw_keys = apply_filters('tpma_mailer_raw_vars', array(), $context);
        foreach ($context as $k => $v) {
            if (is_array($v)) $v = 'Array';
            if (in_array($k, $raw_keys, true)) {
                $replace['{{' . $k . '}}'] = (string)$v;
            } else {
                $replace['{{' . $k . '}}'] = esc_html((string)$v);
            }
        }

        $subject = strtr($subject_raw, $replace);
        $body    = strtr($body_raw, $replace);

        // 套用廣告與共通尾巴（沿用你原本邏輯）
        $config  = TPMA_CR_Mail_Config::get_config();
        $tpl_cfg = $config['templates'][$template_key] ?? array();

        if (!empty($tpl_cfg['use_ad']) && !empty($tpl_cfg['ad_key'])) {
            $ads   = $config['ads'] ?? array();
            $adKey = $tpl_cfg['ad_key'];
            if (!empty($ads[$adKey]['enabled']) && !empty($ads[$adKey]['html'])) {
                $body .= "\n\n" . $ads[$adKey]['html'];
            }
        }

        if (!empty($config['common_footer_html'])) {
            $body .= "\n\n" . $config['common_footer_html'];
        }

        return rest_ensure_response(array(
            'subject'   => $subject,
            'body_html' => $body,
        ));
    }

    public static function send_test_mail($request) {
        if (!class_exists('TPMA_Mailer')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();

        $template_key = sanitize_text_field($d['template_key'] ?? '');
        $to           = sanitize_email($d['to'] ?? '');
        $reg_context  = is_array($d['reg_context'] ?? null) ? $d['reg_context'] : array();

        if (!$template_key || !$to) {
            return new WP_Error('invalid_args', '缺少 template_key 或 to', array('status' => 400));
        }

        try {
            TPMA_Mailer::send_template($template_key, $to, array(
                'reg_context' => $reg_context,
            ));
        } catch (Exception $e) {
            return new WP_Error('send_failed', $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
        ));
    }

    /**
     * 管理員通知：匯款回報（thankyou 頁）
     * - 僅走 TPMA Mailer 事件路由
     * - 未命中模板時通知管理員
     */
    public static function notify_admin_remit_report(WC_Order $order, string $remit_date, string $remit_account)
    {
        $draft = self::get_draft_from_order($order);
        $ctx   = self::build_context($order, is_array($draft) ? $draft : array(), array());
        $ctx['remit_date']    = $remit_date;
        $ctx['remit_account'] = $remit_account;

        $route_context = array(
            'event_key'   => 'admin_remit_report',
            'order'       => $order,
            'draft'       => $draft,
            'reg_context' => $ctx,
            'product_ids' => self::get_order_product_ids($order),
        );
        $routes = function_exists('tpma_mailer_get_event_routes_for_event')
            ? tpma_mailer_get_event_routes_for_event('admin_remit_report', $route_context)
            : array();

        if (empty($routes)) {
            if (function_exists('tpma_mailer_has_event_route_config') && tpma_mailer_has_event_route_config('admin_remit_report')) {
                self::notify_admin_unmatched_event('admin_remit_report', array(
                    'reason' => 'event_triggered_but_no_route_matched',
                ), $order);
            }
            return;
        }

        foreach ($routes as $route) {
            $route_template = self::resolve_existing_template_key(self::extract_route_template($route));
            $route_sources = self::extract_route_sources($route);
            if ($route_template === '') {
                continue;
            }

            $all_recipients = self::get_route_recipients($route_sources, $route_context);
            self::send_route_with_copy_fallback($route_template, $all_recipients, $ctx);
            self::send_route_copies_if_primary_sent($route_template, $all_recipients, $ctx);
        }
    }


    public static function send_after_order_completed(WC_Order $order, $draft = null): bool
    {
        if ($order->get_meta('_tpma_completed_mail_sent', true) === 'yes') {
            return false;
        }

        $flow_key = self::normalize_flow_key($order->get_status() ?: 'completed');
        $sent = self::send_for_order_flow($order, $flow_key, array(
            'skip_tpma' => false,
        ));

        if ($sent) {
            $order->update_meta_data('_tpma_completed_mail_sent', 'yes');
            $order->save();
        }

        return $sent;
    }

    // ──────────────────────────────────────────────────────────
    // Tutor integration mailers
    // ──────────────────────────────────────────────────────────

    private static function empty_mail_result(): array {
        return array(
            'processed' => 0,
            'updated'   => 0,
            'sent'      => 0,
            'skipped'   => array(),
            'failed'    => array(),
        );
    }

    private static function result_skip(array $result, $id, string $reason, string $message = ''): array {
        $result['skipped'][] = array(
            'id'      => $id,
            'reason'  => $reason,
            'message' => $message !== '' ? $message : $reason,
        );
        return $result;
    }

    private static function result_fail(array $result, $id, string $reason, string $message = ''): array {
        $result['failed'][] = array(
            'id'      => $id,
            'reason'  => $reason,
            'message' => $message !== '' ? $message : $reason,
        );
        return $result;
    }

    private static function build_learner_from_reg(array $reg): array {
        return array(
            'reg_id'       => $reg['id'] ?? '',
            'reg_no'       => $reg['reg_no'] ?? '',
            'student_name' => $reg['student_name'] ?? '',
            'job_title'    => $reg['job_title'] ?? '',
            'emails'       => $reg['emails'] ?? '',
        );
    }

    private static function course_event_aliases(): array {
        return array('course_access', 'pre_class_reminder', 'recorded_course_opened');
    }

    private static function canonical_course_event_key(string $event_key): string {
        $event_key = sanitize_key($event_key);
        return in_array($event_key, self::course_event_aliases(), true) ? 'course_access' : $event_key;
    }

    private static function legacy_course_event_for_mode(string $mode): string {
        return sanitize_key($mode) === 'recorded' ? 'recorded_course_opened' : 'pre_class_reminder';
    }

    private static function get_course_event_routes(string $requested_event_key, string $mode, array $base_context): array {
        if (!function_exists('tpma_mailer_get_event_routes_for_event')) {
            return array('event_key' => $requested_event_key, 'routes' => array(), 'has_config' => false);
        }

        $canonical = self::canonical_course_event_key($requested_event_key);
        $candidates = array();
        if ($requested_event_key !== $canonical) {
            $candidates[] = sanitize_key($requested_event_key);
        }
        $candidates[] = $canonical;
        if ($canonical === 'course_access') {
            $candidates[] = self::legacy_course_event_for_mode($mode);
        }
        $candidates = array_values(array_unique(array_filter($candidates)));

        $has_config = false;
        foreach ($candidates as $event_key) {
            $context = $base_context;
            $context['event_key'] = $event_key;
            try {
                $routes = tpma_mailer_get_event_routes_for_event($event_key, $context);
            } catch (Throwable $e) {
                $routes = array();
            }
            $has_sendable_route = false;
            foreach ((array)$routes as $route) {
                $route = is_array($route) ? $route : array();
                if (self::resolve_existing_template_key(self::extract_route_template($route)) !== '') {
                    $has_sendable_route = true;
                    break;
                }
            }
            if (!empty($routes) && $has_sendable_route) {
                return array('event_key' => $event_key, 'routes' => $routes, 'has_config' => true);
            }
            if (function_exists('tpma_mailer_has_event_route_config') && tpma_mailer_has_event_route_config($event_key)) {
                $has_config = true;
            }
        }

        return array('event_key' => $canonical, 'routes' => array(), 'has_config' => $has_config);
    }

    public static function access_event_meta_key(string $event_key, int $session_id): string {
        return '_tpma_access_event_v2_' . self::canonical_course_event_key($event_key) . '_' . max(0, $session_id);
    }

    public static function reset_access_event_meta_for_order(WC_Order $order, int $session_id = 0, string $event_key = ''): int {
        $deleted = 0;
        $event_key = sanitize_key($event_key);
        $events = $event_key !== '' ? array($event_key) : self::course_event_aliases();
        if (in_array($event_key, self::course_event_aliases(), true)) {
            $events = self::course_event_aliases();
        }

        foreach ($order->get_meta_data() as $meta) {
            if (!is_object($meta) || !method_exists($meta, 'get_data')) {
                continue;
            }
            $data = $meta->get_data();
            $key = (string)($data['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $matched = false;
            foreach ($events as $event) {
                $prefix = '_tpma_access_event_v2_' . $event . '_';
                if (strpos($key, $prefix) !== 0) {
                    continue;
                }
                if ($session_id > 0 && $key !== '_tpma_access_event_v2_' . $event . '_' . $session_id) {
                    continue;
                }
                $matched = true;
                break;
            }
            if ($matched) {
                $order->delete_meta_data($key);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $order->save();
        }

        return $deleted;
    }

    public static function get_mail_event_diagnostics(): array {
        $auto_enabled = class_exists('TPMA_CR_Settings')
            ? TPMA_CR_Settings::is_auto_course_mail_enabled()
            : (bool)(int)get_option('tpma_cr_auto_course_mail_enabled', 0);
        $defaults = self::get_default_templates();
        $defs = array(
            'course_access' => array('label' => '課程入口通知', 'trigger' => '自動 / 手動批次'),
            'certificate_ready' => array('label' => '證書寄發', 'trigger' => '手動批次'),
            'receipt_notice' => array('label' => '收據寄發', 'trigger' => '手動批次'),
        );

        $out = array();
        foreach ($defs as $event_key => $def) {
            $routes = array();
            $route_error = '';
            $diagnostic_event_keys = $event_key === 'course_access' ? self::course_event_aliases() : array($event_key);
            foreach ($diagnostic_event_keys as $diagnostic_event_key) {
                $ctx = array('event_key' => $diagnostic_event_key, 'draft' => array(), 'reg_context' => array());
                if (function_exists('tpma_mailer_get_event_routes_for_event')) {
                    try {
                        $event_routes = tpma_mailer_get_event_routes_for_event($diagnostic_event_key, $ctx);
                        foreach ((array)$event_routes as $route) {
                            if (is_array($route)) {
                                $route['_tpma_diagnostic_event_key'] = $diagnostic_event_key;
                            }
                            $routes[] = $route;
                        }
                    } catch (Throwable $e) {
                        $route_error = $e->getMessage();
                    }
                }
            }

            $sources = array();
            $invalid = array();
            $route_template_keys = array();
            $resolved_route_template_keys = array();
            foreach ((array)$routes as $route) {
                $route = is_array($route) ? $route : array();
                $route_sources = self::extract_route_sources($route);
                $sources = array_merge($sources, $route_sources);
                if ($event_key === 'receipt_notice' && in_array('tpma_cr_learner', $route_sources, true)) {
                    $invalid[] = 'tpma_cr_learner';
                }

                $route_template_key = self::extract_route_template($route);
                if ($route_template_key !== '') {
                    $route_template_keys[] = $route_template_key;
                    $resolved_route_template_key = self::resolve_existing_template_key($route_template_key);
                    if ($resolved_route_template_key !== '') {
                        $resolved_route_template_keys[] = $resolved_route_template_key;
                    }
                }
            }
            $sources = array_values(array_unique(array_filter($sources)));
            $route_template_keys = array_values(array_unique(array_filter($route_template_keys)));
            $resolved_route_template_keys = array_values(array_unique(array_filter($resolved_route_template_keys)));
            $default_template_key = (string)($defaults[$event_key] ?? $event_key);
            $has_route_templates = !empty($route_template_keys);
            $template_keys = $has_route_templates ? $route_template_keys : array($default_template_key);
            $template_exists = $has_route_templates
                ? !empty($resolved_route_template_keys)
                : self::resolve_existing_template_key($default_template_key) !== '';
            $template_summary = $has_route_templates
                ? implode(', ', $route_template_keys)
                : $default_template_key;
            if ($has_route_templates && count($resolved_route_template_keys) < count($route_template_keys)) {
                $missing_templates = array_values(array_diff($route_template_keys, $resolved_route_template_keys));
                if (!empty($missing_templates)) {
                    $template_summary .= '；缺少：' . implode(', ', $missing_templates);
                }
            }
            $has_route_config = !empty($routes);
            if (function_exists('tpma_mailer_has_event_route_config')) {
                foreach ($diagnostic_event_keys as $diagnostic_event_key) {
                    if (tpma_mailer_has_event_route_config($diagnostic_event_key)) {
                        $has_route_config = true;
                        break;
                    }
                }
            }

            $out[$event_key] = array(
                'label'             => $def['label'],
                'trigger'           => $def['trigger'],
                'template_key'      => implode(', ', $template_keys),
                'default_template_key' => $default_template_key,
                'template_summary'  => $template_summary,
                'template_exists'   => $template_exists,
                'route_matched'     => !empty($routes),
                'route_summary'     => !empty($routes) ? count($routes) . ' 個啟用路由' : ($has_route_config ? '有設定但目前條件未命中' : '尚未設定啟用路由') . ($route_error ? '：' . $route_error : ''),
                'recipient_valid'   => !empty($sources) && empty($invalid),
                'recipient_summary' => !empty($sources) ? implode(', ', $sources) : '無收件來源',
                'auto_active'       => $auto_enabled && $event_key === 'course_access',
            );
        }

        return $out;
    }

    private static function normalize_send_options($options): array {
        if (is_bool($options)) {
            $options = array('force' => $options);
        }
        return wp_parse_args(is_array($options) ? $options : array(), array(
            'force' => false,
            'manual' => false,
            'allow_finished' => false,
        ));
    }

    public static function course_event_eligibility(string $event_key, array $reg, $options = array()): array {
        $options = self::normalize_send_options($options);
        $event_key = sanitize_key($event_key);
        if (!in_array($event_key, self::course_event_aliases(), true)) {
            return array('eligible' => false, 'reason' => 'invalid_course_event');
        }
        $reg_id = (int)($reg['id'] ?? 0);
        if ($reg_id <= 0) {
            return array('eligible' => false, 'reason' => 'invalid_registration');
        }
        if (!class_exists('TPMA_Course_Access')) {
            return array('eligible' => false, 'reason' => 'course_access_unavailable');
        }
        $result = TPMA_Course_Access::evaluate_registration($reg_id, 'course', '', !empty($options['manual']));
        if (empty($result['allowed'])) {
            return array('eligible' => false, 'reason' => (string)($result['reason'] ?? 'not_allowed'));
        }
        $row = (array)($result['registration'] ?? $reg);
        $allow_finished = !empty($options['manual']) || !empty($options['allow_finished']);
        if (!$allow_finished && TPMA_Course_Access::registration_session_has_ended($row)) {
            return array('eligible' => false, 'reason' => 'session_finished');
        }
        $mode = sanitize_key((string)($result['mode'] ?? ($row['access_mode'] ?? 'live')));
        if ($event_key === 'pre_class_reminder' && $mode !== 'live') {
            return array('eligible' => false, 'reason' => 'not_live_access');
        }
        if ($event_key === 'recorded_course_opened' && $mode !== 'recorded') {
            return array('eligible' => false, 'reason' => 'not_recorded_access');
        }
        return array('eligible' => true, 'reason' => '', 'registration' => $row, 'mode' => $mode);
    }

    private static function certificate_eligibility(WC_Order $order, array $reg): array {
        if ($order->get_status() !== 'completed') {
            return array('eligible' => false, 'reason' => 'order_not_completed');
        }
        $certificate_id = trim((string)($reg['certificate_id'] ?? ''));
        $status = sanitize_key((string)($reg['status'] ?? ''));
        if ($certificate_id === '' && $status !== 'cert_ready') {
            return array('eligible' => false, 'reason' => 'certificate_missing');
        }
        return array('eligible' => true, 'reason' => '');
    }

    private static function receipt_eligibility(WC_Order $order): array {
        if (!class_exists('TPMA_CR_Receipt_Service')) {
            return array('eligible' => false, 'reason' => 'receipt_service_unavailable', 'message' => '收據服務尚未載入。');
        }
        return TPMA_CR_Receipt_Service::receipt_send_order_eligibility($order);
    }

    /**
     * Send certificate_ready email for a single learner registration.
     * Manual-only in normal flow; Tutor completion hook only updates status/certificate data.
     *
     * @param WC_Order $order
     * @param array    $reg  Row from wp_tpma_registrations (ARRAY_A)
     */
    public static function send_certificate_email(WC_Order $order, array $reg, $options = array()): array {
        $options = self::normalize_send_options($options);
        $result = self::empty_mail_result();
        $result['processed'] = 1;
        $reg_id = (int)($reg['id'] ?? 0);

        if (!class_exists('TPMA_Mailer')) {
            return self::result_fail($result, $reg_id, 'mailer_unavailable', 'TPMA Mailer 未載入');
        }
        $eligible = self::certificate_eligibility($order, $reg);
        if (empty($eligible['eligible'])) {
            return self::result_skip($result, $reg_id, (string)$eligible['reason']);
        }

        $sent_key = '_tpma_certificate_ready_sent_' . $reg_id;
        if (empty($options['force']) && $order->get_meta($sent_key, true) === 'yes') {
            return self::result_skip($result, $reg_id, 'already_sent');
        }

        $draft = self::apply_default_templates(self::get_draft_from_order($order));
        $learner = self::build_learner_from_reg($reg);
        $ctx = self::build_context($order, $draft, $learner);
        $route_context = array(
            'event_key'      => 'certificate_ready',
            'order'          => $order,
            'draft'          => $draft,
            'single_learner' => $learner,
            'reg_context'    => $ctx,
        );
        $routes = function_exists('tpma_mailer_get_event_routes_for_event')
            ? tpma_mailer_get_event_routes_for_event('certificate_ready', $route_context)
            : array();
        if (empty($routes)) {
            if (function_exists('tpma_mailer_has_event_route_config') && tpma_mailer_has_event_route_config('certificate_ready')) {
                self::notify_admin_unmatched_event('certificate_ready', array('reason' => 'event_triggered_but_no_route_matched'), $order);
            }
            return self::result_skip($result, $reg_id, 'no_route');
        }

        $sent = false;
        foreach ($routes as $route) {
            $tpl = self::resolve_existing_template_key(self::extract_route_template($route));
            $sources = self::extract_route_sources(is_array($route) ? $route : array());
            if ($tpl === '') {
                continue;
            }
            $recipients = self::get_route_recipients($sources, $route_context);
            if (empty($recipients) && empty(self::get_copy_recipients_from_config($tpl))) {
                continue;
            }
            if (self::send_route_with_copy_fallback($tpl, $recipients, $ctx)) {
                $sent = true;
            }
            if (self::send_route_copies_if_primary_sent($tpl, $recipients, $ctx)) {
                $sent = true;
            }
        }

        if (!$sent) {
            self::notify_admin_unmatched_event('certificate_ready', array('reason' => 'routes_matched_but_no_mail_sent'), $order);
            return self::result_skip($result, $reg_id, 'no_recipients_or_send_failed');
        }

        $order->update_meta_data($sent_key, 'yes');
        $order->save();
        $result['sent'] = 1;
        return $result;
    }

    /**
     * Send pre_class_reminder email for a single learner registration.
     * Called by TPMA_Tutor_Bridge::send_pre_class_reminders().
     *
     * @param WC_Order $order
     * @param array    $reg  Row from wp_tpma_registrations (ARRAY_A)
     */
    public static function send_reminder_email(WC_Order $order, array $reg): void {
        self::send_course_access_event('course_access', $order, $reg);
    }

    public static function send_course_access_event(string $event_key, WC_Order $order, array $reg): array {
        return self::send_course_access_event_for_regs($event_key, $order, array($reg));
    }

    public static function send_course_access_event_for_regs(string $event_key, WC_Order $order, array $regs, $options = array()): array {
        $options = self::normalize_send_options($options);
        $result = self::empty_mail_result();
        if (!class_exists('TPMA_Mailer')) {
            return self::result_fail($result, $order->get_id(), 'mailer_unavailable', 'TPMA Mailer 未載入');
        }

        $regs = array_values(array_filter($regs, 'is_array'));
        $result['processed'] = count($regs);
        if (empty($regs)) return $result;

        $eligible_regs = array();
        $eligible_modes = array();
        foreach ($regs as $reg) {
            $eligibility = self::course_event_eligibility($event_key, $reg, $options);
            if (empty($eligibility['eligible'])) {
                $result = self::result_skip($result, (int)($reg['id'] ?? 0), (string)($eligibility['reason'] ?? 'not_allowed'));
                continue;
            }
            $eligible_regs[] = (array)($eligibility['registration'] ?? $reg);
            $eligible_modes[] = sanitize_key((string)($eligibility['mode'] ?? 'live'));
        }
        $regs = $eligible_regs;
        if (empty($regs)) return $result;
        $first_reg = $regs[0];
        $first_mode = $eligible_modes[0] ?? sanitize_key((string)($first_reg['access_mode'] ?? 'live'));
        $canonical_event_key = self::canonical_course_event_key($event_key);

        $draft = self::get_draft_from_order($order);
        $draft = self::apply_default_templates(is_array($draft) ? $draft : array());
        $sent_key = self::access_event_meta_key($canonical_event_key, (int)($first_reg['session_id'] ?? 0));
        if (empty($options['force']) && $order->get_meta($sent_key, true) === 'yes') {
            foreach ($regs as $reg) {
                $result = self::result_skip($result, (int)($reg['id'] ?? 0), 'already_sent');
            }
            return $result;
        }

        $first_learner = self::build_learner_from_reg($first_reg);
        $first_ctx = self::build_context($order, $draft, $first_learner);
        $route_context = array(
            'event_key'      => $event_key,
            'order'          => $order,
            'draft'          => $draft,
            'single_learner' => $first_learner,
            'reg_context'    => $first_ctx,
        );
        $route_lookup = self::get_course_event_routes($event_key, $first_mode, $route_context);
        $route_event_key = (string)($route_lookup['event_key'] ?? $canonical_event_key);
        $routes = (array)($route_lookup['routes'] ?? array());
        $route_context['event_key'] = $route_event_key;
        if (empty($routes)) {
            if (!empty($route_lookup['has_config'])) {
                self::notify_admin_unmatched_event($route_event_key, array(
                    'reason' => 'event_triggered_but_no_route_matched',
                ), $order);
            }
            foreach ($regs as $reg) {
                $result = self::result_skip($result, (int)($reg['id'] ?? 0), 'no_route');
            }
            return $result;
        }

        $sent = false;
        $all_complete = true;
        foreach ($routes as $route) {
            $route = is_array($route) ? $route : array();
            $tpl = self::resolve_existing_template_key(self::extract_route_template($route));
            $sources = self::extract_route_sources($route);
            if ($tpl === '') {
                continue;
            }

            if (in_array('tpma_cr_learner', $sources, true)) {
                foreach ($regs as $reg) {
                    $learner = self::build_learner_from_reg($reg);
                    $ctx = self::build_context($order, $draft, $learner);
                    $learner_context = $route_context;
                    $learner_context['single_learner'] = $learner;
                    $learner_context['reg_context'] = $ctx;
                    $recipients = self::get_route_recipients(array('tpma_cr_learner'), $learner_context);
                    if (empty($recipients)) continue;
                    if (self::send_template_to_recipients($tpl, $recipients, $ctx)) {
                        $sent = true;
                        self::send_route_copies_if_primary_sent($tpl, $recipients, $ctx);
                    } else {
                        $all_complete = false;
                    }
                }
            }

            $order_sources = array_values(array_diff($sources, array('tpma_cr_learner')));
            if (!empty($order_sources)) {
                $recipients = self::get_route_recipients($order_sources, $route_context);
                if (self::send_route_with_copy_fallback($tpl, $recipients, $first_ctx)) {
                    $sent = true;
                    self::send_route_copies_if_primary_sent($tpl, $recipients, $first_ctx);
                } elseif (!empty($recipients)) {
                    $all_complete = false;
                }
            }
        }

        if (!$sent) {
            self::notify_admin_unmatched_event($route_event_key, array(
                'reason' => 'routes_matched_but_no_mail_sent',
            ), $order);
            foreach ($regs as $reg) {
                $result = self::result_skip($result, (int)($reg['id'] ?? 0), 'no_recipients_or_send_failed');
            }
            return $result;
        }

        if ($all_complete) {
            $order->update_meta_data($sent_key, 'yes');
            $order->save();
        }
        $result['sent'] = count($regs);
        return $result;
    }

    public static function send_receipt_notice(WC_Order $order, $options = array()): array {
        $options = self::normalize_send_options($options);
        $result = self::empty_mail_result();
        $result['processed'] = 1;
        $order_id = (int)$order->get_id();

        if (!class_exists('TPMA_Mailer')) {
            return self::result_fail($result, $order_id, 'mailer_unavailable', 'TPMA Mailer 未載入');
        }
        if (!class_exists('TPMA_CR_Receipt_Service')) {
            return self::result_fail($result, $order_id, 'receipt_service_unavailable', '收據服務尚未載入');
        }
        $eligible = self::receipt_eligibility($order);
        if (empty($eligible['eligible'])) {
            return self::result_skip($result, $order_id, (string)$eligible['reason'], (string)($eligible['message'] ?? '此訂單目前不可寄發收據。'));
        }

        $receipt = TPMA_CR_Receipt_Service::get_receipt_for_order($order_id);
        if (!is_array($receipt)) {
            return self::result_skip($result, $order_id, 'receipt_not_found', '此訂單尚未有可寄發的收據。');
        }
        $receipt_id = (int)($receipt['id'] ?? 0);
        if ($receipt_id <= 0 || ($receipt['status'] ?? '') === TPMA_CR_Receipt_Service::STATUS_VOID) {
            return self::result_skip($result, $order_id, 'receipt_not_sendable', '此收據已作廢或不可寄發。');
        }
        $receipt_eligibility = TPMA_CR_Receipt_Service::receipt_send_eligibility_for_receipt($receipt_id);
        if (is_wp_error($receipt_eligibility)) {
            return self::result_skip($result, $order_id, 'receipt_source_order_not_sendable', $receipt_eligibility->get_error_message());
        }
        if (($receipt['receipt_type'] ?? '') === 'paper' && ($receipt['status'] ?? '') !== TPMA_CR_Receipt_Service::STATUS_SCANNED) {
            return self::result_skip($result, $order_id, 'receipt_scan_required', '紙本收據須先上傳加蓋實印的掃描檔，才可寄發。');
        }
        if (($receipt['receipt_type'] ?? '') !== 'paper' && !in_array(($receipt['status'] ?? ''), array(TPMA_CR_Receipt_Service::STATUS_GENERATED, TPMA_CR_Receipt_Service::STATUS_SENT), true)) {
            return self::result_skip($result, $order_id, 'receipt_not_generated', '電子收據尚未生成有效 PDF，無法寄發。');
        }
        $attachment = TPMA_CR_Receipt_Service::get_effective_file($receipt_id);
        if (is_wp_error($attachment)) {
            return self::result_fail($result, $order_id, 'receipt_attachment_unavailable', '收據附件無法使用：' . $attachment->get_error_message());
        }
        if (empty($options['force']) && !empty($receipt['sent_at'])) {
            return self::result_skip($result, $order_id, 'already_sent');
        }

        $recipients = TPMA_CR_Receipt_Service::get_recipient_emails($receipt_id);
        if (empty($recipients)) {
            return self::result_skip($result, $order_id, 'receipt_no_recipients', '來源訂單沒有可用的承辦人 Email，未寄出收據。');
        }

        $draft = self::apply_default_templates(self::get_draft_from_order($order));
        $ctx = self::build_context($order, $draft);
        $ctx['receipt_id'] = $receipt_id;
        $ctx['receipt_serial'] = (string)($receipt['serial'] ?? '');
        $ctx['receipt_status'] = (string)($receipt['status'] ?? '');
        $route_context = array(
            'event_key'   => 'receipt_notice',
            'order'       => $order,
            'draft'       => $draft,
            'reg_context' => $ctx,
        );
        $routes = function_exists('tpma_mailer_get_event_routes_for_event')
            ? tpma_mailer_get_event_routes_for_event('receipt_notice', $route_context)
            : array();
        if (empty($routes)) {
            if (function_exists('tpma_mailer_has_event_route_config') && tpma_mailer_has_event_route_config('receipt_notice')) {
                self::notify_admin_unmatched_event('receipt_notice', array('reason' => 'event_triggered_but_no_route_matched'), $order);
            }
            return self::result_skip($result, $order_id, 'no_route');
        }

        $template_key = '';
        $has_template_without_order_contact = false;
        // The TPMA Mailer admin UI exposes woo_order_contact as the canonical
        // Woo billing/contact source. Keep the original TPMA-specific key for
        // templates saved before the shared Woo source was introduced.
        $receipt_recipient_sources = array('woo_order_contact', 'tpma_cr_order_contact');
        foreach ($routes as $route) {
            $route = is_array($route) ? $route : array();
            $candidate = self::resolve_existing_template_key(self::extract_route_template($route));
            if ($candidate !== '') {
                $sources = self::extract_route_sources($route);
                if (!array_intersect($receipt_recipient_sources, $sources)) {
                    $has_template_without_order_contact = true;
                    continue;
                }
                $template_key = $candidate;
                break;
            }
        }
        if ($template_key === '') {
            if ($has_template_without_order_contact) {
                return self::result_skip(
                    $result,
                    $order_id,
                    'receipt_route_not_order_contact',
                    '收據寄件模板必須設定收件人為「Woo 訂購人/聯絡人」（woo_order_contact），目前沒有符合設定的可用模板，未寄出收據。'
                );
            }
            return self::result_skip($result, $order_id, 'no_route', '收據寄件設定沒有可用的信件模板。');
        }

        $sent = false;
        $all_deliveries_succeeded = true;
        $had_delivery_attempt = false;

        // A receipt is sent through only the first matching route. Multiple
        // configured receipt routes used to create duplicate attachments for
        // the same contact; configured copy recipients are handled below.
        foreach ($recipients as $to) {
            $had_delivery_attempt = true;
            try {
                $delivered = TPMA_Mailer::send_template($template_key, $to, array(
                    'reg_context' => $ctx,
                    'attachments' => array($attachment),
                ));
                if ($delivered) {
                    $sent = true;
                } else {
                    $all_deliveries_succeeded = false;
                }
            } catch (Throwable $e) {
                $all_deliveries_succeeded = false;
                error_log('[TPMA CR Mail] receipt send failed receipt=' . $receipt_id . ': ' . $e->getMessage());
            }
        }

        if (!$had_delivery_attempt || !$sent || !$all_deliveries_succeeded) {
            self::notify_admin_unmatched_event('receipt_notice', array('reason' => 'routes_matched_but_no_mail_sent'), $order);
            return self::result_fail($result, $order_id, 'receipt_send_failed', '收據信件未能全部成功寄出，收據狀態未標記為已寄發。');
        }

        // Preserve the existing configured-copy behavior, with the same
        // private attachment. Copy delivery does not alter the successful
        // primary-recipient state, matching other TPMA Mailer events.
        foreach (array_diff(self::get_copy_recipients_from_config($template_key), $recipients) as $copy_to) {
            try {
                TPMA_Mailer::send_template($template_key, $copy_to, array(
                    'reg_context' => $ctx,
                    'attachments' => array($attachment),
                ));
            } catch (Throwable $e) {
                error_log('[TPMA CR Mail] receipt copy send failed receipt=' . $receipt_id . ': ' . $e->getMessage());
            }
        }

        $marked = TPMA_CR_Receipt_Service::mark_sent($receipt_id);
        if (is_wp_error($marked)) {
            return self::result_fail($result, $order_id, 'receipt_mark_sent_failed', '信件已送出，但無法更新收據寄發狀態：' . $marked->get_error_message());
        }
        $result['sent'] = 1;
        return $result;
    }

}


