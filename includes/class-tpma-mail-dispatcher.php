<?php
if (!defined('ABSPATH')) exit;

class TPMA_CR_Mail_Dispatcher
{
    /**
     * 依據 draft + Woo order 寄：
     * 1) 報名資訊信（student template）→ 每一筆 learner 都寄（即使 email 相同也不合併）
     * 2) 訂單資料信（order template）→ 每一筆 Woo 訂單寄 1 封
     * 並支援模板「副本/抄送」收件人（依 config 設定補寄）
     */

    /**
     * 統一日期時間顯示：YYYY/MM/DD（週） HH:MM~HH:MM
     */
    private static function format_class_datetime($session_datetime, $duration_minutes = 0): string
    {
        if (!$session_datetime) return '';

        $ts = strtotime(str_replace('T', ' ', (string)$session_datetime));
        if (!$ts) return '';

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
     * 將字串/陣列整理成 email 陣列（逗號/分號/空白分隔都可）
     */
    private static function normalize_emails($raw): array
    {
        $out = array();
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,;]+/', (string)$raw);
        }

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

            // ✅ mail-modal 實際使用的欄位（通常是 array）
            foreach (array('default_cc', 'default_bcc') as $k) {
                if (!empty($tpl_cfg[$k])) $candidates[] = $tpl_cfg[$k];
            }

            // ✅ 兼容其他可能命名（若你之後 UI/DB 有變）
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
     * 寄出信件（由 Woo 觸發）：每筆 learner 1 封報名資訊 ver + 每筆訂單 1 封訂單 ver
     */
    public static function send_after_order_created(WC_Order $order, array $draft)
    {
        if (!class_exists('TPMA_Mailer')) return;

        // 避免同一訂單重複寄送（若你要允許重寄，請改 meta key 或移除此段）
        if ($order->get_meta('_tpma_mail_sent') === 'yes') {
            return;
        }

        $templates   = $draft['mail_templates'] ?? array();
        $tpl_student = $templates['student'] ?? '';
        $tpl_order   = $templates['order'] ?? '';

        $learners = $draft['learners'] ?? array();

        // === 1) 報名資訊信：每一筆 learner 都寄（即使 email 相同也不合併）===
        if ($tpl_student && !empty($learners) && is_array($learners)) {
            foreach ($learners as $learner) {
                if (!is_array($learner)) continue;

                // 每筆 learner 自己的收件人
                $student_emails = array();
                foreach (array($learner['emails'] ?? null, $learner['student_email'] ?? null, $learner['email'] ?? null) as $raw) {
                    if ($raw) $student_emails = array_merge($student_emails, self::normalize_emails($raw));
                }

                // 去重（同一 learner 若填了重複 email 不要重寄）
                $student_emails = array_values(array_unique($student_emails));

                // 本筆 learner 的 context
                $ctx_student = self::build_context($order, $draft, $learner);

                // 主收件人：該 learner email（可能多個）
                foreach ($student_emails as $to) {
                    TPMA_Mailer::send_template($tpl_student, $to, array(
                        'reg_context' => $ctx_student,
                    ));
                }

                // 模板副本：同一筆 learner 也要補寄（通常是行政副本/留存）
                foreach (self::get_copy_recipients_from_config($tpl_student) as $copy) {
                    TPMA_Mailer::send_template($tpl_student, $copy, array(
                        'reg_context' => $ctx_student,
                    ));
                }
            }
        }

        // === 2) 訂單資料信：每筆 Woo 訂單寄 1 封 ===
        if ($tpl_order) {
            $ctx_order = self::build_context($order, $draft); // order template 用（learner 留空，會帶 learners_list）

            $billing_email = trim((string)$order->get_billing_email());
            if ($billing_email && is_email($billing_email)) {
                TPMA_Mailer::send_template($tpl_order, $billing_email, array(
                    'reg_context' => $ctx_order,
                ));
            }

            // 模板副本：訂單 ver 也補寄
            foreach (self::get_copy_recipients_from_config($tpl_order) as $copy) {
                TPMA_Mailer::send_template($tpl_order, $copy, array(
                    'reg_context' => $ctx_order,
                ));
            }
        }

        $order->update_meta_data('_tpma_mail_sent', 'yes');
        $order->save();
    }

