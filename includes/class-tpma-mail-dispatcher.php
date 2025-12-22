<?php
if (!defined('ABSPATH')) exit;

class TPMA_CR_Mail_Dispatcher
{
    /**
     * 依據 draft + Woo order 寄：
     * 1) 學員資料信 → learners email
     * 2) 訂單資料信 → billing email
     */
    public static function send_after_order_created(WC_Order $order, array $draft)
    {
        if (!class_exists('TPMA_Mailer')) return;

        $order_id = $order->get_id();

        // 避免重複寄送
        if ($order->get_meta('_tpma_mail_sent') === 'yes') {
            return;
        }

        $templates = $draft['mail_templates'] ?? [];
        $tpl_student = $templates['student'] ?? '';
        $tpl_order   = $templates['order'] ?? '';

        // 1) 學員資料信：寄給 form learners 的 email
        $learners = $draft['learners'] ?? [];
        if ($tpl_student && !empty($learners)) {
            foreach ($learners as $learner) {
                $student_emails = [];
                $candidates = [];

                if (!empty($learner['emails'])) $candidates[] = $learner['emails'];
                if (!empty($learner['student_email'])) $candidates[] = $learner['student_email'];
                if (!empty($learner['email'])) $candidates[] = $learner['email'];

                foreach ($candidates as $raw) {
                    foreach (preg_split('/[,\s;]+/', (string)$raw) as $e) {
                        $e = trim($e);
                        if ($e && is_email($e)) $student_emails[] = $e;
                    }
                }
                $student_emails = array_values(array_unique($student_emails));

                if (!empty($student_emails)) {
                    $ctx_student = self::build_context($order, $draft, $learner);

                    // ✅ 修正：Mailer 第三參數要傳 args（reg_context / extra_context），不能直接丟 context
                    foreach ($student_emails as $to) {
                        TPMA_Mailer::send_template($tpl_student, $to, array(
                            'reg_context' => $ctx_student,
                        ));
                    }
                }
            }
        }

        // 2) 訂單資料信：寄給 Woo checkout billing email
        $billing_email = trim((string)$order->get_billing_email());
        if ($tpl_order && $billing_email) {
            $ctx_order = self::build_context($order, $draft); // For order email, no specific learner context

            // ✅ 修正：同上
            TPMA_Mailer::send_template($tpl_order, $billing_email, array(
                'reg_context' => $ctx_order,
            ));
        }

        $order->update_meta_data('_tpma_mail_sent', 'yes');
        $order->save();
    }

    private static function collect_student_emails(array $draft): array
    {
        $emails = [];
        $learners = isset($draft['learners']) && is_array($draft['learners']) ? $draft['learners'] : [];

        foreach ($learners as $lr) {
            if (!is_array($lr)) continue;

            // 你系統可能是 emails / student_email / email
            $candidates = [];

            if (!empty($lr['emails'])) $candidates[] = $lr['emails'];
            if (!empty($lr['student_email'])) $candidates[] = $lr['student_email'];
            if (!empty($lr['email'])) $candidates[] = $lr['email'];

            foreach ($candidates as $raw) {
                // 若 lr['emails'] 可能是逗號分隔
                foreach (preg_split('/[,\s;]+/', (string)$raw) as $e) {
                    $e = trim($e);
                    if ($e && is_email($e)) $emails[] = $e;
                }
            }
        }

        $emails = array_values(array_unique($emails));
        return $emails;
    }

    private static function build_context(WC_Order $order, array $draft, array $learner = []): array
    {
        global $wpdb;

        $order_id = $order->get_id();

        $course  = $draft['course'] ?? [];
        $session = $draft['session'] ?? [];

        // === 補齊課程資料：若 draft 沒帶 course，則用 course_id 回查 courses table ===
        if (
            (empty($course) || !is_array($course))
            && !empty($draft['course_id'])
            && class_exists('TPMA_CR_DB')
        ) {
            $courses_table = TPMA_CR_DB::table('courses');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$courses_table} WHERE id = %d",
                intval($draft['course_id'])
            ), ARRAY_A);

            if (is_array($row)) {
                $course = $row; // 轉成 array
            }
        }

        // === 依 course 回算：course_hours & lecturer_name（避免空值）===
        $duration_minutes = intval($course['duration_minutes'] ?? 0);
        $course_hours = ($duration_minutes > 0) ? ($duration_minutes / 60) : '';

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

        $context = array_merge(
            [
                // === registration / draft ===
                'course_id'   => $draft['course_id'] ?? '',
                'session_id'  => $draft['session_id'] ?? '',
                'course_name' => $course['course_name'] ?? ($draft['course_name'] ?? ''),
                'class_date'  => $draft['class_date'] ?? '',
                'session_datetime' => $draft['session_datetime'] ?? ($session['session_datetime'] ?? ''),

                // （注意：learners 是 array，若模板直接用 {{learners}} 會變成 Array；建議用 learners_list）
                'learners'    => $draft['learners'] ?? [],

                'source' => $draft['source'] ?? '',
                'note'   => $draft['note'] ?? '',

                // 模板中需要的額外變數
                'reg_no'        => $order->get_order_number(), // 暫時使用訂單號作為報名編號
                'lecturer_name' => $lecturer_name,
                'course_hours'  => $course_hours,
                'remit_amount'  => $order->get_total(), // 暫時使用訂單總金額
            ],
            [
                // === order / woo ===
                'order_id'     => $order_id,
                'order_number' => $order->get_order_number(),
                'order_status' => $order->get_status(),
                'order_total'  => $order->get_total(),
                'currency'     => $order->get_currency(),

                'payment_method'       => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),

                'billing_name'  => trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()),
                'billing_email' => $order->get_billing_email(),
                'billing_phone' => $order->get_billing_phone(),

                'shipping_name' => trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name()),
                'shipping_address_1' => $order->get_shipping_address_1(),
                'shipping_address_2' => $order->get_shipping_address_2(),
                'shipping_city'      => $order->get_shipping_city(),
                'shipping_postcode'  => $order->get_shipping_postcode(),
            ]
        );

        // 如果有傳入單一學員資料，則加入學員相關變數
        if (!empty($learner)) {
            $context['student_name'] = $learner['student_name'] ?? $learner['name'] ?? '';
            $context['job_title']    = $learner['job_title'] ?? '';
        }

        // 如果是訂單通知信 (即 $learner 為空)，則生成學員列表 HTML
        if (empty($learner) && !empty($draft['learners'])) {
            $learners_list_html = '<ul>';
            foreach ($draft['learners'] as $lr) {
                $name  = esc_html($lr['student_name'] ?? $lr['name'] ?? '');
                $title = esc_html($lr['job_title'] ?? '');
                $email = esc_html($lr['email'] ?? $lr['student_email'] ?? $lr['emails'] ?? '');

                $learners_list_html .= "<li>";
                $learners_list_html .= "姓名: {$name}";
                if ($title) {
                    $learners_list_html .= ", 職稱: {$title}";
                }
                if ($email) {
                    $learners_list_html .= ", Email: {$email}";
                }
                $learners_list_html .= "</li>";
            }
            $learners_list_html .= '</ul>';
            $context['learners_list'] = $learners_list_html;
        }

        return $context;
    }

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
            $replace['{{' . $k . '}}'] = esc_html($v);
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
