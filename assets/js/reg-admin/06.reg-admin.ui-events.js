//專門負責「所有事件綁定 + UI 控制」

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const UI = global.TPMARegAdmin.ui = global.TPMARegAdmin.ui || {};
const U = global.TPMARegAdmin.utils;
const S = global.TPMARegAdmin.state;
const R = global.TPMARegAdmin.render;
const API = global.TPMARegAdmin.api;

UI.buildHeaderFilterOptions = function buildHeaderFilterOptions(ctx){
  const courseSelect = document.getElementById('tpma-filter-course');
  if (courseSelect) {
    const map = {};
    (ctx.data.allRegs || []).forEach(r=>{
      if (!r.course_id) return;
      const key = String(r.course_id);
      if (!map[key]) map[key] = r.course_name || ('課程ID ' + key);
    });
    courseSelect.innerHTML = '<option value="">全部課程</option>';
    Object.keys(map).sort((a,b)=> (map[a]||'').localeCompare(map[b]||'', 'zh-Hant')).forEach(id=>{
      const opt = document.createElement('option');
      opt.value = id;
      opt.textContent = map[id];
      if (ctx.state.filter.course_id && String(ctx.state.filter.course_id) === id) opt.selected = true;
      courseSelect.appendChild(opt);
    });
  }

  const classDateList = document.getElementById('tpma-class-date-list');
  if (classDateList) {
    const dateSet = new Set();
    (ctx.data.allRegs || []).forEach(r=>{
      if (!r.class_date) return;
      if (ctx.state.filter.course_id && String(r.course_id) !== String(ctx.state.filter.course_id)) return;
      dateSet.add(String(r.class_date).substring(0,10));
    });
    classDateList.innerHTML = '';
    Array.from(dateSet).sort().forEach(d=>{
      const opt = document.createElement('option');
      opt.value = d;
      classDateList.appendChild(opt);
    });
  }

  const createdList = document.getElementById('tpma-created-date-list');
  if (createdList) {
    const set = new Set();
    (ctx.data.allRegs || []).forEach(r=>{
      if (!r.created_at) return;
      set.add(String(r.created_at).substring(0,10));
    });
    createdList.innerHTML = '';
    Array.from(set).sort().forEach(d=>{
      const opt=document.createElement('option'); opt.value=d; createdList.appendChild(opt);
    });
  }

  const remitList = document.getElementById('tpma-remit-date-list');
  if (remitList) {
    const set = new Set();
    (ctx.data.allRegs || []).forEach(r=>{
      if (!r.remit_paid_at) return;
      set.add(String(r.remit_paid_at).substring(0,10));
    });
    remitList.innerHTML='';
    Array.from(set).sort().forEach(d=>{
      const opt=document.createElement('option'); opt.value=d; remitList.appendChild(opt);
    });
  }

  UI.updateFilterButtonStates(ctx);
};

UI.anyRowSelected = function anyRowSelected(){
  return !!document.querySelector('.tpma-reg-select:checked');
};

UI.getSelectedRegistrationIds = function getSelectedRegistrationIds(){
  return Array.from(document.querySelectorAll('.tpma-reg-select:checked')).map(cb=>{
    const card = cb.closest('.tpma-reg-card');
    return card ? (parseInt(card.dataset.id || '0', 10) || 0) : 0;
  }).filter(Boolean);
};

UI.updateBatchButtonsEnabled = function updateBatchButtonsEnabled(ctx){
  const hasSel = UI.anyRowSelected();
  document.querySelectorAll('.tpma-batch-btn').forEach(btn=>{
    btn.disabled = !hasSel;
    btn.title = hasSel ? '' : '請先勾選資料';
  });
  document.querySelectorAll('.tpma-batch-select, .tpma-batch-input').forEach(el=>{
    el.disabled = !hasSel;
    el.title = hasSel ? '' : '請先勾選資料';
  });
  UI.updateBulkToolbar(ctx);
};

UI.getSelectedRows = function getSelectedRows(ctx){
  const selected = new Set(UI.getSelectedRegistrationIds().map(String));
  return (ctx.data.allRegs || []).filter(row=> selected.has(String(row.id)));
};

UI.getBulkTargetElement = function getBulkTargetElement(action){
  const map = {
    update_field: 'tpma-bulk-target-update-field',
    send_mail: 'tpma-bulk-mail-event',
    reset_course_mail_meta: 'tpma-bulk-reset-event',
    export_excel: 'tpma-bulk-export-type'
  };
  const id = map[action] || '';
  return id ? document.getElementById(id) : null;
};

UI.getBulkValueElement = function getBulkValueElement(target){
  const map = {
    status: 'tpma-bulk-value-status',
    access_mode: 'tpma-bulk-value-access-mode',
    session_id: 'tpma-bulk-value-session-id',
    receipt_status: 'tpma-bulk-value-receipt-status',
    receipt_type: 'tpma-bulk-value-receipt-type',
    remit_paid_at: 'tpma-bulk-value-remit-paid-at'
  };
  const id = map[target] || '';
  return id ? document.getElementById(id) : null;
};

UI.getBulkSessionContext = function getBulkSessionContext(ctx){
  const rows = UI.getSelectedRows(ctx);
  const courseIds = Array.from(new Set(rows.map(row => parseInt(row.course_id || '0', 10)).filter(Boolean)));
  if (!rows.length) return { valid: false, message: '請先選擇學員。', course: null };
  if (courseIds.length !== 1) return { valid: false, message: '批次更改場次只能選取同一課程的學員。', course: null };

  const course = (ctx.data.allCourses || []).find(item => String(item.id) === String(courseIds[0]));
  if (!course) return { valid: false, message: '找不到所選學員的課程資料，請重新載入後再試。', course: null };
  return { valid: true, message: '', course: course };
};

