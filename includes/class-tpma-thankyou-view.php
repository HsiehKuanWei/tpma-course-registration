<?php
if (!defined('ABSPATH')) exit;

class TPMA_CR_Thankyou_View
{
    public static function init()
    {
        // 只在 order received 頁載入樣式（並可順便把 Woo 原生內容隱藏）
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);

        // 輸出自訂 thankyou 區塊（你要的替換版）
        add_action('woocommerce_thankyou', [self::class, 'render'], 5, 1);
    }

    /**
     * 只在 TPMA 訂單的 thankyou 頁載入 css
     */
    public static function enqueue_assets()
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) return;

        $order_id = absint(get_query_var('order-received'));
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        if (!self::is_tpma_order($order)) return;

        wp_enqueue_style(
            'tpma-thankyou',
            TPMA_CR_URL . 'assets/css/tpma-thankyou.css',
            [],
            defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null
        );
    }

    public static function render($order_id)
    {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        // 只替換 TPMA 報名單
        if (!self::is_tpma_order($order)) return;

        $draft = self::get_draft_from_order($order);
        $learners = $draft['learners'] ?? [];

        $course_name = $draft['course_name'] ?? ($draft['course']['course_name'] ?? '');
        $class_date  = $draft['class_date'] ?? '';
        $session_dt  = $draft['session_datetime'] ?? ($draft['session']['session_datetime'] ?? '');

        // ★ 這裡輸出你要的整段 UI（等於替換掉原本 thankyou 顯示）
        echo '<div class="tpma-thankyou-root">';

        echo '<div class="tpma-thankyou-card">';
        echo '<h2 class="tpma-thankyou-title">訂單已提交（待付款）</h2>';

        echo '<div class="tpma-thankyou-meta">';
        if ($course_name) echo '<div><strong>課程：</strong>' . esc_html($course_name) . '</div>';
        if ($class_date)  echo '<div><strong>上課日期：</strong>' . esc_html($class_date) . '</div>';
        if ($session_dt)  echo '<div><strong>場次時間：</strong>' . esc_html($session_dt) . '</div>';
        echo '<div><strong>訂單編號：</strong>' . esc_html($order->get_order_number()) . '</div>';
        echo '<div><strong>狀態：</strong>' . esc_html(self::label_for_woo_status($order->get_status())) . '</div>';
        echo '</div>';

        echo '<h3 class="tpma-thankyou-subtitle">學員名單</h3>';

        if (empty($learners) || !is_array($learners)) {
            echo '<p>（無學員資料）</p>';
        } else {
            echo '<div class="tpma-thankyou-tablewrap">';
            echo '<table class="tpma-thankyou-table">';
            echo '<thead><tr><th>#</th><th>姓名</th><th>部門</th><th>職稱</th><th>Email</th><th>手機</th></tr></thead><tbody>';

            $i = 1;
            foreach ($learners as $lr) {
                $name  = $lr['student_name'] ?? '';
                $dept  = $lr['department'] ?? '';
                $title = $lr['job_title'] ?? '';
                $email = $lr['emails'] ?? ($lr['student_email'] ?? ($lr['email'] ?? ''));
                $phone = $lr['mobile'] ?? ($lr['phone'] ?? '');

                echo '<tr>';
                echo '<td>' . esc_html($i++) . '</td>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html($dept) . '</td>';
                echo '<td>' . esc_html($title) . '</td>';
                echo '<td>' . esc_html($email) . '</td>';
                echo '<td>' . esc_html($phone) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        // 你也可以在這裡加：匯款資訊 / 匯款回報表單 / 下載收據等
        echo '<div></div>'; // card
        echo '</div>'; // root
    }

    private static function is_tpma_order(WC_Order $order): bool
    {
        return (bool)$order->get_meta('_tpma_reg_draft_json', true)
            || (bool)$order->get_meta('_tpma_reg_no', true);
    }

    private static function get_draft_from_order(WC_Order $order): array
    {
        $draft_json = (string)$order->get_meta('_tpma_reg_draft_json', true);
        if (!$draft_json) return [];
        $draft = json_decode($draft_json, true);
        return is_array($draft) ? $draft : [];
    }

    private static function label_for_woo_status(string $status): string
    {
        $status = (string)$status;

        if ($status === 'on-hold') {
            return '尚未付款';
        }
        if ($status === 'processing') {
            return '待核帳';
        }

        return function_exists('wc_get_order_status_name')
            ? wc_get_order_status_name($status)
            : $status;
    }

}
