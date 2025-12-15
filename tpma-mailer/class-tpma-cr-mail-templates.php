<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Mail_Templates
{
    const OPTION_KEY = 'tpma_cr_mail_templates';

    /**
     * 取得所有模板（若沒有存過就給預設）
     */
    public static function get_all()
    {
        $defaults = self::get_default_templates();
        $saved    = get_option(self::OPTION_KEY, array());

        return wp_parse_args($saved, $defaults);
    }

    /**
     * 更新所有模板（給 REST 用）
     */
    public static function update_all(array $templates)
    {
        update_option(self::OPTION_KEY, $templates);
        return $templates;
    }

    /**
     * 渲染指定模板：套 context、廣告、共通尾巴
     */
    public static function render($template_key, array $context)
    {
        $templates = self::get_all();
        if (empty($templates[$template_key])) {
            throw new Exception('未知的信件模板：' . $template_key);
        }

        $tpl = $templates[$template_key];

        $subject  = self::replace_vars($tpl['subject'], $context);
        $body     = self::replace_vars($tpl['body_html'], $context);

        // 加廣告 & 共通尾巴
        $config = TPMA_CR_Mail_Config::get_config();

        // 廣告
        $tpl_cfg = $config['templates'][$template_key] ?? [];
        if (!empty($tpl_cfg['use_ad']) && !empty($tpl_cfg['ad_key'])) {
            $ads   = $config['ads'] ?? [];
            $adKey = $tpl_cfg['ad_key'];
            if (!empty($ads[$adKey]['enabled']) && !empty($ads[$adKey]['html'])) {
                $body .= "\n\n" . $ads[$adKey]['html'];
            }
        }

        // 共通尾巴
        if (!empty($config['common_footer_html'])) {
            $body .= "\n\n" . $config['common_footer_html'];
        }

        return [
            'subject'   => $subject,
            'body_html' => $body,
        ];
    }

    /**
     * 預設模板（之後你可以從前端改）
     */
    protected static function get_default_templates()
    {
        return [
            'registration_notice' => [
                'subject'   => 'TPMA 上市櫃董事進修課程-「{{course_name}}」報名成功通知及匯款資訊',
                'body_html' =>
					'<p>{{student_name}} {{job_title}} 敬安：</p>' .
					'<br>' .
					'<p>非常感謝您報名本學會「上市上櫃公司董事及公司治理主管」進修課程，我們已收到您的報名申請。</p>' .
					'<br>' .
					'<p><strong>以下為您報名的課程及匯款資訊：</strong></p>' .
					'<ul>' .
						'課程名稱：{{course_name}}<br>' .
						'課程日期：{{class_date}}<br>' .
						'課程模式：線上遠距教學<br>' .
						'課程費用：{{remit_paid_at}}<br>' .
					'</ul>' ,
            ],

            'class_notice' => [
                'subject'   => '課程即將開課：{{course_name}}',
                'body_html' =>
                    '<p>{{student_name}} 您好：</p>' .
                    '<p>提醒您，您所報名的課程即將開課：</p>' .
                    '<ul>' .
                        '<li>課程名稱：{{course_name}}</li>' .
                        '<li>課程日期：{{class_date}}</li>' .
                        '<li>報名編號：{{reg_no}}</li>' .
                    '</ul>' .
                    '<p>線上會議連結：{{class_link}}</p>',
            ],

            'payment_reminder' => [
                'subject'   => 'TPMA 課程繳費提醒：{{course_name}}',
                'body_html' =>
                    '<p>{{student_name}} 您好：</p>' .
                    '<p>提醒您，以下課程尚未完成繳費：</p>' .
                    '<ul>' .
                        '<li>課程名稱：{{course_name}}</li>' .
                        '<li>報名編號：{{reg_no}}</li>' .
                    '</ul>' .
                    '<p>您可透過以下連結回報匯款資訊：{{payment_link}}</p>',
            ],

            'receipt_notice' => [
                'subject'   => 'TPMA 收據通知：{{course_name}}（{{reg_no}}）',
                'body_html' =>
                    '<p>{{student_name}} 您好：</p>' .
                    '<p>您於 TPMA 報名之課程收據已開立並隨信附上，敬請查收。</p>' .
                    '<ul>' .
                        '<li>課程名稱：{{course_name}}</li>' .
                        '<li>報名編號：{{reg_no}}</li>' .
                    '</ul>',
            ],

            'certificate_notice' => [
                'subject'   => 'TPMA 結訓證書通知：{{course_name}}',
                'body_html' =>
                    '<p>{{student_name}} 您好：</p>' .
                    '<p>恭喜您完成 TPMA 課程學習，結訓證書已隨信附上，敬請查收。</p>' .
                    '<ul>' .
                        '<li>課程名稱：{{course_name}}</li>' .
                        '<li>報名編號：{{reg_no}}</li>' .
                    '</ul>',
            ],
        ];
    }

    /**
     * 將 {{var}} 形式替換為 context 中對應值
     */
    protected static function replace_vars($text, array $context)
    {
        $replace = [];
        foreach ($context as $k => $v) {
            $replace['{{' . $k . '}}'] = esc_html($v);
        }
        return strtr($text, $replace);
    }
}
