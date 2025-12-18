//專門負責「把 state 渲染成 DOM」

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const R = global.TPMARegAdmin.render = global.TPMARegAdmin.render || {};
const U = global.TPMARegAdmin.utils;
const L = global.TPMARegAdmin.labels;
const S = global.TPMARegAdmin.state;
const API = global.TPMARegAdmin.api;

R.getCourseHoursForRow = function getCourseHoursForRow(ctx, row){
  const tryParse = (v)=>{ const n=parseFloat(v); return isNaN(n)?0:n; };

  if (row.class_hours){ const n=tryParse(row.class_hours); if (n>0) return n; }
  if (row.course_hours){ const n=tryParse(row.course_hours); if (n>0) return n; }
  if (row.hours){ const n=tryParse(row.hours); if (n>0) return n; }

  const courses = ctx.data.allCourses || [];
  if (courses.length && row.course_id) {
    const c = courses.find(x => String(x.id) === String(row.course_id));
    if (c) {
      if (c.class_hours){ const n=tryParse(c.class_hours); if (n>0) return n; }
      if (c.course_hours){ const n=tryParse(c.course_hours); if (n>0) return n; }
      if (c.hours){ const n=tryParse(c.hours); if (n>0) return n; }
    }
  }
  return 3;
};

R.findSessionDatetimeForRow = function findSessionDatetimeForRow(ctx, row){
  const courses = ctx.data.allCourses || [];
  if (!courses.length) return null;
  if (!row.course_id || !row.class_date) return null;
  const course = courses.find(c => String(c.id) === String(row.course_id));
  if (!course || !Array.isArray(course.sessions) || !course.sessions.length) return null;
  const dateOnly = String(row.class_date).substring(0,10);
  const sameDay = course.sessions.find(s => s.session_datetime && String(s.session_datetime).substring(0,10) === dateOnly);
  return sameDay ? sameDay.session_datetime : null;
};

R.buildClassDateRangeHtml = function buildClassDateRangeHtml(ctx, row){
  let dtStr = row.class_date;
  if (!dtStr) return '';
  let s = String(dtStr);

  if (s.length <= 10 || s.indexOf(' ') === -1) {
    const sessionDt = R.findSessionDatetimeForRow(ctx, row);
    if (sessionDt) s = String(sessionDt);
  }

  if (s.length < 16) {
    const datePart = s.substring(0,10);
    let week = '';
    try{
      const d = new Date(datePart + 'T00:00:00');
      if (!isNaN(d.getTime())) week = U.dayNames[d.getDay()] || '';
    }catch(e){}
    const text = datePart + (week ? '（'+week+'）' : '');
    return U.esc(text);
  }

  const datePart = s.substring(0,10);
  const timePart = s.substring(11,16);

  let week = '';
  let endTimeStr = '';
  try{
    const base = new Date(s.replace(' ', 'T'));
    if (!isNaN(base.getTime())) {
      week = U.dayNames[base.getDay()] || '';
      const hours = R.getCourseHoursForRow(ctx, row);
      if (hours > 0) {
        const end = new Date(base.getTime() + hours*60*60*1000);
        const pad = n => (n<10 ? '0'+n : ''+n);
        endTimeStr = pad(end.getHours()) + ':' + pad(end.getMinutes());
      }
    }
  }catch(e){}

  const range = endTimeStr ? (timePart + '~' + endTimeStr) : timePart;
  const full  = datePart + (week ? '（'+week+'） ' : ' ') + range;
  return U.esc(full);
};

