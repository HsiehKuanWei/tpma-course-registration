<?php
if (!defined('ABSPATH')) { exit; }

$result = isset($_GET['tpma_import_result']) ? sanitize_text_field($_GET['tpma_import_result']) : '';
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
</div>
