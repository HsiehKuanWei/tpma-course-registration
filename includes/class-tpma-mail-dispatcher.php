<?php
if (!defined('ABSPATH')) exit;

class TPMA_CR_Mail_Dispatcher
{
    /**
     * ✅ 目標：
     * - 寄信流程完全不依賴 session
     * - 只依賴 Woo order meta（_tpma_reg_draft_json、_tpma_reg_ids）與 DB
     * - learners_list 以純文字輸出（可讀、可換行）
     * - 明確列出可用模板變數（mail-modal 顯示）
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
     * 從 Mail Config 取得某模板的「副本/抄送」收件人設定（若有）
     * mail-modal 目前使用的是 cfg.default_cc / cfg.default_bcc
     */
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
            $parts[] = "姓名：{$name}" . ($title ? "（{$title}）" : "");
            if ($email) $parts[] = "Email：{$email}";
            if ($regno) $parts[] = "報名編號：{$regno}";
            if ($regid) $parts[] = "RegID：{$regid}";

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
                $regs_table = TPMA_CR_DB::table('registrations');

                $ids = array_values(array_filter(array_map('intval', $reg_ids)));
                if (!empty($ids)) {
                    $in = implode(',', array_fill(0, count($ids), '%d'));
                    $sql = $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id IN ($in) ORDER BY id ASC", $ids);
                    $rows = $wpdb->get_results($sql, ARRAY_A);

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

        // 4) session_datetime（若 draft 沒帶）
        if (empty($draft['session_datetime'])) {
            $sd = $order->get_meta('_tpma_session_datetime', true);
            if ($sd) $draft['session_datetime'] = $sd;
        }

        // 5) mail_templates（若 draft 沒帶，使用 config defaults）
        if (empty($draft['mail_templates']) && class_exists('TPMA_CR_Mail_Config')) {
            $cfg = TPMA_CR_Mail_Config::get_config();
            $defaults = $cfg['default_templates'] ?? $cfg['defaults'] ?? array();

            $draft['mail_templates'] = array(
                'student' => (string)($defaults['student'] ?? ''),
                'order'   => (string)($defaults['order'] ?? ''),
            );
        }

        return is_array($draft) ? $draft : array();
    }