R.buildStatusIconsHtml = function buildStatusIconsHtml(ctx, row){
  const icons = [];
  const pCode = row.payment_status || '';
  const pLabel = L.paymentStatusLabel(pCode);
  if (pLabel) {
    let pClass = '';
    switch(pCode){
      case 'pending': pClass='tpma-status-pill-g1-pending'; break;
      case 'processing':
      case 'on-hold': pClass='tpma-status-pill-g1-verifying'; break;
      case 'completed': pClass='tpma-status-pill-g1-paid'; break;
      case 'cancelled':
      case 'refunded':
      case 'failed': pClass='tpma-status-pill-g1-cancelled'; break;
      case 'checkout-draft': pClass='tpma-status-pill-g1-completed'; break;
      default: pClass='tpma-status-pill-g1-pending';
    }
    icons.push('<span class="tpma-status-pill '+pClass+'" title="付款狀態 (WC): '+U.esc(pLabel)+'">'+U.esc(pLabel)+'</span>');
  }

  const sCode = row.status || 'pending';
  const sLabel = L.statusLabel(sCode);
  if (sLabel) {
    let sClass = '';
    switch(sCode){
      case 'pending': sClass='tpma-status-pill-g1-pending'; break;
      case 'verifying': sClass='tpma-status-pill-g1-verifying'; break;
      case 'paid': sClass='tpma-status-pill-g1-paid'; break;
      case 'cert_pending': sClass='tpma-status-pill-g1-cert'; break;
      case 'completed': sClass='tpma-status-pill-g1-completed'; break;
      case 'cancelled': sClass='tpma-status-pill-g1-cancelled'; break;
      default: sClass='tpma-status-pill-g1-pending';
    }
    icons.push('<span class="tpma-status-pill '+sClass+'" title="報名狀態: '+U.esc(sLabel)+'">'+U.esc(sLabel)+'</span>');
  }

  const rCode = row.receipt_status || '';
  let rLabel = '';
  if (rCode === 'sent') rLabel = '已寄出';
  else if (rCode === 'pending') rLabel = '待開立';
  else if (rCode === 'auto' || rCode === 'manual') rLabel = '已開立待寄';
  const hideG2 = (sCode === 'cancelled' || sCode === 'completed');
  if (!hideG2 && rLabel) {
    let g2Class='tpma-status-pill-g2-pending';
    if (rCode === 'sent') g2Class='tpma-status-pill-g2-sent';
    else if (rCode === 'auto' || rCode === 'manual') g2Class='tpma-status-pill-g2-opened';
    icons.push('<span class="tpma-status-pill '+g2Class+'" title="收據狀態: '+U.esc(rLabel)+'">'+U.esc(rLabel)+'</span>');
  }

  const testState = S.getTestState(row);
  const tLabel = (testState === 'done') ? '已測驗' : '待測驗';
  const hideG3 = (sCode === 'cancelled' || sCode === 'completed' || sCode === 'cert_pending');
  if (!hideG3) {
    const g3Class = (testState === 'done') ? 'tpma-status-pill-g3-done' : 'tpma-status-pill-g3-notyet';
    icons.push('<span class="tpma-status-pill '+g3Class+'" title="測驗狀態: '+U.esc(tLabel)+'">'+U.esc(tLabel)+'</span>');
  }

  return '<div class="tpma-status-icons">'+icons.join('')+'</div>';
};

R.appendFieldView = function appendFieldView(section, labelText, val, asHtml){
  const label = document.createElement('label');
  label.textContent = labelText;
  section.appendChild(label);

  const div = document.createElement('div');
  div.className = 'value';
  if (asHtml) div.innerHTML = val || '';
  else div.innerHTML = U.esc(U.display(val));
  section.appendChild(div);
};

