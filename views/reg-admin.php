<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>
<style>
.tpma-wrap { font-size:13px; }

.tpma-filter-row {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-bottom:8px;
    align-items:center;
}
.tpma-filter-row input,
.tpma-filter-row select {
    padding:3px 6px;
    font-size:13px;
}

.tpma-btn {
    padding:3px 8px;
    font-size:12px;
    cursor:pointer;
    margin:0 4px 4px 0;
}

.tpma-reg-batch-row {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    align-items:center;
    margin-bottom:8px;
    border-top:1px solid #ddd;
    padding-top:6px;
}

.tpma-reg-batch-row select,
.tpma-reg-batch-row input {
    padding:3px 6px;
    font-size:12px;
}

/* 表格樣式 */
.tpma-reg-table {
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}
.tpma-reg-table th,
.tpma-reg-table td {
    border:1px solid #ddd;
    padding:4px 6px;
    vertical-align:top;
}

.tpma-reg-table th {
    background:#f7f7f7;
    white-space:nowrap;
}

/* 單一欄位最多兩行文字，超出隱藏 */
.tpma-cell-wrap {
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2; /* 兩行 */
    overflow:hidden;
    word-break:break-all;
}

/* 狀態 chip */
.tpma-chip {
    display:inline-block;
    padding:1px 6px;
    border-radius:999px;
    border:1px solid #ccc;
    font-size:11px;
    white-space:nowrap;
}
.tpma-chip-status-pending { background:#fff3cd; border-color:#ffeeba; }
.tpma-chip-status-paid { background:#d4edda; border-color:#c3e6cb; }
.tpma-chip-status-test_pending,
.tpma-chip-status-cert_pending { background:#d1ecf1; border-color:#bee5eb; }
.tpma-chip-status-completed { background:#e2e3e5; border-color:#d6d8db; }
.tpma-chip-status-cancelled { background:#f8d7da; border-color:#f5c6cb; }

.tpma-chip-receipt-pending { background:#fff3cd; border-color:#ffeeba; }
.tpma-chip-receipt-sent { background:#d4edda; border-color:#c3e6cb; }

/* 詳細列 */
.tpma-reg-detail-row {
    background:#fcfcfc;
}
.tpma-reg-detail-cell {
    padding:6px 8px;
}
.tpma-reg-detail-section {
    margin-bottom:6px;
}
.tpma-reg-detail-section-title {
    font-weight:bold;
    border-left:3px solid #999;
    padding-left:4px;
    margin-bottom:4px;
}
.tpma-reg-detail-section label {
    display:block;
    font-weight:bold;
    margin-top:4px;
}
.tpma-reg-detail-section .value {
    margin-top:2px;
    white-space:pre-wrap;
}
.tpma-reg-detail-section input,
.tpma-reg-detail-section select,
.tpma-reg-detail-section textarea {
    width:100%;
    box-sizing:border-box;
    font-size:12px;
    padding:3px 4px;
}
.tpma-reg-detail-section textarea {
    min-height:60px;
}
.tpma-reg-detail-actions {
    text-align:right;
    margin-top:6px;
}
</style>

<div id="tpma-reg-admin" class="tpma-wrap">
    <!-- 篩選列 -->
    <div class="tpma-filter-row">
        <input type="text" id="tpma-reg-filter-q"
               placeholder="關鍵字：報名編號 / 學員 / 承辦 / 公司（模糊）">

        <select id="tpma-reg-filter-course">
            <option value="">全部課程</option>
        </select>

        <select id="tpma-reg-filter-class-date" disabled>
            <option value="">全部授課日期</option>
        </select>

        <select id="tpma-reg-filter-receipt-type">
            <option value="">收據方式（全部）</option>
            <option value="electronic">電子</option>
            <option value="paper">紙本</option>
        </select>

        <select id="tpma-reg-filter-status">
            <option value="">報名狀態（全部）</option>
            <option value="pending">未付款</option>
            <option value="paid">已付款</option>
            <option value="test_pending">待測驗</option>
            <option value="cert_pending">待發證</option>
            <option value="completed">已結訓</option>
            <option value="cancelled">已取消</option>
        </select>

        <select id="tpma-reg-filter-receipt-status">
            <option value="">收據狀態（全部）</option>
            <option value="pending">待開立</option>
            <option value="auto">已自動開立</option>
            <option value="manual">已手動開立</option>
            <option value="sent">已寄出</option>
        </select>
    </div>

    <!-- 日期篩選 -->
    <div class="tpma-filter-row">
        <span>日期欄位：</span>
        <label>
            <input type="radio" name="tpma-reg-date-mode" value="created" checked>
            報名時間
        </label>
        <label>
            <input type="radio" name="tpma-reg-date-mode" value="paid">
            匯款時間
        </label>

        <span>從</span>
        <input type="date" id="tpma-reg-filter-date-from">
        <span>到</span>
        <input type="date" id="tpma-reg-filter-date-to">

        <button class="tpma-btn" id="tpma-reg-search">查詢</button>
    </div>

    <!-- 批次變更 -->
    <div class="tpma-reg-batch-row">
        <label>
            <input type="checkbox" id="tpma-reg-select-all">
            全選
        </label>

        <span>批次變更欄位：</span>
        <select id="tpma-batch-field">
            <option value="">請選擇</option>
            <option value="status">報名狀態</option>
            <option value="receipt_status">收據狀態</option>
            <option value="receipt_type">收據方式</option>
            <option value="remit_paid_at">匯款日期</option>
        </select>

        <span id="tpma-batch-value-wrap"></span>

        <button class="tpma-btn" id="tpma-batch-apply">套用批次變更</button>
    </div>

    <!-- 列表 -->
    <table class="tpma-reg-table">
        <thead>
        <tr>
            <th style="width:26px;">
                <input type="checkbox" id="tpma-select-all-head">
            </th>
            <th>報名時間</th>
            <th>課程名稱</th>
            <th>講師</th>
            <th>授課日期</th>
            <th>學員姓名</th>
            <th>公司抬頭</th>
            <th>報名狀態</th>
            <th>收據狀態</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody id="tpma-reg-tbody">
        <tr><td colspan="10">載入中...</td></tr>
        </tbody>
    </table>
</div>

<script>
(function(){
    const apiBase    = '<?php echo $apiBase; ?>';
    const wpRestNonce = '<?php echo $restNonce; ?>';

    const STATUS_LABELS = {
        pending: '未付款',
        paid: '已付款',
        test_pending: '待測驗',
        cert_pending: '待發證',
        completed: '已結訓',
        cancelled: '已取消'
    };
    const RECEIPT_TYPE_LABELS = {
        electronic: '電子',
        paper: '紙本'
    };
    const RECEIPT_STATUS_LABELS = {
        pending: '待開立',
        auto: '已自動開立',
        manual: '已手動開立',
        sent: '已寄出'
    };

    let allCourses = [];
    let currentRegs = [];

    const tbody        = document.getElementById('tpma-reg-tbody');
    const $q           = document.getElementById('tpma-reg-filter-q');
    const $course      = document.getElementById('tpma-reg-filter-course');
    const $classDate   = document.getElementById('tpma-reg-filter-class-date');
    const $receiptType = document.getElementById('tpma-reg-filter-receipt-type');
    const $status      = document.getElementById('tpma-reg-filter-status');
    const $receiptStat = document.getElementById('tpma-reg-filter-receipt-status');
    const $dateFrom    = document.getElementById('tpma-reg-filter-date-from');
    const $dateTo      = document.getElementById('tpma-reg-filter-date-to');

    const $selectAllBody = document.getElementById('tpma-reg-select-all');
    const $selectAllHead = document.getElementById('tpma-select-all-head');
    const $batchField    = document.getElementById('tpma-batch-field');
    const $batchValueWrap= document.getElementById('tpma-batch-value-wrap');
    const $batchApply    = document.getElementById('tpma-batch-apply');

    function esc(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function(m){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }
    function display(v) { return v == null ? '' : String(v); }

    function statusLabel(code){ return STATUS_LABELS[code] || code || ''; }
    function receiptTypeLabel(code){ return RECEIPT_TYPE_LABELS[code] || code || ''; }
    function receiptStatusLabel(code){ return RECEIPT_STATUS_LABELS[code] || code || ''; }

    function statusChipClass(code){ return 'tpma-chip-status-' + (code || 'pending'); }

    async function fetchJson(url, options){
        const res = await fetch(url, options || {});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    }

    async function loadCourses(){
        try{
            const list = await fetchJson(apiBase + '/admin/courses', {
                credentials:'include',
                headers:{ 'X-WP-Nonce': wpRestNonce }
            });
            allCourses = Array.isArray(list) ? list : [];
            $course.innerHTML = '<option value="">全部課程</option>';
            allCourses.forEach(c=>{
                const opt = document.createElement('option');
                opt.value = c.id || '';
                opt.textContent = c.course_name || '';
                $course.appendChild(opt);
            });
        }catch(e){
            console.error(e);
            allCourses = [];
        }
    }

    function buildClassDateOptions(){
        const cid = parseInt($course.value,10) || 0;
        $classDate.innerHTML = '<option value="">全部授課日期</option>';
        if(!cid){
            $classDate.disabled = true;
            return;
        }
        const c = allCourses.find(x=>parseInt(x.id,10)===cid);
        if(!c || !Array.isArray(c.sessions)){
            $classDate.disabled = true;
            return;
        }
        const dates = [];
        c.sessions.forEach(s=>{
            if(!s.session_datetime) return;
            const d = s.session_datetime.substr(0,10);
            if(d && dates.indexOf(d)===-1) dates.push(d);
        });
        dates.forEach(d=>{
            const opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            $classDate.appendChild(opt);
        });
        $classDate.disabled = false;
    }

    async function fetchRegistrations(){
        const params = new URLSearchParams();
        const q = $q.value.trim();
        if(q) params.set('q', q);
        if($course.value)    params.set('course_id', $course.value);
        if($classDate.value) params.set('class_date', $classDate.value);
        if($receiptType.value) params.set('receipt_type', $receiptType.value);
        if($status.value)      params.set('status', $status.value);
        if($receiptStat.value) params.set('receipt_status', $receiptStat.value);

        const mode = (document.querySelector('input[name="tpma-reg-date-mode"]:checked') || {}).value || 'created';
        params.set('date_field', mode);
        if($dateFrom.value) params.set('date_from', $dateFrom.value);
        if($dateTo.value)   params.set('date_to', $dateTo.value);

        tbody.innerHTML = '<tr><td colspan="10">載入中...</td></tr>';

        try{
            const list = await fetchJson(apiBase + '/admin/registrations?' + params.toString(), {
                credentials:'include',
                headers:{ 'X-WP-Nonce': wpRestNonce }
            });
            currentRegs = Array.isArray(list) ? list : [];
            renderTable(currentRegs);
        }catch(e){
            console.error(e);
            tbody.innerHTML = '<tr><td colspan="10">載入失敗</td></tr>';
        }
    }

    function renderTable(list){
        tbody.innerHTML = '';
        $selectAllBody.checked = false;
        $selectAllHead.checked = false;

        if(!list || !list.length){
            tbody.innerHTML = '<tr><td colspan="10">查無符合條件的報名資料。</td></tr>';
            return;
        }

        list.forEach(row=>{
            const tr = document.createElement('tr');
            tr.dataset.id = row.id || '';

            // checkbox
            const tdSel = document.createElement('td');
            tdSel.innerHTML = '<input type="checkbox" class="tpma-reg-select">';
            tr.appendChild(tdSel);

            // 報名時間
            const tdCreated = document.createElement('td');
            tdCreated.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.created_at)) + '</div>';
            tr.appendChild(tdCreated);

            // 課程名稱
            const tdCourse = document.createElement('td');
            tdCourse.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.course_name)) + '</div>';
            tr.appendChild(tdCourse);

            // 講師
            const tdLect = document.createElement('td');
            tdLect.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.lecturer)) + '</div>';
            tr.appendChild(tdLect);

            // 授課日期
            const tdDate = document.createElement('td');
            tdDate.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.class_date)) + '</div>';
            tr.appendChild(tdDate);

            // 學員姓名
            const tdStu = document.createElement('td');
            tdStu.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.student_name)) + '</div>';
            tr.appendChild(tdStu);

            // 公司抬頭
            const tdComp = document.createElement('td');
            tdComp.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.company_name)) + '</div>';
            tr.appendChild(tdComp);

            // 報名狀態
            const tdStatus = document.createElement('td');
            const sCode = row.status || 'pending';
            tdStatus.innerHTML = '<span class="tpma-chip ' + statusChipClass(sCode) + '">' + esc(statusLabel(sCode)) + '</span>';
            tr.appendChild(tdStatus);

            // 收據狀態
            const tdRStatus = document.createElement('td');
            const rLabel = receiptStatusLabel(row.receipt_status);
            tdRStatus.innerHTML = '<div class="tpma-cell-wrap">' + esc(rLabel) + '</div>';
            tr.appendChild(tdRStatus);

            // 操作
            const tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="tpma-btn tpma-detail-btn">詳細</button>';
            tr.appendChild(tdAct);

            tbody.appendChild(tr);

            // 詳細列
            const trDetail = document.createElement('tr');
            trDetail.className = 'tpma-reg-detail-row';
            trDetail.style.display = 'none';
            trDetail.dataset.id = row.id || '';
            const tdDetail = document.createElement('td');
            tdDetail.className = 'tpma-reg-detail-cell';
            tdDetail.colSpan = 10;
            trDetail.appendChild(tdDetail);
            tbody.appendChild(trDetail);

            renderDetailView(tdDetail, row);

            tdAct.querySelector('.tpma-detail-btn').addEventListener('click', function(){
                if(trDetail.style.display === 'none'){
                    trDetail.style.display = '';
                    this.textContent = '收合';
                }else{
                    trDetail.style.display = 'none';
                    this.textContent = '詳細';
                }
            });
        });
    }

    function appendFieldView(section, labelText, val){
        const label = document.createElement('label');
        label.textContent = labelText;
        section.appendChild(label);

        const div = document.createElement('div');
        div.className = 'value';
        div.innerHTML = esc(display(val));
        section.appendChild(div);
    }

    function renderDetailView(container, row){
        container.innerHTML = '';

        const basic = document.createElement('div');
        basic.className = 'tpma-reg-detail-section';
        basic.innerHTML = '<div class="tpma-reg-detail-section-title">基本資訊（唯讀）</div>';
        appendFieldView(basic, '報名編號', row.reg_no);
        appendFieldView(basic, '報名時間', row.created_at);
        appendFieldView(basic, '課程名稱', row.course_name);
        appendFieldView(basic, '授課講師', row.lecturer);
        appendFieldView(basic, '授課日期', row.class_date);
        container.appendChild(basic);

        const stu = document.createElement('div');
        stu.className = 'tpma-reg-detail-section';
        stu.innerHTML = '<div class="tpma-reg-detail-section-title">學員資訊</div>';
        appendFieldView(stu, '學員姓名', row.student_name);
        appendFieldView(stu, '部門', row.department);
        appendFieldView(stu, '職稱', row.job_title);
        appendFieldView(stu, '手機', row.mobile);
        appendFieldView(stu, '電話', row.phone);
        appendFieldView(stu, 'Email（多筆）', row.emails);
        container.appendChild(stu);

        const company = document.createElement('div');
        company.className = 'tpma-reg-detail-section';
        company.innerHTML = '<div class="tpma-reg-detail-section-title">公司與聯絡資訊</div>';
        appendFieldView(company, '公司抬頭', row.company_name);
        appendFieldView(company, '統一編號', row.tax_id);
        appendFieldView(company, '承辦人姓名', row.contact_name);
        appendFieldView(company, '承辦人Email', row.contact_email);
        appendFieldView(company, '收件人', row.receiver);
        appendFieldView(company, '地址', row.address);
        appendFieldView(company, '資訊來源', row.source);
        container.appendChild(company);

        const receipt = document.createElement('div');
        receipt.className = 'tpma-reg-detail-section';
        receipt.innerHTML = '<div class="tpma-reg-detail-section-title">收據與付款</div>';
        appendFieldView(receipt, '收據方式', receiptTypeLabel(row.receipt_type));
        appendFieldView(receipt, '收據狀態', receiptStatusLabel(row.receipt_status));
        appendFieldView(receipt, '匯款金額', row.remit_amount);
        appendFieldView(receipt, '匯款日期', row.remit_paid_at);
        container.appendChild(receipt);

        const other = document.createElement('div');
        other.className = 'tpma-reg-detail-section';
        other.innerHTML = '<div class="tpma-reg-detail-section-title">其他資訊</div>';
        appendFieldView(other, '報名狀態', statusLabel(row.status));
        appendFieldView(other, '測驗成績', row.test_score);
        appendFieldView(other, '證書編號', row.certificate_id);
        appendFieldView(other, '備註', row.note);
        container.appendChild(other);

        const actions = document.createElement('div');
        actions.className = 'tpma-reg-detail-actions';
        actions.innerHTML = '<button class="tpma-btn tpma-edit-btn">編輯</button>';
        container.appendChild(actions);

        actions.querySelector('.tpma-edit-btn').addEventListener('click', function(){
            renderDetailEdit(container, row);
        });
    }

    function renderDetailEdit(container, row){
        container.innerHTML = '';

        const basic = document.createElement('div');
        basic.className = 'tpma-reg-detail-section';
        basic.innerHTML = '<div class="tpma-reg-detail-section-title">基本資訊（唯讀）</div>';
        appendFieldView(basic, '報名編號', row.reg_no);
        appendFieldView(basic, '報名時間', row.created_at);
        appendFieldView(basic, '課程名稱', row.course_name);
        appendFieldView(basic, '授課講師', row.lecturer);
        appendFieldView(basic, '授課日期', row.class_date);
        container.appendChild(basic);

        const stu = document.createElement('div');
        stu.className = 'tpma-reg-detail-section';
        stu.innerHTML = '<div class="tpma-reg-detail-section-title">學員資訊</div>'
            + '<label>學員姓名</label>'
            + '<input type="text" data-field="student_name" value="'+esc(display(row.student_name))+'">'
            + '<label>部門</label>'
            + '<input type="text" data-field="department" value="'+esc(display(row.department))+'">'
            + '<label>職稱</label>'
            + '<input type="text" data-field="job_title" value="'+esc(display(row.job_title))+'">'
            + '<label>手機</label>'
            + '<input type="text" data-field="mobile" value="'+esc(display(row.mobile))+'">'
            + '<label>電話</label>'
            + '<input type="text" data-field="phone" value="'+esc(display(row.phone))+'">'
            + '<label>Email（多筆）</label>'
            + '<input type="text" data-field="emails" value="'+esc(display(row.emails))+'">';
        container.appendChild(stu);

        const company = document.createElement('div');
        company.className = 'tpma-reg-detail-section';
        company.innerHTML = '<div class="tpma-reg-detail-section-title">公司與聯絡資訊</div>'
            + '<label>公司抬頭</label>'
            + '<input type="text" data-field="company_name" value="'+esc(display(row.company_name))+'">'
            + '<label>統一編號</label>'
            + '<input type="text" data-field="tax_id" value="'+esc(display(row.tax_id))+'">'
            + '<label>承辦人姓名</label>'
            + '<input type="text" data-field="contact_name" value="'+esc(display(row.contact_name))+'">'
            + '<label>承辦人Email</label>'
            + '<input type="text" data-field="contact_email" value="'+esc(display(row.contact_email))+'">'
            + '<label>收件人</label>'
            + '<input type="text" data-field="receiver" value="'+esc(display(row.receiver))+'">'
            + '<label>地址</label>'
            + '<input type="text" data-field="address" value="'+esc(display(row.address))+'">';
        container.appendChild(company);

        const receipt = document.createElement('div');
        receipt.className = 'tpma-reg-detail-section';
        receipt.innerHTML = '<div class="tpma-reg-detail-section-title">收據與付款</div>'
            + '<label>收據方式</label>'
            + '<select data-field="receipt_type">'
            + '  <option value="">請選擇</option>'
            + '  <option value="electronic"'+(row.receipt_type==='electronic'?' selected':'')+'>電子</option>'
            + '  <option value="paper"'+(row.receipt_type==='paper'?' selected':'')+'>紙本</option>'
            + '</select>'
            + '<label>收據狀態</label>'
            + '<select data-field="receipt_status">'
            + '  <option value="">請選擇</option>'
            + '  <option value="pending"'+(row.receipt_status==='pending'?' selected':'')+'>待開立</option>'
            + '  <option value="auto"'+(row.receipt_status==='auto'?' selected':'')+'>已自動開立</option>'
            + '  <option value="manual"'+(row.receipt_status==='manual'?' selected':'')+'>已手動開立</option>'
            + '  <option value="sent"'+(row.receipt_status==='sent'?' selected':'')+'>已寄出</option>'
            + '</select>'
            + '<label>匯款金額</label>'
            + '<input type="text" data-field="remit_amount" value="'+esc(display(row.remit_amount))+'">'
            + '<label>匯款日期</label>'
            + '<input type="date" data-field="remit_paid_at" value="'+esc(display(row.remit_paid_at))+'">';
        container.appendChild(receipt);

        const other = document.createElement('div');
        other.className = 'tpma-reg-detail-section';
        other.innerHTML = '<div class="tpma-reg-detail-section-title">其他資訊</div>'
            + '<label>報名狀態</label>'
            + '<select data-field="status">'
            + '  <option value="pending"'+(row.status==='pending'?' selected':'')+'>未付款</option>'
            + '  <option value="paid"'+(row.status==='paid'?' selected':'')+'>已付款</option>'
            + '  <option value="test_pending"'+(row.status==='test_pending'?' selected':'')+'>待測驗</option>'
            + '  <option value="cert_pending"'+(row.status==='cert_pending'?' selected':'')+'>待發證</option>'
            + '  <option value="completed"'+(row.status==='completed'?' selected':'')+'>已結訓</option>'
            + '  <option value="cancelled"'+(row.status==='cancelled'?' selected':'')+'>已取消</option>'
            + '</select>'
            + '<label>測驗成績</label>'
            + '<input type="text" data-field="test_score" value="'+esc(display(row.test_score))+'">'
            + '<label>證書編號</label>'
            + '<input type="text" data-field="certificate_id" value="'+esc(display(row.certificate_id))+'">'
            + '<label>備註</label>'
            + '<textarea data-field="note">'+esc(display(row.note))+'</textarea>';
        container.appendChild(other);

        const actions = document.createElement('div');
        actions.className = 'tpma-reg-detail-actions';
        actions.innerHTML = ''
            + '<button class="tpma-btn tpma-save-btn">儲存</button>'
            + '<button class="tpma-btn tpma-cancel-btn">取消</button>';
        container.appendChild(actions);

        actions.querySelector('.tpma-save-btn').addEventListener('click', async function(){
            await saveDetail(container, row.id);
        });
        actions.querySelector('.tpma-cancel-btn').addEventListener('click', function(){
            renderDetailView(container, row);
        });
    }

    async function saveDetail(container, id){
        const inputs = container.querySelectorAll('[data-field]');
        const payload = { id: parseInt(id,10) || 0 };
        inputs.forEach(el=>{
            const f = el.dataset.field;
            if(!f) return;
            let v = el.value;
            if(v == null) v = '';
            payload[f] = v.trim();
        });
        if(!payload.id){
            alert('找不到這筆資料的 ID');
            return;
        }
        try{
            const res = await fetch(apiBase + '/admin/registration/update', {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-WP-Nonce': wpRestNonce
                },
                credentials:'include',
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if(!res.ok || !data || !data.success){
                throw new Error(data && data.message ? data.message : '更新失敗');
            }
            await fetchRegistrations();
        }catch(e){
            console.error(e);
            alert('儲存失敗：' + e.message);
        }
    }

    function updateBatchValueControl(){
        const field = $batchField.value;
        let html = '';
        if(!field){
            $batchValueWrap.innerHTML = '';
            return;
        }
        if(field === 'status'){
            html = '<select id="tpma-batch-value">'
                 + '<option value="">請選擇狀態</option>'
                 + '<option value="pending">未付款</option>'
                 + '<option value="paid">已付款</option>'
                 + '<option value="test_pending">待測驗</option>'
                 + '<option value="cert_pending">待發證</option>'
                 + '<option value="completed">已結訓</option>'
                 + '<option value="cancelled">已取消</option>'
                 + '</select>';
        }else if(field === 'receipt_status'){
            html = '<select id="tpma-batch-value">'
                 + '<option value="">請選擇狀態</option>'
                 + '<option value="pending">待開立</option>'
                 + '<option value="auto">已自動開立</option>'
                 + '<option value="manual">已手動開立</option>'
                 + '<option value="sent">已寄出</option>'
                 + '</select>';
        }else if(field === 'receipt_type'){
            html = '<select id="tpma-batch-value">'
                 + '<option value="">請選擇方式</option>'
                 + '<option value="electronic">電子</option>'
                 + '<option value="paper">紙本</option>'
                 + '</select>';
        }else if(field === 'remit_paid_at'){
            html = '<input type="date" id="tpma-batch-value">';
        }
        $batchValueWrap.innerHTML = html;
    }

    async function applyBatch(){
        const field = $batchField.value;
        const valueEl = document.getElementById('tpma-batch-value');
        if(!field){
            alert('請先選擇批次變更欄位');
            return;
        }
        if(!valueEl){
            alert('請先選擇 / 輸入批次變更的值');
            return;
        }
        const value = valueEl.value;
        if(!value){
            alert('批次變更的值不可空白');
            return;
        }

        const checked = Array.from(document.querySelectorAll('.tpma-reg-select:checked'));
        if(!checked.length){
            alert('請先勾選要變更的筆數');
            return;
        }
        if(!confirm('確定要批次修改 ' + checked.length + ' 筆資料？')) return;

        try{
            for(const cb of checked){
                const tr = cb.closest('tr');
                if(!tr) continue;
                const id = parseInt(tr.dataset.id,10) || 0;
                if(!id) continue;
                const payload = { id: id };
                payload[field] = value;

                const res = await fetch(apiBase + '/admin/registration/update', {
                    method:'POST',
                    headers:{
                        'Content-Type':'application/json',
                        'X-WP-Nonce': wpRestNonce
                    },
                    credentials:'include',
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(!res.ok || !data || !data.success){
                    console.error('批次更新失敗 id=' + id, data);
                }
            }
            await fetchRegistrations();
        }catch(e){
            console.error(e);
            alert('批次變更發生錯誤：' + e.message);
        }
    }

    // 事件綁定
    document.getElementById('tpma-reg-search').addEventListener('click', fetchRegistrations);
    $course.addEventListener('change', buildClassDateOptions);
    $batchField.addEventListener('change', updateBatchValueControl);
    $batchApply.addEventListener('click', applyBatch);

    $selectAllBody.addEventListener('change', function(){
        const checked = this.checked;
        document.querySelectorAll('.tpma-reg-select').forEach(cb=>{ cb.checked = checked; });
        $selectAllHead.checked = checked;
    });
    $selectAllHead.addEventListener('change', function(){
        const checked = this.checked;
        document.querySelectorAll('.tpma-reg-select').forEach(cb=>{ cb.checked = checked; });
        $selectAllBody.checked = checked;
    });

    (async function init(){
        await loadCourses();
        await fetchRegistrations();
    })();
})();
</script>