UI.populateBulkSessionOptions = function populateBulkSessionOptions(ctx){
  const select = UI.getBulkValueElement('session_id');
  const context = UI.getBulkSessionContext(ctx);
  if (!select) return context;

  const previous = select.value;
  select.innerHTML = '';
  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = context.valid ? '選擇目標課程場次' : context.message;
  select.appendChild(placeholder);

  if (!context.valid) return context;

  const duration = parseInt(context.course.duration_minutes || '0', 10) || 0;
  (context.course.sessions || []).forEach(session => {
    if (!session || !session.id || !session.session_datetime || String(session.is_active) === '0') return;
    const option = document.createElement('option');
    option.value = String(session.id);
    option.textContent = U.formatSessionDisplay(session.session_datetime, duration) || String(session.session_datetime);
    if (String(session.id) === String(previous)) option.selected = true;
    select.appendChild(option);
  });

  if (select.options.length === 1) {
    context.valid = false;
    context.message = '此課程沒有可移動至的啟用場次。';
    placeholder.textContent = context.message;
  }
  return context;
};

UI.updateBulkToolbar = function updateBulkToolbar(ctx){
  if (!ctx || !ctx.dom || !ctx.dom.bulkToolbar) return;
  const ids = UI.getSelectedRegistrationIds();
  const action = ctx.dom.bulkAction ? (ctx.dom.bulkAction.value || '') : '';
  const targetEl = UI.getBulkTargetElement(action);
  const target = targetEl ? (targetEl.value || '') : '';
  const hasSel = ids.length > 0;
  const requiresSelection = !!action && action !== 'export_excel';
  const sessionContext = action === 'update_field' && target === 'session_id'
    ? UI.populateBulkSessionOptions(ctx)
    : null;
  if (ctx.dom.bulkCount) ctx.dom.bulkCount.textContent = '已選取 ' + ids.length + ' 筆';

  document.querySelectorAll('.tpma-bulk-target').forEach(el=>{
    const show = action && el.getAttribute('data-bulk-for') === action;
    el.style.display = show ? '' : 'none';
    el.disabled = !show || (requiresSelection && !hasSel);
  });
  document.querySelectorAll('.tpma-bulk-value').forEach(el=>{
    const show = action === 'update_field' && target && el.getAttribute('data-bulk-target') === target;
    el.style.display = show ? '' : 'none';
    el.disabled = !show || (requiresSelection && !hasSel);
  });

  if (ctx.dom.bulkAction) ctx.dom.bulkAction.disabled = false;
  if (ctx.dom.bulkClear) ctx.dom.bulkClear.disabled = !hasSel;

  let hint = hasSel ? '請選擇操作、目標項目與必要欄位值。' : '可直接匯出目前篩選結果；其他批次操作請先勾選學員。';
  if (action === 'send_mail') {
    const rows = UI.getSelectedRows(ctx);
    const orderIds = new Set(rows.map(r=> String(r.woocommerce_order_id || '')).filter(Boolean));
    hint = '寄信會由伺服器再次檢查資格；收據通知會按 ' + orderIds.size + ' 筆訂單去重。';
  } else if (action === 'reset_course_mail_meta') {
    hint = '只會清除課程開放類寄件紀錄，不會清除證書或收據紀錄。';
  } else if (action === 'update_field' && target === 'status') {
    hint = '課後付款會同步影響同一 Woo 訂單狀態。';
  } else if (action === 'update_field' && target === 'session_id') {
    hint = sessionContext && sessionContext.valid
      ? '僅可移動至同一課程的啟用場次；系統會重建課程入口與 Meet 連結。'
      : ((sessionContext && sessionContext.message) || '請先選擇同一課程的學員。');
  } else if (action === 'export_excel') {
    const rows = UI.getSelectedRows(ctx);
    const count = rows.length || (ctx.data.currentRegs || []).length;
    hint = rows.length ? ('將匯出已選取的 ' + rows.length + ' 筆資料。') : ('將匯出目前篩選結果 ' + count + ' 筆資料。');
  }
  if (ctx.dom.bulkHint) ctx.dom.bulkHint.textContent = hint;

  const valueEl = UI.getBulkValueElement(target);
  const targetRequired = action === 'update_field' || action === 'send_mail';
  const targetReady = !targetRequired || !!target;
  const needsValue = action === 'update_field';
  const hasValue = !needsValue || (valueEl && valueEl.value !== '');
  const sessionReady = target !== 'session_id' || (sessionContext && sessionContext.valid);
  if (ctx.dom.bulkApply) ctx.dom.bulkApply.disabled = !action || !targetReady || !hasValue || !sessionReady || (requiresSelection && !hasSel);
};

UI.summarizeBulkResult = function summarizeBulkResult(data){
  const parts = [
    '處理 ' + (data.processed || 0) + ' 筆',
    '更新 ' + (data.updated || 0) + ' 筆',
    '寄出 ' + (data.sent || 0) + ' 筆',
    '排除 ' + ((data.skipped || []).length) + ' 筆',
    '失敗 ' + ((data.failed || []).length) + ' 筆'
  ];
  const lines = [parts.join('，')];
  const details = []
    .concat((data.skipped || []).slice(0, 5).map(item=> '排除 #' + (item.id || '-') + '：' + (item.message || item.reason || 'skipped')))
    .concat((data.failed || []).slice(0, 5).map(item=> '失敗 #' + (item.id || '-') + '：' + (item.message || item.reason || 'failed')));
  if (details.length) lines.push(details.join('\n'));
  return lines.join('\n');
};