R.renderDetailView = function renderDetailView(ctx, container, row){
  container.innerHTML = '';

  const detailContainer = document.createElement('div');
  detailContainer.className = 'tpma-reg-detail-container';

  const title = document.createElement('h2');
  title.className = 'text-xl font-semibold mb-4 border-b pb-2';
  title.innerHTML = `報名編號：<span id="detail-reg-id">${U.esc(row.reg_no || 'N/A')}</span> 詳細資料`;
  detailContainer.appendChild(title);

  // 區塊 1: 報名基本資料
  const basicSection = document.createElement('div');
  basicSection.className = 'tpma-reg-detail-section';
  basicSection.id = 'section-basic';
  
  const appendField = (parent, labelText, value, isHtml = false) => {
    const fieldDiv = document.createElement('div');
    fieldDiv.className = 'tpma-detail-field';
    const label = document.createElement('label');
    label.textContent = labelText;
    const valueSpan = document.createElement('span');
    valueSpan.className = 'value';
    if (isHtml) {
      valueSpan.innerHTML = value || '';
    } else {
      valueSpan.textContent = U.esc(U.display(value));
    }
    fieldDiv.appendChild(label);
    fieldDiv.appendChild(valueSpan);
    parent.appendChild(fieldDiv);
  };

  appendField(basicSection, '課程名稱', row.course_name);
  appendField(basicSection, '授課講師', row.lecturer);
  appendField(basicSection, '授課日期時間', R.buildClassDateRangeHtml(ctx, row), true);
  appendField(basicSection, '報名時間', U.trimToMinute(row.created_at));
  if (row.woocommerce_order_id) {
    const wcOrderLink = ctx.orderEditBase ? `${ctx.orderEditBase}${row.woocommerce_order_id}&action=edit` : '';
    const orderIdLabel = row.woocommerce_order_id;
    const linkHtml = wcOrderLink ? `<a href="${wcOrderLink}" target="_blank">${orderIdLabel}</a>` : orderIdLabel;
    appendField(basicSection, 'WooCommerce 訂單 ID', linkHtml, true);
  }
  appendField(basicSection, '付款狀態 (WC)', L.paymentStatusLabel(row.payment_status));
  detailContainer.appendChild(basicSection);

  // 區塊 2: 學員資訊
  const studentSection = document.createElement('div');
  studentSection.className = 'tpma-reg-detail-section';
  studentSection.id = 'section-student';
  appendField(studentSection, '學員姓名', row.student_name);
  appendField(studentSection, '部門', row.department);
  appendField(studentSection, '職稱', row.job_title);
  appendField(studentSection, '手機', row.mobile);
  appendField(studentSection, '電話', row.phone);
  appendField(studentSection, 'Email（多筆）', row.emails);
  detailContainer.appendChild(studentSection);

  // 區塊 3: 公司與聯絡資訊
  const companySection = document.createElement('div');
  companySection.className = 'tpma-reg-detail-section';
  companySection.id = 'section-company';
  appendField(companySection, '公司抬頭', row.company_name);
  appendField(companySection, '統一編號', row.tax_id);
  appendField(companySection, '承辦人姓名', row.contact_name);
  appendField(companySection, '承辦人Email', row.contact_email);
  appendField(companySection, '收件人', row.receiver);
  appendField(companySection, '地址', row.address);
  appendField(companySection, '資訊來源', row.source);
  detailContainer.appendChild(companySection);

  // 區塊 4: 收據與付款
  const receiptSection = document.createElement('div');
  receiptSection.className = 'tpma-reg-detail-section';
  receiptSection.id = 'section-receipt';
  appendField(receiptSection, '收據方式', L.receiptTypeLabel(row.receipt_type));
  appendField(receiptSection, '收據狀態', L.receiptStatusLabel(row.receipt_status));
  appendField(receiptSection, '匯款金額（元）', U.formatAmount(row.remit_amount));
  appendField(receiptSection, '匯款日期', row.remit_paid_at);
  detailContainer.appendChild(receiptSection);

  // 區塊 5: 其他資訊
  const otherSection = document.createElement('div');
  otherSection.className = 'tpma-reg-detail-section';
  otherSection.id = 'section-other';
  appendField(otherSection, '報名狀態', L.statusLabel(row.status));
  appendField(otherSection, '測驗成績', row.test_score);
  appendField(otherSection, '證書編號', row.certificate_id);
  appendField(otherSection, '備註', row.note);
  detailContainer.appendChild(otherSection);

  // 操作按鈕
  const actionsDiv = document.createElement('div');
  actionsDiv.className = 'flex justify-end mt-4 gap-3';
/*  actionsDiv.innerHTML = `
    <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-edit-${row.id}">編輯詳情</button>
    <button class="tpma-btn tpma-btn-danger" id="tpma-btn-delete-${row.id}">刪除報名記錄</button>    
  `;
*/
  actionsDiv.innerHTML = `
    <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-edit-${row.id}">編輯詳情</button>
  `;

  detailContainer.appendChild(actionsDiv);

  container.appendChild(detailContainer);

  // 綁定編輯按鈕事件
  actionsDiv.querySelector(`#tpma-btn-edit-${row.id}`).addEventListener('click', function(){
    R.renderDetailEdit(ctx, container, row);
  });
  // 綁定刪除按鈕事件 (這裡只是模擬，實際需要實作刪除邏輯)
/*  actionsDiv.querySelector(`#tpma-btn-delete-${row.id}`).addEventListener('click', function(){
    if (confirm('確定要刪除這筆報名記錄嗎？')) {
      // 實際應用中，這裡會調用 API 執行刪除
      alert('刪除功能尚未實作');
    }
  });*/
};

