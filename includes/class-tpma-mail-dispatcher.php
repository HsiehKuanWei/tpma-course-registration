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

        $ctx = self::build_context($order, $draft);

        // 1) 學員資料信：寄給 form learners 的 email
        $student_recipients = self::collect_student_emails($draft);
        if ($tpl_student && !empty($student_recipients)) {
            foreach ($student_recipients as $to) {
                TPMA_Mailer::send_template($tpl_student, $to, $ctx);
            }
        }

        // 2) 訂單資料信：寄給 Woo checkout billing email
        $billing_email = trim((string)$order->get_billing_email());
        if ($tpl_order && $billing_email) {
            TPMA_Mailer::send_template($tpl_order, $billing_email, $ctx);
        }

        $order->update_meta_data('_tpma_mail_sent', 'yes');
        $order->save();
    }

    private static function collect_student_emails(array $draft): array
    {
        $emails = [];

        // 依你 draft 結構：learners[]
        $learners = $draft['learners'] ?? [];
        if (is_array($learners)) {
            foreach ($learners as $lr) {
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
        }

        $emails = array_values(array_unique($emails));
        return $emails;
    }

    /**
     * 統一 mail context：模板可用的變數都在這裡
     */
    private static function build_context(WC_Order $order, array $draft): array
    {
        $order_id = $order->get_id();

        $course = $draft['course'] ?? [];   // 你 draft 若有 course 物件/陣列
        $session = $draft['session'] ?? []; // 若有 session

        return [
            // === registration / draft ===
            'reg_context' => [
                'course_id'   => $draft['course_id'] ?? '',
                'session_id'  => $draft['session_id'] ?? '',
                'course_name' => $course['course_name'] ?? ($draft['course_name'] ?? ''),
                'class_date'  => $draft['class_date'] ?? '',
                'session_datetime' => $draft['session_datetime'] ?? ($session['session_datetime'] ?? ''),
                'learners'    => $draft['learners'] ?? [],
                'learner_count' => (int)($draft['learner_count'] ?? 0),

                'remit_amount_per_learner' => $draft['remit_amount_per_learner'] ?? '',
                'total_order_amount'       => $draft['total_order_amount'] ?? '',

                'source' => $draft['source'] ?? '',
                'note'   => $draft['note'] ?? '',
            ],

            // === order / woo ===
            'order_context' => [
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

                'view_order_url' => $order->get_view_order_url(),
                'order_received_url' => $order->get_checkout_order_received_url(),
                'pay_url' => $order->get_checkout_payment_url(),
            ],

            // 你模板系統若還有 extra_context，也可以一起給
            'extra_context' => [
                // 先留空，之後你要加匯款帳號、Meet 連結等都放這
            ],
        ];
    }
}
