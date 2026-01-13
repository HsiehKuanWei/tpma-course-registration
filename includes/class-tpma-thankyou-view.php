<?php
if (!defined('ABSPATH')) exit;

class TPMA_CR_Thankyou_View
{
    public static function init()
    {
        if (class_exists('TPMA_Woo_Thankyou_View')) {
            add_action('tpma_thankyou_after_summary', [self::class, 'render_1083_table'], 10, 2);
            return;
        }

        // Legacy fallback when common thankyou view is not available.
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('woocommerce_thankyou', [self::class, 'render'], 5, 1);
        add_action('woocommerce_thankyou_bacs', [self::class, 'maybe_disable_default_bacs'], 1, 1);
        add_filter('woocommerce_bacs_accounts', [self::class, 'filter_bacs_accounts'], 10, 2);
    }

    /**
     * 只在 TPMA 訂單的 thankyou 頁載入資源
     */
    public static function enqueue_assets()
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) return;

        $order_id = absint(get_query_var('order-received'));
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        if (!self::is_tpma_order($order)) return;

        // thankyou 專用樣式
        wp_enqueue_style(
            'tpma-thankyou',
            TPMA_CR_URL . 'assets/css/tpma-thankyou.css',
            [],
            defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null
        );

        // ✅ 你要引入的共用樣式（注意：這是 admin-common，但你指定要在 thankyou 用）
        wp_enqueue_style(
            'tpma-admin-common',
            TPMA_CR_URL . 'assets/css/admin-common.css',
            [],
            defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null
        );

        // ✅ 課程日期格式化（前端）
        wp_enqueue_script(
            'tpma-public-datetime',
            TPMA_CR_URL . 'assets/js/public/00.tpma-datetime.js',
            [],
            defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null,
            true
        );
    }

    public static function render_1083_table($order, $context = array())
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!self::is_1083_order($order)) {
            return;
        }

        $draft = $context['draft'] ?? self::get_draft_from_order($order);
        $learners = $draft['learners'] ?? [];

        echo '<h3 class="tpma-thankyou-subtitle">學員名單</h3>';

        if (empty($learners) || !is_array($learners)) {
            echo '<p>（無學員資料）</p>';
            return;
        }

        echo '<div class="tpma-thankyou-tablewrap">';
        echo   '<div class="tpma-container">';
        echo     '<div class="tpma-list-header tpma-grid-layout tpma-grid-layout--learners">';
        echo       '<div class="tpma-list-header-item">#</div>';
        echo       '<div class="tpma-list-header-item">姓名</div>';
        echo       '<div class="tpma-list-header-item">部門</div>';
        echo       '<div class="tpma-list-header-item">職稱</div>';
        echo       '<div class="tpma-list-header-item">Email</div>';
        echo       '<div class="tpma-list-header-item">手機</div>';
        echo     '</div>';

        echo     '<div class="tpma-body">';
        $i = 1;
        foreach ($learners as $lr) {
            $name  = $lr['student_name'] ?? '';
            $dept  = $lr['department'] ?? '';
            $title = $lr['job_title'] ?? '';
            $email = $lr['emails'] ?? ($lr['student_email'] ?? ($lr['email'] ?? ''));
            $phone = $lr['mobile'] ?? ($lr['phone'] ?? '');

            echo '<div class="tpma-table-card item-row">';
            echo   '<div class="tpma-card-summary tpma-grid-layout tpma-grid-layout--learners">';
            echo     '<div class="cell" data-label="#">' . esc_html($i++) . '</div>';
            echo     '<div class="cell" data-label="姓名">' . esc_html($name) . '</div>';
            echo     '<div class="cell" data-label="部門">' . esc_html($dept) . '</div>';
            echo     '<div class="cell" data-label="職稱">' . esc_html($title) . '</div>';
            echo     '<div class="cell" data-label="Email">' . esc_html($email) . '</div>';
            echo     '<div class="cell" data-label="手機">' . esc_html($phone) . '</div>';
            echo   '</div>';
            echo '</div>';
        }
        echo     '</div>';
        echo   '</div>';
        echo '</div>';
    }

    public static function render($order_id)
    {
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        if (!self::is_tpma_order($order)) return;

        $draft    = self::get_draft_from_order($order);
        $learners = $draft['learners'] ?? [];

        $course_name       = $draft['course_name'] ?? ($draft['course']['course_name'] ?? '');
        $lecturer_display  = $draft['lecturer'] ?? ($draft['lecturer_name'] ?? ''); // draft 內通常是 lecturer（已含職稱）
        $session_dt        = $draft['session_datetime'] ?? ($draft['session']['session_datetime'] ?? '');
        $duration_minutes  = isset($draft['duration_minutes']) ? intval($draft['duration_minutes']) : 0;

        echo '<div class="tpma-thankyou-root">';
        echo   '<div class="tpma-thankyou-card">';

        echo     '<h2 class="tpma-thankyou-title">訂單已提交</h2>';

        echo     '<div class="tpma-thankyou-meta">';
        echo       '<div><strong>訂單編號：</strong>' . esc_html($order->get_order_number()) . '</div>';
        echo       '<div><strong>訂單狀態：</strong>' . esc_html(self::label_for_woo_status($order->get_status())) . '</div>';

        if ($course_name) {
            echo '<div><strong>課程名稱：</strong>' . esc_html($course_name) . '</div>';
        }

        // ✅ 1) 授課講師+職稱（王大明 教授）
        if ($lecturer_display) {
            echo '<div><strong>授課講師：</strong>' . esc_html($lecturer_display) . '</div>';
        }

        // ✅ 2) 課程日期：用 00.tpma-datetime.js 格式化（前端顯示）
        if ($session_dt) {
            echo '<div><strong>課程日期：</strong>'
               .   '<span class="tpma-session-dt"'
               .     ' data-session-dt="' . esc_attr($session_dt) . '"'
               .     ' data-duration="' . esc_attr((string)$duration_minutes) . '"'
               .   '>'
               .     esc_html($session_dt)
               .   '</span>'
               . '</div>';
        }

        echo     '</div>'; // meta

        echo     '<h3 class="tpma-thankyou-subtitle">學員名單</h3>';

        if (empty($learners) || !is_array($learners)) {
            echo '<p>（無學員資料）</p>';
        } else {
            echo '<div class="tpma-thankyou-tablewrap">';
            echo   '<table class="tpma-thankyou-table">';
            echo     '<thead><tr><th>#</th><th>姓名</th><th>部門</th><th>職稱</th><th>Email</th><th>手機</th></tr></thead><tbody>';

            $i = 1;
            foreach ($learners as $lr) {
                $name  = $lr['student_name'] ?? '';
                $dept  = $lr['department'] ?? '';
                $title = $lr['job_title'] ?? '';
                $email = $lr['emails'] ?? ($lr['student_email'] ?? ($lr['email'] ?? ''));
                $phone = $lr['mobile'] ?? ($lr['phone'] ?? '');

                echo '<tr>';
                echo   '<td>' . esc_html($i++) . '</td>';
                echo   '<td>' . esc_html($name) . '</td>';
                echo   '<td>' . esc_html($dept) . '</td>';
                echo   '<td>' . esc_html($title) . '</td>';
                echo   '<td>' . esc_html($email) . '</td>';
                echo   '<td>' . esc_html($phone) . '</td>';
                echo '</tr>';
            }

            echo     '</tbody></table>';
            echo '</div>';
        }

        self::render_bacs_details($order);

        // 前端日期格式化：使用 TPMAPublic.datetime.formatRange()
        // 00.tpma-datetime.js 會掛在 window.TPMAPublic.datetime :contentReference[oaicite:1]{index=1}
        echo '<script>
        (function(){
          function fmt(){
            try{
              var dt = window.TPMAPublic && window.TPMAPublic.datetime;
              if(!dt || !dt.formatRange) return;

              var nodes = document.querySelectorAll(".tpma-session-dt");
              nodes.forEach(function(el){
                var s = el.getAttribute("data-session-dt") || "";
                var dur = parseInt(el.getAttribute("data-duration") || "0", 10) || 0;
                var out = dt.formatRange(s, dur, { multiLine:false });
                if(out) el.textContent = out;
              });
            }catch(e){}
          }
          if(document.readyState === "loading"){
            document.addEventListener("DOMContentLoaded", fmt);
          }else{
            fmt();
          }
        })();
        </script>';

        
        // === 匯款回報（thankyou）===
        $order_key  = (string)$order->get_order_key();
        $rest_url = esc_url_raw( rest_url('tpma/v1/remit-report') );
        $show_remit = in_array($order->get_status(), array('on-hold', 'pending', 'failed'), true);

        if ($show_remit) {
            echo '<div class="tpma-thankyou-remit">';
            echo   '<h3 class="tpma-thankyou-subtitle">匯款回報</h3>';
            echo   '<p class="tpma-thankyou-hint">填寫匯款日期與匯款帳號末五碼後送出，我們將進行核帳。</p>';

            echo   '<button type="button" class="button tpma-remit-btn" id="tpma-remit-open">回報匯款</button>';

            echo   '<div class="tpma-remit-modal" id="tpma-remit-modal" style="display:none;">';
            echo     '<div class="tpma-remit-modal-inner">';
            echo       '<div class="tpma-remit-row">';
            echo         '<label>匯款日期</label>';
            echo         '<input type="date" id="tpma-remit-date" />';
            echo       '</div>';
            echo       '<div class="tpma-remit-row">';
            echo         '<label>公司戶名或匯款帳號末五碼</label>';
            echo         '<input type="text" id="tpma-remit-account" maxlength="50" placeholder="公司戶名或末五碼（例如：王小明／12345）" />
';
            echo       '</div>';
            echo       '<div class="tpma-remit-actions">';
            echo         '<button type="button" class="button button-primary" id="tpma-remit-submit">送出</button>';
            echo         '<button type="button" class="button" id="tpma-remit-cancel">取消</button>';
            echo       '</div>';
            echo       '<div class="tpma-remit-msg" id="tpma-remit-msg" style="margin-top:8px;"></div>';
            echo     '</div>';
            echo   '</div>';

            echo '<script>
            (function(){
              var ENDPOINT = ' . wp_json_encode($rest_url) . ';
              var ORDER_ID = ' . (int)$order->get_id() . ';
              var ORDER_KEY = ' . wp_json_encode($order_key) . ';

              var openBtn = document.getElementById("tpma-remit-open");
              var modal   = document.getElementById("tpma-remit-modal");
              var cancel  = document.getElementById("tpma-remit-cancel");
              var submit  = document.getElementById("tpma-remit-submit");
              var msg     = document.getElementById("tpma-remit-msg");
              var inDate  = document.getElementById("tpma-remit-date");

              // ✅ 你 HTML 已改成 tpma-remit-account，JS 也要跟著改
              var inAcct  = document.getElementById("tpma-remit-account");

              function show(v){ if(modal) modal.style.display = v ? "block" : "none"; }
              function setMsg(t, ok){
                if(!msg) return;
                msg.textContent = t || "";
                msg.style.color = ok ? "green" : "red";
              }

              if(openBtn) openBtn.addEventListener("click", function(){ setMsg(""); show(true); });
              if(cancel)  cancel.addEventListener("click", function(){ setMsg(""); show(false); });

              if(submit) submit.addEventListener("click", async function(){
                var remitDate = (inDate && inDate.value) ? String(inDate.value).trim() : "";
                var remitAccount = (inAcct && inAcct.value) ? String(inAcct.value).trim() : "";

                if(!remitDate){ setMsg("請填寫匯款日期"); return; }

                // ✅ 允許：5 碼數字（末五碼）或公司戶名（2~50 字）
                var isLast5 = /^\\d{5}$/.test(remitAccount);
                var isName  = (remitAccount.length >= 2 && remitAccount.length <= 50);

                if(!(isLast5 || isName)){
                  setMsg("請填寫公司戶名或匯款帳號末五碼（5 碼數字）");
                  return;
                }

                submit.disabled = true;
                setMsg("送出中...", true);

                try{
                  var res = await fetch(ENDPOINT, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                      order_id: ORDER_ID,
                      order_key: ORDER_KEY,
                      remit_date: remitDate,
                      // ✅ payload key 改成 remit_account
                      remit_account: remitAccount
                    })
                  });

                  var data = null;
                  try{ data = await res.json(); }catch(e){}

                  if(!res.ok){
                    var err = (data && (data.message || data.error)) ? (data.message || data.error) : ("HTTP " + res.status);
                    setMsg("送出失敗：" + err);
                    submit.disabled = false;
                    return;
                  }

                  setMsg("已送出回報，我們將進行核帳。", true);
                  setTimeout(function(){ window.location.reload(); }, 800);
                }catch(e){
                  setMsg("送出失敗：" + (e && e.message ? e.message : "未知錯誤"));
                  submit.disabled = false;
                }
              });
            })();
            </script>';

            echo '</div>';
        }