R.populateEditCourseAndDate = function populateEditCourseAndDate(ctx, row){
  const cid = 'tpma-edit-course-' + row.id;
  const did = 'tpma-edit-class-date-' + row.id;
  const courseSel = document.getElementById(cid);
  const dateSel = document.getElementById(did);
  if (!courseSel || !dateSel) return;

  courseSel.innerHTML = '<option value="">請選擇課程</option>';
  (ctx.data.allCourses || []).forEach(c=>{
    const opt = document.createElement('option');
    opt.value = c.id || '';
    opt.textContent = c.course_name || '';
    if (String(c.id) === String(row.course_id || '')) opt.selected = true;
    courseSel.appendChild(opt);
  });

  function rebuildDates(selectedCourseId){
    dateSel.innerHTML = '<option value="">請選擇授課日期時間</option>';
    const course = (ctx.data.allCourses || []).find(c => String(c.id) === String(selectedCourseId));
    let has=false;

    let compareValue = String(row.class_date || '');
    if (compareValue && (compareValue.length <= 10 || compareValue.indexOf(' ') === -1)) {
      const resolved = R.findSessionDatetimeForRow(ctx, Object.assign({}, row, {course_id: selectedCourseId || row.course_id}));
      if (resolved) compareValue = String(resolved);
    }

    if (course && Array.isArray(course.sessions)) {
      const hours = R.getCourseHoursForRow(ctx, Object.assign({}, row, {course_id: selectedCourseId || row.course_id}));
      const durationMinutes = hours>0 ? hours*60 : 0;

      course.sessions.forEach(s=>{
        if (!s.session_datetime) return;
        const sessionValue = String(s.session_datetime);
        const opt = document.createElement('option');
        opt.value = sessionValue;

        const label = durationMinutes ? U.formatSessionDisplay(sessionValue, durationMinutes) : sessionValue;
        opt.textContent = label;

        if (sessionValue === compareValue || sessionValue.substring(0,16) === compareValue.substring(0,16)) opt.selected = true;

        dateSel.appendChild(opt);
        has=true;
      });
    }

    if (!has && row.class_date) {
      const baseRow = Object.assign({}, row, {course_id: selectedCourseId || row.course_id});
      const hours = R.getCourseHoursForRow(ctx, baseRow);
      const durationMinutes = hours>0 ? hours*60 : 0;

      const opt = document.createElement('option');
      opt.value = row.class_date;
      const label = durationMinutes ? U.formatSessionDisplay(row.class_date, durationMinutes) : row.class_date;
      opt.textContent = label + '（原資料）';
      opt.selected = true;
      dateSel.appendChild(opt);
    }
  }

  const initCourseId = courseSel.value || row.course_id || '';
  rebuildDates(initCourseId);

  courseSel.addEventListener('change', function(){ rebuildDates(this.value); });
};

