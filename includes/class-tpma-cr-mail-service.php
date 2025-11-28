<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Mail_Service
{
    /**
     * 共用寄信入口
     *
     * $args 支援：
     * - reg_context: array (立即報名時可用，避免重撈 DB)
     * - reg_id: int (其他情況只知道 id 時用 DB 撈)
     * - to: array|string 收件人
     * - cc: array|string 副本
     * - bcc: array|string 密件副本
     * - extra_context: array 額外補充（如 class_link, payment_link）
     */
    public static function send($template_key, array $args)
    {
        // 1. 建立 context
        if (!empty($args['reg_context']) && is_array($args['reg_context'])) {
            $context = self::build_context_from_array($args['reg_context']);
        } elseif (!empty($args['reg_id'])) {
            $context = self::build_context_from_db((int) $args['reg_id']);
        } else {
            throw new Exception('缺少 reg_context 或 reg_id');
        }

        // 2. 合併 extra_context
        if (!empty($args['extra_context']) && is_array($args['extra_context'])) {
            $context = array_merge($context, $args['extra_context']);
        }

        // 3. 信件設定 & 模板
        $config   = TPMA_CR_Mail_Config::get_config();
        $tpl_data = TPMA_CR_Mail_Templates::render($template_key, $context);

        // 4. 寄件人
        $from_email = $config['from_email'] ?? 'noreply@tw-pma.org.tw';
        $from_name  = $config['from_name'] ?? 'TPMA 課程系統';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . sprintf('%s <%s>', $from_name, $from_email),
        ];

        // 5. 收件人 / CC / BCC：結合參數 + 預設
        $tpl_cfg = $config['templates'][$template_key] ?? [];

        $to  = $args['to']  ?? [];
        $cc  = $args['cc']  ?? [];
        $bcc = $args['bcc'] ?? [];

        if (!is_array($to)) {
            $to = [$to];
        }
        if (!is_array($cc)) {
            $cc = $cc ? [$cc] : [];
        }
        if (!is_array($bcc)) {
            $bcc = $bcc ? [$bcc] : [];
        }

        // 預設 CC / BCC
        if (!empty($tpl_cfg['default_cc'])) {
            $cc = array_merge($cc, (array) $tpl_cfg['default_cc']);
        }
        if (!empty($tpl_cfg['default_bcc'])) {
            $bcc = array_merge($bcc, (array) $tpl_cfg['default_bcc']);
        }

        $cc  = array_unique(array_filter(array_map('trim', $cc)));
        $bcc = array_unique(array_filter(array_map('trim', $bcc)));

        if (!empty($cc)) {
            $headers[] = 'Cc: ' . implode(',', $cc);
        }
        if (!empty($bcc)) {
            $headers[] = 'Bcc: ' . implode(',', $bcc);
        }

        // 6. 寄出
        $ok = wp_mail($to, $tpl_data['subject'], $tpl_data['body_html'], $headers);

        return [
            'sent'   => (bool) $ok,
            'to'     => $to,
            'cc'     => $cc,
            'bcc'    => $bcc,
        ];
    }

    /**
     * 由 register() 已有的 insert array 組 context（不用再查 DB）
     */
    protected static function build_context_from_array(array $reg)
    {
        return [
            'reg_id'       => $reg['id'] ?? 0,
            'reg_no'       => $reg['reg_no'] ?? '',
            'course_id'    => $reg['course_id'] ?? 0,
            'course_name'  => $reg['course_name'] ?? '',
            'class_date'   => $reg['class_date'] ?? '',
            'student_name' => $reg['student_name'] ?? '',
            'company_name' => $reg['company_name'] ?? '',
        ];
    }

    /**
     * 由 reg_id 到資料庫撈報名 & 課程資料
     */
    protected static function build_context_from_db($reg_id)
    {
        global $wpdb;

        if (!class_exists('TPMA_CR_DB')) {
            throw new Exception('TPMA_CR_DB 尚未載入');
        }

        $regs_table    = TPMA_CR_DB::table('regs');
        $courses_table = TPMA_CR_DB::table('courses');

        $reg = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$regs_table} WHERE id = %d",
            $reg_id
        ));

        if (!$reg) {
            throw new Exception('找不到報名資料');
        }

        $course = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$courses_table} WHERE id = %d",
            $reg->course_id
        ));

        return [
            'reg_id'       => $reg->id,
            'reg_no'       => $reg->reg_no,
            'course_id'    => $reg->course_id,
            'course_name'  => $course->course_name ?? '',
            'class_date'   => $reg->class_date,
            'student_name' => $reg->student_name,
            'company_name' => $reg->company_name,
        ];
    }
}