UI.bulkReasonLabel = function bulkReasonLabel(reason, fallback){
  const map = {
    already_sent: '先前已寄送，未重複處理',
    certificate_missing: '缺少證書資料',
    course_access_unavailable: '課程權限模組尚未載入',
    dispatcher_unavailable: '寄件處理模組尚未載入',
    event_triggered_but_no_route_matched: '事件已觸發，但沒有符合條件的路由',
    exception: '處理時發生例外錯誤',
    invalid_access_mode: '此場次不支援所選課程型態',
    invalid_course_event: '不支援的課程通知事件',
    invalid_field: '不支援的批次欄位',
    invalid_registration: '報名資料無效',
    invalid_status: '不支援的訂單狀態',
    learner_route_ignored: '收據為訂單層級事件，已略過學員收件來源',
    mailer_unavailable: 'TPMA Mailer 尚未載入',
    no_order: '找不到對應的 Woo 訂單',
    no_recipients_or_send_failed: '沒有有效收件人，或寄送失敗',
    no_route: '沒有命中啟用中的寄件路由',
    not_live_access: '不是直播課程型態',
    not_recorded_access: '不是錄播課程型態',
    order_closed: '訂單已取消、退款或失敗',
    order_locked: '訂單狀態不允許修改金額',
    order_not_completed: '訂單尚未完成',
    order_not_found: '找不到對應的 Woo 訂單',
    outside_access_window: '不在課程開放寄送時間內',
    payment_required: '付款狀態不符合寄送條件',
    postpay_status_not_allowed: '課後付款訂單狀態不符合寄送條件',
    registration_cancelled: '報名已取消',
    registration_not_found: '找不到報名資料',
    route_invalid: '寄件路由設定不合法',
    routes_matched_but_no_mail_sent: '路由已命中，但沒有成功寄出',
    session_finished: '場次已授課完畢'
  };
  if (map[reason]) return map[reason];
  const text = fallback || reason || '未提供原因';
  return /[\u4e00-\u9fff]/.test(text) ? text : ('未翻譯原因：' + text);
};

UI.bulkItemMessage = function bulkItemMessage(item){
  const reason = item && item.reason ? String(item.reason) : '';
  const fallback = item && item.message ? String(item.message) : '';
  return UI.bulkReasonLabel(reason, fallback);
};

UI.closeBulkResultModal = function closeBulkResultModal(){
  const overlay = document.getElementById('tpma-bulk-result-modal');
  if (overlay) overlay.classList.remove('open');
  document.body.classList.remove('tpma-reg-modal-open');
  if (overlay && UI.bulkResultModalPlaceholder && UI.bulkResultModalPlaceholder.parentNode) {
    UI.bulkResultModalPlaceholder.parentNode.insertBefore(overlay, UI.bulkResultModalPlaceholder);
    UI.bulkResultModalPlaceholder.remove();
    UI.bulkResultModalPlaceholder = null;
  }
};

UI.mountBulkResultModal = function mountBulkResultModal(overlay){
  if (!overlay || overlay.parentNode === document.body) return;
  const placeholder = document.createComment('tpma bulk result modal placeholder');
  overlay.parentNode.insertBefore(placeholder, overlay);
  document.body.appendChild(overlay);
  UI.bulkResultModalPlaceholder = placeholder;
};

UI.openBulkResultModal = function openBulkResultModal(data, title){
  const overlay = document.getElementById('tpma-bulk-result-modal');
  const body = document.getElementById('tpma-bulk-result-modal-body');
  const titleEl = document.getElementById('tpma-bulk-result-modal-title');
  if (!overlay || !body) return;
  if (titleEl) titleEl.textContent = title || '批次操作結果';
  body.innerHTML = '';

  const summary = document.createElement('div');
  summary.className = 'tpma-bulk-result-summary';
  [
    ['processed', '處理', data.processed || 0],
    ['updated', '更新', data.updated || 0],
    ['sent', '寄出', data.sent || 0],
    ['skipped', '排除', (data.skipped || []).length],
    ['failed', '失敗', (data.failed || []).length]
  ].forEach(item=>{
    const box = document.createElement('div');
    box.className = 'tpma-bulk-result-stat tpma-bulk-result-stat-' + item[0];
    const num = document.createElement('strong');
    num.textContent = String(item[2]);
    const label = document.createElement('span');
    label.textContent = item[1];
    box.appendChild(num);
    box.appendChild(label);
    summary.appendChild(box);
  });
  body.appendChild(summary);

  const appendList = function(label, rows){
    const section = document.createElement('div');
    section.className = 'tpma-bulk-result-section';
    const h = document.createElement('h4');
    h.textContent = label + '（' + rows.length + '）';
    section.appendChild(h);
    if (!rows.length) {
      const empty = document.createElement('div');
      empty.className = 'tpma-bulk-result-empty';
      empty.textContent = '無';
      section.appendChild(empty);
    } else {
      const list = document.createElement('ul');
      list.className = 'tpma-bulk-result-list';
      rows.forEach(row=>{
        const li = document.createElement('li');
        const id = document.createElement('span');
        id.className = 'tpma-bulk-result-id';
        id.textContent = '#' + (row.id || '-');
        const msg = document.createElement('span');
        msg.textContent = UI.bulkItemMessage(row);
        li.appendChild(id);
        li.appendChild(msg);
        list.appendChild(li);
      });
      section.appendChild(list);
    }
    body.appendChild(section);
  };

  appendList('排除項目', Array.isArray(data.skipped) ? data.skipped : []);
  appendList('失敗項目', Array.isArray(data.failed) ? data.failed : []);
  UI.mountBulkResultModal(overlay);
  document.body.classList.add('tpma-reg-modal-open');
  overlay.classList.add('open');
  overlay.scrollTop = 0;
  requestAnimationFrame(function(){
    const dialog = overlay.querySelector('.tpma-bulk-result-dialog');
    if (dialog && typeof dialog.scrollIntoView === 'function') {
      dialog.scrollIntoView({ block: 'center', inline: 'center' });
    }
  });
};

