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
  if (row.duration_minutes){ const n=tryParse(row.duration_minutes); if (n>0) return n/60; }

  const courses = ctx.data.allCourses || [];
  if (courses.length && row.course_id) {
    const c = courses.find(x => String(x.id) === String(row.course_id));
    if (c) {
      if (c.class_hours){ const n=tryParse(c.class_hours); if (n>0) return n; }
      if (c.course_hours){ const n=tryParse(c.course_hours); if (n>0) return n; }
      if (c.hours){ const n=tryParse(c.hours); if (n>0) return n; }
      if (c.duration_minutes){ const n=tryParse(c.duration_minutes); if (n>0) return n/60; }
    }
  }
  return 3;
};

R.findSessionDatetimeForRow = function findSessionDatetimeForRow(ctx, row){
  return S && typeof S.findSessionDatetimeForRow === 'function'
    ? S.findSessionDatetimeForRow(ctx, row)
    : null;
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
    const weekHtml = week ? '<wbr><span class="tpma-class-date-fragment tpma-class-date-week">（' + U.esc(week) + '）</span>' : '';
    return '<span class="tpma-class-date-fragment tpma-class-date-date">' + U.esc(datePart) + '</span>' + weekHtml;
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
  const weekHtml = week ? '<wbr><span class="tpma-class-date-fragment tpma-class-date-week">（' + U.esc(week) + '）</span>' : '';
  const timeHtml = range ? '<wbr><span class="tpma-class-date-fragment tpma-class-date-time">' + U.esc(range) + '</span>' : '';
  return '<span class="tpma-class-date-fragment tpma-class-date-date">' + U.esc(datePart) + '</span>' + weekHtml + timeHtml;
};

R.getClassStartDate = function getClassStartDate(ctx, row){
  if (!row) return null;
  let raw = row.class_date ? String(row.class_date).trim() : '';
  if (isAdjustingDate(raw) || !raw) return null;

  if (raw.length <= 10 || raw.indexOf(' ') === -1) {
    const sessionDt = R.findSessionDatetimeForRow(ctx, row);
    if (sessionDt) raw = String(sessionDt).trim();
  }

  let start = new Date(raw.replace(' ', 'T'));
  if (!isNaN(start.getTime())) return start;

  const dateOnly = raw.substring(0,10);
  start = new Date(dateOnly + 'T00:00:00');
  return isNaN(start.getTime()) ? null : start;
};

R.isClassCompleted = function isClassCompleted(ctx, row){
  const start = R.getClassStartDate(ctx, row);
  if (!start) return false;

  const hours = R.getCourseHoursForRow(ctx, row);
  const durationMs = hours > 0 ? hours * 60 * 60 * 1000 : 0;
  const end = new Date(start.getTime() + durationMs);
  return end.getTime() < Date.now();
};

R.accessModeLabel = function accessModeLabel(row){
  const mode = String(row.access_mode || (row.delivery_mode === 'recorded' ? 'recorded' : 'live')).toLowerCase();
  return mode === 'recorded' ? '錄播' : '直播';
};

R.buildAccessModeBadgeHtml = function buildAccessModeBadgeHtml(row){
  const label = R.accessModeLabel(row);
  const cls = label === '錄播' ? 'is-recorded' : 'is-live';
  return '<span class="tpma-access-mode-badge ' + cls + '" title="課程型態: ' + U.esc(label) + '">' + U.esc(label) + '</span>';
};

R.getAccessModeSummary = function getAccessModeSummary(rows){
  const labels = [];
  (rows || []).forEach(function(row){
    const label = R.accessModeLabel(row);
    if (labels.indexOf(label) === -1) labels.push(label);
  });
  return labels.join(' / ');
};

R.buildAccessModeSummaryHtml = function buildAccessModeSummaryHtml(rows){
  const representativeByLabel = {};
  (rows || []).forEach(function(row){
    const label = R.accessModeLabel(row);
    if (!representativeByLabel[label]) representativeByLabel[label] = row;
  });

  const labels = ['直播', '錄播'].filter(function(label){ return !!representativeByLabel[label]; });
  Object.keys(representativeByLabel).forEach(function(label){
    if (labels.indexOf(label) === -1) labels.push(label);
  });

  const badges = labels.map(function(label){
    return R.buildAccessModeBadgeHtml(representativeByLabel[label]);
  });
  return badges.join(' ');
};

R.copySessionPortal = async function copySessionPortal(ctx, row, regenerate){
  const result = await API.getSessionPortal(ctx, row.id, !!regenerate);
  const url = result?.urls?.portal || result?.portal || '';
  if (!url) throw new Error('未取得全員測驗入口');
  await navigator.clipboard.writeText(url);
  return url;
};

R.copyOrderPortal = async function copyOrderPortal(ctx, row, regenerate){
  const result = await API.regeneratePortal(ctx, row.id, !!regenerate);
  const url = result?.urls?.portal || result?.portal || '';
  if (!url) throw new Error('未取得個人／同訂單入口');
  await navigator.clipboard.writeText(url);
  return url;
};

R.receiptTypeText = function receiptTypeText(type){
  return String(type || '').toLowerCase() === 'paper' ? '紙本' : '電子';
};

R.receiptStatusText = function receiptStatusText(status){
  const labels = {
    pending: '待開',
    generated: '待寄',
    awaiting_scan: '待掃描',
    scanned: '待寄',
    sent: '已寄',
    void: '作廢'
  };
  return labels[String(status || 'pending')] || '待開';
};

R.receiptCompactText = function receiptCompactText(type, status){
  return R.receiptTypeText(type) + '／' + R.receiptStatusText(status);
};

R.receiptStatusPillClass = function receiptStatusPillClass(status){
  switch(String(status || 'pending')){
    case 'sent': return 'tpma-status-pill-g2-sent';
    case 'generated':
    case 'awaiting_scan':
    case 'scanned': return 'tpma-status-pill-g2-opened';
    case 'void': return 'tpma-status-pill-g1-cancelled';
    case 'pending':
    default: return 'tpma-status-pill-g2-pending';
  }
};

