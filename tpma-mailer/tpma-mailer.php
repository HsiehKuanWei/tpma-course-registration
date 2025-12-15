<?php
/**
 * Plugin Name: TPMA Mailer
 * Description: TPMA 共用寄信服務（供課程報名系統等外掛呼叫）。
 * Version: 1.0.0
 * Author: TPMA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TPMA_MAILER_PATH', plugin_dir_path( __FILE__ ) );

// 這三個 class 目前叫「TPMA_CR_Mail_*」，而且檔案就放在外掛根目錄
if ( ! class_exists( 'TPMA_CR_Mail_Config' ) ) {
    require_once TPMA_MAILER_PATH . 'class-tpma-cr-mail-config.php';
}
if ( ! class_exists( 'TPMA_CR_Mail_Service' ) ) {
    require_once TPMA_MAILER_PATH . 'class-tpma-cr-mail-service.php';
}
if ( ! class_exists( 'TPMA_CR_Mail_Templates' ) ) {
    require_once TPMA_MAILER_PATH . 'class-tpma-cr-mail-templates.php';
}

/**
 * 對外提供一個簡單的共用入口：
 *
 * TPMA_Mailer::send_template(
 *      'registration_notice',
 *      'user@example.com',
 *      [
 *          'reg_context'   => [...], // 選填：有現成報名陣列就丟這個
 *          'extra_context' => [...], // 選填：額外變數，如 class_link / payment_link 等
 *          'cc'            => [...], // 選填
 *          'bcc'           => [...], // 選填
 *      ]
 * );
 */
class TPMA_Mailer {

    /**
     * @param string $template_key  模板 key，例如 registration_notice, class_notice...
     * @param string|array $to      收件人 email
     * @param array $args           其他參數（會轉給 TPMA_CR_Mail_Service::send）
     *
     * @return array|false          回傳 TPMA_CR_Mail_Service::send 的結果或 false
     */
    public static function send_template( $template_key, $to, array $args = array() ) {

        if ( ! class_exists( 'TPMA_CR_Mail_Service' ) ) {
            return false;
        }

        // TPMA_CR_Mail_Service::send 期待的陣列格式：
        // - reg_context
        // - reg_id
        // - to
        // - cc
        // - bcc
        // - extra_context
        $args['to'] = $to;

        return TPMA_CR_Mail_Service::send( $template_key, $args );
    }
}
