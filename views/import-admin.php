<?php
if (!defined('ABSPATH')) { exit; }

$result = isset($_GET['tpma_import_result']) ? sanitize_text_field($_GET['tpma_import_result']) : '';
$action_url = esc_url( admin_url('admin-post.php') );
?>
<style>
.tpma-import-wrap { font-size:13px; }
.tpma-import-block {
    border:1px solid #ddd;
    padding:10px;
    margin-bottom:12px;
}
.tpma-import-block h3 { margin:0 0 6px; font-size:15px; }
.tpma-import-block p { margin:0 0 6px; line-height:1.5; }
.tpma-import-textarea {
    width:100%;
    min-height:120px;
    font-size:12px;
    font-family:Consolas, Menlo, monospace;
    box-sizing:border-box;
}
.tpma-import-submit { padding:4px 10px; font-size:12px; cursor:pointer; margin-top:6px; }
.tpma-import-note { font-size:11px; color:#666; margin-top:4px; white-space:pre-line; }
.tpma-import-result {
    padding:6px 8px;
    margin-bottom:10px;
    border:1px solid #4cae4c;
    background:#dff0d8;
    font-size:12px;
    white-space:pre-line;
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