R.renderDetailEdit = function renderDetailEdit(ctx, container, row){
  container.innerHTML = '';

  const detailContainer = document.createElement('div');
  detailContainer.className = 'tpma-reg-detail-container';

  const title = document.createElement('h2');
  title.className = 'text-xl font-semibold mb-4 border-b pb-2';
  title.innerHTML = `報名編號：<span id="detail-reg-id">${U.esc(row.reg_no || 'N/A')}</span> 編輯資料`;
  detailContainer.appendChild(title);

  const appendEditField = (parent, labelText, fieldName, type, value, options = [], isReadonly = false) => {
    const fieldDiv = document.createElement('div');
    fieldDiv.className = 'tpma-detail-field';
    const label = document.createElement('label');
    label.textContent = labelText;
    fieldDiv.appendChild(label);

    if (type === 'select') {
      const select = document.createElement('select');
      select.dataset.field = fieldName;
      options.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.label;
        if (String(opt.value) === String(value)) option.selected = true;
        select.appendChild(option);
      });
      fieldDiv.appendChild(select);
    } else if (type === 'textarea') {
      const textarea = document.createElement('textarea');
      textarea.dataset.field = fieldName;
      textarea.value = U.esc(U.display(value));
      if (isReadonly) textarea.readOnly = true;
      fieldDiv.appendChild(textarea);
    } else {
      const input = document.createElement('input');
      input.type = type;
      input.dataset.field = fieldName;
      input.value = U.esc(U.display(value));
      if (isReadonly) input.readOnly = true;
      fieldDiv.appendChild(input);
    }
    parent.appendChild(fieldDiv);
  };

  // 區塊 1: 報名基本資料
  const basicSection = document.createElement('div');
  basicSection.className = 'tpma-reg-detail-section edit-mode';
  basicSection.id = 'section-basic';
  appendEditField(basicSection, '報名編號', 'reg_no', 'text', row.reg_no, [], true);
  appendEditField(basicSection, '報名時間', 'created_at', 'text', U.trimToMinute(row.created_at), [], true);
  appendEditField(basicSection, '課程名稱', 'course_id', 'select', row.course_id, [], false); // This will be populated by populateEditCourseAndDate
  basicSection.querySelector('[data-field="course_id"]').id = `tpma-edit-course-${row.id}`;
  appendEditField(basicSection, '授課日期時間', 'class_date', 'select', row.class_date, [], false); // This will be populated by populateEditCourseAndDate
  basicSection.querySelector('[data-field="class_date"]').id = `tpma-edit-class-date-${row.id}`;
  if (row.woocommerce_order_id) {
    const wcOrderLink = ctx.orderEditBase ? `${ctx.orderEditBase}${row.woocommerce_order_id}&action=edit` : '';
    const orderIdLabel = row.woocommerce_order_id;
    const linkHtml = wcOrderLink ? `<a href="${wcOrderLink}" target="_blank">${orderIdLabel}</a>` : orderIdLabel;
    appendEditField(basicSection, 'WooCommerce 訂單 ID', 'woocommerce_order_id', 'text', linkHtml, [], true);
  }
  appendEditField(basicSection, '付款狀態 (WC)', 'payment_status', 'text', L.paymentStatusLabel(row.payment_status), [], true);
  detailContainer.appendChild(basicSection);

  // 區塊 2: 學員資訊
  const studentSection = document.createElement('div');
  studentSection.className = 'tpma-reg-detail-section edit-mode';
  studentSection.id = 'section-student';
  appendEditField(studentSection, '學員姓名', 'student_name', 'text', row.student_name);
  appendEditField(studentSection, '部門', 'department', 'text', row.department);
  appendEditField(studentSection, '職稱', 'job_title', 'text', row.job_title);
  appendEditField(studentSection, '手機', 'mobile', 'text', row.mobile);
  appendEditField(studentSection, '電話', 'phone', 'text', row.phone);
  appendEditField(studentSection, 'Email（多筆）', 'emails', 'text', row.emails);
  detailContainer.appendChild(studentSection);

  // 區塊 3: 公司與聯絡資訊
  const companySection = document.createElement('div');
  companySection.className = 'tpma-reg-detail-section edit-mode';
  companySection.id = 'section-company';
  appendEditField(companySection, '公司抬頭', 'company_name', 'text', row.company_name);
  appendEditField(companySection, '統一編號', 'tax_id', 'text', row.tax_id);
  appendEditField(companySection, '承辦人姓名', 'contact_name', 'text', row.contact_name);
  appendEditField(companySection, '承辦人Email', 'contact_email', 'text', row.contact_email);
  appendEditField(companySection, '收件人', 'receiver', 'text', row.receiver);
  appendEditField(companySection, '地址', 'address', 'text', row.address);
  appendEditField(companySection, '資訊來源', 'source', 'text', row.source);
  detailContainer.appendChild(companySection);

  // 區塊 4: 收據與付款
  const receiptSection = document.createElement('div');
  receiptSection.className = 'tpma-reg-detail-section edit-mode';
  receiptSection.id = 'section-receipt';
  appendEditField(receiptSection, '收據方式', 'receipt_type', 'select', row.receipt_type, [
    { value: '', label: '請選擇' },
    { value: 'electronic', label: '電子' },
    { value: 'paper', label: '紙本' }
  ]);
  appendEditField(receiptSection, '收據狀態', 'receipt_status', 'select', row.receipt_status, [
    { value: '', label: '請選擇' },
    { value: 'pending', label: '待開立' },
    { value: 'auto', label: '已開立待寄（自動）' },
    { value: 'manual', label: '已開立待寄（手動）' },
    { value: 'sent', label: '已寄出' }
  ]);
  appendEditField(receiptSection, '匯款金額（元）', 'remit_amount', 'text', U.formatAmount(row.remit_amount));
  appendEditField(receiptSection, '匯款日期', 'remit_paid_at', 'date', row.remit_paid_at);
  detailContainer.appendChild(receiptSection);

  // 區塊 5: 其他資訊
  const otherSection = document.createElement('div');
  otherSection.className = 'tpma-reg-detail-section edit-mode';
  otherSection.id = 'section-other';
  appendEditField(otherSection, '報名狀態', 'status', 'select', row.status, [
    { value: 'pending', label: '待付款' },
    { value: 'verifying', label: '待核帳' },
    { value: 'paid', label: '已付款' },
    { value: 'cert_pending', label: '待發證' },
    { value: 'completed', label: '已結訓' },
    { value: 'cancelled', label: '已取消' }
  ]);
  appendEditField(otherSection, '測驗成績', 'test_score', 'text', row.test_score);
  appendEditField(otherSection, '證書編號', 'certificate_id', 'text', row.certificate_id);
  appendEditField(otherSection, '備註', 'note', 'textarea', row.note);
  detailContainer.appendChild(otherSection);

  // 操作按鈕
  const actionsDiv = document.createElement('div');
  actionsDiv.className = 'flex justify-end mt-4 gap-3';
  actionsDiv.innerHTML = `
    <button class="tpma-btn" id="tpma-btn-save-detail-${row.id}">儲存變更</button>
    <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-cancel-edit-${row.id}">取消編輯</button>
  `;
  detailContainer.appendChild(actionsDiv);

  container.appendChild(detailContainer);

  R.populateEditCourseAndDate(ctx, row);

  actionsDiv.querySelector(`#tpma-btn-save-detail-${row.id}`).addEventListener('click', async function(){
    await R.saveDetail(ctx, container, row.id);
  });
  actionsDiv.querySelector(`#tpma-btn-cancel-edit-${row.id}`).addEventListener('click', function(){
    R.renderDetailView(ctx, container, row);
  });
};

