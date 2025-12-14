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

  const grid = document.createElement('div');
  grid.className = 'tpma-reg-detail-grid';

  const basic = document.createElement('div');
  basic.className = 'tpma-reg-detail-section';
  basic.innerHTML = '<div class="tpma-reg-detail-section-title">基本資訊（部分唯讀）</div>';
  R.appendFieldView(basic, '報名編號', row.reg_no);
  R.appendFieldView(basic, '報名時間', U.trimToMinute(row.created_at));
  R.appendFieldView(basic, '課程名稱', row.course_name);
  R.appendFieldView(basic, '授課講師', row.lecturer);
  R.appendFieldView(basic, '授課日期時間', R.buildClassDateRangeHtml(ctx, row), true);

  if (row.woocommerce_order_id) {
    const wcOrderLink = ctx.orderEditBase ? `${ctx.orderEditBase}${row.woocommerce_order_id}&action=edit` : '';
    const orderIdLabel = row.woocommerce_order_id;
    const linkHtml = wcOrderLink ? `<a href="${wcOrderLink}" target="_blank">${orderIdLabel}</a>` : orderIdLabel;
    R.appendFieldView(basic, 'WooCommerce 訂單 ID', linkHtml, true);
  }
  R.appendFieldView(basic, '付款狀態 (WC)', L.paymentStatusLabel(row.payment_status));
  grid.appendChild(basic);

  const stu = document.createElement('div');
  stu.className = 'tpma-reg-detail-section';
  stu.innerHTML = '<div class="tpma-reg-detail-section-title">學員資訊</div>';
  R.appendFieldView(stu, '學員姓名', row.student_name);
  R.appendFieldView(stu, '部門', row.department);
  R.appendFieldView(stu, '職稱', row.job_title);
  R.appendFieldView(stu, '手機', row.mobile);
  R.appendFieldView(stu, '電話', row.phone);
  R.appendFieldView(stu, 'Email（多筆）', row.emails);
  grid.appendChild(stu);

  const company = document.createElement('div');
  company.className = 'tpma-reg-detail-section';
  company.innerHTML = '<div class="tpma-reg-detail-section-title">公司與聯絡資訊</div>';
  R.appendFieldView(company, '公司抬頭', row.company_name);
  R.appendFieldView(company, '統一編號', row.tax_id);
  R.appendFieldView(company, '承辦人姓名', row.contact_name);
  R.appendFieldView(company, '承辦人Email', row.contact_email);
  R.appendFieldView(company, '收件人', row.receiver);
  R.appendFieldView(company, '地址', row.address);
  R.appendFieldView(company, '資訊來源', row.source);
  grid.appendChild(company);

  const receipt = document.createElement('div');
  receipt.className = 'tpma-reg-detail-section';
  receipt.innerHTML = '<div class="tpma-reg-detail-section-title">收據與付款</div>';
  R.appendFieldView(receipt, '收據方式', L.receiptTypeLabel(row.receipt_type));
  R.appendFieldView(receipt, '收據狀態', L.receiptStatusLabel(row.receipt_status));
  R.appendFieldView(receipt, '匯款金額（元）', U.formatAmount(row.remit_amount));
  R.appendFieldView(receipt, '匯款日期', row.remit_paid_at);
  grid.appendChild(receipt);

  const other = document.createElement('div');
  other.className = 'tpma-reg-detail-section';
  other.innerHTML = '<div class="tpma-reg-detail-section-title">其他資訊</div>';
  R.appendFieldView(other, '報名狀態', L.statusLabel(row.status));
  R.appendFieldView(other, '測驗成績', row.test_score);
  R.appendFieldView(other, '證書編號', row.certificate_id);
  R.appendFieldView(other, '備註', row.note);
  grid.appendChild(other);

  container.appendChild(grid);

  const actions = document.createElement('div');
  actions.className = 'tpma-reg-detail-actions';
  actions.innerHTML = '<button class="tpma-btn tpma-edit-btn">編輯</button>';
  container.appendChild(actions);

  actions.querySelector('.tpma-edit-btn').addEventListener('click', function(){
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

  const grid = document.createElement('div');
  grid.className = 'tpma-reg-detail-grid';

  const basic = document.createElement('div');
  basic.className = 'tpma-reg-detail-section edit-mode';
  basic.innerHTML = ''
    + '<div class="tpma-reg-detail-section-title">基本資訊</div>'
    + '<label>報名編號（唯讀）</label>'
    + '<div class="value">' + U.esc(U.display(row.reg_no)) + '</div>'
    + '<input type="hidden" data-field="reg_no" value="'+U.esc(U.display(row.reg_no))+'">'
    + '<label>報名時間（唯讀）</label>'
    + '<div class="value">' + U.esc(U.trimToMinute(row.created_at)) + '</div>'
    + '<input type="hidden" data-field="created_at" value="'+U.esc(U.trimToMinute(row.created_at))+'">'
    + '<label>課程名稱</label>'
    + '<select data-field="course_id" id="tpma-edit-course-' + row.id + '"></select>'
    + '<label>授課日期時間</label>'
    + '<select data-field="class_date" id="tpma-edit-class-date-' + row.id + '"></select>'
    + '<label>WooCommerce 訂單 ID（唯讀）</label>'
    + '<div class="value">' + U.esc(U.display(row.woocommerce_order_id)) + '</div>'
    + '<input type="hidden" data-field="woocommerce_order_id" value="'+U.esc(U.display(row.woocommerce_order_id))+'">'
    + '<label>付款狀態 (WC)（唯讀）</label>'
    + '<div class="value">' + U.esc(L.paymentStatusLabel(row.payment_status)) + '</div>'
    + '<input type="hidden" data-field="payment_status" value="'+U.esc(U.display(row.payment_status))+'">';
  grid.appendChild(basic);

  const stu = document.createElement('div');
  stu.className = 'tpma-reg-detail-section edit-mode';
  stu.innerHTML = '<div class="tpma-reg-detail-section-title">學員資訊</div>'
    + '<label>學員姓名</label><input type="text" data-field="student_name" value="'+U.esc(U.display(row.student_name))+'">'
    + '<label>部門</label><input type="text" data-field="department" value="'+U.esc(U.display(row.department))+'">'
    + '<label>職稱</label><input type="text" data-field="job_title" value="'+U.esc(U.display(row.job_title))+'">'
    + '<label>手機</label><input type="text" data-field="mobile" value="'+U.esc(U.display(row.mobile))+'">'
    + '<label>電話</label><input type="text" data-field="phone" value="'+U.esc(U.display(row.phone))+'">'
    + '<label>Email（多筆）</label><input type="text" data-field="emails" value="'+U.esc(U.display(row.emails))+'">';
  grid.appendChild(stu);

  const company = document.createElement('div');
  company.className = 'tpma-reg-detail-section edit-mode';
  company.innerHTML = '<div class="tpma-reg-detail-section-title">公司與聯絡資訊</div>'
    + '<label>公司抬頭</label><input type="text" data-field="company_name" value="'+U.esc(U.display(row.company_name))+'">'
    + '<label>統一編號</label><input type="text" data-field="tax_id" value="'+U.esc(U.display(row.tax_id))+'">'
    + '<label>承辦人姓名</label><input type="text" data-field="contact_name" value="'+U.esc(U.display(row.contact_name))+'">'
    + '<label>承辦人Email</label><input type="text" data-field="contact_email" value="'+U.esc(U.display(row.contact_email))+'">'
    + '<label>收件人</label><input type="text" data-field="receiver" value="'+U.esc(U.display(row.receiver))+'">'
    + '<label>地址</label><input type="text" data-field="address" value="'+U.esc(U.display(row.address))+'">';
  grid.appendChild(company);

  const receipt = document.createElement('div');
  receipt.className = 'tpma-reg-detail-section edit-mode';
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
    + '  <option value="auto"'+(row.receipt_status==='auto'?' selected':'')+'>已開立待寄（自動）</option>'
    + '  <option value="manual"'+(row.receipt_status==='manual'?' selected':'')+'>已開立待寄（手動）</option>'
    + '  <option value="sent"'+(row.receipt_status==='sent'?' selected':'')+'>已寄出</option>'
    + '</select>'
    + '<label>匯款金額（元）</label>'
    + '<input type="text" data-field="remit_amount" value="'+U.esc(U.formatAmount(row.remit_amount))+'">'
    + '<label>匯款日期</label>'
    + '<input type="date" data-field="remit_paid_at" value="'+U.esc(U.display(row.remit_paid_at))+'">';
  grid.appendChild(receipt);

  const other = document.createElement('div');
  other.className = 'tpma-reg-detail-section edit-mode';
  other.innerHTML = '<div class="tpma-reg-detail-section-title">其他資訊</div>'
    + '<label>報名狀態</label>'
    + '<select data-field="status">'
    + '  <option value="pending"'+(row.status==='pending'?' selected':'')+'>待付款</option>'
    + '  <option value="verifying"'+(row.status==='verifying'?' selected':'')+'>待核帳</option>'
    + '  <option value="paid"'+(row.status==='paid'?' selected':'')+'>已付款</option>'
    + '  <option value="cert_pending"'+(row.status==='cert_pending'?' selected':'')+'>待發證</option>'
    + '  <option value="completed"'+(row.status==='completed'?' selected':'')+'>已結訓</option>'
    + '  <option value="cancelled"'+(row.status==='cancelled'?' selected':'')+'>已取消</option>'
    + '</select>'
    + '<label>測驗成績</label><input type="text" data-field="test_score" value="'+U.esc(U.display(row.test_score))+'">'
    + '<label>證書編號</label><input type="text" data-field="certificate_id" value="'+U.esc(U.display(row.certificate_id))+'">'
    + '<label>備註</label><textarea data-field="note">'+U.esc(U.display(row.note))+'</textarea>';
  grid.appendChild(other);

  container.appendChild(grid);

  const actions = document.createElement('div');
  actions.className = 'tpma-reg-detail-actions';
  actions.innerHTML = '<button class="tpma-btn tpma-save-btn">儲存</button><button class="tpma-btn tpma-cancel-btn">取消</button>';
  container.appendChild(actions);

  R.populateEditCourseAndDate(ctx, row);

  actions.querySelector('.tpma-save-btn').addEventListener('click', async function(){
    await R.saveDetail(ctx, container, row.id);
  });
  actions.querySelector('.tpma-cancel-btn').addEventListener('click', function(){
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

  const total = list.length;
  const totalPages = Math.max(1, Math.ceil(total / ctx.state.pageSize));
  if (ctx.state.currentPage > totalPages) ctx.state.currentPage = totalPages;

  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="10">查無符合條件的報名資料。</td></tr>';
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
    tdSel.innerHTML = '<input type="checkbox" class="tpma-reg-select">';
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

    const tdRemit = document.createElement('td');
    tdRemit.innerHTML = '<div class="tpma-cell-wrap">'+U.esc((row.remit_paid_at || '').substring(0,10))+'</div>';
    tr.appendChild(tdRemit);

    const tdStu = document.createElement('td');
    tdStu.innerHTML = '<div class="tpma-cell-wrap">'+U.esc(U.display(row.student_name))+'</div>';
    tr.appendChild(tdStu);

    const tdComp = document.createElement('td');
    tdComp.innerHTML = '<div class="tpma-cell-wrap">'+U.esc(U.display(row.company_name))+'</div>';
    tr.appendChild(tdComp);

    const tdStatus = document.createElement('td');
    tdStatus.innerHTML = R.buildStatusIconsHtml(ctx, row);
    tr.appendChild(tdStatus);

    const tdAct = document.createElement('td');
    tdAct.innerHTML = '<button class="tpma-btn tpma-view-btn">檢視</button>';
    tr.appendChild(tdAct);

    tbody.appendChild(tr);

    const trDetail = document.createElement('tr');
    trDetail.className = 'tpma-reg-detail-row';
    trDetail.style.display = 'none';
    trDetail.dataset.id = row.id || '';
    const tdDetail = document.createElement('td');
    tdDetail.className = 'tpma-reg-detail-cell';
    tdDetail.colSpan = 10;
    trDetail.appendChild(tdDetail);
    tbody.appendChild(trDetail);

    R.renderDetailView(ctx, tdDetail, row);

    tdAct.querySelector('.tpma-view-btn').addEventListener('click', function(){
      const isVisible = trDetail.style.display !== 'none';
      if (isVisible) { trDetail.style.display = 'none'; this.textContent = '檢視'; }
      else { trDetail.style.display = ''; this.textContent = '收合'; }
    });

    tdSel.querySelector('.tpma-reg-select').addEventListener('change', ctx.actions.updateBatchButtonsEnabled);
  });

  ctx.actions.updateBatchButtonsEnabled();
  ctx.actions.updatePaginationControls();
};

})(window);
