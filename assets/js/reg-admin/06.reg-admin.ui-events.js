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
};

UI.updatePaginationControls = function updatePaginationControls(ctx){
  const total = (ctx.data.currentRegs || []).length;
  const totalPages = Math.max(1, Math.ceil(total / ctx.state.pageSize));
  if (ctx.state.currentPage > totalPages) ctx.state.currentPage = totalPages;
  const start = total === 0 ? 0 : (ctx.state.currentPage - 1) * ctx.state.pageSize + 1;
  const end = total === 0 ? 0 : Math.min(ctx.state.currentPage * ctx.state.pageSize, total);

  if (ctx.dom.pageInfo) ctx.dom.pageInfo.textContent = '第 ' + ctx.state.currentPage + ' / ' + totalPages + ' 頁，顯示 ' + start + '–' + end + ' 筆，共 ' + total + ' 筆';
  if (ctx.dom.pagePrev) ctx.dom.pagePrev.disabled = (ctx.state.currentPage <= 1);
  if (ctx.dom.pageNext) ctx.dom.pageNext.disabled = (ctx.state.currentPage >= totalPages);
};

UI.updateFilterButtonStates = function updateFilterButtonStates(ctx){
  const btnCreated = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="created_at"]');
  const btnCourse  = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="course"]');
  const btnClass   = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="class_date"]');
  const btnRemit   = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="remit_paid_at"]');
  const btnStatus  = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="status"]');
  const btnReceipt = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="receipt_status"]');
  const btnPaymentWC = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="payment_status"]');

  const f = ctx.state.filter;
  const hasCreated = !!(f.created_from || f.created_to);
  const hasCourse  = !!f.course_id;
  const hasClass   = !!f.class_date;
  const hasRemit   = !!(f.remit_from || f.remit_to);
  const hasStatus  = !!f.status;
  const hasReceipt = !!(f.receipt_status || f.receipt_type);
  const hasPaymentWC = !!f.payment_status;
  const hasTestState = !!f.test_state;

  if (btnCreated) btnCreated.classList.toggle('tpma-filter-active', hasCreated);
  if (btnCourse) btnCourse.classList.toggle('tpma-filter-active', hasCourse);
  if (btnClass) btnClass.classList.toggle('tpma-filter-active', hasClass);
  if (btnRemit) btnRemit.classList.toggle('tpma-filter-active', hasRemit);
  if (btnStatus) btnStatus.classList.toggle('tpma-filter-active', hasStatus || hasReceipt || hasPaymentWC || hasTestState);
  if (btnReceipt) btnReceipt.classList.toggle('tpma-filter-active', hasReceipt);
  if (btnPaymentWC) btnPaymentWC.classList.toggle('tpma-filter-active', hasPaymentWC);
};

UI.applyFiltersAndRender = function applyFiltersAndRender(ctx){
  S.apply(ctx);
  R.renderTable(ctx);
  UI.buildHeaderFilterOptions(ctx);
  UI.updateFilterButtonStates(ctx);
};

UI.refreshFromServer = async function refreshFromServer(ctx){
  ctx.dom.tbody.innerHTML = '<tr><td colspan="10">載入中...</td></tr>';
  try{
    const list = await API.loadRegistrations(ctx);
    ctx.data.allRegs = list;
    ctx.data.allRegs = Array.isArray(ctx.data.allRegs) ? ctx.data.allRegs : [];
    ctx.state.currentPage = 1;
    UI.applyFiltersAndRender(ctx);
  }catch(e){
    console.error(e);
    ctx.dom.tbody.innerHTML = '<tr><td colspan="10">載入失敗</td></tr>';
  }
};