echo   '</div>'; // card
        echo '</div>';   // root
    }

    private static function is_tpma_order(WC_Order $order): bool
    {
        // 接受任一 TPMA 標記：draft_json / reg_no / reg_ids / course_id
        if ((bool)$order->get_meta('_tpma_reg_draft_json', true)) return true;
        if ((bool)$order->get_meta('_tpma_reg_no', true)) return true;
        if ((bool)$order->get_meta('_tpma_reg_ids', true)) return true;
        if ((int)$order->get_meta('_tpma_course_id', true) > 0) return true;
        return false;
    }

    private static function is_1083_order(WC_Order $order): bool
    {
        $target_id = 1083;
        if (class_exists('TPMA_Woo_Special_1083')) {
            $target_id = apply_filters('tpma_special_product_id', TPMA_Woo_Special_1083::PRODUCT_ID);
        } else {
            $target_id = apply_filters('tpma_special_product_id', $target_id);
        }

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $pid = (int) $item->get_product_id();
            if ($pid && $pid === (int) $target_id) {
                return true;
            }
        }
        return false;
    }

    public static function filter_bacs_accounts($accounts, $order_id)
    {
        if (empty($accounts) || !is_array($accounts)) return $accounts;
        if (!self::is_order_page_context()) return $accounts;
        if (!function_exists('wc_get_order')) return $accounts;

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order) {
            $order = self::get_order_from_query();
        }
        if (!$order || !self::is_tpma_order($order)) return $accounts;

        $target_name = '社團法人台灣專案管理學會';
        $filtered = array();
        foreach ($accounts as $account) {
            $name = isset($account['account_name']) ? trim((string)$account['account_name']) : '';
            if ($name === $target_name) {
                $filtered[] = $account;
            }
        }

        return !empty($filtered) ? $filtered : $accounts;
    }

    public static function maybe_disable_default_bacs($order_id)
    {
        if (!$order_id || !function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order || !self::is_tpma_order($order)) return;

        if (!function_exists('WC')) return;
        $gateways = WC()->payment_gateways();
        if (!$gateways || !method_exists($gateways, 'payment_gateways')) return;

        $all = $gateways->payment_gateways();
        $bacs = $all['bacs'] ?? null;
        if (!$bacs) return;

        remove_action('woocommerce_thankyou_bacs', [$bacs, 'thankyou_page'], 10);
    }

    private static function is_order_page_context(): bool
    {
        if (function_exists('is_order_received_page') && is_order_received_page()) return true;
        if (function_exists('is_view_order_page') && is_view_order_page()) return true;
        return false;
    }

    private static function get_order_from_query()
    {
        $order_id = 0;
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            $order_id = absint(get_query_var('order-received'));
        } elseif (function_exists('is_view_order_page') && is_view_order_page()) {
            $order_id = absint(get_query_var('view-order'));
        }
        if (!$order_id || !function_exists('wc_get_order')) return null;
        $order = wc_get_order($order_id);
        return $order instanceof WC_Order ? $order : null;
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

        if ($status === 'on-hold') return '尚未付款';
        if ($status === 'processing') return '待核帳';

        return function_exists('wc_get_order_status_name')
            ? wc_get_order_status_name($status)
            : $status;
    }

    private static function render_bacs_details(WC_Order $order)
    {
        if (!$order || $order->get_payment_method() !== 'bacs') return;
        if (!function_exists('WC')) return;

        $gateways = WC()->payment_gateways();
        if (!$gateways || !method_exists($gateways, 'payment_gateways')) return;

        $all = $gateways->payment_gateways();
        $bacs = $all['bacs'] ?? null;
        if (!$bacs || !method_exists($bacs, 'thankyou_page')) return;

        ob_start();
        $bacs->thankyou_page($order->get_id());
        $html = trim((string)ob_get_clean());
        if ($html === '') return;

        echo '<div class="tpma-thankyou-bacs">' . $html . '</div>';
    }
}
