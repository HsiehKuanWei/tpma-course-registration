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

  if (ctx.dom.selectAllHead) {
    const allStudentCheckboxes = Array.from(document.querySelectorAll('.tpma-reg-select'));
    const checkedCount = allStudentCheckboxes.filter(cb => cb.checked).length;
    ctx.dom.selectAllHead.checked = !!allStudentCheckboxes.length && checkedCount === allStudentCheckboxes.length;
    ctx.dom.selectAllHead.indeterminate = checkedCount > 0 && checkedCount < allStudentCheckboxes.length;
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
};

UI.updatePaginationControls = function updatePaginationControls(ctx){
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
