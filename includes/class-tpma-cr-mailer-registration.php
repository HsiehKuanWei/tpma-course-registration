<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Mailer_Registration
{
    public static function init(): void
    {
        add_action('tpma_mailer_register_variables', array(__CLASS__, 'register_variables'));
        add_action('tpma_mailer_register_events', array(__CLASS__, 'register_events'));
        add_action('tpma_mailer_register_recipient_sources', array(__CLASS__, 'register_recipient_sources'));
    }

    private static function normalize_emails($raw): array
    {
        if (empty($raw)) {
            return array();
        }

        $parts = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
        $emails = array();
        foreach ((array) $parts as $part) {
            $email = sanitize_email((string) $part);
            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    public static function register_recipient_sources(): void
    {
        if (!function_exists('tpma_mailer_register_recipient_source')) {
            return;
        }

        tpma_mailer_register_recipient_source('tpma_cr_learner', array(
            'label'             => 'TPMA 學員 Email',
            'description'       => '來源：TPMA 報名 learners（email/student_email/emails）。',
            'origin'            => 'tpma',
            'plugin'            => 'tpma-course-registration',
            'compatible_groups' => array('woocommerce-order', 'tpma-course-registration-tutor'),
            'resolver'    => function (array $context): array {
                $single = is_array($context['single_learner'] ?? null) ? $context['single_learner'] : array();
                $reg = is_array($context['reg_context'] ?? null) ? $context['reg_context'] : array();
                $emails = array();

                if (!empty($single)) {
                    foreach (array($single['email'] ?? null, $single['student_email'] ?? null, $single['emails'] ?? null) as $raw) {
                        if ($raw === null || $raw === '') {
                            continue;
                        }
                        $emails = array_merge($emails, self::normalize_emails($raw));
                    }
                    return array_values(array_unique($emails));
                }

                if (!empty($reg['student_email'])) {
                    foreach (array($reg['student_email'] ?? null, $reg['email'] ?? null) as $raw) {
                        if ($raw === null || $raw === '') {
                            continue;
                        }
                        $emails = array_merge($emails, self::normalize_emails($raw));
                    }
                    if (!empty($emails)) {
                        return array_values(array_unique($emails));
                    }
                }

                $learners = is_array($reg['learners'] ?? null) ? $reg['learners'] : array();
                foreach ($learners as $learner) {
                    if (!is_array($learner)) {
                        continue;
                    }

                    foreach (array($learner['email'] ?? null, $learner['student_email'] ?? null, $learner['emails'] ?? null) as $raw) {
                        if ($raw === null || $raw === '') {
                            continue;
                        }
                        $emails = array_merge($emails, self::normalize_emails($raw));
                    }
                }

                return array_values(array_unique($emails));
            },
        ));

        tpma_mailer_register_recipient_source('tpma_cr_order_contact', array(
            'label'             => 'TPMA 承辦人（Woo 訂單聯絡）',
            'description'       => '來源：Woo billing_email + _tpma_contact_emails。',
            'origin'            => 'woo',
            'plugin'            => 'tpma-course-registration',
            'compatible_groups' => array('woocommerce-order', 'tpma-course-registration-tutor'),
            'resolver'    => function (array $context): array {
                $order = $context['order'] ?? null;
                if (!$order instanceof WC_Order) {
                    return array();
                }

                $emails = array();
                $billing = sanitize_email((string) $order->get_billing_email());
                if ($billing !== '' && is_email($billing)) {
                    $emails[] = $billing;
                }

                $emails = array_merge($emails, self::normalize_emails($order->get_meta('_tpma_contact_emails', true)));
                return array_values(array_unique($emails));
            },
        ));
    }

    public static function register_variables(): void
    {
        if (!function_exists('tpma_mailer_register_variable_group')) {
            return;
        }

        tpma_mailer_register_variable_group('tpma-cr-learner', array(
            'label'       => 'TPMA 報名 / 學員',
            'plugin'      => 'tpma-course-registration',
            'plugin_label'=> 'TPMA 課程報名',
            'description' => '報名資料與單一學員寄信內容。',
            'vars'        => array(
                'student_name'              => array('label' => '學員姓名', 'description' => '單一學員姓名。'),
                'job_title'                 => array('label' => '職稱', 'description' => '學員職稱。'),
                'student_email'             => array('label' => '學員 Email', 'description' => '單一學員 Email。'),
                'student_reg_no'            => array('label' => '學員報名編號', 'description' => '單一學員的報名編號。'),
                'student_reg_id'            => array('label' => '學員 RegID', 'description' => '單一學員在報名表的資料 ID。'),
                'reg_no'                    => array('label' => '報名編號', 'description' => '目前信件主報名編號（學員信常為 student_reg_no）。'),
                'reg_nos'                   => array('label' => '報名清單', 'description' => '同筆訂單所有報名編號（逗號分隔）。'),
                'learners_list'             => array('label' => '學員清單', 'description' => '純文字，可換行。'),
                'learners_count'            => array('label' => '學員數', 'description' => '本次報名學員人數。'),
                'remit_amount_per_learner'  => array('label' => '每位學員費用', 'description' => '每位學員分攤或設定費用。'),
                'student_fee'               => array('label' => '每位學員費用', 'description' => 'remit_amount_per_learner 的別名。'),
                'source'                    => array('label' => '報名來源', 'description' => '報名來源標記（例如前台/匯入）。'),
                'note'                      => array('label' => '報名備註', 'description' => '報名備註內容。'),
            ),
        ));

        tpma_mailer_register_variable_group('tpma-cr-course', array(
            'label'       => 'TPMA 報名 / 課程',
            'plugin'      => 'tpma-course-registration',
            'plugin_label'=> 'TPMA 課程報名',
            'description' => '課程、場次與講師資料。',
            'vars'        => array(
                'course_id'        => array('label' => '課程 ID', 'description' => '課程資料主鍵 ID。'),
                'session_id'       => array('label' => '場次 ID', 'description' => '課程場次主鍵 ID。'),
                'course_name'      => array('label' => '課程名稱', 'description' => '課程標題。'),
                'class_date'       => array('label' => '課程日期', 'description' => '格式化後的課程日期時間字串。'),
                'session_datetime' => array('label' => '場次時間', 'description' => '原始場次日期時間值。'),
                'lecturer_name'    => array('label' => '講師姓名', 'description' => '講師名稱。'),
                'course_hours'     => array('label' => '課程時數', 'description' => '課程總時數（小時）。'),
                'duration_minutes' => array('label' => '課程分鐘數', 'description' => '課程總分鐘數。'),
            ),
        ));

        tpma_mailer_register_variable_group('tpma-cr-tutor-links', array(
            'label'       => 'TPMA 報名 / Tutor 連結',
            'plugin'      => 'tpma-course-registration',
            'plugin_label'=> 'TPMA 課程報名',
            'description' => 'TPMA Tutor Bridge 產生的免登入與課程連結。',
            'vars'        => array(
                'magic_link_course'      => array('label' => '免登入課程連結', 'description' => '可直接登入課程的 magic link。'),
                'magic_link_portal'      => array('label' => '訂單共用課程入口', 'description' => '同一訂單所有學員共用的課程入口。'),
                'magic_link_quiz'        => array('label' => '免登入測驗連結', 'description' => '可直接進入測驗的 magic link。'),
                'magic_link_certificate' => array('label' => '免登入證書連結', 'description' => '可直接查看證書的 magic link。'),
                'magic_link_meet'        => array('label' => '免登入 Google Meet 連結', 'description' => '課程會議用免登入連結。'),
                'google_meet_url'        => array('label' => 'Google Meet URL', 'description' => '一般 Google Meet 連結。'),
                'tutor_course_url'       => array('label' => 'Tutor 課程網址', 'description' => 'Tutor 課程頁 URL。'),
                'class_link'             => array('label' => '課程連結', 'description' => '課程或教室連結。'),
                'payment_link'           => array('label' => '繳費回報連結', 'description' => '匯款/繳費回報頁連結。'),
                'admin_link'             => array('label' => '後台管理連結', 'description' => '管理員查看訂單或資料的後台連結。'),
            ),
        ));
    }

    public static function register_events(): void
    {
        if (!function_exists('tpma_mailer_register_event_group')) {
            return;
        }

        tpma_mailer_register_event_group('tpma-course-registration-tutor', array(
            'label'       => 'for Tutor',
            'plugin'      => 'tpma-course-registration',
            'plugin_label'=> 'tpma-course-registration',
            'category_key'=> 'tutor',
            'category_label' => 'for Tutor',
            'description' => '依附 Tutor Bridge 的 TPMA 延伸事件。',
            'events'      => array(
                'course_access' => array(
                    'label'                => '課程存取連結',
                    'source'               => 'tpma-course-registration',
                    'note'                 => 'Tutor Bridge',
                    'default_template_key' => 'course_access',
                    'default_template'     => array(
                        'subject'   => 'TPMA 課程連結：{{course_name}}',
                        'body_html' => '<p>{{student_name}} 您好：</p><p>您的課程連結如下：</p><p><a href="{{magic_link_course}}">前往課程</a></p>',
                    ),
                ),
                'pre_class_reminder' => array(
                    'label'                => '課前提醒',
                    'source'               => 'tpma-course-registration',
                    'note'                 => 'cron',
                    'default_template_key' => 'pre_class_reminder',
                    'default_template'     => array(
                        'subject'   => 'TPMA 課前提醒：{{course_name}}',
                        'body_html' => '<p>{{student_name}} 您好：</p><p>提醒您課程即將開始。</p><ul><li>課程名稱：{{course_name}}</li><li>課程日期：{{class_date}}</li></ul><p><a href="{{magic_link_meet}}">前往線上教室</a></p>',
                    ),
                ),
                'recorded_course_opened' => array(
                    'label'                => '錄播課程開放通知',
                    'source'               => 'tpma-course-registration',
                    'note'                 => 'cron',
                    'default_template_key' => 'recorded_course_opened',
                    'default_template'     => array(
                        'subject'   => 'TPMA 錄播課程已開放：{{course_name}}',
                        'body_html' => '<p>您好：</p><p>錄播課程已開放，請由以下共用入口選擇學員後進入：</p><p><a href="{{magic_link_portal}}">進入課程</a></p>',
                    ),
                ),
                'quiz_invitation' => array(
                    'label'                => '測驗邀請',
                    'source'               => 'tpma-course-registration',
                    'note'                 => 'admin trigger',
                    'default_template_key' => 'quiz_invitation',
                    'default_template'     => array(
                        'subject'   => 'TPMA 測驗通知：{{course_name}}',
                        'body_html' => '<p>{{student_name}} 您好：</p><p>請透過以下連結完成測驗：</p><p><a href="{{magic_link_quiz}}">前往測驗</a></p>',
                    ),
                ),
                'certificate_ready' => array(
                    'label'                => '證書完成',
                    'source'               => 'tpma-course-registration',
                    'note'                 => 'Tutor course completed',
                    'default_template_key' => 'certificate_ready',
                    'default_template'     => array(
                        'subject'   => 'TPMA 結訓證書通知：{{course_name}}',
                        'body_html' => '<p>{{student_name}} 您好：</p><p>您的結訓證書已可檢視。</p><p><a href="{{magic_link_certificate}}">查看證書</a></p>',
                    ),
                ),
            ),
        ));
    }
}

TPMA_CR_Mailer_Registration::init();
