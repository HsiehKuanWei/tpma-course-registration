<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Mail_Config
{
    const OPTION_KEY = 'tpma_cr_mail_config';

    /**
     * 取得目前信件設定（不存在就給預設值）
     */
    public static function get_config()
    {
        $defaults = self::get_default_config();
        $saved    = get_option(self::OPTION_KEY, array());

        return wp_parse_args($saved, $defaults);
    }

    /**
     * 更新設定（給 REST / 管理介面使用）
     */
    public static function update_config(array $new_config)
    {
        $defaults = self::get_default_config();
        // 避免整個結構被砍壞，做一層 merge
        $merged   = wp_parse_args($new_config, $defaults);

        update_option(self::OPTION_KEY, $merged);

        return $merged;
    }

    /**
     * 預設設定值
     */
    protected static function get_default_config()
    {
        return [
            'from_email' => 'noreply@tw-pma.org.tw',
            'from_name'  => 'TPMA 課程系統',

            // 每種模板的預設：是否使用廣告、用哪則廣告、預設 CC/BCC
            'templates' => [
                'registration_notice' => [
                    'default_cc'  => [],
                    'default_bcc' => [],
                    'use_ad'      => true,
                    'ad_key'      => 'student_general_a',
                ],
                'class_notice' => [
                    'default_cc'  => [],
                    'default_bcc' => [],
                    'use_ad'      => true,
                    'ad_key'      => 'contact_notice_b',
                ],
                'payment_reminder' => [
                    'default_cc'  => [],
                    'default_bcc' => [],
                    'use_ad'      => false,
                    'ad_key'      => '',
                ],
                'receipt_notice' => [
                    'default_cc'  => [],
                    'default_bcc' => [],
                    'use_ad'      => false,
                    'ad_key'      => '',
                ],
                'certificate_notice' => [
                    'default_cc'  => [],
                    'default_bcc' => [],
                    'use_ad'      => false,
                    'ad_key'      => '',
                ],
            ],

            // 廣告區塊
            'ads' => [
                'student_general_a' => [
                    'enabled' => true,
                    'html'    => '<p style="font-size:12px;color:#555;margin-top:8px;">
                        —<br />
                        TPMA 課程推薦：IPMA Level D 國際專案管理認證<br />
                        ?? 深入了解 TPMA  <a href="http://www.tw-pma.org.tw">http://www.tw-pma.org.tw</a>
                    </p>',
                ],
                'contact_notice_b' => [
                    'enabled' => true,
                    'html'    => '<p style="font-size:12px;color:#555;margin-top:8px;">
                        —<br />
                        TPMA 系統小提醒：<br />
                        歡迎體驗「企業批次匯入 + 自動寄送證書」功能，減少行政作業時間。<br />
                        ?? 了解 TPMA 企業方案 <a href="http://www.tw-pma.org.tw">http://www.tw-pma.org.tw</a>
                    </p>',
                ],
            ],

            // 共通尾巴
            'common_footer_html' => '
				<p>此封信件由系統自動寄出，如果有任何需要我們服務的地方，請惠賜告知。</p>
				<br>
				<hr style="  border: none;  border-top: 2px dashed;  border-bottom: 2px dashed;  height: 0;  padding: 1.5px 0;">
				<p>台灣專案管理學會 ( 總部 )</p>
				<p>83062高雄市鳳山區博愛路529號12樓</p>
				<p>TEL：(07)747-6543</p>
				<p>專案經理 許智旺</p>
				<p>網址：<a href="https://www.tw-pma.org.tw/" target="_blank">https://www.tw-pma.org.tw/</a></p>
				<p>官方Line帳號：@uns7215z (必須包含@)</p>
				<hr style="  border: none;  border-top: 2px dashed;  border-bottom: 2px dashed;  height: 0;  padding: 1.5px 0;">
				<br>
			',
        ];
    }
}
