//專門負責「把 state 渲染成 DOM」

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const R = global.TPMARegAdmin.render = global.TPMARegAdmin.render || {};
const U = global.TPMARegAdmin.utils;
const L = global.TPMARegAdmin.labels;
const S = global.TPMARegAdmin.state;
const UI = global.TPMARegAdmin.ui || {};
const API = global.TPMARegAdmin.api;
const O = global.TPMARegAdmin.options || {};
const ADJUSTING_VALUE = 'adjusting';
const ADJUSTING_LABEL = '調整中';
const isAdjustingCourse = (v)=>(
  v === ADJUSTING_VALUE || v === 0 || v === '0' || v === '' || v == null
);
const isAdjustingDate = (v)=>(
  v === ADJUSTING_VALUE || v === '' || v == null
);

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
  if (isAdjustingDate(dtStr)) return ADJUSTING_LABEL;
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
  const hideStatusByPayment = (
    pCode === 'on-hold' ||
    pCode === 'cancelled' ||
    pCode === 'refunded' ||
    pCode === 'failed' ||
    pCode === 'checkout-draft'
  );
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
  const testState = S.getTestState(row);
  const hideCertPendingWhenNoScore = (sCode === 'cert_pending' && testState === 'notyet');
  if (sLabel && !hideStatusByPayment && !hideCertPendingWhenNoScore) {
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

  const tLabel = (testState === 'done') ? '已測驗' : '待測驗';
  const hideG3 = (sCode === 'cancelled' || sCode === 'completed' || (sCode === 'cert_pending' && testState !== 'notyet'));
  if (!hideG3 && !hideStatusByPayment) {
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

  const isAdjustingRow = isAdjustingCourse(row.course_id) || isAdjustingDate(row.class_date);
  const courseText = U.display(row.course_name) || (isAdjustingCourse(row.course_id) ? ADJUSTING_LABEL : '');
  const lecturerText = isAdjustingRow ? ADJUSTING_LABEL : U.display(row.lecturer || row.lecturer_name);

  const detailContainer = document.createElement('div');
  detailContainer.className = 'tpma-reg-detail-container';

  const title = document.createElement('h2');
  title.className = 'text-xl font-semibold mb-4 border-b pb-2';
  title.innerHTML = `報名編號：<span id="detail-reg-id">${U.esc(row.reg_no || 'N/A')}</span> 詳細資料`;
  detailContainer.appendChild(title);

  // 區塊 1: 課程資料
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

  appendField(basicSection, '課程名稱', courseText);
  appendField(basicSection, '授課講師', lecturerText);
  appendField(basicSection, '授課日期時間', R.buildClassDateRangeHtml(ctx, row), true);
  detailContainer.appendChild(basicSection);

  // 區塊 2: 學員資料
  const studentSection = document.createElement('div');
  studentSection.className = 'tpma-reg-detail-section';
  studentSection.id = 'section-student';
  appendField(studentSection, '學員姓名', row.student_name);
  appendField(studentSection, '部門', row.department);
  appendField(studentSection, '職稱', row.job_title);
  appendField(studentSection, '手機', row.mobile);
  appendField(studentSection, 'Email', row.emails);
  detailContainer.appendChild(studentSection);

  // 區塊 3: 公司資料
  const companySection = document.createElement('div');
  companySection.className = 'tpma-reg-detail-section';
  companySection.id = 'section-company';
  appendField(companySection, '公司抬頭', row.company_name);
  appendField(companySection, '統一編號', row.tax_id);
  appendField(companySection, '承辦人姓名', row.contact_name);
  appendField(companySection, '承辦人Email', row.contact_email);
  appendField(companySection, '電話', row.phone);
  appendField(companySection, '收件人', row.receiver);
  appendField(companySection, '地址', row.address);
  appendField(companySection, '資訊來源', row.source);
  detailContainer.appendChild(companySection);

  // 區塊 4: 帳單資訊
  const receiptSection = document.createElement('div');
  receiptSection.className = 'tpma-reg-detail-section';
  receiptSection.id = 'section-receipt';
  if (row.woocommerce_order_id) {
    const wcOrderLink = ctx.orderEditBase ? `${ctx.orderEditBase}${row.woocommerce_order_id}&action=edit` : '';
    const orderIdLabel = row.woocommerce_order_id;
    const linkHtml = wcOrderLink ? `<a href="${wcOrderLink}" target="_blank">${orderIdLabel}</a>` : orderIdLabel;
    appendField(receiptSection, 'WooCommerce 訂單 ID', linkHtml, true);
  }
  appendField(receiptSection, '付款狀態 (WC)', L.paymentStatusLabel(row.payment_status));
  appendField(receiptSection, '報名時間', U.trimToMinute(row.created_at));
  appendField(receiptSection, '收據方式', L.receiptTypeLabel(row.receipt_type));
  appendField(receiptSection, '收據狀態', L.receiptStatusLabel(row.receipt_status));
  appendField(receiptSection, '匯款金額（元）', U.formatAmount(row.remit_amount));
  appendField(receiptSection, '匯款帳號', row.remit_account);
  appendField(receiptSection, '匯款日期', row.remit_paid_at);
  detailContainer.appendChild(receiptSection);

  // 區塊 5: 學習狀態
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
  actionsDiv.className = 'tpma-reg-detail-actions';
  actionsDiv.innerHTML = `
    <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-edit-${row.id}">編輯詳情</button>
  `;

  detailContainer.appendChild(actionsDiv);

  container.appendChild(detailContainer);

  // 綁定編輯按鈕事件
  actionsDiv.querySelector(`#tpma-btn-edit-${row.id}`).addEventListener('click', function(){
    R.renderDetailEdit(ctx, container, row);
  });
};

R.populateEditCourseAndDate = function populateEditCourseAndDate(ctx, row){
  const cid = 'tpma-edit-course-' + row.id;
  const did = 'tpma-edit-class-date-' + row.id;
  const courseSel = document.getElementById(cid);
  const dateSel = document.getElementById(did);
  if (!courseSel || !dateSel) return;

  courseSel.innerHTML = '<option value="">請選擇課程</option>';
  const adjustingCourseOpt = document.createElement('option');
  adjustingCourseOpt.value = ADJUSTING_VALUE;
  adjustingCourseOpt.textContent = ADJUSTING_LABEL;
  if (isAdjustingCourse(row.course_id)) adjustingCourseOpt.selected = true;
  courseSel.appendChild(adjustingCourseOpt);
  (ctx.data.allCourses || []).forEach(c=>{
    const opt = document.createElement('option');
    opt.value = c.id || '';
    opt.textContent = c.course_name || '';
    if (String(c.id) === String(row.course_id || '')) opt.selected = true;
    courseSel.appendChild(opt);
  });

  function rebuildDates(selectedCourseId){
    dateSel.innerHTML = '<option value="">請選擇授課日期時間</option>';
    const adjustingDateOpt = document.createElement('option');
    adjustingDateOpt.value = ADJUSTING_VALUE;
    adjustingDateOpt.textContent = ADJUSTING_LABEL;
    if (isAdjustingDate(row.class_date) || String(selectedCourseId || '') === ADJUSTING_VALUE) {
      adjustingDateOpt.selected = true;
    }
    dateSel.appendChild(adjustingDateOpt);

    if (String(selectedCourseId || '') === ADJUSTING_VALUE) {
      return;
    }
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

  // 區塊 1: 課程資料
  const basicSection = document.createElement('div');
  basicSection.className = 'tpma-reg-detail-section edit-mode';
  basicSection.id = 'section-basic';
  appendEditField(basicSection, '課程名稱', 'course_id', 'select', row.course_id, [], false); // populated by populateEditCourseAndDate
  basicSection.querySelector('[data-field="course_id"]').id = `tpma-edit-course-${row.id}`;
  appendEditField(basicSection, '授課講師', '', 'text', row.lecturer, [], true);
  const lecturerInput = basicSection.querySelector('input[type="text"]');
  if (lecturerInput) lecturerInput.id = `tpma-edit-lecturer-${row.id}`;
  appendEditField(basicSection, '授課日期時間', 'class_date', 'select', row.class_date, [], false); // populated by populateEditCourseAndDate
  basicSection.querySelector('[data-field="class_date"]').id = `tpma-edit-class-date-${row.id}`;
  detailContainer.appendChild(basicSection);

  // 區塊 2: 學員資料
  const studentSection = document.createElement('div');
  studentSection.className = 'tpma-reg-detail-section edit-mode';
  studentSection.id = 'section-student';
  appendEditField(studentSection, '學員姓名', 'student_name', 'text', row.student_name);
  appendEditField(studentSection, '部門', 'department', 'text', row.department);
  appendEditField(studentSection, '職稱', 'job_title', 'text', row.job_title);
  appendEditField(studentSection, '手機', 'mobile', 'text', row.mobile);
  appendEditField(studentSection, 'Email', 'emails', 'text', row.emails);
  detailContainer.appendChild(studentSection);

  // 區塊 3: 公司資料
  const companySection = document.createElement('div');
  companySection.className = 'tpma-reg-detail-section edit-mode';
  companySection.id = 'section-company';
  appendEditField(companySection, '公司抬頭', 'company_name', 'text', row.company_name);
  appendEditField(companySection, '統一編號', 'tax_id', 'text', row.tax_id);
  appendEditField(companySection, '承辦人姓名', 'contact_name', 'text', row.contact_name);
  appendEditField(companySection, '承辦人Email', 'contact_email', 'text', row.contact_email);
  appendEditField(companySection, '電話', 'phone', 'text', row.phone);
  appendEditField(companySection, '收件人', 'receiver', 'text', row.receiver);

  // 地址：檢視模式合併顯示；編輯模式一分為四（避免回寫 Woo 時重複拼接）
  appendEditField(companySection, '郵遞區號', 'address_postcode', 'text', row.address_postcode);
  appendEditField(companySection, '縣市', 'address_state', 'text', row.address_state);
  appendEditField(companySection, '區 / 鄉鎮', 'address_city', 'text', row.address_city);
  appendEditField(companySection, '地址列', 'address_line1', 'text', row.address_line1);

  appendEditField(companySection, '資訊來源', 'source', 'text', row.source);
  detailContainer.appendChild(companySection);

  // 區塊 4: 帳單資訊
  const receiptSection = document.createElement('div');
  receiptSection.className = 'tpma-reg-detail-section edit-mode';
  receiptSection.id = 'section-receipt';
  if (row.woocommerce_order_id) {
    const wcOrderLink = ctx.orderEditBase ? `${ctx.orderEditBase}${row.woocommerce_order_id}&action=edit` : '';
    const orderIdLabel = row.woocommerce_order_id;
    const linkHtml = wcOrderLink ? `<a href="${wcOrderLink}" target="_blank">${orderIdLabel}</a>` : orderIdLabel;
    appendEditField(receiptSection, 'WooCommerce 訂單 ID', 'woocommerce_order_id', 'text', linkHtml, [], true);
  }
  appendEditField(receiptSection, '付款狀態 (WC)', 'payment_status', 'select', row.payment_status, (O.wcStatus || []));
  appendEditField(receiptSection, '報名時間', 'created_at', 'text', U.trimToMinute(row.created_at), [], true);
  appendEditField(receiptSection, '收據方式', 'receipt_type', 'select', row.receipt_type, [
    { value: '', label: '請選擇' },
    ...(O.receiptType || [])
  ]);
  appendEditField(receiptSection, '收據狀態', 'receipt_status', 'select', row.receipt_status, [
    { value: '', label: '請選擇' },
    ...(O.receiptStatus || [])
  ]);
  
  appendEditField(receiptSection, '匯款金額（元）', 'remit_amount', 'text', U.formatAmount(row.remit_amount));
  appendEditField(receiptSection, '匯款帳號', 'remit_account', 'text', row.remit_account);
  appendEditField(receiptSection, '匯款日期', 'remit_paid_at', 'date', row.remit_paid_at);
  detailContainer.appendChild(receiptSection);

  // 區塊 5: 學習狀態
  const otherSection = document.createElement('div');
  otherSection.className = 'tpma-reg-detail-section edit-mode';
  otherSection.id = 'section-other';
  appendEditField(
    otherSection,
    '報名狀態',
    'status',
    'select',
    row.status,
    (O.regStatus || []).filter(x => x.value !== '') // 編輯不需要「全部」
  );
  otherSection.querySelector('[data-field="status"]').id = `tpma-edit-status-${row.id}`;
  appendEditField(otherSection, '測驗成績', 'test_score', 'text', row.test_score);
  appendEditField(otherSection, '證書編號', 'certificate_id', 'text', row.certificate_id);
  appendEditField(otherSection, '備註', 'note', 'textarea', row.note);
  detailContainer.appendChild(otherSection);

  // 操作按鈕
  const actionsDiv = document.createElement('div');
  actionsDiv.className = 'tpma-reg-detail-actions';
  actionsDiv.innerHTML = `
    <button class="tpma-btn" id="tpma-btn-save-detail-${row.id}">儲存變更</button>
    <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-cancel-edit-${row.id}">取消編輯</button>
  `;
  detailContainer.appendChild(actionsDiv);

  container.appendChild(detailContainer);

  R.populateEditCourseAndDate(ctx, row);

  const courseSel = document.getElementById(`tpma-edit-course-${row.id}`);
  const dateSel = document.getElementById(`tpma-edit-class-date-${row.id}`);
  const statusSel = document.getElementById(`tpma-edit-status-${row.id}`);
  const lecturerEl = document.getElementById(`tpma-edit-lecturer-${row.id}`);
  const originalLecturerText = U.display(row.lecturer || row.lecturer_name);

  function setAdjustingMode(){
    if (courseSel) courseSel.value = ADJUSTING_VALUE;
    if (dateSel) dateSel.value = ADJUSTING_VALUE;
    if (statusSel) statusSel.value = 'hold';
    if (lecturerEl) lecturerEl.value = ADJUSTING_LABEL;
  }

  function resetLecturerIfNeeded(){
    if (!lecturerEl) return;
    if (lecturerEl.value === ADJUSTING_LABEL) {
      lecturerEl.value = originalLecturerText;
    }
  }

  if (courseSel && dateSel && statusSel) {
    courseSel.addEventListener('change', function(){
      if (this.value === ADJUSTING_VALUE) {
        dateSel.value = ADJUSTING_VALUE;
        statusSel.value = 'hold';
        if (lecturerEl) lecturerEl.value = ADJUSTING_LABEL;
      } else if (statusSel.value !== 'hold') {
        resetLecturerIfNeeded();
      }
    });

    statusSel.addEventListener('change', function(){
      if (this.value === 'hold') {
        setAdjustingMode();
      }
    });
  }

  if (statusSel && statusSel.value === 'hold') {
    setAdjustingMode();
  } else if (lecturerEl && (isAdjustingCourse(row.course_id) || isAdjustingDate(row.class_date))) {
    lecturerEl.value = ADJUSTING_LABEL;
  }

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
    if (f === 'course_id' && v === ADJUSTING_VALUE) v = '0';
    if (f === 'class_date' && v === ADJUSTING_VALUE) v = '';
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

R.buildCreatedAtHtml = function buildCreatedAtHtml(value){
  const createdText = U.trimToMinute(value);
  if (createdText && createdText.length >= 16) {
    const datePart = createdText.substring(0,10);
    const timePart = createdText.substring(11,16);
    return U.esc(datePart) + '<br>' + U.esc(timePart);
  }
  return U.esc(createdText);
};

R.getCourseSummaryText = function getCourseSummaryText(row){
  return U.display(row.course_name) || (isAdjustingCourse(row.course_id) ? ADJUSTING_LABEL : '');
};

R.getLecturerSummaryText = function getLecturerSummaryText(row){
  const isAdjustingRow = isAdjustingCourse(row.course_id) || isAdjustingDate(row.class_date);
  if (isAdjustingRow) return ADJUSTING_LABEL;
  return U.display(row.lecturer_name || row.lecturer);
};

R.bindDetailToggle = function bindDetailToggle(button, details){
  button.addEventListener('click', function(){
    const isOpen = details.classList.contains('open');
    details.classList.toggle('open', !isOpen);
    this.textContent = isOpen ? '詳細' : '收合';
  });
};

R.bindStudentSelection = function bindStudentSelection(ctx, studentCard, checkbox){
  checkbox.addEventListener('change', function(){
    const classCard = studentCard.closest('.tpma-reg-class-card');
    if (classCard && UI.updateClassSelectionState) {
      UI.updateClassSelectionState(classCard);
    }
    ctx.actions.updateBatchButtonsEnabled();
    if (UI.updateAllClassSelectionStates) {
      UI.updateAllClassSelectionStates(ctx);
    }
  });
};

R.createFlatRowCard = function createFlatRowCard(ctx, row, seq){
  const card = document.createElement('div');
  card.className = 'tpma-reg-card';
  card.dataset.id = row.id || '';

  const summary = document.createElement('div');
  summary.className = 'tpma-reg-card-summary tpma-reg-grid-layout';

  const cSel = document.createElement('div');
  cSel.className = 'tpma-reg-cell';
  cSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-reg-select"></div>';
  summary.appendChild(cSel);

  const cSeq = document.createElement('div');
  cSeq.className = 'tpma-reg-cell tpma-seq-col';
  cSeq.textContent = seq;
  summary.appendChild(cSeq);

  const cCreated = document.createElement('div');
  cCreated.className = 'tpma-reg-cell';
  cCreated.innerHTML = '<div class="tpma-cell-wrap">' + R.buildCreatedAtHtml(row.created_at) + '</div>';
  summary.appendChild(cCreated);

  const cCourse = document.createElement('div');
  cCourse.className = 'tpma-reg-cell';
  const courseText = R.getCourseSummaryText(row);
  const lecturerText = R.getLecturerSummaryText(row);
  const titleAttr = lecturerText ? ' title="講師：' + U.esc(lecturerText) + '"' : '';
  cCourse.innerHTML = '<div class="tpma-cell-wrap"><span' + titleAttr + '>' + U.esc(courseText) + '</span></div>';
  summary.appendChild(cCourse);

  const cDate = document.createElement('div');
  cDate.className = 'tpma-reg-cell';
  const classText = R.buildClassDateRangeHtml(ctx, row);
  let classHtml = '';
  if (classText) {
    const sp = classText.indexOf(' ');
    if (sp > 0) {
      classHtml = U.esc(classText.substring(0, sp)) + '<br>' + U.esc(classText.substring(sp + 1).trim());
    } else {
      classHtml = U.esc(classText);
    }
  }
  cDate.innerHTML = '<div class="tpma-cell-wrap">' + classHtml + '</div>';
  summary.appendChild(cDate);

  const cStu = document.createElement('div');
  cStu.className = 'tpma-reg-cell';
  cStu.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(U.display(row.student_name)) + '</div>';
  summary.appendChild(cStu);

  const cComp = document.createElement('div');
  cComp.className = 'tpma-reg-cell';
  cComp.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(U.display(row.company_name)) + '</div>';
  summary.appendChild(cComp);

  const cStatus = document.createElement('div');
  cStatus.className = 'tpma-reg-cell';
  cStatus.innerHTML = '<div class="tpma-cell-wrap">' + R.buildStatusIconsHtml(ctx, row) + '</div>';
  summary.appendChild(cStatus);

  const cAct = document.createElement('div');
  cAct.className = 'tpma-reg-cell';
  cAct.innerHTML = '<div class="tpma-cell-wrap"><button class="tpma-btn tpma-view-btn">詳細</button></div>';
  summary.appendChild(cAct);

  card.appendChild(summary);

  const details = document.createElement('div');
  details.className = 'tpma-reg-card-details';
  details.dataset.id = row.id || '';
  card.appendChild(details);

  R.renderDetailView(ctx, details, row);
  R.bindDetailToggle(cAct.querySelector('.tpma-view-btn'), details);
  R.bindStudentSelection(ctx, card, cSel.querySelector('.tpma-reg-select'));

  return card;
};

R.createNestedStudentRow = function createNestedStudentRow(ctx, row, seq){
  const card = document.createElement('div');
  card.className = 'tpma-reg-card tpma-reg-student-card';
  card.dataset.id = row.id || '';

  const summary = document.createElement('div');
  summary.className = 'tpma-reg-card-summary tpma-reg-student-grid-layout';

  const cSel = document.createElement('div');
  cSel.className = 'tpma-reg-cell';
  cSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-reg-select"></div>';
  summary.appendChild(cSel);

  const cSeq = document.createElement('div');
  cSeq.className = 'tpma-reg-cell tpma-seq-col';
  cSeq.textContent = seq;
  summary.appendChild(cSeq);

  const cCreated = document.createElement('div');
  cCreated.className = 'tpma-reg-cell';
  cCreated.innerHTML = '<div class="tpma-cell-wrap">' + R.buildCreatedAtHtml(row.created_at) + '</div>';
  summary.appendChild(cCreated);

  const cStu = document.createElement('div');
  cStu.className = 'tpma-reg-cell';
  cStu.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(U.display(row.student_name)) + '</div>';
  summary.appendChild(cStu);

  const cComp = document.createElement('div');
  cComp.className = 'tpma-reg-cell';
  cComp.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(U.display(row.company_name)) + '</div>';
  summary.appendChild(cComp);

  const cStatus = document.createElement('div');
  cStatus.className = 'tpma-reg-cell';
  cStatus.innerHTML = '<div class="tpma-cell-wrap">' + R.buildStatusIconsHtml(ctx, row) + '</div>';
  summary.appendChild(cStatus);

  const cAct = document.createElement('div');
  cAct.className = 'tpma-reg-cell';
  cAct.innerHTML = '<div class="tpma-cell-wrap"><button class="tpma-btn tpma-view-btn">詳細</button></div>';
  summary.appendChild(cAct);

  card.appendChild(summary);

  const details = document.createElement('div');
  details.className = 'tpma-reg-card-details';
  details.dataset.id = row.id || '';
  card.appendChild(details);

  R.renderDetailView(ctx, details, row);
  R.bindDetailToggle(cAct.querySelector('.tpma-view-btn'), details);
  R.bindStudentSelection(ctx, card, cSel.querySelector('.tpma-reg-select'));

  return card;
};

R.renderFlatTable = function renderFlatTable(ctx, tbody){
  const meta = S.getPaginationMeta(ctx);
  const pageRows = S.getCurrentPageRows(ctx);

  pageRows.forEach(function(row, idx){
    tbody.appendChild(R.createFlatRowCard(ctx, row, meta.start + idx));
  });
};

R.renderNestedTable = function renderNestedTable(ctx, tbody){
  const pageGroups = S.getCurrentPageGroups(ctx);

  pageGroups.forEach(function(group, classIdx){
    const firstRow = (group.rows || [])[0] || {};
    const classCard = document.createElement('section');
    classCard.className = 'tpma-reg-class-card';
    classCard.dataset.groupKey = group.key || '';

    const classSummary = document.createElement('div');
    classSummary.className = 'tpma-reg-class-summary tpma-reg-class-grid-layout';
    classSummary.setAttribute('role', 'button');
    classSummary.setAttribute('tabindex', '0');
    classSummary.setAttribute('aria-expanded', 'true');

    const classSel = document.createElement('div');
    classSel.className = 'tpma-reg-class-cell';
    classSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-class-select"></div>';
    classSummary.appendChild(classSel);

    const classSeq = document.createElement('div');
    classSeq.className = 'tpma-reg-class-cell tpma-seq-col';
    classSeq.textContent = classIdx + 1;
    classSummary.appendChild(classSeq);

    const classDate = document.createElement('div');
    classDate.className = 'tpma-reg-class-cell';
    classDate.innerHTML = '<div class="tpma-cell-wrap">' + R.buildClassDateRangeHtml(ctx, firstRow) + '</div>';
    classSummary.appendChild(classDate);

    const classCourse = document.createElement('div');
    classCourse.className = 'tpma-reg-class-cell';
    classCourse.innerHTML = '<div class="tpma-cell-wrap"><span class="tpma-class-toggle-indicator" aria-hidden="true">▾</span>' + U.esc(R.getCourseSummaryText(firstRow)) + '</div>';
    classSummary.appendChild(classCourse);

    const classLecturer = document.createElement('div');
    classLecturer.className = 'tpma-reg-class-cell';
    classLecturer.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(R.getLecturerSummaryText(firstRow)) + '</div>';
    classSummary.appendChild(classLecturer);

    const classCount = document.createElement('div');
    classCount.className = 'tpma-reg-class-cell';
    classCount.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(String(group.studentCount || 0)) + ' 人</div>';
    classSummary.appendChild(classCount);

    classCard.appendChild(classSummary);

    const classBody = document.createElement('div');
    classBody.className = 'tpma-reg-class-body open';
    classCard.appendChild(classBody);

    const studentHeader = document.createElement('div');
    studentHeader.className = 'tpma-reg-student-header tpma-reg-student-grid-layout';
    studentHeader.innerHTML = [
      '<div class="tpma-reg-student-head">選取</div>',
      '<div class="tpma-reg-student-head">序</div>',
      '<div class="tpma-reg-student-head">報名時間</div>',
      '<div class="tpma-reg-student-head">學員姓名</div>',
      '<div class="tpma-reg-student-head">公司抬頭</div>',
      '<div class="tpma-reg-student-head">狀態</div>',
      '<div class="tpma-reg-student-head">操作</div>'
    ].join('');
    classBody.appendChild(studentHeader);

    (group.rows || []).forEach(function(row, idx){
      classBody.appendChild(R.createNestedStudentRow(ctx, row, idx + 1));
    });

    const classCheckbox = classSel.querySelector('.tpma-class-select');
    classCheckbox.addEventListener('click', function(e){
      e.stopPropagation();
    });
    classCheckbox.addEventListener('change', function(){
      classCard.querySelectorAll('.tpma-reg-select').forEach(function(cb){
        cb.checked = classCheckbox.checked;
      });
      classCheckbox.indeterminate = false;
      ctx.actions.updateBatchButtonsEnabled();
      if (UI.updateAllClassSelectionStates) {
        UI.updateAllClassSelectionStates(ctx);
      }
    });

    const toggleClassBody = function(){
      const isOpen = classBody.classList.contains('open');
      classBody.classList.toggle('open', !isOpen);
      classSummary.classList.toggle('is-collapsed', isOpen);
      classSummary.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    };

    classSummary.addEventListener('click', function(e){
      if (e.target.closest('input') || e.target.closest('button') || e.target.closest('a')) {
        return;
      }
      toggleClassBody();
    });

    classSummary.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleClassBody();
      }
    });

    tbody.appendChild(classCard);
  });
};

R.renderTable = function renderTable(ctx){
  const tbody = ctx.dom.tbody;
  const selectAllHead = ctx.dom.selectAllHead;
  const list = ctx.data.currentRegs || [];

  tbody.innerHTML = '';
  if (selectAllHead) {
    selectAllHead.checked = false;
    selectAllHead.indeterminate = false;
  }

  if (ctx.state && ctx.state.isLoading) {
    tbody.innerHTML = '<div class="tpma-loading-row">載入中.</div>';
    ctx.actions.updateBatchButtonsEnabled();
    ctx.actions.updatePaginationControls();
    return;
  }

  if (!list.length) {
    tbody.innerHTML = '<div class="tpma-empty-row">查無符合條件的報名資料。</div>';
    ctx.actions.updateBatchButtonsEnabled();
    ctx.actions.updatePaginationControls();
    return;
  }

  if (ctx.state.viewMode === 'flat') {
    R.renderFlatTable(ctx, tbody);
  } else {
    R.renderNestedTable(ctx, tbody);
  }

  ctx.actions.updateBatchButtonsEnabled();
  ctx.actions.updatePaginationControls();
  if (UI.updateAllClassSelectionStates) {
    UI.updateAllClassSelectionStates(ctx);
  }
};

})(window);
