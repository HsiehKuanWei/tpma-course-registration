<?php
if (!defined('ABSPATH')) { exit; }

$result = '';
if (!empty($_GET['tpma_import_result_key'])) {
    $result_key = sanitize_key(wp_unslash($_GET['tpma_import_result_key']));
    $stored_result = get_transient($result_key);
    if (is_string($stored_result)) {
        $result = $stored_result;
        delete_transient($result_key);
    } else {
        $result = '匯入結果已過期或讀取失敗，請重新執行一次。';
    }
} elseif (isset($_GET['tpma_import_result'])) {
    $result = sanitize_textarea_field(wp_unslash($_GET['tpma_import_result']));
}
$action_url = esc_url( admin_url('admin-post.php') );
?>
<style>
.tpma-import-wrap {
    --tpma-admin-bg:#f5f8fb;
    --tpma-admin-surface:#fff;
    --tpma-admin-border:#d7e2ee;
    --tpma-admin-text:#172033;
    --tpma-admin-muted:#5f7086;
    --tpma-admin-primary:#0f6c7b;
    --tpma-admin-primary-dark:#0a4f5a;
    --tpma-admin-success:#1f7a4d;
    font-size:14px;
    color:var(--tpma-admin-text);
    display:grid;
    gap:16px;
}
.tpma-import-block {
    border:1px solid var(--tpma-admin-border);
    border-radius:12px;
    padding:18px;
    background:var(--tpma-admin-surface);
    box-shadow:0 10px 28px rgba(19,35,61,.06);
}
.tpma-import-block h3 { margin:0 0 8px; font-size:16px; line-height:1.35; }
.tpma-import-block p { margin:0 0 8px; line-height:1.55; color:var(--tpma-admin-muted); }
.tpma-import-block code { white-space:normal; word-break:break-word; }
.tpma-import-textarea {
    width:100%;
    min-height:120px;
    font-size:13px;
    font-family:Consolas, Menlo, monospace;
    box-sizing:border-box;
    border:1px solid #b6c6d7;
    border-radius:8px;
    padding:12px;
    resize:vertical;
}
.tpma-import-textarea:focus {
    border-color:var(--tpma-admin-primary);
    box-shadow:0 0 0 3px rgba(15,108,123,.16);
    outline:none;
}
.tpma-import-submit {
    min-height:38px;
    padding:0 16px;
    border:1px solid var(--tpma-admin-primary);
    border-radius:8px;
    background:var(--tpma-admin-primary);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    margin-top:8px;
}
.tpma-import-submit:hover,
.tpma-import-submit:focus {
    background:var(--tpma-admin-primary-dark);
    border-color:var(--tpma-admin-primary-dark);
}
.tpma-import-note { font-size:12px; color:var(--tpma-admin-muted); margin-top:4px; white-space:pre-line; }
.tpma-import-result {
    padding:12px 14px;
    border:1px solid #9acfb1;
    border-radius:10px;
    background:#e8f6ee;
    color:var(--tpma-admin-success);
    font-size:13px;
    font-weight:700;
    white-space:pre-line;
    max-height:420px;
    overflow:auto;
}
@media (max-width: 640px) {
    .tpma-import-wrap { gap:12px; }
    .tpma-import-block { padding:14px; border-radius:10px; }
    .tpma-import-submit { width:100%; }
}
</style>

<div class="tpma-import-wrap">
    <?php if ($result): ?>
        <div class="tpma-import-result"><?php echo esc_html($result); ?></div>
    <?php endif; ?>

    <div class="tpma-import-block">
        <h3>匯入講師資料（CSV 貼上）</h3>
        <p>格式：<code>code,name,title,sort_order</code></p>
        <p class="tpma-import-note">
示例：
HSSA,王小明,資深講師,10
HSSB,林大偉,律師,20
        </p>
        <form method="post" action="<?php echo $action_url; ?>">
            <input type="hidden" name="action" value="tpma_import">
            <input type="hidden" name="type" value="lecturers">
            <?php wp_nonce_field('tpma_import_lecturers'); ?>
            <textarea name="csv" class="tpma-import-textarea" placeholder="在此貼上講師 CSV 資料"></textarea>
            <br>
            <button type="submit" class="tpma-import-submit">匯入講師</button>
        </form>
    </div>

    <div class="tpma-import-block">
        <h3>匯入課程資料（CSV 貼上）</h3>
        <p>格式：
            <code>course_code,course_name,category_code,lecturer_code,intro,outline,is_active,sessions</code>
        </p>
        <p class="tpma-import-note">
