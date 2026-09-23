<?php defined('ABSPATH') || exit; ?>
<div class="wrap tpma-receipt-admin" id="tpma-certificate-admin">
    <h1>TPMA 證書管理</h1>
    <div class="tpma-receipt-filters" role="search">
        <label class="screen-reader-text" for="tpma-certificate-search">搜尋證書</label>
        <input id="tpma-certificate-search" type="search" placeholder="搜尋證書號、報名編號、學員、公司或課程">
        <select id="tpma-certificate-status"><option value="">全部狀態</option><option value="candidate">準證書（有測驗成績待配號）</option><option value="pending">待產製</option><option value="generated">待發證</option><option value="sent">已結訓</option></select>
        <label>課程日期 <input id="tpma-certificate-course-from" type="date"> 至 <input id="tpma-certificate-course-to" type="date"></label>
        <label>通過日期 <input id="tpma-certificate-passed-from" type="date"> 至 <input id="tpma-certificate-passed-to" type="date"></label>
        <label for="tpma-certificate-sort">排序</label><select id="tpma-certificate-sort"><option value="serial:desc">證書編號（新到舊）</option><option value="serial:asc">證書編號（舊到新）</option><option value="course_date:desc">課程日期（新到舊）</option><option value="course_date:asc">課程日期（舊到新）</option><option value="passed_at:desc">通過日期（新到舊）</option><option value="student:asc">學員姓名</option><option value="status:asc">證書狀態</option></select>
        <button type="button" class="button" id="tpma-certificate-reset">清除篩選</button>
    </div>
    <div class="tpma-receipt-bulk"><label for="tpma-certificate-bulk-action">批次操作</label><select id="tpma-certificate-bulk-action"><option value="">選擇操作</option><option value="allocate">人工配號</option><option value="render">產製／重製 PDF</option><option value="print">列印</option><option value="download">下載合併 PDF</option><option value="send">寄送</option></select><button type="button" class="button button-primary" id="tpma-certificate-bulk-run">套用</button><span id="tpma-certificate-selection-count">尚未選取</span></div>
    <div class="notice inline" id="tpma-certificate-message" hidden><p></p></div>
    <div class="tpma-receipt-table-wrap"><table class="widefat fixed striped tpma-receipt-table"><thead><tr><td class="check-column"><input type="checkbox" id="tpma-certificate-select-all" aria-label="選取本頁全部資料"></td><th>正式證書編號</th><th>課程／授課日期</th><th>學員／公司</th><th>測驗通過／產製／寄送</th><th>狀態／操作</th></tr></thead><tbody id="tpma-certificate-list"><tr><td colspan="6">載入中…</td></tr></tbody></table></div>
    <div class="tablenav bottom tpma-receipt-pagination"><div class="tablenav-pages" id="tpma-certificate-pagination"></div></div>
</div>