    /**
     * build_context：建立模板變數（寄信用）
     * - 優先使用 draft 內資訊；若缺，就以 course_id 回查 courses / lecturers 補齊
     * - class_date 統一輸出成字串（包含週與時間區間）
     * - learners_list 為純文字（避免信件顯示 HTML 標記）
     */
    private static function build_context(WC_Order $order, array $draft, array $learner = array()): array
    {
        global $wpdb;

        $order_id = $order->get_id();

        $course  = $draft['course'] ?? array();
        $session = $draft['session'] ?? array();

        // === 補齊課程資料：若 draft 沒帶 course，則用 course_id 回查 courses table ===
        if ((empty($course) || !is_array($course)) && !empty($draft['course_id']) && class_exists('TPMA_CR_DB')) {
            $courses_table = TPMA_CR_DB::table('courses');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$courses_table} WHERE id = %d",
                intval($draft['course_id'])
            ), ARRAY_A);
            if (is_array($row)) $course = $row;
        }

        // duration_minutes → course_hours
        $duration_minutes = intval($course['duration_minutes'] ?? 0);
        $course_hours = ($duration_minutes > 0) ? ($duration_minutes / 60) : '';

        // lecturer_name（courses.lecturer_code → lecturers）
        $lecturer_name = '';
        $lecturer_code = $course['lecturer_code'] ?? '';
        if ($lecturer_code && class_exists('TPMA_CR_DB')) {
            $lecturers_table = TPMA_CR_DB::table('lecturers');
            $lect = $wpdb->get_row($wpdb->prepare(
                "SELECT lecturers_name, lecturers_title
                 FROM {$lecturers_table}
                 WHERE lecturers_code = %s",
                $lecturer_code
            ));
            if ($lect && !empty($lect->lecturers_name)) {
                $lecturer_name = trim(
                    $lect->lecturers_name .
                    (!empty($lect->lecturers_title) ? ' ' . $lect->lecturers_title : '')
                );
            }
        }

        // === class_date（寄信用顯示字串）===
        $class_date_display = '';
        $session_dt = $draft['session_datetime'] ?? ($session['session_datetime'] ?? '');
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

        // remit_amount：目前用訂單總額（你若要改成特定 line item 金額，可再調）
        $remit_amount = $order->get_total();

        $context = array_merge(
            array(
                // === registration / draft ===
                'course_id'         => $draft['course_id'] ?? '',
                'session_id'        => $draft['session_id'] ?? '',
                'course_name'       => $course['course_name'] ?? ($draft['course_name'] ?? ''),
                'class_date'        => $class_date_display,
                'session_datetime'  => $session_dt ?: '',

                // learners：原始陣列（模板若直接用 {{learners}} 會變 Array，不建議）
                'learners'          => $draft['learners'] ?? array(),

                'source'            => $draft['source'] ?? '',
                'note'              => $draft['note'] ?? '',

                // 模板常用變數
                'reg_no'            => $order->get_order_number(),
                'lecturer_name'     => $lecturer_name,
                'course_hours'      => $course_hours,
                'remit_amount'      => $remit_amount,
            ),
            array(
                // === order / woo ===
                'order_id'              => $order_id,
                'order_number'          => $order->get_order_number(),
                'order_status'          => $order->get_status(),
                'order_total'           => $order->get_total(),
                'currency'              => $order->get_currency(),

                'payment_method'        => $order->get_payment_method(),
                'payment_method_title'  => $order->get_payment_method_title(),

                'billing_name'          => trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()),
                'billing_email'         => $order->get_billing_email(),
                'billing_phone'         => $order->get_billing_phone(),

                'shipping_name'         => trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name()),
                'shipping_address_1'    => $order->get_shipping_address_1(),
                'shipping_address_2'    => $order->get_shipping_address_2(),
                'shipping_city'         => $order->get_shipping_city(),
                'shipping_postcode'     => $order->get_shipping_postcode(),
            )
        );

        // 如果有傳入單一學員資料（報名資訊 ver），則加入學員相關變數
        if (!empty($learner)) {
            $context['student_name'] = $learner['student_name'] ?? ($learner['name'] ?? '');
            $context['job_title']    = $learner['job_title'] ?? '';
            // 讓模板可用 learner email（若你模板有用）
            $context['student_email'] = $learner['email'] ?? ($learner['student_email'] ?? ($learner['emails'] ?? ''));
        }

        // 如果是訂單通知信 (即 $learner 為空)，則生成學員列表（純文字）
        if (empty($learner) && !empty($draft['learners']) && is_array($draft['learners'])) {
            $lines = array();
            $idx = 1;
            foreach ($draft['learners'] as $lr) {
                if (!is_array($lr)) continue;

                $name  = trim((string)($lr['student_name'] ?? ($lr['name'] ?? '')));
                $title = trim((string)($lr['job_title'] ?? ''));
                $email = trim((string)($lr['email'] ?? ($lr['student_email'] ?? ($lr['emails'] ?? ''))));

                $line = "{$idx}. 姓名：{$name}";
                if ($title) $line .= "；職稱：{$title}";
                if ($email) $line .= "；Email：{$email}";
                $lines[] = $line;
                $idx++;
            }
            $context['learners_list']  = implode("\n", $lines);
            $context['learners_count'] = count($lines);
        }

        return $context;
    }

    // === 以下為原有 REST 介面（保留不動） ===

    public static function get_mail_templates($request) {
        if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $templates = TPMA_CR_Mail_Templates::get_all();
        $config    = TPMA_CR_Mail_Config::get_config();

        return rest_ensure_response(array(
            'templates' => $templates,
            'config'    => $config,
        ));
    }

    public static function save_mail_templates($request) {
        if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();

        $templates = isset($d['templates']) && is_array($d['templates']) ? $d['templates'] : array();
        $config    = isset($d['config']) && is_array($d['config']) ? $d['config'] : array();

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

        // 簡單版變數替換，模仿 TPMA_CR_Mail_Templates::replace_vars()
        $replace = array();
        foreach ($context as $k => $v) {
            if (is_array($v)) $v = 'Array';
            $replace['{{' . $k . '}}'] = esc_html((string)$v);
        }

        $subject = strtr($subject_raw, $replace);
        $body    = strtr($body_raw, $replace);

        // 套用廣告與共通尾巴，模仿 render() 裡的邏輯
        $config  = TPMA_CR_Mail_Config::get_config();
        $tpl_cfg = $config['templates'][$template_key] ?? array();

        // 廣告
        if (!empty($tpl_cfg['use_ad']) && !empty($tpl_cfg['ad_key'])) {
            $ads   = $config['ads'] ?? array();
            $adKey = $tpl_cfg['ad_key'];
            if (!empty($ads[$adKey]['enabled']) && !empty($ads[$adKey]['html'])) {
                $body .= "\n\n" . $ads[$adKey]['html'];
            }
        }

        // 共通尾巴
        if (!empty($config['common_footer_html'])) {
            $body .= "\n\n" . $config['common_footer_html'];
        }

        return rest_ensure_response(array(
            'subject'   => $subject,
            'body_html' => $body,
        ));
    }

    public static function send_test_mail($request) {
        if (!class_exists('TPMA_CR_Mail_Service')) {
            return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();

        $template_key = sanitize_text_field($d['template_key'] ?? '');
        $to           = sanitize_email($d['to'] ?? '');
        $reg_context   = is_array($d['reg_context'] ?? null) ? $d['reg_context'] : array();

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