R.saveDetail = async function saveDetail(ctx, container, id){
  const inputs = container.querySelectorAll('[data-field]');
  const payload = { id: parseInt(id,10) || 0 };
  inputs.forEach(el=>{
    const f = el.dataset.field;
    if (!f) return;
    let v = el.value;
    if (v == null) v = '';
    v = v.trim();
    if (f === 'remit_amount' && v !== '') v = String(parseInt(v.replace(/,/g,''), 10) || 0);
    payload[f] = v;
  });
  if (!payload.id) { alert('找不到這筆資料的 ID'); return; }

  try{
    await API.updateRegistration(ctx, payload);
    await ctx.actions.refresh();
  }catch(e){
    console.error(e);
    alert('儲存失敗：' + e.message);
  }
};

R.renderTable = function renderTable(ctx){
  const tbody = ctx.dom.tbody;
  const pageInfo = ctx.dom.pageInfo;
  const pagePrev = ctx.dom.pagePrev;
  const pageNext = ctx.dom.pageNext;
  const selectAllHead = ctx.dom.selectAllHead;

  const list = ctx.data.currentRegs || [];
  tbody.innerHTML = '';
  if (selectAllHead) selectAllHead.checked = false;

  if (ctx.state && ctx.state.isLoading) {
    tbody.innerHTML = '<tr><td colspan="9">載入中...</td></tr>';
    ctx.actions.updateBatchButtonsEnabled();
    ctx.actions.updatePaginationControls();
    return;
  }

  const total = list.length;
  const totalPages = Math.max(1, Math.ceil(total / ctx.state.pageSize));
  if (ctx.state.currentPage > totalPages) ctx.state.currentPage = totalPages;

  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="9">查無符合條件的報名資料。</td></tr>';
    ctx.actions.updateBatchButtonsEnabled();
    ctx.actions.updatePaginationControls();
    return;
  }

  const startIndex = (ctx.state.currentPage - 1) * ctx.state.pageSize;
  const endIndex = Math.min(startIndex + ctx.state.pageSize, total);
  const pageItems = list.slice(startIndex, endIndex);

  pageItems.forEach((row, idx)=>{
    const tr = document.createElement('tr');
    tr.dataset.id = row.id || '';

    const tdSel = document.createElement('td');
    tdSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-reg-select"></div>';
    tr.appendChild(tdSel);

    const tdSeq = document.createElement('td');
    tdSeq.className = 'tpma-seq-col';
    tdSeq.textContent = idx + 1;
    tr.appendChild(tdSeq);

    const tdCreated = document.createElement('td');
    const createdText = U.trimToMinute(row.created_at);
    let createdHtml = '';
    if (createdText && createdText.length >= 16) {
      const datePart = createdText.substring(0,10);
      const timePart = createdText.substring(11,16);
      createdHtml = U.esc(datePart) + '<br>' + U.esc(timePart);
    } else createdHtml = U.esc(createdText);
    tdCreated.innerHTML = '<div class="tpma-cell-wrap">'+createdHtml+'</div>';
    tr.appendChild(tdCreated);

    const tdCourse = document.createElement('td');
    const cname = U.display(row.course_name);
    const lect = U.display(row.lecturer);
    const titleAttr = lect ? ' title="講師：' + U.esc(lect) + '"' : '';
    tdCourse.innerHTML = '<div class="tpma-cell-wrap"><span'+titleAttr+'>' + U.esc(cname) + '</span></div>';
    tr.appendChild(tdCourse);

    const tdDate = document.createElement('td');
    const classText = R.buildClassDateRangeHtml(ctx, row);
    let classHtml = '';
    if (classText) {
      const sp = classText.indexOf(' ');
      if (sp > 0) {
        const datePart = classText.substring(0, sp);
        const timePart = classText.substring(sp + 1).trim();
        classHtml = U.esc(datePart) + '<br>' + U.esc(timePart);
      } else classHtml = U.esc(classText);
    }
    tdDate.innerHTML = '<div class="tpma-cell-wrap">'+classHtml+'</div>';
    tr.appendChild(tdDate);

    /*const tdRemit = document.createElement('td');
    tdRemit.innerHTML = '<div class="tpma-cell-wrap">'+U.esc((row.remit_paid_at || '').substring(0,10))+'</div>';
    tr.appendChild(tdRemit);*/

    const tdStu = document.createElement('td');
    tdStu.innerHTML = '<div class="tpma-cell-wrap">'+U.esc(U.display(row.student_name))+'</div>';
    tr.appendChild(tdStu);

    const tdComp = document.createElement('td');
    tdComp.innerHTML = '<div class="tpma-cell-wrap">'+U.esc(U.display(row.company_name))+'</div>';
    tr.appendChild(tdComp);

    const tdStatus = document.createElement('td');
    tdStatus.innerHTML = '<div class="tpma-cell-wrap">' + R.buildStatusIconsHtml(ctx, row) + '</div>';
    tr.appendChild(tdStatus);

    const tdAct = document.createElement('td');
    tdAct.innerHTML = '<div class="tpma-cell-wrap"><button class="tpma-btn tpma-view-btn">詳細</button></div>';
    tr.appendChild(tdAct);

    tbody.appendChild(tr);

    const trDetail = document.createElement('tr');
    trDetail.className = 'tpma-reg-detail-row';
    trDetail.style.display = 'none';
    trDetail.dataset.id = row.id || '';
    const tdDetail = document.createElement('td');
    tdDetail.className = 'tpma-reg-detail-cell';
    tdDetail.colSpan = 9;
    trDetail.appendChild(tdDetail);
    tbody.appendChild(trDetail);

    R.renderDetailView(ctx, tdDetail, row);

    tdAct.querySelector('.tpma-view-btn').addEventListener('click', function(){
      const isVisible = trDetail.style.display !== 'none';
      if (isVisible) { trDetail.style.display = 'none'; this.textContent = '詳細'; }
      else { trDetail.style.display = ''; this.textContent = '收合'; }
    });

    tdSel.querySelector('.tpma-reg-select').addEventListener('change', ctx.actions.updateBatchButtonsEnabled);
  });

  ctx.actions.updateBatchButtonsEnabled();
  ctx.actions.updatePaginationControls();
};

})(window);
