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
            // 其他情境可再擴充；保留管理員匯款通知的常用 key
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
     * ✅ lookup lecturer_name：courses.lecturer_code -> lecturers 表
     * - 不能用 OR 連不存在的欄位，會讓整個 SQL 失敗（Unknown column）
     * - 先查舊欄位 lecturers_code，再查新欄位 lecturer_code
     */
    private static function lookup_lecturer_name($lecturer_code): string
    {
        global $wpdb;
        $code = trim((string)$lecturer_code);
        if ($code === '' || !class_exists('TPMA_CR_DB')) return '';

        $tbl = TPMA_CR_DB::table('lecturers');

        // ① 先用舊版欄位：lecturers_code（你舊版有效就是靠這個）
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tbl} WHERE lecturers_code = %s LIMIT 1", $code),
            ARRAY_A
        );

        // ② 再嘗試新版欄位：lecturer_code（若你的表其實有）
        if (!$row) {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$tbl} WHERE lecturer_code = %s LIMIT 1", $code),
                ARRAY_A
            );
        }

        if (!$row) return '';

        // 兼容 name/title 欄位
        $name  = trim((string)($row['lecturers_name'] ?? $row['lecturer_name'] ?? ''));
        $title = trim((string)($row['lecturers_title'] ?? $row['lecturer_title'] ?? ''));

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
        if (self::has_template_mapping($order, $flow_key)
            && apply_filters('tpma_mailer_skip_tpma_order_flow', true, $order, $flow_key)) {
            return;
        }

        if (!is_array($draft)) {
            $draft = self::get_draft_from_order($order);
        }
        $draft = self::apply_default_templates(is_array($draft) ? $draft : array());

        $templates   = $draft['mail_templates'] ?? array();
        $tpl_student = (string)($templates['student'] ?? '');
        $tpl_order   = (string)($templates['order'] ?? '');
        $flow_key    = $flow_key ?: self::normalize_flow_key($order->get_status() ?: 'on-hold');

        $tpl_student = self::resolve_template_for_flow($order, $flow_key, $tpl_student);
        $tpl_order   = self::resolve_template_for_flow($order, $flow_key, $tpl_order);

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
        $invoice_company = (string) $order->get_billing_company();
        $invoice_vat_id = (string) $order->get_meta('_billing_vat_id', true);
        $invoice_type_display = $invoice_type_label;
        if ($invoice_type_raw === 'three') {
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
        $order_public_url = method_exists($order, 'get_checkout_order_received_url')
            ? $order->get_checkout_order_received_url()
            : '';

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
                'order_items_table'     => self::build_order_items_table($order),

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
                'billing_phone'         => $order->get_billing_phone(),
                'billing_address'       => $billing_address,

                'shipping_name'         => trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name()),
                'shipping_address_1'    => $order->get_shipping_address_1(),
                'shipping_address_2'    => $order->get_shipping_address_2(),
                'shipping_city'         => $order->get_shipping_city(),
                'shipping_postcode'     => $order->get_shipping_postcode(),
                'shipping_address'      => $shipping_address,
                'order_address'         => $order_address,

                // 你要的「訂單查詢連結」（order-received/?key=...）
                'order_public_url'      => $order_public_url,
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

    private static function build_order_items_table(WC_Order $order): string
    {
        $items = $order->get_items();
        if (empty($items)) {
            return '';
        }

        $currency = $order->get_currency();
        $subtotal = 0;
        foreach ($items as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $subtotal += (float) $item->get_subtotal();
        }
        $tax_total = (float) $order->get_total_tax();
        $shipping_total = (float) $order->get_shipping_total();
        $shipping_tax = (float) $order->get_shipping_tax();
        $shipping_amount = $shipping_total + $shipping_tax;
        $total = (float) $order->get_total();
        $summary_flags = apply_filters('tpma_thankyou_summary_flags', array(
            'show_subtotal' => true,
            'show_shipping' => true,
            'show_tax'      => true,
        ), $order);
        $show_subtotal = !empty($summary_flags['show_subtotal']);
        $show_shipping = !empty($summary_flags['show_shipping']);
        $show_tax = !empty($summary_flags['show_tax']);

        $rows = '';
        foreach ($items as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product_name = $item->get_name();
            $qty = (int) $item->get_quantity();
            $line_total = (float) $item->get_total();

            $rows .= '<tr>';
            $rows .= '<td style="border:1px solid #ddd;padding:8px 10px;vertical-align:top;">'
                . '<span class="tpma-mail-label" style="display:none;font-weight:600;margin-bottom:4px;">商品名稱</span>'
                . '<span class="tpma-mail-value">' . esc_html($product_name) . '</span>'
                . '</td>';
            $rows .= '<td style="border:1px solid #ddd;padding:8px 10px;vertical-align:top;">'
                . '<span class="tpma-mail-label" style="display:none;font-weight:600;margin-bottom:4px;">數量</span>'
                . '<span class="tpma-mail-value">' . esc_html((string) $qty) . '</span>'
                . '</td>';
            $rows .= '<td style="border:1px solid #ddd;padding:8px 10px;vertical-align:top;">'
                . '<span class="tpma-mail-label" style="display:none;font-weight:600;margin-bottom:4px;">金額</span>'
                . '<span class="tpma-mail-value">' . wp_kses_post(wc_price($line_total, ['currency' => $currency])) . '</span>'
                . '</td>';

            $meta_html = '';
            if (function_exists('wc_display_item_meta')) {
                ob_start();
                wc_display_item_meta($item);
                $meta_html = trim((string) ob_get_clean());
            }
            $meta_text = $meta_html !== '' ? wp_strip_all_tags($meta_html) : '-';

            $rows .= '<td style="border:1px solid #ddd;padding:8px 10px;vertical-align:top;">'
                . '<span class="tpma-mail-label" style="display:none;font-weight:600;margin-bottom:4px;">備註</span>'
                . '<span class="tpma-mail-value">' . esc_html($meta_text) . '</span>'
                . '</td>';
            $rows .= '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        $style = '<style>
            @media only screen and (max-width: 620px) {
                .tpma-mail-table thead { display: none !important; }
                .tpma-mail-table tr { display: block !important; margin-bottom: 12px; border: 1px solid #e5e7eb; }
                .tpma-mail-table td { display: block !important; width: 100% !important; box-sizing: border-box; border: none !important; border-bottom: 1px solid #e5e7eb !important; }
                .tpma-mail-table td:last-child { border-bottom: none !important; }
                .tpma-mail-label { display: block !important; }
            }
        </style>';

        $table = $style;
        $table .= '<table class="tpma-mail-table" style="width:100%;border-collapse:collapse;">';
        $table .= '<thead><tr style="background:#f3f4f6;">';
        $table .= '<th style="text-align:left;border:1px solid #ddd;padding:8px 10px;">商品名稱</th>';
        $table .= '<th style="text-align:left;border:1px solid #ddd;padding:8px 10px;">數量</th>';
        $table .= '<th style="text-align:left;border:1px solid #ddd;padding:8px 10px;">金額</th>';
        $table .= '<th style="text-align:left;border:1px solid #ddd;padding:8px 10px;">備註</th>';
        $table .= '</tr></thead>';
        $table .= '<tbody>' . $rows;
        if ($show_subtotal) {
            $table .= '<tr>';
            $table .= '<td colspan="3" style="text-align:right;border:1px solid #ddd;padding:8px 10px;font-weight:600;">合計</td>';
            $table .= '<td style="border:1px solid #ddd;padding:8px 10px;font-weight:600;">' . wp_kses_post(wc_price($subtotal, ['currency' => $currency])) . '</td>';
            $table .= '</tr>';
        }
        if ($show_shipping && $shipping_amount > 0) {
            $table .= '<tr>';
            $table .= '<td colspan="3" style="text-align:right;border:1px solid #ddd;padding:8px 10px;font-weight:600;">運費</td>';
            $table .= '<td style="border:1px solid #ddd;padding:8px 10px;font-weight:600;">' . wp_kses_post(wc_price($shipping_amount, ['currency' => $currency])) . '</td>';
            $table .= '</tr>';
        }
        if ($show_tax) {
            $table .= '<tr>';
            $table .= '<td colspan="3" style="text-align:right;border:1px solid #ddd;padding:8px 10px;font-weight:600;">營業稅</td>';
            $table .= '<td style="border:1px solid #ddd;padding:8px 10px;font-weight:600;">' . wp_kses_post(wc_price($tax_total, ['currency' => $currency])) . '</td>';
            $table .= '</tr>';
        }
        $table .= '<tr>';
        $table .= '<td colspan="3" style="text-align:right;border:1px solid #ddd;padding:8px 10px;font-weight:700;">總計金額</td>';
        $table .= '<td style="border:1px solid #ddd;padding:8px 10px;font-weight:700;">' . wp_kses_post(wc_price($total, ['currency' => $currency])) . '</td>';
        $table .= '</tr>';
        $table .= '</tbody></table>';

        return $table;
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
    // Available vars for mail-modal
    // =========================================================

    private static function get_available_vars(): array
    {
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
            'order_items_table' => '訂購項目',
            'payment_method_title' => '付款方式',
            'invoice_type' => '發票類型',
            'invoice_type_display' => '發票類型（含抬頭/統編）',
            'invoice_company' => '公司抬頭',
            'invoice_vat_id' => '公司統編',
            'billing_name' => '帳單姓名（Woo 結帳填寫）',
            'billing_email' => '帳單 Email（Woo 結帳填寫）',
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

            // 連結
            'order_public_url' => '訂單查詢連結',
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
            return $config;
        }

        foreach ($config['templates'] as $tpl_key => $tpl_cfg) {
            $assign = self::normalize_assign($tpl_cfg['assign'] ?? array());
            $config['templates'][$tpl_key]['assign'] = $assign;
        }

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

    private static function resolve_template_for_flow(WC_Order $order, string $flow_key, string $fallback): string
    {
        $template_key = self::find_template_for_flow($order, $flow_key);
        return $template_key !== '' ? $template_key : $fallback;
    }

    private static function find_template_for_flow(WC_Order $order, string $flow_key): string
    {
        if (!class_exists('TPMA_CR_Mail_Config')) return '';

        $flow_key = self::normalize_flow_key($flow_key);
        if (!$flow_key) return '';

        $config = TPMA_CR_Mail_Config::get_config();
        $tpl_cfgs = is_array($config['templates'] ?? null) ? $config['templates'] : array();
        if (empty($tpl_cfgs)) return '';

        $product_ids = self::get_order_product_ids($order);
        if (empty($product_ids)) return '';

        foreach ($tpl_cfgs as $tpl_key => $cfg) {
            $assign = self::normalize_assign($cfg['assign'] ?? array());
            if (empty($assign['products']) || empty($assign['flows'])) continue;
            if (!in_array($flow_key, $assign['flows'], true)) continue;

            foreach ($product_ids as $pid) {
                if (in_array($pid, $assign['products'], true)) {
                    return (string)$tpl_key;
                }
            }
        }

        return '';
    }

    private static function is_tpma_order_like(WC_Order $order): bool
    {
        if ((bool) $order->get_meta('_tpma_reg_draft_json', true)) return true;
        if ((bool) $order->get_meta('_tpma_reg_no', true)) return true;
        if ((bool) $order->get_meta('_tpma_reg_ids', true)) return true;
        if ((int) $order->get_meta('_tpma_course_id', true) > 0) return true;
        if ((string) $order->get_meta('_tpma_invoice_type', true) !== '') return true;
        if ((string) $order->get_meta('_billing_tpma_invoice_type', true) !== '') return true;
        if ((string) $order->get_meta('_tpma_postcode', true) !== '') return true;
        if ((string) $order->get_meta('_tpma_state', true) !== '') return true;
        if ((string) $order->get_meta('_tpma_city', true) !== '') return true;
        if ((string) $order->get_meta('_tpma_street', true) !== '') return true;

        return (bool) apply_filters('tpma_is_tpma_order', false, $order);
    }

    public static function has_template_mapping(WC_Order $order, string $flow_key): bool
    {
        return self::find_template_for_flow($order, $flow_key) !== '';
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

        $template_key = self::find_template_for_flow($order, $flow_key);
        if ($template_key === '') return false;

        $sent_flag = '_tpma_mailer_sent_' . $flow_key;
        if ($order->get_meta($sent_flag, true) === 'yes') {
            return false;
        }
        $created_tpl_flag = '';
        if (in_array($flow_key, array('pending', 'on-hold'), true)) {
            $created_tpl_flag = '_tpma_mailer_sent_created_tpl_' . md5($template_key);
            if ($order->get_meta($created_tpl_flag, true) === 'yes') {
                return false;
            }
        }

        $draft = self::get_draft_from_order($order);
        $ctx = self::build_context($order, is_array($draft) ? $draft : array());

        $sent = false;
        $billing_email = trim((string)$order->get_billing_email());
        if ($billing_email && is_email($billing_email)) {
            try {
                if (TPMA_Mailer::send_template($template_key, $billing_email, array(
                    'reg_context' => $ctx,
                ))) {
                    $sent = true;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        $copies = self::get_copy_recipients_from_config($template_key);
        foreach ((array)$copies as $copy) {
            $copy = trim((string)$copy);
            if (!$copy || !is_email($copy)) continue;
            try {
                if (TPMA_Mailer::send_template($template_key, $copy, array(
                    'reg_context' => $ctx,
                ))) {
                    $sent = true;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        if ($sent) {
            $order->update_meta_data($sent_flag, 'yes');
            if ($created_tpl_flag !== '') {
                $order->update_meta_data($created_tpl_flag, 'yes');
            }
            $order->save();
        }

        return $sent;
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
            // ✅ 新增：讓 mail-modal 顯示可用變數（不破壞既有 keys）
            'available_vars' => self::get_available_vars(),
            'products' => self::get_mailer_products(),
            'flows'    => self::get_mailer_flows(),
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
        $raw_keys = apply_filters('tpma_mailer_raw_vars', array('order_items_table'), $context);
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
     * - 優先使用資料庫模板（TPMA_Mailer::send_template）
     * - 若模板/寄送類別不可用，fallback 純文字 wp_mail
     */
    public static function notify_admin_remit_report(WC_Order $order, string $remit_date, string $remit_account)
    {
        // 主收件人：admin_email + 後台設定 tpma_cr_admin_notify_emails（逗號/分號）
        $admin_email = get_option('admin_email');
        $extra = get_option('tpma_cr_admin_notify_emails', '');
        $to = array();
        if ($admin_email && is_email($admin_email)) {
            $to[] = sanitize_email($admin_email);
        }
        $to = array_merge($to, self::normalize_emails($extra));
        $to = array_values(array_unique(array_filter($to, 'is_email')));
        if (empty($to)) return;

        // 模板 key
        $defaults = self::get_default_templates();
        $template_key = $defaults['admin_remit_report'] ?? 'admin_remit_report';
        $template_key = self::resolve_template_for_flow($order, 'admin_remit_report', $template_key);

        // draft/context
        $draft = self::get_draft_from_order($order);
        $ctx   = self::build_context($order, is_array($draft) ? $draft : array(), array());
        $ctx['remit_date']    = $remit_date;
        $ctx['remit_account'] = $remit_account;
        $ctx['admin_link']    = admin_url('post.php?post=' . $order->get_id() . '&action=edit');

        // 合併模板內的副本/抄送
        if (class_exists('TPMA_Mailer')) {
            $all_recipients = $to;
            if (method_exists(__CLASS__, 'get_copy_recipients_from_config')) {
                $copies = self::get_copy_recipients_from_config($template_key);
                $all_recipients = array_merge($all_recipients, (array)$copies);
            }
            $all_recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $all_recipients), 'is_email')));

            foreach ($all_recipients as $email) {
                TPMA_Mailer::send_template($template_key, $email, array(
                    'reg_context' => $ctx,
                ));
            }
            return;
        }

        // fallback 純文字
        $subject = sprintf('[TPMA] 匯款回報｜訂單 #%s', $order->get_order_number());
        $body  = "收到匯款回報：\n";
        $body .= "訂單編號：#" . $order->get_order_number() . "\n";
        $body .= "回報匯款日期：" . $remit_date . "\n";
        $body .= "回報公司戶名/末五碼：" . $remit_account . "\n";
        $body .= "後台訂單：" . $ctx['admin_link'] . "\n";
        wp_mail($to, $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));
    }


    public static function send_after_order_completed(WC_Order $order, $draft = null): bool
    {
        if (!class_exists('TPMA_Mailer')) return false;

        $flow_key = self::normalize_flow_key($order->get_status() ?: 'completed');
        if (self::has_template_mapping($order, $flow_key)
            && apply_filters('tpma_mailer_skip_tpma_order_flow', true, $order, $flow_key)) {
            return false;
        }

        if (!is_array($draft)) {
            $draft = self::get_draft_from_order($order);
        }
        $draft = self::apply_default_templates(is_array($draft) ? $draft : array());

        $templates = is_array($draft['mail_templates'] ?? null) ? $draft['mail_templates'] : array();
        // ✅ 沒有就用預設模板 key（避免 draft 沒帶 completed 造成完全不寄）
        $tpl_completed = trim((string)($templates['completed'] ?? self::get_default_templates()['completed'] ?? 'registration_completed'));
        $flow_key = $flow_key ?: self::normalize_flow_key($order->get_status() ?: 'completed');
        $tpl_completed = self::resolve_template_for_flow($order, $flow_key, $tpl_completed);

        // 如果你想要「必須存在模板才寄」，可在 TPMA_Mailer 內處理；這裡先嘗試寄
        $ctx = self::build_context($order, $draft);

        $sent = false;

        // 寄給訂購者（Woo 結帳信箱）
        $billing_email = trim((string)$order->get_billing_email());
        if ($billing_email && is_email($billing_email)) {
            $ok = TPMA_Mailer::send_template($tpl_completed, $billing_email, array(
                'reg_context' => $ctx,
            ));
            if ($ok) $sent = true;
        }

        // 副本（若模板有設定）
        $copies = array();
        if (method_exists(__CLASS__, 'get_copy_recipients_from_config')) {
            $copies = self::get_copy_recipients_from_config($tpl_completed);
        }
        foreach ((array)$copies as $copy) {
            $copy = trim((string)$copy);
            if (!$copy || !is_email($copy)) continue;
            $ok = TPMA_Mailer::send_template($tpl_completed, $copy, array(
                'reg_context' => $ctx,
            ));
            if ($ok) $sent = true;
        }

        return $sent;
    }


}