UI.applyBatch = async function applyBatch(ctx, field, value){
  if (!field) return;
  const checked = Array.from(document.querySelectorAll('.tpma-reg-select:checked'));
  if (!checked.length) return;
  if (value == null || value === '') return;

  if (!confirm('確定要批次修改 ' + checked.length + ' 筆資料？')) return;

  try{
    for (const cb of checked) {
      const tr = cb.closest('tr');
      if (!tr) continue;
      const id = parseInt(tr.dataset.id, 10) || 0;
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
  // header menu toggle
  let openMenuCol = null;
  function closeAllMenus(){
    document.querySelectorAll('.tpma-th-menu').forEach(m=> m.style.display='none');
    openMenuCol=null;
  }
  document.querySelectorAll('.tpma-th-menu-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      const col = this.getAttribute('data-menu-toggle');
      if (!col) return;
      const menu = document.querySelector('.tpma-th-menu[data-menu-col="'+col+'"]');
      if (!menu) return;
      if (openMenuCol === col) { menu.style.display='none'; openMenuCol=null; }
      else { closeAllMenus(); menu.style.display='block'; openMenuCol=col; }
    });
  });
  document.addEventListener('click', function(e){
    if (!e.target.closest('.tpma-th-menu') && !e.target.closest('.tpma-th-menu-btn')) closeAllMenus();
  });

  document.querySelectorAll('.tpma-th-menu [data-sort-field]').forEach(btn=>{
    btn.addEventListener('click', function(){
      ctx.state.sort.field = this.getAttribute('data-sort-field');
      ctx.state.sort.dir = this.getAttribute('data-sort-dir');
      UI.applyFiltersAndRender(ctx);
    });
  });

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
  const clearCourse = document.getElementById('tpma-btn-clear-course');
  if (clearCourse) clearCourse.addEventListener('click', function(){
    ctx.state.filter.course_id = '';
    if ($course) $course.value='';
    UI.applyFiltersAndRender(ctx);
  });

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
  const clearCreated = document.getElementById('tpma-btn-clear-created');
  if (clearCreated) clearCreated.addEventListener('click', function(){
    ctx.state.filter.created_from='';
    ctx.state.filter.created_to='';
    if ($createdSingle) $createdSingle.value='';
    if ($createdFrom) $createdFrom.value='';
    if ($createdTo) $createdTo.value='';
    if ($createdRangeCheck) $createdRangeCheck.checked=false;
    if ($createdSingleWrap) $createdSingleWrap.style.display='';
    if ($createdRangeWrap) $createdRangeWrap.style.display='none';
    UI.applyFiltersAndRender(ctx);
  });

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
  const clearRemit = document.getElementById('tpma-btn-clear-remit');
  if (clearRemit) clearRemit.addEventListener('click', function(){
    ctx.state.filter.remit_from='';
    ctx.state.filter.remit_to='';
    if ($remitSingle) $remitSingle.value='';
    if ($remitFrom) $remitFrom.value='';
    if ($remitTo) $remitTo.value='';
    if ($remitRangeCheck) $remitRangeCheck.checked=false;
    if ($remitSingleWrap) $remitSingleWrap.style.display='';
    if ($remitRangeWrap) $remitRangeWrap.style.display='none';
    UI.applyFiltersAndRender(ctx);
  });

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
  const clearClass = document.getElementById('tpma-btn-clear-class-date');
  if (clearClass) clearClass.addEventListener('click', function(){
    ctx.state.filter.class_date='';
    ctx.state.filter.class_date_from='';
    ctx.state.filter.class_date_to='';
    if ($classSingle) $classSingle.value='';
    if ($classFrom) $classFrom.value='';
    if ($classTo) $classTo.value='';
    if ($classRangeCheck) $classRangeCheck.checked=false;
    if ($classSingleWrap) $classSingleWrap.style.display='';
    if ($classRangeWrap) $classRangeWrap.style.display='none';
    UI.applyFiltersAndRender(ctx);
  });

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
    UI.updateBatchButtonsEnabled(ctx);
  });

  // pagination
  if (ctx.dom.pagePrev) ctx.dom.pagePrev.addEventListener('click', function(){
    if (ctx.state.currentPage > 1) { ctx.state.currentPage--; R.renderTable(ctx); }
  });
  if (ctx.dom.pageNext) ctx.dom.pageNext.addEventListener('click', function(){
    const total = (ctx.data.currentRegs || []).length;
    const totalPages = Math.max(1, Math.ceil(total / ctx.state.pageSize));
    if (ctx.state.currentPage < totalPages) { ctx.state.currentPage++; R.renderTable(ctx); }
  });

  // batch buttons
  document.querySelectorAll('.tpma-batch-btn').forEach(btn=>{
    btn.addEventListener('click', async function(){
      if (!UI.anyRowSelected()) return;
      const field = this.getAttribute('data-batch-field');
      let value = '';
      if (field === 'status') value = (document.getElementById('tpma-batch-status')||{}).value || '';
      else if (field === 'receipt_status') value = (document.getElementById('tpma-batch-receipt-status')||{}).value || '';
      else if (field === 'receipt_type') value = (document.getElementById('tpma-batch-receipt-type')||{}).value || '';
      else if (field === 'remit_paid_at') value = (document.getElementById('tpma-batch-remit-date')||{}).value || '';
      if (!value) { alert('請先選擇要套用的值'); return; }
      await UI.applyBatch(ctx, field, value);
    });
  });
};

})(window);