    /**
     * ✅ lookup lecturer_name：courses.lecturer_code -> lecturers 表
     * - 兼容不同欄位命名（lecturer_* / lecturers_*）
     */
    private static function lookup_lecturer_name($lecturer_code): string
    {
        global $wpdb;
        $code = trim((string)$lecturer_code);
        if ($code === '' || !class_exists('TPMA_CR_DB')) return '';

        $tbl = TPMA_CR_DB::table('lecturers');

        // 兼容欄位命名差異
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE lecturer_code = %s OR lecturers_code = %s LIMIT 1",
            $code, $code
        ), ARRAY_A);

        if (!$row) return '';

        $name  = (string)($row['lecturer_name'] ?? $row['lecturers_name'] ?? '');
        $title = (string)($row['lecturer_title'] ?? $row['lecturers_title'] ?? '');

        $name = trim($name);
        $title = trim($title);

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

        if (!is_array($draft)) {
            $draft = self::get_draft_from_order($order);
        }

        $templates   = $draft['mail_templates'] ?? array();
        $tpl_student = (string)($templates['student'] ?? '');
        $tpl_order   = (string)($templates['order'] ?? '');

        $learners = $draft['learners'] ?? array();

        // 1) 學員信：每一筆 learner 都寄
        if ($tpl_student && !empty($learners) && is_array($learners)) {
            foreach ($learners as $learner) {
                if (!is_array($learner)) continue;

                $student_emails = array();
                foreach (array($learner['emails'] ?? null, $learner['student_email'] ?? null, $learner['email'] ?? null) as $raw) {
                    if ($raw) $student_emails = array_merge($student_emails, self::normalize_emails($raw));
                }
                $student_emails = array_values(array_unique($student_emails));

                $ctx_student = self::build_context($order, $draft, $learner);

                foreach ($student_emails as $to) {
                    TPMA_Mailer::send_template($tpl_student, $to, array(
                        'reg_context' => $ctx_student,
                    ));
                }

                foreach (self::get_copy_recipients_from_config($tpl_student) as $copy) {
                    TPMA_Mailer::send_template($tpl_student, $copy, array(
                        'reg_context' => $ctx_student,
                    ));
                }
            }
        }

        // 2) 訂單信：每筆訂單 1 封
        if ($tpl_order) {
            $ctx_order = self::build_context($order, $draft);

            $billing_email = trim((string)$order->get_billing_email());
            if ($billing_email && is_email($billing_email)) {
                TPMA_Mailer::send_template($tpl_order, $billing_email, array(
                    'reg_context' => $ctx_order,
                ));
            }

            foreach (self::get_copy_recipients_from_config($tpl_order) as $copy) {
                TPMA_Mailer::send_template($tpl_order, $copy, array(
                    'reg_context' => $ctx_order,
                ));
            }
        }

        $order->update_meta_data('_tpma_mail_sent', 'yes');
        $order->save();
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
            $sessions_table = TPMA_CR_DB::table('course_sessions');
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
        $lecturer_code = $course['lecturer_code'] ?? ($draft['lecturer_code'] ?? '');
        if ($lecturer_code) {
            $lecturer_name = self::lookup_lecturer_name($lecturer_code);
        }
        if (!$lecturer_name) {
            $lecturer_name = (string)($draft['lecturer_name'] ?? '');
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
                'order_status'          => $order->get_status(),
                'order_total'           => $order->get_total(),
                'currency'              => $order->get_currency(),

                'payment_method'        => $order->get_payment_method(),
                'payment_method_title'  => $order->get_payment_method_title(),

                // billing（保留你原本姓/名順序邏輯）
                'billing_name'          => trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()),
                'billing_email'         => $order->get_billing_email(),
                'billing_phone'         => $order->get_billing_phone(),

                'shipping_name'         => trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name()),
                'shipping_address_1'    => $order->get_shipping_address_1(),
                'shipping_address_2'    => $order->get_shipping_address_2(),
                'shipping_city'         => $order->get_shipping_city(),
                'shipping_postcode'     => $order->get_shipping_postcode(),

                // 你要的「訂單查詢連結」（order-received/?key=...）
                'order_public_url'      => method_exists($order, 'get_checkout_order_received_url') ? $order->get_checkout_order_received_url() : '',
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

        return $context;
    }

    // =========================================================
    // Available vars for mail-modal
    // =========================================================

    private static function get_available_vars(): array
    {
        return array(
            // 學員
            'student_name' => '學員姓名（學員信）',
            'job_title' => '職稱（學員信）',
            'student_email' => '學員 Email（學員信）',
            'student_reg_no' => '學員報名編號（學員信）',
            'student_reg_id' => '學員 RegID（學員信）',

            // 兼容
            'reg_no' => '報名編號（學員信＝該學員；訂單信＝reg_nos）',
            'reg_nos' => '報名編號清單（訂單含多學員）',

            // 課程
            'course_name' => '課程名稱',
            'lecturer_name' => '講師姓名（courses.lecturer_code -> lecturers lookup）',
            'class_date' => '課程日期（含週與起迄時間）',
            'course_hours' => '課程時數（由 duration_minutes 換算）',

            // 訂單
            'order_id' => 'Woo 訂單ID（例如 1535）',
            'order_number' => 'Woo 訂單顯示編號（可能等於 order_id）',
            'order_total' => '訂單總額',
            'remit_amount' => '訂單總額（同 order_total）',

            // 金額（每位）
            'remit_amount_per_learner' => '每位學員費用（draft.remit_amount_per_learner）',
            'student_fee' => '每位學員費用（同 remit_amount_per_learner）',

            // 清單
            'learners_list' => '學員清單（純文字、可換行）',
            'learners_count' => '學員數',

            // 連結
            'order_public_url' => '訂單查詢連結（order-received/?key=...）',
        );
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

        return rest_ensure_response(array(
            'templates' => $templates,
            'config'    => $config,
            // ✅ 新增：讓 mail-modal 顯示可用變數（不破壞既有 keys）
            'available_vars' => self::get_available_vars(),
        ));
    }

    public static function save_mail_templates($request) {
        if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();

        $templates = isset($d['templates']) && is_array($d['templates']) ? $d['templates'] : array();
        $config    = isset($d['config']) && is_array($d['config']) ? $d['config'] : array();

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
        foreach ($context as $k => $v) {
            if (is_array($v)) $v = 'Array';
            $replace['{{' . $k . '}}'] = esc_html((string)$v);
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
        // 這裡維持你舊版依賴 TPMA_CR_Mail_Service 的模式（恢復原有功能）
        if (!class_exists('TPMA_CR_Mail_Service')) {
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
            TPMA_CR_Mail_Service::send($template_key, array(
                'reg_context' => $reg_context,
                'to'          => $to,
            ));
        } catch (Exception $e) {
            return new WP_Error('send_failed', $e->getMessage(), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
        ));
    }
}