說明：
- course_code 可留空，系統會依「講師碼+類別碼+流水號」自動產生。
- is_active：1=開課中，0=已停課
- sessions：多筆場次以 | 分隔，格式為「YYYY-MM-DD HH:MM」，例如：
  HSSA101,董事會運作實務,A1,HSSA,簡介文字,大綱文字,1,2025-03-01 09:00|2025-03-15 14:00
        </p>
        <form method="post" action="<?php echo $action_url; ?>">
            <input type="hidden" name="action" value="tpma_import">
            <input type="hidden" name="type" value="courses">
            <?php wp_nonce_field('tpma_import_courses'); ?>
            <textarea name="csv" class="tpma-import-textarea" placeholder="在此貼上課程 CSV 資料"></textarea>
            <br>
            <button type="submit" class="tpma-import-submit">匯入課程</button>
        </form>
    </div>

    <div class="tpma-import-block">
        <h3>匯入報名 / 學員資料（CSV 貼上）</h3>
        <p>格式：
            <code>reg_no,course_code,course_name,lecturer,class_date,student_name,company_name,tax_id,department,job_title,phone,emails,receiver,address,source,note,remit_account,remit_date,remit_amount,status</code>
        </p>
        <p class="tpma-import-note">
說明：
- reg_no 可留空，系統會自動產生。
- 如 reg_no 已存在，將更新該筆資料（不新增重複）。
- course_code 用來對應課程主檔（如無對應則 course_id=0，僅做紀錄）。
示例：
R20250101001,HSSA101,董事會運作實務,王小明,2025-03-01,張三,ABC公司,12345678,財會部,經理,0912345678,a@b.com,張三,台北市中正區xx路,官網,備註,123-456,2025-02-20,5000,paid
        </p>
        <form method="post" action="<?php echo $action_url; ?>">
            <input type="hidden" name="action" value="tpma_import">
            <input type="hidden" name="type" value="registrations">
            <?php wp_nonce_field('tpma_import_regs'); ?>
            <textarea name="csv" class="tpma-import-textarea" placeholder="在此貼上報名 CSV 資料"></textarea>
            <br>
            <button type="submit" class="tpma-import-submit">匯入報名資料</button>
        </form>
    </div>

    <div class="tpma-import-block">
        <h3>匯入舊報名資料（查詢留存）</h3>
        <p>格式：
            <code>報名時間,課程編號,課程名稱,講師,授課日期,學員姓名,公司抬頭,公司統編,任職部門,職稱,連絡電話,Email,收件人,通訊地址,資訊來源,顧客備註,繳費日期,匯款帳號,金額,學會備註,報名狀態</code>
        </p>
        <p class="tpma-import-note">
欄位說明：
- 報名時間：原始報名建立時間，寫入系統 created_at
- 課程編號：舊系統課程編號，用來建立 legacy 課程代碼；同編號若課名或講師不同，系統會建立獨立 legacy 課程副本
- 課程名稱：用來建立或對應停用 legacy 課程
- 講師：用來建立停用 legacy 講師並連到課程
- 授課日期：用來建立停用 legacy 場次，可填 YYYY-MM-DD、YYYY-MM-DD HH:MM 或 YYYY-MM-DD HH:MM~HH:MM（五）
- 學員姓名：報名學員姓名
- 公司抬頭：公司名稱
- 公司統編：統一編號
- 任職部門：部門
- 職稱：職稱
- 連絡電話：電話 / 手機
- Email：學員 Email，多筆可用逗號或分號分隔
- 收件人：收件人，也會作為聯絡人名稱
- 通訊地址：地址
- 資訊來源：資料來源
- 顧客備註：保留進報名備註內容，標示為顧客備註
- 繳費日期：匯款 / 繳費日期
- 匯款帳號：匯款帳號或後五碼
- 金額：舊資料收入金額；也可用費用、課程費用、學費、實收金額、收款金額、繳費金額、匯款金額、報名費作為表頭
- 學會備註：對應系統 note，標示為學會備註
- 報名狀態：可填完成、取消、退費、退款、保留、已結訓；空白時系統會從顧客備註 / 學會備註自動判斷取消、退費或保留