R.receiptIsPreviewable = function receiptIsPreviewable(type, status){
  const receiptType = String(type || '').toLowerCase();
  const receiptStatus = String(status || 'pending');
  if (receiptType === 'paper') return receiptStatus === 'awaiting_scan' || receiptStatus === 'scanned' || receiptStatus === 'sent';
  return receiptStatus === 'generated' || receiptStatus === 'sent';
};

R.receiptIsSendable = function receiptIsSendable(type, status){
  const receiptType = String(type || '').toLowerCase();
  const receiptStatus = String(status || 'pending');
  if (receiptType === 'paper') return receiptStatus === 'scanned' || receiptStatus === 'sent';
  return receiptStatus === 'generated' || receiptStatus === 'sent';
};

R.buildStatusIconsHtml = function buildStatusIconsHtml(ctx, row){
  const icons = [];
  if (!row.session_id) {
    icons.push('<span class="tpma-status-pill tpma-status-pill-g1-pending" title="此舊報名尚未綁定場次">待指定場次</span>');
  }
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

  const rCode = row.receipt_status || 'pending';
  const rType = row.receipt_type || 'electronic';
  const rText = R.receiptCompactText(rType, rCode);
  const rClass = R.receiptStatusPillClass(rCode);
  const previewable = row.woocommerce_order_id && R.receiptIsPreviewable(rType, rCode);
  if (previewable) {
    icons.push('<a href="#" class="tpma-status-pill '+rClass+' tpma-receipt-link tpma-receipt-status-link" data-receipt-preview-order="'+U.esc(row.woocommerce_order_id)+'" title="預覽收據">'+U.esc(rText)+'</a>');
  } else {
    icons.push('<span class="tpma-status-pill '+rClass+' tpma-receipt-state" title="收據方式／狀態: '+U.esc(rText)+'">'+U.esc(rText)+'</span>');
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
  detailContainer.className = 'tpma-reg-detail-container tpma-reg-detail-view';

  const grid = document.createElement('div');
  grid.className = 'tpma-reg-detail-view-grid';

  const appendField = (labelText, value, opts = {}) => {
    const item = document.createElement('div');
    item.className = 'tpma-reg-detail-info' + (opts.span ? ' ' + opts.span : '');

    const label = document.createElement('label');
    label.textContent = labelText;
    item.appendChild(label);

    const valueDiv = document.createElement('div');
    valueDiv.className = 'tpma-reg-detail-info-value';
    if (opts.html) {
      valueDiv.innerHTML = value || '';
    } else {
      valueDiv.textContent = U.display(value);
    }
    item.appendChild(valueDiv);
    grid.appendChild(item);
    return item;
  };

  appendField('課程名稱', courseText);
  appendField('授課講師', lecturerText);
  appendField('授課日期時間', R.buildClassDateRangeHtml(ctx, row), { html: true });
  appendField('課程型態', R.accessModeLabel(row));
  appendField('報名編號', row.reg_no || 'N/A');

  appendField('學員姓名', row.student_name);
  appendField('部門 / 職稱', [U.display(row.department), U.display(row.job_title)].filter(Boolean).join(' / '));
  appendField('手機', row.mobile);
  appendField('Email', row.emails);

  appendField('公司抬頭', row.company_name);
  appendField('統一編號', row.tax_id);
  appendField('承辦人姓名', row.contact_name);
  appendField('承辦人 Email', row.contact_email);

  appendField('地址', row.address, { span: 'span-2' });
  appendField('電話', row.phone);
  appendField('收件人', row.receiver);
  appendField('報名時間', U.trimToMinute(row.created_at));
  appendField('場次關聯', row.session_id ? ('#' + row.session_id) : '待指定場次');
  appendField('報名狀態', L.statusLabel(row.status));
  appendField('付款狀態 (WC)', L.paymentStatusLabel(row.payment_status));
  const receiptInfo = appendField('收據方式 / 狀態', R.receiptDisplayHtml(row, null), { html: true });
  appendField('測驗成績', row.test_score);
  appendField('證書編號', row.certificate_id);
  appendField('匯款金額（元）', U.formatAmount(row.remit_amount));
  appendField('匯款帳號', row.remit_account);
  appendField('匯款日期', row.remit_paid_at);
  appendField('資訊來源', row.source);

  if (row.woocommerce_order_id) {
    const wcOrderLink = ctx.orderEditBase ? `${ctx.orderEditBase}${row.woocommerce_order_id}&action=edit` : '';
    const orderIdLabel = row.woocommerce_order_id;
    const linkHtml = wcOrderLink ? `<a href="${wcOrderLink}" target="_blank">${orderIdLabel}</a>` : orderIdLabel;
    appendField('WooCommerce 訂單 ID', linkHtml, { html: true });
  }

  appendField('備註', row.note, { span: 'span-all' });
  detailContainer.appendChild(grid);
  R.bindReceiptPreviewLink(ctx, receiptInfo.querySelector('[data-receipt-preview], [data-receipt-preview-order]'), row.woocommerce_order_id);

  const actionsDiv = document.createElement('div');
  actionsDiv.className = 'tpma-reg-detail-actions tpma-reg-detail-view-actions';
  actionsDiv.innerHTML = `
    ${row.session_id ? `<button class="tpma-btn tpma-btn-secondary tpma-reg-action-btn" id="tpma-btn-session-portal-${row.id}" type="button">複製全員測驗入口</button>` : ''}
    ${row.woocommerce_order_id ? `<button class="tpma-btn tpma-btn-secondary tpma-reg-action-btn" id="tpma-btn-order-portal-${row.id}" type="button">複製個人／同訂單入口</button>` : ''}
    <button class="tpma-btn tpma-btn-secondary tpma-reg-action-btn" id="tpma-btn-edit-${row.id}">編輯詳情</button>
  `;

  detailContainer.appendChild(actionsDiv);

  container.appendChild(detailContainer);

  // 綁定編輯按鈕事件
  const sessionPortalBtn = actionsDiv.querySelector(`#tpma-btn-session-portal-${row.id}`);
  if (sessionPortalBtn) sessionPortalBtn.addEventListener('click', async function(){
    try {
      sessionPortalBtn.disabled = true;
      await R.copySessionPortal(ctx, row, false);
      global.alert('全員測驗入口已複製。');
    } catch (e) { global.alert(e.message || '無法取得全員測驗入口'); }
    finally { sessionPortalBtn.disabled = false; }
  });
  const orderPortalBtn = actionsDiv.querySelector(`#tpma-btn-order-portal-${row.id}`);
  if (orderPortalBtn) orderPortalBtn.addEventListener('click', async function(){
    try {
      orderPortalBtn.disabled = true;
      await R.copyOrderPortal(ctx, row, false);
      global.alert('個人／同訂單入口已複製。');
    } catch (e) { global.alert(e.message || '無法取得個人／同訂單入口'); }
    finally { orderPortalBtn.disabled = false; }
  });
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

  const courseGroups = {};
  (ctx.data.allCourses || []).forEach(c=>{
    const lecturer = U.display(c.lecturer || c.lecturer_name || c.lecturer_code) || '未指定講師';
    if (!courseGroups[lecturer]) {
      courseGroups[lecturer] = [];
    }
    courseGroups[lecturer].push(c);
  });

  Object.keys(courseGroups).forEach(lecturer=>{
    const group = document.createElement('optgroup');
    group.label = lecturer;

    courseGroups[lecturer].forEach(c=>{
      if (String(c.is_active) === '0' && String(c.id) !== String(row.course_id || '')) return;
      const opt = document.createElement('option');
      opt.value = c.id || '';
      opt.textContent = c.course_name || '';
      if (String(c.id) === String(row.course_id || '')) opt.selected = true;
      group.appendChild(opt);
    });

    courseSel.appendChild(group);
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
        if (String(s.is_active) === '0' && String(s.id) !== String(row.session_id || '')) return;
        const sessionValue = String(s.session_datetime);
        const opt = document.createElement('option');
        opt.value = sessionValue;
        opt.dataset.sessionId = s.id || '';
        opt.dataset.deliveryMode = s.delivery_mode || 'live';

        const label = durationMinutes ? U.formatSessionDisplay(sessionValue, durationMinutes) : sessionValue;
        opt.textContent = label;

        if ((row.session_id && String(s.id) === String(row.session_id)) || sessionValue === compareValue || sessionValue.substring(0,16) === compareValue.substring(0,16)) opt.selected = true;

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

  function updateAccessMode(){
    const accessSel = document.querySelector('.tpma-reg-card[data-id="' + row.id + '"] [data-field="access_mode"]');
    if (!accessSel) return;
    const mode = dateSel.selectedOptions?.[0]?.dataset?.deliveryMode || row.delivery_mode || 'live';
    const current = accessSel.value;
    const allowed = mode === 'hybrid' ? ['live', 'recorded'] : [mode === 'recorded' ? 'recorded' : 'live'];
    accessSel.innerHTML = allowed.map(value => `<option value="${value}">${value === 'recorded' ? '錄播' : '直播'}</option>`).join('');
    accessSel.value = allowed.includes(current) ? current : allowed[0];
  }
  updateAccessMode();

  courseSel.addEventListener('change', function(){ rebuildDates(this.value); updateAccessMode(); });
  dateSel.addEventListener('change', updateAccessMode);
};

R.receiptDisplayHtml = function receiptDisplayHtml(row, receipt){
  const type = receipt?.receipt_type || row.receipt_type || 'electronic';
  const status = receipt?.status || row.receipt_status || 'pending';
  const text = R.receiptCompactText(type, status);
  const cls = R.receiptStatusPillClass(status);
  if (receipt && receipt.id && R.receiptIsPreviewable(type, status)) {
    return '<a href="#" class="tpma-status-pill '+cls+' tpma-receipt-link" data-receipt-preview="'+U.esc(receipt.id)+'" title="預覽收據">'+U.esc(text)+'</a>';
  }
  if (!receipt && row.woocommerce_order_id && R.receiptIsPreviewable(type, status)) {
    return '<a href="#" class="tpma-status-pill '+cls+' tpma-receipt-link" data-receipt-preview-order="'+U.esc(row.woocommerce_order_id)+'" title="預覽收據">'+U.esc(text)+'</a>';
  }
  return '<span class="tpma-status-pill '+cls+' tpma-receipt-state">'+U.esc(text)+'</span>';
};

R.bindReceiptPreviewLink = function bindReceiptPreviewLink(ctx, link, fallbackOrderId){
  if (!link) return;
  link.addEventListener('click', async function(event){
    event.preventDefault();
    event.stopPropagation();
    const previewWindow = R.prepareReceiptPreviewWindow();
    if (!previewWindow) return;
    try {
      const receiptId = parseInt(link.getAttribute('data-receipt-preview') || '0', 10) || 0;
      const orderId = parseInt(link.getAttribute('data-receipt-preview-order') || fallbackOrderId || '0', 10) || 0;
      const receipt = receiptId ? { id: receiptId } : await R.loadReceiptForOrder(ctx, orderId);
      if (!receipt || !receipt.id) throw new Error('此訂單尚未有可預覽的收據。');
      await R.openReceiptPreview(ctx, receipt.id, previewWindow);
    } catch (error) {
      API.closePdfWindow(previewWindow);
      global.alert(error.message || '無法預覽收據');
    }
  });
};

R.prepareReceiptPreviewWindow = function prepareReceiptPreviewWindow(){
  try {
    return API.preparePdfWindow();
  } catch (e) {
    global.alert(e.message || '無法開啟收據預覽視窗');
    return null;
  }
};

R.openReceiptPreview = async function openReceiptPreview(ctx, receiptId, popup){
  const previewWindow = popup || R.prepareReceiptPreviewWindow();
  if (!previewWindow) return;
  try {
    const blob = await API.receiptBlob(ctx, receiptId, false);
    API.openPdfBlob(blob, previewWindow);
  } catch (e) {
    API.closePdfWindow(previewWindow);
    global.alert(e.message || '無法預覽收據');
  }
};

R.loadReceiptForOrder = async function loadReceiptForOrder(ctx, orderId){
  if (!orderId) return null;
  return await API.getOrderReceipt(ctx, orderId);
};

R.closeReceiptActionMenus = function closeReceiptActionMenus(except){
  document.querySelectorAll('.tpma-receipt-action-menu.is-open').forEach(function(menu){
    if (menu === except) return;
    menu.classList.remove('is-open');
    const toggle = menu.querySelector('.tpma-receipt-menu-toggle');
    const panel = menu.querySelector('.tpma-receipt-menu-panel');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (panel) panel.hidden = true;
  });
};

R.bindReceiptActionMenu = function bindReceiptActionMenu(menu, handlers){
  if (!menu) return;
  const toggle = menu.querySelector('.tpma-receipt-menu-toggle');
  const panel = menu.querySelector('.tpma-receipt-menu-panel');
  const items = Array.from(menu.querySelectorAll('[data-receipt-action]'));
  const setOpen = function(open){
    R.closeReceiptActionMenus(open ? menu : null);
    menu.classList.toggle('is-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (panel) panel.hidden = !open;
  };
  if (toggle) {
    toggle.addEventListener('click', function(event){
      event.preventDefault();
      event.stopPropagation();
      setOpen(!menu.classList.contains('is-open'));
    });
    toggle.addEventListener('keydown', function(event){
      if (event.key === 'Escape') {
        setOpen(false);
      } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        setOpen(true);
        if (items[0]) items[0].focus();
      }
    });
  }
  items.forEach(function(item){
    item.addEventListener('click', async function(){
      const handler = handlers[item.getAttribute('data-receipt-action') || ''];
      setOpen(false);
      if (typeof handler === 'function') await handler();
    });
    item.addEventListener('keydown', function(event){
      if (event.key === 'Escape') {
        event.preventDefault();
        setOpen(false);
        if (toggle) toggle.focus();
      }
    });
  });
  if (!R._receiptActionMenuOutsideBound) {
    R._receiptActionMenuOutsideBound = true;
    document.addEventListener('click', function(event){
      if (!event.target.closest('.tpma-receipt-action-menu')) R.closeReceiptActionMenus();
    });
  }
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
  const accessOptions = row.delivery_mode === 'hybrid'
    ? (O.accessMode || [])
    : (O.accessMode || []).filter(x => x.value === (row.delivery_mode === 'recorded' ? 'recorded' : 'live'));
  appendEditField(studentSection, '課程型態', 'access_mode', 'select', row.access_mode || (row.delivery_mode === 'recorded' ? 'recorded' : 'live'), accessOptions);
  detailContainer.appendChild(studentSection);

  // 區塊 3: 公司資料
  const companySection = document.createElement('div');
  companySection.className = 'tpma-reg-detail-section edit-mode';
  companySection.id = 'section-company';
  appendEditField(companySection, '公司抬頭', 'company_name', 'text', row.company_name);
  appendEditField(companySection, '統一編號', 'tax_id', 'text', row.tax_id);
  appendEditField(companySection, '承辦人姓名', 'contact_name', 'text', row.contact_name);
  appendEditField(companySection, '承辦人Email（可用 , 或 ; 分隔多筆）', 'contact_email', 'text', row.contact_email);
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
  appendEditField(receiptSection, '變更收據方式', 'receipt_type', 'select', row.receipt_type, [
    { value: '', label: '請選擇' },
    ...(O.receiptType || [])
  ]);
  const receiptTypeSelect = receiptSection.querySelector('[data-field="receipt_type"]');
  if (receiptTypeSelect && row.woocommerce_order_id) {
    receiptTypeSelect.disabled = true;
    receiptTypeSelect.dataset.receiptLoading = '1';
    receiptTypeSelect.title = '正在讀取收據狀態。';
  }
  const receiptManagedField = document.createElement('div');
  receiptManagedField.className = 'tpma-detail-field';
  receiptManagedField.innerHTML = '<label>目前收據方式 / 狀態</label><div class="tpma-receipt-admin-state">讀取收據中…</div>';
  receiptSection.appendChild(receiptManagedField);
  
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
  actionsDiv.className = 'tpma-reg-detail-actions tpma-reg-edit-actions';
  actionsDiv.innerHTML = `
    <div class="tpma-reg-edit-actions-primary">
      <button class="tpma-btn" id="tpma-btn-save-detail-${row.id}">儲存變更</button>
      ${row.woocommerce_order_id ? `<span class="tpma-receipt-action-slot" aria-live="polite">讀取收據中…</span>` : ''}
    </div>
    <div class="tpma-reg-edit-actions-secondary">
      ${row.session_id ? `<button class="tpma-btn tpma-btn-secondary" id="tpma-btn-portal-${row.id}" type="button">複製全員測驗入口</button>` : ''}
      ${row.woocommerce_order_id ? `<button class="tpma-btn tpma-btn-secondary" id="tpma-btn-order-portal-edit-${row.id}" type="button">複製個人／同訂單入口</button>` : ''}
      ${row.session_id ? `<button class="tpma-btn tpma-btn-secondary" id="tpma-btn-portal-reset-${row.id}" type="button">重置全員入口</button>` : ''}
      <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-cancel-edit-${row.id}">取消編輯</button>
    </div>
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
    if (statusSel && statusSel.value === 'postpay' && row.status !== 'postpay') {
      if (!global.confirm('課後付款會套用至同一 Woo 訂單的全部學員，並將訂單維持為 on-hold。確定繼續？')) return;
    }
    await R.saveDetail(ctx, container, row.id, row);
  });
  actionsDiv.querySelector(`#tpma-btn-cancel-edit-${row.id}`).addEventListener('click', function(){
    R.renderDetailView(ctx, container, row);
  });
  const portalBtn = actionsDiv.querySelector(`#tpma-btn-portal-${row.id}`);
  if (portalBtn) portalBtn.addEventListener('click', async function(){
    try {
      await R.copySessionPortal(ctx, row, false);
      global.alert('全員測驗入口已複製。');
    } catch (e) { global.alert(e.message || '無法取得全員測驗入口'); }
  });
  const orderPortalEditBtn = actionsDiv.querySelector(`#tpma-btn-order-portal-edit-${row.id}`);
  if (orderPortalEditBtn) orderPortalEditBtn.addEventListener('click', async function(){
    try {
      await R.copyOrderPortal(ctx, row, false);
      global.alert('個人／同訂單入口已複製。');
    } catch (e) { global.alert(e.message || '無法取得個人／同訂單入口'); }
  });
  const portalResetBtn = actionsDiv.querySelector(`#tpma-btn-portal-reset-${row.id}`);
  if (portalResetBtn) portalResetBtn.addEventListener('click', async function(){
    if (!global.confirm('重置全員入口會讓此場次舊的全員測驗入口立即失效。確定產生新入口？')) return;
    try {
      await R.copySessionPortal(ctx, row, true);
      global.alert('新的全員測驗入口已複製。');
    } catch (e) { global.alert(e.message || '無法重置全員測驗入口'); }
  });

  const receiptActionSlot = actionsDiv.querySelector('.tpma-receipt-action-slot');
  let currentReceipt = null;

  const refreshReceipt = async function(message){
    if (message) global.alert(message);
    await ctx.actions.refresh();
  };
  const withReceipt = async function(){
    if (currentReceipt) return currentReceipt;
    if (!row.woocommerce_order_id) throw new Error('此報名沒有對應的 WooCommerce 訂單。');
    currentReceipt = await R.loadReceiptForOrder(ctx, row.woocommerce_order_id);
    return currentReceipt;
  };
  const actionGenerate = async function(){
    try {
      const existing = await withReceipt();
      if (existing && existing.status !== 'void') throw new Error('此訂單已有收據，請改用重新生成。');
      const receipt = await API.generateReceipt(ctx, row.woocommerce_order_id);
      syncReceiptControls(receipt);
      await refreshReceipt('已生成收據。');
    } catch (error) { global.alert(error.message || '無法生成收據'); }
  };
  const actionRegenerate = async function(){
    try {
      const receipt = await withReceipt();
      if (!receipt || receipt.status === 'void') throw new Error('此訂單尚未有可重新生成的有效收據。');
      if (!global.confirm('會以目前訂單資料重建收據，並保留原流水號。確定繼續？')) return;
      const updated = await API.regenerateReceipt(ctx, receipt.id);
      syncReceiptControls(updated);
      await refreshReceipt('收據已重新生成。');
    } catch (error) { global.alert(error.message || '無法重新生成收據'); }
  };
  const actionUploadScan = async function(){
    try {
      const receipt = await withReceipt();
      if (!receipt || receipt.receipt_type !== 'paper' || receipt.status !== 'awaiting_scan') {
        throw new Error('目前沒有待上傳的紙本收據掃描檔。');
      }
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
      input.addEventListener('change', async function(){
        const file = input.files && input.files[0];
        if (!file) return;
        try {
          const updated = await API.uploadReceiptScan(ctx, receipt.id, file);
          syncReceiptControls(updated);
          await refreshReceipt('紙本收據掃描檔已上傳。');
        } catch (error) { global.alert(error.message || '上傳掃描檔失敗'); }
      });
      input.click();
    } catch (error) { global.alert(error.message || '無法上傳掃描檔'); }
  };
  const actionSend = async function(){
    try {
      const receipt = await withReceipt();
      if (!receipt || !R.receiptIsSendable(receipt.receipt_type, receipt.status) || receipt.status === 'sent') {
        throw new Error('此收據目前不可寄發。');
      }
      if (!global.confirm('將依 receipt_notice 模板寄給此收據所有來源訂單的承辦人，並附上目前有效收據。確定寄發？')) return;
      const updated = await API.sendReceipt(ctx, receipt.id, false);
      syncReceiptControls(updated);
      await refreshReceipt('收據已寄發給來源訂單承辦人。');
    } catch (error) { global.alert(error.message || '收據寄發失敗'); }
  };
  const actionChangeReceiptType = async function(receiptType){
    const receipt = await withReceipt();
    if (!receipt || receipt.status === 'sent' || receipt.status === 'void') {
      throw new Error('只有未寄收據可變更方式；已寄收據請先作廢後重新開立。');
    }
    if (receipt.receipt_type === receiptType) return receipt;
    const typeLabel = receiptType === 'paper' ? '紙本' : '電子';
    if (!global.confirm('確定改為' + typeLabel + '收據嗎？會保留收據號並自動重新生成。')) {
      return null;
    }
    const updated = await API.changeReceiptType(ctx, receipt.id, receiptType);
    row.receipt_type = updated.receipt_type;
    row.receipt_status = updated.status;
    syncReceiptControls(updated);
    await refreshReceipt('收據已改為' + typeLabel + '並重新生成。');
    return updated;
  };
  const actionVoid = async function(){
    try {
      const receipt = await withReceipt();
      if (!receipt || receipt.status !== 'sent') throw new Error('只有已寄收據可作廢。');
      if (!global.confirm('確定作廢收據 ' + receipt.serial + ' 嗎？作廢後可重新開立。')) return;
      const updated = await API.voidReceipt(ctx, receipt.id);
      row.receipt_status = updated.status;
      syncReceiptControls(updated);
      await refreshReceipt('收據已作廢，可重新開立。');
    } catch (error) { global.alert(error.message || '收據作廢失敗'); }
  };
  const renderReceiptMenu = function(receipt){
    if (!receiptActionSlot) return;
    receiptActionSlot.innerHTML = '';
    const actions = [];
    if (!receipt) actions.push({ key: 'generate', label: '生成收據' });
    else if (receipt.status === 'void') actions.push({ key: 'generate', label: '重新開立收據' });
    else if (receipt.status === 'sent') actions.push({ key: 'void', label: '作廢收據' });
    else {
      actions.push({ key: 'regenerate', label: '重新生成收據' });
      if (receipt.receipt_type === 'paper' && receipt.status === 'awaiting_scan') {
        actions.push({ key: 'upload_scan', label: '上傳紙本掃描檔' });
      }
      if (R.receiptIsSendable(receipt.receipt_type, receipt.status) && receipt.status !== 'sent') {
        actions.push({ key: 'send', label: '寄發收據' });
      }
    }
    if (!actions.length) return;
    const menu = document.createElement('div');
    menu.className = 'tpma-receipt-action-menu';
    const menuId = 'tpma-receipt-menu-' + row.id;
    menu.innerHTML = '<button type="button" class="tpma-btn tpma-btn-secondary tpma-receipt-menu-toggle" aria-expanded="false" aria-controls="'+U.esc(menuId)+'">收據操作 <span aria-hidden="true">▾</span></button>' +
      '<div id="'+U.esc(menuId)+'" class="tpma-receipt-menu-panel" role="group" aria-label="收據操作" hidden>' +
      actions.map(function(action){ return '<button type="button" class="tpma-receipt-menu-item" data-receipt-action="'+U.esc(action.key)+'">'+U.esc(action.label)+'</button>'; }).join('') +
      '</div>';
    receiptActionSlot.appendChild(menu);
    R.bindReceiptActionMenu(menu, { generate: actionGenerate, regenerate: actionRegenerate, upload_scan: actionUploadScan, send: actionSend, void: actionVoid });
  };
  const syncReceiptControls = function(receipt){
    currentReceipt = receipt || null;
    const statusEl = receiptManagedField.querySelector('.tpma-receipt-admin-state');
    if (statusEl) {
      statusEl.innerHTML = R.receiptDisplayHtml(row, receipt);
      R.bindReceiptPreviewLink(ctx, statusEl.querySelector('[data-receipt-preview]'), row.woocommerce_order_id);
    }
    if (receiptTypeSelect) {
      if (receipt && receipt.receipt_type) receiptTypeSelect.value = receipt.receipt_type;
      const locked = !!(receipt && (receipt.status === 'sent' || receipt.status === 'void'));
      receiptTypeSelect.disabled = locked;
      delete receiptTypeSelect.dataset.receiptLoading;
      if (receipt) receiptTypeSelect.dataset.receiptManaged = '1';
      else delete receiptTypeSelect.dataset.receiptManaged;
      receiptTypeSelect.title = locked ? '已寄或已作廢收據的方式已鎖定。' : (receipt ? '選擇後會自動重新生成收據。' : '選擇後按儲存即可套用。');
    }
    renderReceiptMenu(receipt);
  };
  if (receiptTypeSelect) receiptTypeSelect.addEventListener('change', async function(){
    const selectedType = this.value;
    const beforeType = currentReceipt && currentReceipt.receipt_type ? currentReceipt.receipt_type : row.receipt_type;
    if (!currentReceipt || !selectedType || selectedType === beforeType) return;
    this.disabled = true;
    try {
      const updated = await actionChangeReceiptType(selectedType);
      if (!updated) this.value = beforeType || '';
    } catch (error) {
      global.alert(error.message || '收據方式變更失敗');
      this.value = beforeType || '';
    } finally {
      if (detailContainer.isConnected) this.disabled = !!(currentReceipt && (currentReceipt.status === 'sent' || currentReceipt.status === 'void'));
    }
  });
  if (!row.woocommerce_order_id) {
    syncReceiptControls(null);
  } else {
    const isCurrentEdit = function(){
      return detailContainer.isConnected && container.contains(detailContainer);
    };
    R.loadReceiptForOrder(ctx, row.woocommerce_order_id).then(function(receipt){
      if (!isCurrentEdit()) return;
      syncReceiptControls(receipt);
    }).catch(function(error){
      if (!isCurrentEdit()) return;
      const statusEl = receiptManagedField.querySelector('.tpma-receipt-admin-state');
      if (statusEl) statusEl.textContent = '無法讀取收據：' + (error.message || '請稍後再試');
      if (receiptActionSlot) receiptActionSlot.textContent = '';
    });
  }
};

R.saveDetail = async function saveDetail(ctx, container, id, sourceRow = {}){
  const inputs = container.querySelectorAll('[data-field]');
  const payload = { id: parseInt(id,10) || 0 };
  const ignoredFields = new Set(['woocommerce_order_id', 'created_at', 'receipt_status']);
  const normaliseFieldValue = function(field, value){
    let normalized = value == null ? '' : String(value).trim();
    if (field === 'course_id' && normalized === ADJUSTING_VALUE) normalized = '0';
    if (field === 'class_date' && normalized === ADJUSTING_VALUE) normalized = '';
    if (field === 'remit_amount' && normalized !== '') normalized = String(parseInt(normalized.replace(/,/g,''), 10) || 0);
    return normalized;
  };
  inputs.forEach(el=>{
    const f = el.dataset.field;
    if (!f || ignoredFields.has(f)) return;
    if (f === 'receipt_type' && (el.disabled || el.dataset.receiptLoading === '1' || el.dataset.receiptManaged === '1')) return;
    const v = normaliseFieldValue(f, el.value);
    const previous = normaliseFieldValue(f, sourceRow[f]);
    if (v !== previous) payload[f] = v;
  });
  const sessionSelect = container.querySelector('[data-field="class_date"]');
  if (sessionSelect && sessionSelect.selectedOptions && sessionSelect.selectedOptions[0]) {
    const sessionId = parseInt(sessionSelect.selectedOptions[0].dataset.sessionId || '0', 10) || 0;
    if (String(sessionId || '') !== String(sourceRow.session_id || '')) payload.session_id = sessionId;
  }
  if (!payload.id) { alert('找不到這筆資料的 ID'); return; }
  if (Object.keys(payload).length === 1) { alert('沒有可儲存的變更。'); return; }
  if (Object.prototype.hasOwnProperty.call(payload, 'course_id')) {
    const courseSelect = container.querySelector('[data-field="course_id"]');
    const courseName = courseSelect?.selectedOptions?.[0]?.textContent?.trim() || '目標課程';
    const sessionName = sessionSelect?.selectedOptions?.[0]?.textContent?.trim() || '目標場次';
    if (!global.confirm('確定將此學員轉至「' + courseName + '／' + sessionName + '」？\n原付款金額、退款與發票不會自動調整。')) return;
  }

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
    this.classList.toggle('is-open', !isOpen);
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
  summary.className = 'tpma-reg-card-summary tpma-reg-grid-layout tpma-reg-flat-summary';

  const cSel = document.createElement('div');
  cSel.className = 'tpma-reg-cell';
  cSel.setAttribute('data-label', '選取');
  cSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-reg-select"></div>';
  summary.appendChild(cSel);

  const cSeq = document.createElement('div');
  cSeq.className = 'tpma-reg-cell tpma-seq-col';
  cSeq.setAttribute('data-label', '序');
  cSeq.textContent = seq;
  summary.appendChild(cSeq);

  const cCreated = document.createElement('div');
  cCreated.className = 'tpma-reg-cell';
  cCreated.setAttribute('data-label', '報名時間');
  cCreated.innerHTML = '<div class="tpma-cell-wrap">' + R.buildCreatedAtHtml(row.created_at) + '</div>';
  summary.appendChild(cCreated);

  const cCourse = document.createElement('div');
  cCourse.className = 'tpma-reg-cell';
  cCourse.setAttribute('data-label', '課程名稱');
  const courseText = R.getCourseSummaryText(row);
  const lecturerText = R.getLecturerSummaryText(row);
  const titleAttr = lecturerText ? ' title="講師：' + U.esc(lecturerText) + '"' : '';
  cCourse.innerHTML = '<div class="tpma-cell-wrap"><div><strong class="tpma-reg-flat-primary"' + titleAttr + '>' + U.esc(courseText) + '</strong><div class="tpma-reg-flat-secondary">' + R.buildAccessModeBadgeHtml(row) + (lecturerText ? ' <span>講師：' + U.esc(lecturerText) + '</span>' : '') + '</div></div></div>';
  summary.appendChild(cCourse);

  const cDate = document.createElement('div');
  cDate.className = 'tpma-reg-cell';
  cDate.setAttribute('data-label', '授課日期');
  const classText = R.buildClassDateRangeHtml(ctx, row);
  cDate.innerHTML = '<div class="tpma-cell-wrap">' + (classText || '') + '</div>';
  summary.appendChild(cDate);

  const cStu = document.createElement('div');
  cStu.className = 'tpma-reg-cell';
  cStu.setAttribute('data-label', '學員姓名');
  const studentSub = [U.display(row.department), U.display(row.job_title)].filter(Boolean).join(' / ');
  cStu.innerHTML = '<div class="tpma-cell-wrap"><div><strong class="tpma-reg-flat-primary">' + U.esc(U.display(row.student_name)) + '</strong>' + (studentSub ? '<div class="tpma-reg-flat-secondary">' + U.esc(studentSub) + '</div>' : '') + '</div></div>';
  summary.appendChild(cStu);

  const cComp = document.createElement('div');
  cComp.className = 'tpma-reg-cell';
  cComp.setAttribute('data-label', '公司抬頭');
  cComp.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(U.display(row.company_name)) + '</div>';
  summary.appendChild(cComp);

  const cStatus = document.createElement('div');
  cStatus.className = 'tpma-reg-cell';
  cStatus.setAttribute('data-label', '狀態');
  cStatus.innerHTML = '<div class="tpma-cell-wrap">' + R.buildStatusIconsHtml(ctx, row) + '</div>';
  R.bindReceiptPreviewLink(ctx, cStatus.querySelector('[data-receipt-preview-order]'), row.woocommerce_order_id);
  summary.appendChild(cStatus);

  const cAct = document.createElement('div');
  cAct.className = 'tpma-reg-cell';
  cAct.setAttribute('data-label', '操作');
  cAct.innerHTML = '<div class="tpma-cell-wrap"><button class="tpma-btn tpma-btn-secondary tpma-view-btn tpma-reg-view-action">詳細</button></div>';
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
  summary.className = 'tpma-reg-card-summary tpma-reg-student-grid-layout tpma-reg-student-summary';

  const cSel = document.createElement('div');
  cSel.className = 'tpma-reg-cell';
  cSel.setAttribute('data-label', '選取');
  cSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-reg-select"></div>';
  summary.appendChild(cSel);

  const cSeq = document.createElement('div');
  cSeq.className = 'tpma-reg-cell tpma-seq-col';
  cSeq.setAttribute('data-label', '序');
  cSeq.textContent = seq;
  summary.appendChild(cSeq);

  const cCreated = document.createElement('div');
  cCreated.className = 'tpma-reg-cell';
  cCreated.setAttribute('data-label', '報名時間');
  cCreated.innerHTML = '<div class="tpma-cell-wrap">' + R.buildCreatedAtHtml(row.created_at) + '</div>';
  summary.appendChild(cCreated);

  const cStu = document.createElement('div');
  cStu.className = 'tpma-reg-cell';
  cStu.setAttribute('data-label', '學員姓名');
  const studentSub = [U.display(row.department), U.display(row.job_title)].filter(Boolean).join(' / ');
  cStu.innerHTML = '<div class="tpma-cell-wrap tpma-reg-student-name-wrap"><div><strong class="tpma-reg-student-name">' + U.esc(U.display(row.student_name)) + '</strong><div class="tpma-reg-student-sub">' + R.buildAccessModeBadgeHtml(row) + (studentSub ? ' <span>' + U.esc(studentSub) + '</span>' : '') + '</div></div></div>';
  summary.appendChild(cStu);

  const cComp = document.createElement('div');
  cComp.className = 'tpma-reg-cell';
  cComp.setAttribute('data-label', '公司抬頭');
  cComp.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(U.display(row.company_name)) + '</div>';
  summary.appendChild(cComp);

  const cStatus = document.createElement('div');
  cStatus.className = 'tpma-reg-cell';
  cStatus.setAttribute('data-label', '狀態');
  cStatus.innerHTML = '<div class="tpma-cell-wrap">' + R.buildStatusIconsHtml(ctx, row) + '</div>';
  R.bindReceiptPreviewLink(ctx, cStatus.querySelector('[data-receipt-preview-order]'), row.woocommerce_order_id);
  summary.appendChild(cStatus);

  const cAct = document.createElement('div');
  cAct.className = 'tpma-reg-cell';
  cAct.setAttribute('data-label', '操作');
  cAct.innerHTML = '<div class="tpma-cell-wrap"><button class="tpma-btn tpma-btn-secondary tpma-view-btn tpma-reg-view-action">詳細</button></div>';
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
  const pageGroups = S.getNestedMonthGroups(ctx);
  const hasAdjustingTab = !!(((ctx.data || {}).nestedMonths || {}).adjusting || []).length;

  const tabs = document.createElement('div');
  tabs.className = 'tpma-nested-month-tabs';
  for (let month = 1; month <= 12; month += 1) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tpma-nested-month-tab' + (month === ctx.state.nestedMonth ? ' is-active' : '');
    btn.textContent = month + '月';
    btn.dataset.month = String(month);
    btn.addEventListener('click', function(){
      const selectedMonth = parseInt(this.dataset.month || '', 10);
      if (!selectedMonth || selectedMonth === ctx.state.nestedMonth) return;
      ctx.state.nestedMonth = selectedMonth;
      R.renderTable(ctx);
    });
    tabs.appendChild(btn);
  }
  if (hasAdjustingTab) {
    const adjustingBtn = document.createElement('button');
    adjustingBtn.type = 'button';
    adjustingBtn.className = 'tpma-nested-month-tab' + (ctx.state.nestedMonth === ADJUSTING_VALUE ? ' is-active' : '');
    adjustingBtn.textContent = ADJUSTING_LABEL;
    adjustingBtn.dataset.month = ADJUSTING_VALUE;
    adjustingBtn.addEventListener('click', function(){
      if (ctx.state.nestedMonth === ADJUSTING_VALUE) return;
      ctx.state.nestedMonth = ADJUSTING_VALUE;
      R.renderTable(ctx);
    });
    tabs.appendChild(adjustingBtn);
  }
  tbody.appendChild(tabs);

  if (!pageGroups.length) {
    const empty = document.createElement('div');
    empty.className = 'tpma-empty-row';
    empty.textContent = ctx.state.nestedMonth === ADJUSTING_VALUE
      ? ADJUSTING_LABEL + '查無課程資料。'
      : ctx.state.nestedMonth + ' 月查無課程資料。';
    tbody.appendChild(empty);
    return;
  }

  const header = document.createElement('div');
  header.className = 'tpma-reg-nested-main-header tpma-reg-class-grid-layout';
  header.innerHTML = [
    '<div class="tpma-reg-nested-main-cell"><input type="checkbox" id="tpma-select-all-nested"></div>',
    '<div class="tpma-reg-nested-main-cell">序</div>',
    '<div class="tpma-reg-nested-main-cell">授課日期</div>',
    '<div class="tpma-reg-nested-main-cell">課程名稱</div>',
    '<div class="tpma-reg-nested-main-cell">授課講師</div>',
    '<div class="tpma-reg-nested-main-cell">人數統計</div>'
  ].join('');
  tbody.appendChild(header);

  pageGroups.forEach(function(group, classIdx){
    const firstRow = S.getGroupBaseRow(group);
    const classCard = document.createElement('section');
    classCard.className = 'tpma-reg-class-card';
    classCard.dataset.groupKey = group.key || '';

    const classSummary = document.createElement('div');
    classSummary.className = 'tpma-reg-class-summary tpma-reg-class-grid-layout';
    classSummary.setAttribute('role', 'button');
    classSummary.setAttribute('tabindex', '0');
    classSummary.setAttribute('aria-expanded', 'false');
    classSummary.classList.add('is-collapsed');

    const classSel = document.createElement('div');
    classSel.className = 'tpma-reg-class-cell';
    classSel.setAttribute('data-label', '選取');
    classSel.innerHTML = '<div class="tpma-cell-wrap"><input type="checkbox" class="tpma-class-select"></div>';
    classSummary.appendChild(classSel);

    const classSeq = document.createElement('div');
    classSeq.className = 'tpma-reg-class-cell tpma-seq-col';
    classSeq.setAttribute('data-label', '序');
    classSeq.textContent = classIdx + 1;
    classSummary.appendChild(classSeq);

    const classDate = document.createElement('div');
    classDate.className = 'tpma-reg-class-cell';
    classDate.setAttribute('data-label', '授課日期');
    classDate.innerHTML = '<div class="tpma-cell-wrap">' + R.buildClassDateRangeHtml(ctx, firstRow) + '</div>';
    classSummary.appendChild(classDate);

    const classCourse = document.createElement('div');
    classCourse.className = 'tpma-reg-class-cell';
    classCourse.setAttribute('data-label', '課程名稱');
    const accessModeSummary = R.buildAccessModeSummaryHtml(group.rows || []);
    classCourse.innerHTML = '<div class="tpma-cell-wrap tpma-reg-class-title-wrap"><div><strong class="tpma-reg-class-title">' + U.esc(R.getCourseSummaryText(firstRow)) + '</strong>' + (accessModeSummary ? '<div class="tpma-reg-flat-secondary">' + accessModeSummary + '</div>' : '') + '</div></div>';
    classSummary.appendChild(classCourse);

    const classLecturer = document.createElement('div');
    classLecturer.className = 'tpma-reg-class-cell';
    classLecturer.setAttribute('data-label', '授課講師');
    classLecturer.innerHTML = '<div class="tpma-cell-wrap">' + U.esc(R.getLecturerSummaryText(firstRow)) + '</div>';
    classSummary.appendChild(classLecturer);

    const classCount = document.createElement('div');
    classCount.className = 'tpma-reg-class-cell';
    classCount.setAttribute('data-label', '人數統計');
    const countedRows = S.getCountedRows ? S.getCountedRows(group.rows || []) : (group.rows || []);
    classCount.innerHTML = '<div class="tpma-cell-wrap tpma-reg-class-count-wrap"><span class="tpma-reg-class-count">' + U.esc(String(countedRows.length || 0)) + ' 人</span><span class="tpma-class-toggle-btn" aria-hidden="true">▶</span></div>';
    classSummary.appendChild(classCount);

    classCard.appendChild(classSummary);

    const classBody = document.createElement('div');
    classBody.className = 'tpma-reg-class-body';
    classCard.appendChild(classBody);

    if ((group.rows || []).length) {
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
    } else {
      const emptyStudents = document.createElement('div');
      emptyStudents.className = 'tpma-empty-row tpma-reg-class-empty';
      emptyStudents.textContent = '目前尚無報名學員';
      classBody.appendChild(emptyStudents);
    }

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
      classCard.classList.toggle('is-open', !isOpen);
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

  const nestedSelectAll = header.querySelector('#tpma-select-all-nested');
  if (nestedSelectAll) {
    nestedSelectAll.addEventListener('change', function(){
      document.querySelectorAll('.tpma-class-select, .tpma-reg-select').forEach(function(cb){
        cb.checked = nestedSelectAll.checked;
        cb.indeterminate = false;
      });
      ctx.actions.updateBatchButtonsEnabled();
      if (UI.updateAllClassSelectionStates) {
        UI.updateAllClassSelectionStates(ctx);
      }
    });
  }
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