UI.applyBulk = async function applyBulk(ctx){
  const ids = UI.getSelectedRegistrationIds();
  const action = ctx.dom.bulkAction ? (ctx.dom.bulkAction.value || '') : '';
  if (!action) return;
  const targetEl = UI.getBulkTargetElement(action);
  const target = targetEl ? (targetEl.value || '') : '';
  if (action !== 'export_excel' && !ids.length) return;
  const valueEl = UI.getBulkValueElement(target);
  const value = valueEl ? (valueEl.value || '') : '';

  let payload = { ids };
  if (action === 'export_excel') {
    const exportModule = global.TPMARegAdmin && global.TPMARegAdmin.exportModule;
    if (!exportModule || typeof exportModule.openModal !== 'function') {
      alert('匯出模組尚未載入');
      return;
    }
    exportModule.openModal(ctx, target || 'students');
    return;
  } else if (action === 'update_field') {
    if (!target) { alert('請先選擇要更新的欄位'); return; }
    if (!value) { alert('請先選擇或輸入套用值'); return; }
    if (target === 'session_id') {
      const sessionContext = UI.getBulkSessionContext(ctx);
      if (!sessionContext.valid) { alert(sessionContext.message || '只能選取同一課程的學員。'); return; }
      const targetOption = valueEl && valueEl.selectedOptions ? valueEl.selectedOptions[0] : null;
      payload.action = 'move_session';
      payload.session_id = parseInt(value, 10) || 0;
      if (!payload.session_id) { alert('請選擇目標課程場次'); return; }
      if (!confirm('確定將 ' + ids.length + ' 位學員移至「' + (targetOption ? targetOption.textContent : '目標場次') + '」？')) return;
    } else {
      payload.action = 'update_field';
      payload.field = target;
      payload.value = value;
    }
    if (target === 'status' && value === 'postpay' && !confirm('課後付款會同步套用所選資料所屬 Woo 訂單狀態。確定繼續？')) return;
    if (target !== 'session_id' && !confirm('確定批次更新 ' + ids.length + ' 筆資料？')) return;
  } else if (action === 'send_mail') {
    if (!target) { alert('請先選擇信件種類'); return; }
    const rows = UI.getSelectedRows(ctx);
    const orderIds = new Set(rows.map(r=> String(r.woocommerce_order_id || '')).filter(Boolean));
    payload.action = 'send_course_mail';
    payload.event_key = target;
    payload.force = false;
    const scope = target === 'receipt_notice' ? ('將按 ' + orderIds.size + ' 筆 Woo 訂單去重寄送') : ('將檢查 ' + ids.length + ' 位學員');
    if (!confirm(scope + '，伺服器會自動排除不符合資格或無有效路由者。確定寄送？')) return;
  } else if (action === 'reset_course_mail_meta') {
    payload.action = 'reset_course_mail_meta';
    payload.event_key = target;
    if (!confirm('確定重置所選資料對應訂單 / 場次的課程寄件紀錄？')) return;
  } else {
    return;
  }

  if (ctx.dom.bulkApply) ctx.dom.bulkApply.disabled = true;
  if (ctx.dom.bulkResult) ctx.dom.bulkResult.textContent = '處理中...';
  try{
    const data = await API.bulkRegistrations(ctx, payload);
    if (ctx.dom.bulkResult) ctx.dom.bulkResult.textContent = '';
    UI.openBulkResultModal(data, '批次操作結果');
    await UI.refreshFromServer(ctx);
  }catch(e){
    console.error(e);
    if (ctx.dom.bulkResult) ctx.dom.bulkResult.textContent = '';
    UI.openBulkResultModal({
      processed: 0,
      updated: 0,
      sent: 0,
      skipped: [],
      failed: [{ id: '-', reason: 'exception', message: e.message || '批次操作失敗' }]
    }, '批次操作失敗');
  }finally{
    UI.updateBulkToolbar(ctx);
  }
};

UI.updateClassSelectionState = function updateClassSelectionState(classCard){
  if (!classCard) return;
  const classCheckbox = classCard.querySelector('.tpma-class-select');
  const studentCheckboxes = Array.from(classCard.querySelectorAll('.tpma-reg-select'));
  if (!classCheckbox || !studentCheckboxes.length) {
    if (classCheckbox) {
      classCheckbox.checked = false;
      classCheckbox.indeterminate = false;
    }
    return;
  }

  const checkedCount = studentCheckboxes.filter(cb => cb.checked).length;
  classCheckbox.checked = checkedCount === studentCheckboxes.length;
  classCheckbox.indeterminate = checkedCount > 0 && checkedCount < studentCheckboxes.length;
};

UI.updateAllClassSelectionStates = function updateAllClassSelectionStates(ctx){
  document.querySelectorAll('.tpma-reg-class-card').forEach(function(classCard){
    UI.updateClassSelectionState(classCard);
  });

  const allStudentCheckboxes = Array.from(document.querySelectorAll('.tpma-reg-select'));
  const checkedCount = allStudentCheckboxes.filter(cb => cb.checked).length;

  if (ctx.dom.selectAllHead) {
    ctx.dom.selectAllHead.checked = !!allStudentCheckboxes.length && checkedCount === allStudentCheckboxes.length;
    ctx.dom.selectAllHead.indeterminate = checkedCount > 0 && checkedCount < allStudentCheckboxes.length;
  }

  const nestedSelectAll = document.getElementById('tpma-select-all-nested');
  if (nestedSelectAll) {
    nestedSelectAll.checked = !!allStudentCheckboxes.length && checkedCount === allStudentCheckboxes.length;
    nestedSelectAll.indeterminate = checkedCount > 0 && checkedCount < allStudentCheckboxes.length;
  }
};

UI.updateViewModeButtons = function updateViewModeButtons(ctx){
  const isNested = ctx.state.viewMode === 'nested';
  if (ctx.dom.viewModeNested) {
    ctx.dom.viewModeNested.classList.toggle('is-active', isNested);
    ctx.dom.viewModeNested.setAttribute('aria-pressed', isNested ? 'true' : 'false');
  }
  if (ctx.dom.viewModeFlat) {
    ctx.dom.viewModeFlat.classList.toggle('is-active', !isNested);
    ctx.dom.viewModeFlat.setAttribute('aria-pressed', !isNested ? 'true' : 'false');
  }
  if (ctx.dom.grid) {
    ctx.dom.grid.classList.toggle('tpma-reg-grid-nested-mode', isNested);
    ctx.dom.grid.classList.toggle('tpma-reg-grid-flat-mode', !isNested);
  }
  if (ctx.dom.pagination) {
    ctx.dom.pagination.style.display = isNested ? 'none' : '';
  }
};

