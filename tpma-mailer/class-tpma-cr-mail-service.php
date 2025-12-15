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
	public static function send($template_key, array $args = [])
	{
		// 1. 統一 recipients
		$to = $args['to'] ?? $args['recipients'] ?? [];
		if (!is_array($to)) {
			$to = [$to];
		}
		$to = array_values(array_filter(array_map('trim', $to)));
		if (empty($to)) {
			throw new Exception('TPMA_Mailer: 收件人為空');
		}

		// 2. 建立 context
		$context = self::build_context_from_args($args);
		$context = apply_filters('tpma_mailer_context', $context, $template_key, $args);

		// 3. 渲染模板
		$rendered = TPMA_CR_Mail_Templates::render($template_key, $context);

		$subject = $rendered['subject']   ?? '';
		$body    = $rendered['body_html'] ?? '';

		// 4. 根據 config 決定 from, cc, bcc 等
		$config  = TPMA_CR_Mail_Config::get_config();
		$headers = [];

		// 範例：from
		if (!empty($config['from_email'])) {
			$from_name = $config['from_name'] ?? '';
			if ($from_name) {
				$headers[] = 'From: ' . sprintf('"%s" <%s>', $from_name, $config['from_email']);
			} else {
				$headers[] = 'From: ' . $config['from_email'];
			}
		}

		// 這裡可以再補 CC / BCC...

		// 5. 設定為 HTML 信件
		add_filter('wp_mail_content_type', [__CLASS__, 'content_type_html']);

		$sent = wp_mail($to, $subject, $body, $headers);

		// 寄完記得還原，避免影響其他地方
		remove_filter('wp_mail_content_type', [__CLASS__, 'content_type_html']);

		return [
			'sent'    => (bool) $sent,
			'to'      => $to,
			'subject' => $subject,
			'headers' => $headers,
			'context' => $context,
		];
	}
	
	public static function content_type_html()
	{
		return 'text/html; charset=UTF-8';
	}	
	
	
	protected static function build_context_from_array(array $row)
	{
		// 不再查 DB，只做最基本的清洗或別名轉換
		// 目前你的 reg_context 就是 $insert，欄位名已經可以直接對應模板，所以可以直接回傳
		return $row;
	}	

	protected static function build_context_from_args(array $args)
	{
		// 1. 如果 plugin 直接給「整包 context」，就直接用
		if (!empty($args['context']) && is_array($args['context'])) {
			$context = $args['context'];
		} else {
			// 2. 否則走「base + extra」的路線
			$base = [];

			// reg_context 是歷史命名，保留給像 tpma-course-registration 這種 plugin 用
			if (!empty($args['reg_context']) && is_array($args['reg_context'])) {
				$base = self::build_context_from_array($args['reg_context']);
			} elseif (!empty($args['base_context']) && is_array($args['base_context'])) {
				$base = $args['base_context'];
			}

			$context = $base;

			if (!empty($args['extra_context']) && is_array($args['extra_context'])) {
				$context = array_merge($context, $args['extra_context']);
			}
		}

		// 再給一個更低階的 filter，讓特殊需求可以介入
		return apply_filters('tpma_mailer_build_context', $context, $args);
	}
	
	
}