說明：
- 第一列可使用上述中文表頭；若沒有表頭，系統會依上述 21 欄固定順序解析。
- 仍相容舊版無課程編號的 19 欄固定順序。
- 仍相容無報名狀態的 20 欄固定順序；若取消資訊寫在備註中，系統會嘗試自動判斷。
- 表頭也可使用「舊課程編號」；只有「課程編號 + 課程名稱 + 講師」三者與既有課程完全相同時才會合併，任一不同都會建立獨立停用 legacy 課程，避免同編號改名、換講師或重複使用時被誤併。
- 舊資料沒有課程編號時，系統會依「課程名稱 + 講師」自動產生 legacy 課程代碼；報名編號也會自動產生。
- 報名時間可接受「2025/1/2 下午 4:28」或「2023/5/23 PM 2:53:28」；授課日期可接受「2025-02-14 13:30~16:30（五）」，系統會取 13:30 作為場次開始時間，並用 16:30 推算課程時數。
- 若金額欄空白，系統會嘗試從顧客備註 / 學會備註抓取「金額:3000」「NT$3,000」「3000元」這類字樣；若金額本身含千分位逗號，CSV 欄位需用雙引號包住，例如 "3,000"。
- 若可補「課程時數 / 時數」或「結束時間 / 下課時間」，系統會用來計算課程時數；沒有提供時數時，legacy 課程預設為 3 小時。
- 停課講師若不存在於講師資料庫，系統會建立停用的 legacy 講師，不需要先手動錄入。
- 不建立 WooCommerce 訂單，不觸發付款、收據、信件或 Tutor 流程。
- 系統會建立停用的 legacy 課程與場次，不出現在前台公開課程列表。
- 收入統計：無 Woo 訂單的舊資料每筆以「金額」欄位單獨計算。
示例：
報名時間,課程編號,課程名稱,講師,授課日期,學員姓名,公司抬頭,公司統編,任職部門,職稱,連絡電話,Email,收件人,通訊地址,資訊來源,顧客備註,繳費日期,匯款帳號,金額,學會備註,報名狀態
2020-01-05 10:30,OLD-001,董事會運作舊課,王小明,2020-03-01,張三,ABC公司,12345678,財會部,經理,0912345678,a@b.com,張三,台北市中正區xx路,舊系統,需要紙本講義,2020-02-20,123-456,5000,已人工核帳,完成
        </p>
        <form method="post" action="<?php echo $action_url; ?>">
            <input type="hidden" name="action" value="tpma_import">
            <input type="hidden" name="type" value="legacy_registrations">
            <?php wp_nonce_field('tpma_import_legacy_regs'); ?>
            <textarea name="csv" class="tpma-import-textarea" placeholder="在此貼上舊報名 CSV 資料"></textarea>
            <br>
            <button type="submit" class="tpma-import-submit">匯入舊報名資料</button>
        </form>
    </div>

    <div class="tpma-import-block">
        <h3>舊資料校正工具</h3>
        <p class="tpma-import-note">
用途：
- 回填已匯入舊資料中金額為 0 或空白的報名資料。
- 系統會依同筆備註、同場次、同課程、同課名與講師、同課名的既有非零金額推算並寫回匯款金額。
- 若課程推斷錯誤，可到「報名管理」展開該筆資料，使用編輯模式修改課程名稱 / 場次；匯款金額也可在同一處手動修正。
- 無任何可參照金額的資料會保留 0，需人工編輯。
        </p>
        <form method="post" action="<?php echo $action_url; ?>">
            <input type="hidden" name="action" value="tpma_import">
            <input type="hidden" name="type" value="legacy_backfill_amounts">
            <?php wp_nonce_field('tpma_import_legacy_backfill_amounts'); ?>
            <button type="submit" class="tpma-import-submit">回填舊資料 0 金額</button>
        </form>
    </div>
</div>