UI.updatePaginationControls = function updatePaginationControls(ctx){
  if (ctx.state.viewMode === 'nested') {
    if (ctx.dom.pageInfo) ctx.dom.pageInfo.textContent = '';
    if (ctx.dom.pagePrev) ctx.dom.pagePrev.disabled = true;
    if (ctx.dom.pageNext) ctx.dom.pageNext.disabled = true;
    return;
  }
  const meta = S.getPaginationMeta(ctx);
  if (ctx.dom.pageInfo) {
    ctx.dom.pageInfo.textContent = '第 ' + meta.currentPage + ' / ' + meta.totalPages + ' 頁，顯示 ' + meta.start + '–' + meta.end + ' 筆，共 ' + meta.totalRows + ' 筆';
  }
  if (ctx.dom.pagePrev) ctx.dom.pagePrev.disabled = (meta.currentPage <= 1);
  if (ctx.dom.pageNext) ctx.dom.pageNext.disabled = (meta.currentPage >= meta.totalPages);
};

UI.updateFilterButtonStates = function updateFilterButtonStates(ctx){
  const f = ctx.state.filter;
  const hasCreated = !!(f.created_from || f.created_to);
  const hasCourse  = !!f.course_id;
  const hasClass   = !!(f.class_date || f.class_date_from || f.class_date_to);
  const hasRemit   = !!(f.remit_from || f.remit_to);
  const hasStatus  = !!f.status;
  const hasReceipt = !!(f.receipt_status || f.receipt_type);
  const hasPaymentWC = !!f.payment_status;
  const hasTestState = !!f.test_state;

  // Update menu button active states based on filters or sort
  document.querySelectorAll('.tpma-th-menu-btn').forEach(btn => {
    const menuTarget = btn.getAttribute('data-menu-target');
    let isActive = false;
    if (menuTarget === 'menu-created_at') isActive = hasCreated;
    else if (menuTarget === 'menu-course') isActive = hasCourse || (ctx.state.sort.field === 'course_name');
    else if (menuTarget === 'menu-class_date') isActive = hasClass || (ctx.state.sort.field === 'class_date');
    else if (menuTarget === 'menu-remit_paid_at') isActive = hasRemit || (ctx.state.sort.field === 'remit_paid_at');
    else if (menuTarget === 'menu-student_name') isActive = (ctx.state.sort.field === 'student_name');
    else if (menuTarget === 'menu-company_name') isActive = (ctx.state.sort.field === 'company_name');
    else if (menuTarget === 'menu-status') isActive = hasStatus || hasReceipt || hasPaymentWC || hasTestState || (ctx.state.sort.field === 'status'); // Assuming 'status' sort field if needed

    btn.classList.toggle('tpma-filter-active', isActive);
  });
};

UI.applyFiltersAndRender = function applyFiltersAndRender(ctx){
  S.apply(ctx);
  R.renderTable(ctx);
  UI.buildHeaderFilterOptions(ctx);
  UI.updateFilterButtonStates(ctx);
  UI.updateViewModeButtons(ctx);
};

UI.refreshFromServer = async function refreshFromServer(ctx){
  ctx.state.isLoading = true;
  R.renderTable(ctx);
  try{
    const list = await API.loadRegistrations(ctx);
    ctx.data.allRegs = Array.isArray(list) ? list : [];
    ctx.state.currentPage = 1;
  }catch(e){
    console.error(e);
    ctx.data.allRegs = [];
    ctx.data.currentRegs = [];
    ctx.state.currentPage = 1;
    ctx.state.isLoading = false;
    ctx.dom.tbody.innerHTML = '<div class="tpma-empty-row">載入失敗</div>';
    ctx.actions.updateBatchButtonsEnabled();
    ctx.actions.updatePaginationControls();
    return;
  }finally{
    ctx.state.isLoading = false;
  }

  UI.applyFiltersAndRender(ctx);
};

UI.applyBatch = async function applyBatch(ctx, field, value){
  if (!field) return;
  const checked = Array.from(document.querySelectorAll('.tpma-reg-select:checked'));
  if (!checked.length) return;
  if (value == null || value === '') return;

  if (!confirm('確定要批次修改 ' + checked.length + ' 筆資料？')) return;

  try{
    for (const cb of checked) {
      const card = cb.closest('.tpma-reg-card');
      if (!card) continue;
      const id = card.dataset.id;
      if (!id) continue;

      const payload = { id };
      payload[field] = value;

      try{
        await API.updateRegistration(ctx, payload);
      }catch(err){
        console.error('批次更新失敗 id=' + id, err);
      }
    }
    await UI.refreshFromServer(ctx);
  }catch(e){
    console.error(e);
    alert('批次變更發生錯誤：' + e.message);
  }
};

UI.bind = function bind(ctx){
  // header menu toggle, sort, and clear filters
  document.addEventListener('click', e => {
    const btn = e.target.closest ? e.target.closest('.tpma-th-menu-btn, [data-sort], [data-clear]') : e.target;
    // 開啟/關閉欄位篩選選單
    const menuTarget = btn && btn.getAttribute ? btn.getAttribute('data-menu-target') : null;
if (menuTarget) {
  e.preventDefault();
  e.stopImmediatePropagation(); // ← 原本是 e.stopPropagation()

  const menu = document.getElementById(menuTarget);
  if (!menu) return;

  document.querySelectorAll('.tpma-th-menu.open').forEach(m => {
    if (m !== menu) m.classList.remove('open');
  });

  menu.classList.toggle('open');
  return;
}

    // 排序
    const sortKey = btn && btn.getAttribute ? btn.getAttribute('data-sort') : null;
    if (sortKey) {
      const [field, dir] = sortKey.split('-');
      ctx.state.sort.field = field || '';
      ctx.state.sort.dir = dir || 'asc';
      UI.applyFiltersAndRender(ctx);
      return;
    }

    // 清除篩選
    const clearKey = btn && btn.getAttribute ? btn.getAttribute('data-clear') : null;
    if (clearKey) {
      if (clearKey === 'created_at') {
        ctx.state.filter.created_from = '';
        ctx.state.filter.created_to = '';
        const $createdSingle = document.getElementById('tpma-filter-created-single');
        const $createdFrom = document.getElementById('tpma-filter-created-from');
        const $createdTo = document.getElementById('tpma-filter-created-to');
        const $createdRangeCheck = document.getElementById('tpma-filter-created-range');
        const $createdSingleWrap = document.getElementById('tpma-created-single');
        const $createdRangeWrap = document.getElementById('tpma-created-range');
        if ($createdSingle) $createdSingle.value = '';
        if ($createdFrom) $createdFrom.value = '';
        if ($createdTo) $createdTo.value = '';
        if ($createdRangeCheck) $createdRangeCheck.checked = false;
        if ($createdSingleWrap) $createdSingleWrap.style.display = '';
        if ($createdRangeWrap) $createdRangeWrap.style.display = 'none';
      } else if (clearKey === 'course') {
        ctx.state.filter.course_id = '';
        const $course = document.getElementById('tpma-filter-course');
        if ($course) $course.value = '';
      } else if (clearKey === 'class_date') {
        ctx.state.filter.class_date = '';
        ctx.state.filter.class_date_from = '';
        ctx.state.filter.class_date_to = '';
        const $classSingle = document.getElementById('tpma-filter-class-single');
        const $classFrom = document.getElementById('tpma-filter-class-from');
        const $classTo = document.getElementById('tpma-filter-class-to');
        const $classRangeCheck = document.getElementById('tpma-filter-class-range');
        const $classSingleWrap = document.getElementById('tpma-class-single');
        const $classRangeWrap = document.getElementById('tpma-class-range');
        if ($classSingle) $classSingle.value = '';
        if ($classFrom) $classFrom.value = '';
        if ($classTo) $classTo.value = '';
        if ($classRangeCheck) $classRangeCheck.checked = false;
        if ($classSingleWrap) $classSingleWrap.style.display = '';
        if ($classRangeWrap) $classRangeWrap.style.display = 'none';
      } else if (clearKey === 'remit_paid_at') {
        ctx.state.filter.remit_from = '';
        ctx.state.filter.remit_to = '';
        const $remitSingle = document.getElementById('tpma-filter-remit-single');
        const $remitFrom = document.getElementById('tpma-filter-remit-from');
        const $remitTo = document.getElementById('tpma-filter-remit-to');
        const $remitRangeCheck = document.getElementById('tpma-filter-remit-range');
        const $remitSingleWrap = document.getElementById('tpma-remit-single');
        const $remitRangeWrap = document.getElementById('tpma-remit-range');
        if ($remitSingle) $remitSingle.value = '';
        if ($remitFrom) $remitFrom.value = '';
        if ($remitTo) $remitTo.value = '';
        if ($remitRangeCheck) $remitRangeCheck.checked = false;
        if ($remitSingleWrap) $remitSingleWrap.style.display = '';
        if ($remitRangeWrap) $remitRangeWrap.style.display = 'none';
      } else if (clearKey === 'student_name') {
        // No specific filter input for student_name, only sort
        ctx.state.sort.field = '';
        ctx.state.sort.dir = 'asc';
      } else if (clearKey === 'company_name') {
        // No specific filter input for company_name, only sort
        ctx.state.sort.field = '';
        ctx.state.sort.dir = 'asc';
      } else if (clearKey === 'status') {
        ctx.state.filter.status = '';
        ctx.state.filter.receipt_status = '';
        ctx.state.filter.receipt_type = '';
        ctx.state.filter.payment_status = '';
        ctx.state.filter.test_state = '';
        const $status = document.getElementById('tpma-filter-status');
        const $receiptStat = document.getElementById('tpma-filter-receipt-status');
        const $receiptType = document.getElementById('tpma-filter-receipt-type');
        const $paymentStatusEl = document.getElementById('tpma-filter-payment-status');
        const $testFilter = document.getElementById('tpma-filter-test');
        if ($status) $status.value = '';
        if ($receiptStat) $receiptStat.value = '';
        if ($receiptType) $receiptType.value = '';
        if ($paymentStatusEl) $paymentStatusEl.value = '';
        if ($testFilter) $testFilter.value = '';
      }
      UI.applyFiltersAndRender(ctx);
      return;
    }
  });

  // 點擊表格外關閉所有篩選選單
  document.addEventListener('click', e => {
    const isMenuBtn = !!(e.target.closest && e.target.closest('.tpma-th-menu-btn'));
    document.querySelectorAll('.tpma-th-menu.open').forEach(menu => {
      if (!menu.contains(e.target) && !isMenuBtn) {
        menu.classList.remove('open');
      }
    });
  });

  function isFilterButton(el) {
    return Array.from(ctx.dom.menuButtons).some(btn => btn.contains(el));
  }

  // keyword
  const $q = document.getElementById('tpma-filter-q');
  const applyQ = document.getElementById('tpma-btn-apply-q');
  if (applyQ && $q) applyQ.addEventListener('click', function(){
    ctx.state.filter.q = ($q.value || '').trim();
    UI.applyFiltersAndRender(ctx);
  });

  // course
  const $course = document.getElementById('tpma-filter-course');
  if ($course) $course.addEventListener('change', function(){
    ctx.state.filter.course_id = $course.value || '';
    UI.applyFiltersAndRender(ctx);
  });
  // Clear button for course filter is now handled by data-clear="course"

  // created single/range
  const $createdSingle = document.getElementById('tpma-filter-created-single');
  const $createdFrom = document.getElementById('tpma-filter-created-from');
  const $createdTo = document.getElementById('tpma-filter-created-to');
  const $createdRangeCheck = document.getElementById('tpma-filter-created-range');
  const $createdSingleWrap = document.getElementById('tpma-created-single');
  const $createdRangeWrap = document.getElementById('tpma-created-range');

  function updateCreatedFilter(){
    const range = $createdRangeCheck && $createdRangeCheck.checked;
    if (range) {
      ctx.state.filter.created_from = $createdFrom ? ($createdFrom.value || '') : '';
      ctx.state.filter.created_to = $createdTo ? ($createdTo.value || '') : '';
    } else {
      const v = $createdSingle ? ($createdSingle.value || '') : '';
      ctx.state.filter.created_from = v;
      ctx.state.filter.created_to = v;
    }
    UI.applyFiltersAndRender(ctx);
  }
  if ($createdSingle) $createdSingle.addEventListener('change', updateCreatedFilter);
  if ($createdFrom) $createdFrom.addEventListener('change', updateCreatedFilter);
  if ($createdTo) $createdTo.addEventListener('change', updateCreatedFilter);
  if ($createdRangeCheck) $createdRangeCheck.addEventListener('change', function(){
    const range = this.checked;
    if ($createdSingleWrap) $createdSingleWrap.style.display = range ? 'none' : '';
    if ($createdRangeWrap) $createdRangeWrap.style.display = range ? '' : 'none';
    updateCreatedFilter();
  });
  // Clear button for created filter is now handled by data-clear="created_at"

  // remit single/range
  const $remitSingle = document.getElementById('tpma-filter-remit-single');
  const $remitFrom = document.getElementById('tpma-filter-remit-from');
  const $remitTo = document.getElementById('tpma-filter-remit-to');
  const $remitRangeCheck = document.getElementById('tpma-filter-remit-range');
  const $remitSingleWrap = document.getElementById('tpma-remit-single');
  const $remitRangeWrap = document.getElementById('tpma-remit-range');

  function updateRemitFilter(){
    const range = $remitRangeCheck && $remitRangeCheck.checked;
    if (range) {
      ctx.state.filter.remit_from = $remitFrom ? ($remitFrom.value || '') : '';
      ctx.state.filter.remit_to = $remitTo ? ($remitTo.value || '') : '';
    } else {
      const v = $remitSingle ? ($remitSingle.value || '') : '';
      ctx.state.filter.remit_from = v;
      ctx.state.filter.remit_to = v;
    }
    UI.applyFiltersAndRender(ctx);
  }
  if ($remitSingle) $remitSingle.addEventListener('change', updateRemitFilter);
  if ($remitFrom) $remitFrom.addEventListener('change', updateRemitFilter);
  if ($remitTo) $remitTo.addEventListener('change', updateRemitFilter);
  if ($remitRangeCheck) $remitRangeCheck.addEventListener('change', function(){
    const range = this.checked;
    if ($remitSingleWrap) $remitSingleWrap.style.display = range ? 'none' : '';
    if ($remitRangeWrap) $remitRangeWrap.style.display = range ? '' : 'none';
    updateRemitFilter();
  });
  // Clear button for remit filter is now handled by data-clear="remit_paid_at"

  // class date single/range
  const $classSingle = document.getElementById('tpma-filter-class-single');
  const $classFrom = document.getElementById('tpma-filter-class-from');
  const $classTo = document.getElementById('tpma-filter-class-to');
  const $classRangeCheck = document.getElementById('tpma-filter-class-range');
  const $classSingleWrap = document.getElementById('tpma-class-single');
  const $classRangeWrap = document.getElementById('tpma-class-range');

  function updateClassDateFilter(){
    const range = $classRangeCheck && $classRangeCheck.checked;
    if (range) {
      ctx.state.filter.class_date = '';
      ctx.state.filter.class_date_from = $classFrom ? ($classFrom.value||'') : '';
      ctx.state.filter.class_date_to = $classTo ? ($classTo.value||'') : '';
    } else {
      const v = $classSingle ? ($classSingle.value||'') : '';
      ctx.state.filter.class_date = v;
      ctx.state.filter.class_date_from = '';
      ctx.state.filter.class_date_to = '';
    }
    UI.applyFiltersAndRender(ctx);
  }
  if ($classSingle) $classSingle.addEventListener('change', updateClassDateFilter);
  if ($classFrom) $classFrom.addEventListener('change', updateClassDateFilter);
  if ($classTo) $classTo.addEventListener('change', updateClassDateFilter);
  if ($classRangeCheck) $classRangeCheck.addEventListener('change', function(){
    const range = this.checked;
    if ($classSingleWrap) $classSingleWrap.style.display = range ? 'none' : '';
    if ($classRangeWrap) $classRangeWrap.style.display = range ? '' : 'none';
    updateClassDateFilter();
  });
  // Clear button for class date filter is now handled by data-clear="class_date"

  // status/receipt/payment/test filters
  const $status = document.getElementById('tpma-filter-status');
  const $receiptStat = document.getElementById('tpma-filter-receipt-status');
  const $receiptType = document.getElementById('tpma-filter-receipt-type');
  const $paymentStatusEl = document.getElementById('tpma-filter-payment-status');
  const $testFilter = document.getElementById('tpma-filter-test');

  if ($status) $status.addEventListener('change', ()=>{ ctx.state.filter.status = $status.value||''; UI.applyFiltersAndRender(ctx); });
  if ($receiptStat) $receiptStat.addEventListener('change', ()=>{ ctx.state.filter.receipt_status = $receiptStat.value||''; UI.applyFiltersAndRender(ctx); });
  if ($receiptType) $receiptType.addEventListener('change', ()=>{ ctx.state.filter.receipt_type = $receiptType.value||''; UI.applyFiltersAndRender(ctx); });
  if ($paymentStatusEl) $paymentStatusEl.addEventListener('change', ()=>{ ctx.state.filter.payment_status = $paymentStatusEl.value||''; UI.applyFiltersAndRender(ctx); });
  if ($testFilter) $testFilter.addEventListener('change', ()=>{ ctx.state.filter.test_state = $testFilter.value||''; UI.applyFiltersAndRender(ctx); });

  // clear all
  const clearAll = document.getElementById('tpma-btn-clear-all');
  if (clearAll) clearAll.addEventListener('click', function(){
    const f = ctx.state.filter;
    Object.keys(f).forEach(k=> f[k]='');
    if ($q) $q.value='';
    if ($status) $status.value='';
    if ($receiptStat) $receiptStat.value='';
    if ($receiptType) $receiptType.value='';
    if ($paymentStatusEl) $paymentStatusEl.value='';
    if ($testFilter) $testFilter.value='';
    if ($course) $course.value='';
    if ($classSingle) $classSingle.value='';
    if ($classFrom) $classFrom.value='';
    if ($classTo) $classTo.value='';
    if ($classRangeCheck) $classRangeCheck.checked=false;
    if ($createdSingle) $createdSingle.value='';
    if ($createdFrom) $createdFrom.value='';
    if ($createdTo) $createdTo.value='';
    if ($createdRangeCheck) $createdRangeCheck.checked=false;
    if ($remitSingle) $remitSingle.value='';
    if ($remitFrom) $remitFrom.value='';
    if ($remitTo) $remitTo.value='';
    if ($remitRangeCheck) $remitRangeCheck.checked=false;

    if ($createdSingleWrap) $createdSingleWrap.style.display='';
    if ($createdRangeWrap) $createdRangeWrap.style.display='none';
    if ($remitSingleWrap) $remitSingleWrap.style.display='';
    if ($remitRangeWrap) $remitRangeWrap.style.display='none';
    if ($classSingleWrap) $classSingleWrap.style.display='';
    if ($classRangeWrap) $classRangeWrap.style.display='none';

    ctx.state.sort.field = 'created_at';
    ctx.state.sort.dir = 'desc';
    UI.applyFiltersAndRender(ctx);
  });

  // select all
  if (ctx.dom.selectAllHead) ctx.dom.selectAllHead.addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('.tpma-reg-select').forEach(cb=> cb.checked=checked);
    document.querySelectorAll('.tpma-class-select').forEach(cb=>{
      cb.checked = checked;
      cb.indeterminate = false;
    });
    UI.updateBatchButtonsEnabled(ctx);
  });

  // pagination
  if (ctx.dom.pagePrev) ctx.dom.pagePrev.addEventListener('click', function(){
    if (ctx.state.currentPage > 1) { ctx.state.currentPage--; R.renderTable(ctx); }
  });
  if (ctx.dom.pageNext) ctx.dom.pageNext.addEventListener('click', function(){
    const totalPages = S.getTotalPages(ctx);
    if (ctx.state.currentPage < totalPages) { ctx.state.currentPage++; R.renderTable(ctx); }
  });

  if (ctx.dom.viewModeNested) {
    ctx.dom.viewModeNested.addEventListener('click', function(){
      if (ctx.actions.setViewMode) ctx.actions.setViewMode('nested');
    });
  }
  if (ctx.dom.viewModeFlat) {
    ctx.dom.viewModeFlat.addEventListener('click', function(){
      if (ctx.actions.setViewMode) ctx.actions.setViewMode('flat');
    });
  }

  if (ctx.dom.bulkAction) ctx.dom.bulkAction.addEventListener('change', function(){
    if (ctx.dom.bulkResult) ctx.dom.bulkResult.textContent = '';
    document.querySelectorAll('.tpma-bulk-target, .tpma-bulk-value').forEach(el=>{
      if (el.tagName === 'SELECT') el.selectedIndex = 0;
      else el.value = '';
    });
    UI.updateBulkToolbar(ctx);
  });
  document.querySelectorAll('.tpma-bulk-target').forEach(el=>{
    el.addEventListener('change', function(){
      if (ctx.dom.bulkResult) ctx.dom.bulkResult.textContent = '';
      document.querySelectorAll('.tpma-bulk-value').forEach(valueEl=>{
        if (valueEl.tagName === 'SELECT') valueEl.selectedIndex = 0;
        else valueEl.value = '';
      });
      UI.updateBulkToolbar(ctx);
    });
  });
  document.querySelectorAll('.tpma-bulk-value').forEach(el=>{
    el.addEventListener('change', function(){
      if (ctx.dom.bulkResult) ctx.dom.bulkResult.textContent = '';
      UI.updateBulkToolbar(ctx);
    });
  });
  if (ctx.dom.bulkClear) ctx.dom.bulkClear.addEventListener('click', function(){
    document.querySelectorAll('.tpma-reg-select, .tpma-class-select').forEach(cb=>{
      cb.checked = false;
      cb.indeterminate = false;
    });
    UI.updateAllClassSelectionStates(ctx);
    UI.updateBulkToolbar(ctx);
  });
  if (ctx.dom.bulkApply) ctx.dom.bulkApply.addEventListener('click', function(){
    UI.applyBulk(ctx);
  });
  const bulkResultModal = document.getElementById('tpma-bulk-result-modal');
  const bulkResultClose = document.getElementById('tpma-bulk-result-modal-close');
  const bulkResultOk = document.getElementById('tpma-bulk-result-modal-ok');
  if (bulkResultClose) bulkResultClose.addEventListener('click', UI.closeBulkResultModal);
  if (bulkResultOk) bulkResultOk.addEventListener('click', UI.closeBulkResultModal);
  if (bulkResultModal) bulkResultModal.addEventListener('click', function(e){
    if (e.target === bulkResultModal) UI.closeBulkResultModal();
  });
  UI.updateBulkToolbar(ctx);
};

})(window);
