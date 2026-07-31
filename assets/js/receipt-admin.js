/* global TPMAReceiptAdminConfig */
(function () {
  'use strict';

  const cfg = window.TPMAReceiptAdminConfig || {};
  const state = {
    page: 1,
    perPage: 20,
    sortBy: 'receipt_number',
    sortOrder: 'asc',
    selected: new Map(),
    pagination: null,
    debounce: null,
    bulkInFlight: false,
    loadRequestId: 0,
    printFallbackUrl: null,
    scanTrigger: null,
    scanReceipt: null
  };
  const el = (id) => document.getElementById(id);
  const list = el('tpma-receipt-list');
  if (!list || !cfg.apiBase) return;
  const scanDialog = el('tpma-receipt-scan-dialog');
  const scanForm = el('tpma-receipt-scan-form');
  const scanFile = el('tpma-receipt-scan-file');
  const scanError = el('tpma-receipt-scan-error');
  const scanCancel = el('tpma-receipt-scan-cancel');
  const scanSubmit = el('tpma-receipt-scan-submit');

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[character]));
  }

  function api(path, options) {
    const request = Object.assign({ credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } }, options || {});
    request.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce }, request.headers || {});
    return fetch(cfg.apiBase + path, request).then(async (response) => {
      if (!response.ok) {
        let body = {};
        try { body = await response.json(); } catch (ignore) { /* use generic error */ }
        throw new Error(body.message || '收據操作失敗。');
      }
      return response;
    });
  }

  function showMessage(message, type) {
    releasePrintFallbackUrl();
    const box = el('tpma-receipt-message');
    if (!box) return;
    box.hidden = !message;
    box.className = 'notice inline ' + (type === 'error' ? 'notice-error' : 'notice-success');
    box.querySelector('p').textContent = message || '';
  }

  function showPrintLink(url, message) {
    releasePrintFallbackUrl();
    const box = el('tpma-receipt-message');
    if (!box) return;
    box.hidden = false;
    box.className = 'notice inline notice-success';
    const paragraph = box.querySelector('p');
    paragraph.textContent = message + ' ';
    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = '開啟列印檔';
    paragraph.appendChild(link);
    state.printFallbackUrl = url;
  }

  function releasePrintFallbackUrl() {
    if (!state.printFallbackUrl) return;
    URL.revokeObjectURL(state.printFallbackUrl);
    state.printFallbackUrl = null;
  }

  function setBulkSubmitting(submitting) {
    state.bulkInFlight = submitting;
    const bulkRun = el('tpma-receipt-bulk-run');
    const bulkAction = el('tpma-receipt-bulk-action');
    if (bulkRun) bulkRun.disabled = submitting;
    if (bulkAction) bulkAction.disabled = submitting;
  }

  function clearSelection() {
    state.selected.clear();
    list.querySelectorAll('input[data-row-select]').forEach((checkbox) => { checkbox.checked = false; });
    updateSelectionCount();
  }

  function updateSortControls() {
    const direction = state.sortOrder === 'asc' ? '升冪' : '降冪';
    document.querySelectorAll('.tpma-receipt-sort-button').forEach((button) => {
      const header = button.closest('th');
      const active = button.dataset.sortBy === state.sortBy;
      const indicator = button.querySelector('.tpma-receipt-sort-indicator');
      if (header) header.setAttribute('aria-sort', active ? (state.sortOrder === 'asc' ? 'ascending' : 'descending') : 'none');
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.dataset.sortOrder = active ? state.sortOrder : 'asc';
      const sortLabel = `${button.dataset.sortBy === 'receipt_number' ? '收據號／關聯訂單' : button.textContent.trim().replace(/升冪|降冪|未排序/g, '').trim()}，${active ? `目前${direction}` : '未排序'}；點擊${active ? '切換排序方向' : '改為升冪排序'}`;
      button.setAttribute('aria-label', sortLabel);
      button.title = active ? `目前${direction}；點擊切換為${state.sortOrder === 'asc' ? '降冪' : '升冪'}` : '點擊改為升冪排序';
      if (indicator) indicator.textContent = active ? direction : '未排序';
    });
    const mobileSortBy = el('tpma-receipt-mobile-sort-by');
    const mobileSortOrder = el('tpma-receipt-mobile-sort-order');
    if (mobileSortBy) mobileSortBy.value = state.sortBy;
    if (mobileSortOrder) {
      mobileSortOrder.textContent = state.sortOrder === 'asc' ? '升冪' : '降冪';
      mobileSortOrder.setAttribute('aria-label', `目前${direction}排序；點擊切換為${state.sortOrder === 'asc' ? '降冪' : '升冪'}排序`);
    }
    syncSortControlAvailability();
  }

  function syncSortControlAvailability() {
    const mobile = window.matchMedia && window.matchMedia('(max-width: 782px)').matches;
    document.querySelectorAll('.tpma-receipt-sort-button').forEach((button) => { button.tabIndex = mobile ? -1 : 0; });
  }

  function changeSort(sortBy, toggleCurrent) {
    state.sortOrder = toggleCurrent || state.sortBy === sortBy
      ? (state.sortOrder === 'asc' ? 'desc' : 'asc')
      : 'asc';
    state.sortBy = sortBy;
    state.page = 1;
    clearSelection();
    updateSortControls();
    load();
  }

  function updateSelectionCount() {
    const count = state.selected.size;
    el('tpma-receipt-selection-count').textContent = count ? `已選取 ${count} 筆` : '尚未選取';
    const all = el('tpma-receipt-select-all');
    if (all) all.checked = !!list.querySelectorAll('input[data-row-select]:checked').length && !list.querySelector('input[data-row-select]:not(:checked)');
  }

  function orderLines(orders) {
    return `<ul class="tpma-receipt-lines tpma-receipt-orders">${(orders || []).map((order) =>
      `<li><a href="${escapeHtml(order.edit_url)}">#${escapeHtml(order.number)}</a></li>`).join('')}</ul>`;
  }

  function courseLines(courses) {
    return `<ul class="tpma-receipt-lines">${(courses || []).map((course) =>
      `<li>${escapeHtml(course.name || '—')}<br><small>${escapeHtml(course.date || '—')}</small></li>`).join('')}</ul>`;
  }

  function heading(item) {
    const identity = item.heading || {};
    if (identity.kind === 'company') {
      return `<div class="tpma-receipt-heading">${escapeHtml(identity.company_name)}<br><small>統編 ${escapeHtml(identity.tax_id)}</small></div>`;
    }
    return `<div class="tpma-receipt-heading">承辦：${escapeHtml(identity.contact_name || '—')}</div>`;
  }

  function actionIcon(name) {
    const paths = {
      upload: '<path d="M12 21V10m0 0 4 4m-4-4-4 4M5 4h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
    };
    return `<svg class="tpma-receipt-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths[name]}</svg>`;
  }

  function receiptNumber(item) {
    if (item.kind !== 'receipt') return '<span class="tpma-receipt-pending">尚未開立</span>';
    const serial = escapeHtml(item.serial);
    const voidClass = item.status === 'void' ? ' is-void' : '';
    return item.preview_url
      ? `<a class="tpma-receipt-serial-link${voidClass}" href="${escapeHtml(item.preview_url)}" target="_blank" rel="noopener noreferrer" aria-label="預覽收據 ${serial}">${serial}</a>`
      : `<span class="tpma-receipt-serial${voidClass}">${serial}</span>`;
  }

  function receiptStatus(item) {
    const statusClasses = {
      pending: 'is-pending', generated: 'is-pending', awaiting_scan: 'is-awaiting-scan',
      scanned: 'is-ready', sent: 'is-sent', void: 'is-void'
    };
    const canChangeType = item.kind === 'receipt' && !['sent', 'void'].includes(item.status);
    const targetType = item.receipt_type === 'paper' ? 'electronic' : 'paper';
    const typeBadge = canChangeType
      ? `<button type="button" class="tpma-receipt-badge is-type tpma-receipt-type-switch" data-receipt-id="${escapeHtml(item.receipt_id)}" data-receipt-serial="${escapeHtml(item.serial)}" data-target-type="${targetType}" title="變更為${targetType === 'paper' ? '紙本' : '電子'}收據">${escapeHtml(item.display_type)}</button>`
      : `<span class="tpma-receipt-badge is-type">${escapeHtml(item.display_type)}</span>`;
    const badges = `${typeBadge}<span class="tpma-receipt-badge ${statusClasses[item.status] || ''}">${escapeHtml(item.display_status)}</span>`;
    if (item.receipt_type === 'paper' && item.status === 'awaiting_scan') {
      return `<div class="tpma-receipt-status-badges">${badges}<button type="button" class="tpma-receipt-status-upload tpma-receipt-upload-scan" data-receipt-id="${escapeHtml(item.receipt_id)}" data-receipt-serial="${escapeHtml(item.serial)}" aria-label="上傳收據 ${escapeHtml(item.serial)} 的掃描檔">${actionIcon('upload')}<span class="tpma-receipt-action-label" aria-hidden="true">上傳掃描檔</span></button></div>`;
    }
    return `<div class="tpma-receipt-status-badges">${badges}</div>`;
  }

  function changeReceiptType(button) {
    const receiptId = Number(button.dataset.receiptId);
    const serial = button.dataset.receiptSerial || '';
    const targetType = button.dataset.targetType;
    const targetLabel = targetType === 'paper' ? '紙本' : '電子';
    if (!receiptId || !['electronic', 'paper'].includes(targetType)) return;
    if (!window.confirm(`確定將收據 ${serial} 改為${targetLabel}嗎？會保留收據號並自動重新生成，且不會自動寄送。`)) return;
    button.disabled = true;
    api(`/admin/receipts/${encodeURIComponent(receiptId)}/type`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ receipt_type: targetType })
    }).then((response) => response.json()).then(() => {
      showMessage(`收據 ${serial} 已改為${targetLabel}並重新生成。`, 'success');
      clearSelection();
      load();
    }).catch((error) => {
      button.disabled = false;
      showMessage(error.message, 'error');
    });
  }

  function renderRows(items) {
    if (!items.length) {
      list.innerHTML = '<tr><td colspan="6">沒有符合條件的收據或待開立訂單。</td></tr>';
      updateSelectionCount();
      return;
    }
    list.innerHTML = items.map((item) => {
      const key = `${item.kind}:${item.kind === 'pending' ? item.order_id : item.receipt_id}`;
      const selected = state.selected.has(key) ? ' checked' : '';
      const number = receiptNumber(item);
      return `<tr data-kind="${escapeHtml(item.kind)}" data-id="${escapeHtml(item.kind === 'pending' ? item.order_id : item.receipt_id)}">
        <th scope="row" class="check-column" data-label="選取"><input type="checkbox" data-row-select data-key="${escapeHtml(key)}"${selected} aria-label="選取 ${escapeHtml(item.serial)}"></th>
        <td data-label="收據號／關聯訂單">${number}${orderLines(item.orders)}</td>
        <td data-label="課程／授課日期">${courseLines(item.courses)}</td>
        <td data-label="收據抬頭／統編">${heading(item)}</td>
        <td class="amount" data-label="金額">${escapeHtml(item.amount_formatted)} 元</td>
        <td class="tpma-receipt-status" data-label="方式／狀態">${receiptStatus(item)}</td>
      </tr>`;
    }).join('');
    list.querySelectorAll('input[data-row-select]').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        const row = checkbox.closest('tr');
        const key = checkbox.dataset.key;
        if (checkbox.checked) state.selected.set(key, { kind: row.dataset.kind, id: Number(row.dataset.id) });
        else state.selected.delete(key);
        updateSelectionCount();
      });
    });
    list.querySelectorAll('.tpma-receipt-upload-scan').forEach((button) => button.addEventListener('click', () => openScanDialog(button)));
    list.querySelectorAll('.tpma-receipt-type-switch').forEach((button) => button.addEventListener('click', () => changeReceiptType(button)));
    updateSelectionCount();
  }

  function renderPagination(pagination) {
    state.pagination = pagination;
    const root = el('tpma-receipt-pagination');
    if (!root) return;
    root.innerHTML = `<span class="displaying-num">共 ${pagination.total} 筆</span>
      <button type="button" class="button" data-page="${pagination.page - 1}"${pagination.page <= 1 ? ' disabled' : ''}>‹ 上一頁</button>
      <span>第 ${pagination.page} / ${pagination.total_pages} 頁</span>
      <button type="button" class="button" data-page="${pagination.page + 1}"${pagination.page >= pagination.total_pages ? ' disabled' : ''}>下一頁 ›</button>`;
    root.querySelectorAll('button[data-page]').forEach((button) => button.addEventListener('click', () => {
      state.page = Number(button.dataset.page);
      load();
    }));
  }

  function filters() {
    return {
      q: el('tpma-receipt-search').value.trim(),
      receipt_type: el('tpma-receipt-type-filter').value,
      status: el('tpma-receipt-status-filter').value,
      number: el('tpma-receipt-filter-number-value').value.trim(),
      course: el('tpma-receipt-filter-course-value').value.trim(),
      course_date_from: el('tpma-receipt-filter-course-date-from').value,
      course_date_to: el('tpma-receipt-filter-course-date-to').value,
      heading: el('tpma-receipt-filter-heading-value').value.trim(),
      amount_min: el('tpma-receipt-filter-amount-min').value,
      amount_max: el('tpma-receipt-filter-amount-max').value
    };
  }

  function syncHeaderFilterControls() {
    const type = el('tpma-receipt-type-filter').value;
    const status = el('tpma-receipt-status-filter').value;
    el('tpma-receipt-header-type-filter').value = type;
    el('tpma-receipt-header-status-filter').value = status;
    const values = filters();
    const activeByMenu = {
      'tpma-receipt-filter-number': !!values.number,
      'tpma-receipt-filter-course': !!(values.course || values.course_date_from || values.course_date_to),
      'tpma-receipt-filter-heading': !!values.heading,
      'tpma-receipt-filter-amount': !!(values.amount_min || values.amount_max),
      'tpma-receipt-filter-status': !!(values.receipt_type || values.status)
    };
    document.querySelectorAll('.tpma-receipt-filter-toggle').forEach((button) => {
      button.classList.toggle('is-active', !!activeByMenu[button.dataset.filterMenu]);
    });
  }

  function syncMobileFilterControls() {
    const mappings = {
      'tpma-receipt-filter-number-value': 'tpma-receipt-mobile-filter-number',
      'tpma-receipt-filter-course-value': 'tpma-receipt-mobile-filter-course',
      'tpma-receipt-filter-course-date-from': 'tpma-receipt-mobile-filter-course-date-from',
      'tpma-receipt-filter-course-date-to': 'tpma-receipt-mobile-filter-course-date-to',
      'tpma-receipt-filter-heading-value': 'tpma-receipt-mobile-filter-heading',
      'tpma-receipt-filter-amount-min': 'tpma-receipt-mobile-filter-amount-min',
      'tpma-receipt-filter-amount-max': 'tpma-receipt-mobile-filter-amount-max',
      'tpma-receipt-type-filter': 'tpma-receipt-mobile-filter-type',
      'tpma-receipt-status-filter': 'tpma-receipt-mobile-filter-status'
    };
    Object.keys(mappings).forEach((source) => { el(mappings[source]).value = el(source).value; });
  }

  function applyMobileFilters() {
    const mappings = {
      'tpma-receipt-mobile-filter-number': 'tpma-receipt-filter-number-value',
      'tpma-receipt-mobile-filter-course': 'tpma-receipt-filter-course-value',
      'tpma-receipt-mobile-filter-course-date-from': 'tpma-receipt-filter-course-date-from',
      'tpma-receipt-mobile-filter-course-date-to': 'tpma-receipt-filter-course-date-to',
      'tpma-receipt-mobile-filter-heading': 'tpma-receipt-filter-heading-value',
      'tpma-receipt-mobile-filter-amount-min': 'tpma-receipt-filter-amount-min',
      'tpma-receipt-mobile-filter-amount-max': 'tpma-receipt-filter-amount-max',
      'tpma-receipt-mobile-filter-type': 'tpma-receipt-type-filter',
      'tpma-receipt-mobile-filter-status': 'tpma-receipt-status-filter'
    };
    Object.keys(mappings).forEach((source) => { el(mappings[source]).value = el(source).value; });
    applyHeaderFilters();
  }

  function closeFilterMenus(except) {
    document.querySelectorAll('.tpma-receipt-filter-menu').forEach((menu) => {
      if (menu === except) return;
      menu.hidden = true;
    });
    document.querySelectorAll('.tpma-receipt-filter-toggle').forEach((button) => {
      button.setAttribute('aria-expanded', String(!!except && button.dataset.filterMenu === except.id));
    });
  }

  function applyHeaderFilters() {
    const min = el('tpma-receipt-filter-amount-min').value;
    const max = el('tpma-receipt-filter-amount-max').value;
    if (min !== '' && max !== '' && Number(min) > Number(max)) {
      showMessage('最低金額不可高於最高金額。', 'error');
      return;
    }
    clearSelection();
    state.page = 1;
    syncHeaderFilterControls();
    syncMobileFilterControls();
    closeFilterMenus();
    load();
  }

  function clearHeaderFilter(group) {
    const groups = {
      number: ['tpma-receipt-filter-number-value'],
      course: ['tpma-receipt-filter-course-value', 'tpma-receipt-filter-course-date-from', 'tpma-receipt-filter-course-date-to'],
      heading: ['tpma-receipt-filter-heading-value'],
      amount: ['tpma-receipt-filter-amount-min', 'tpma-receipt-filter-amount-max'],
      status: ['tpma-receipt-header-type-filter', 'tpma-receipt-header-status-filter']
    };
    (groups[group] || []).forEach((id) => { el(id).value = ''; });
    if (group === 'status') {
      el('tpma-receipt-type-filter').value = '';
      el('tpma-receipt-status-filter').value = '';
    }
    applyHeaderFilters();
  }

  function load() {
    const requestId = ++state.loadRequestId;
    const params = new URLSearchParams(Object.assign(filters(), {
      page: state.page,
      per_page: state.perPage,
      sort_by: state.sortBy,
      sort_order: state.sortOrder
    }));
    list.innerHTML = '<tr><td colspan="6">載入中…</td></tr>';
    api('/admin/receipts/list?' + params.toString()).then((response) => response.json()).then((data) => {
      if (requestId !== state.loadRequestId) return;
      renderRows(data.items || []);
      renderPagination(data.pagination || { page: 1, total_pages: 1, total: 0 });
    }).catch((error) => {
      if (requestId !== state.loadRequestId) return;
      list.innerHTML = '<tr><td colspan="6">無法載入收據資料。</td></tr>';
      showMessage(error.message, 'error');
    });
  }

  function selectedFor(kind) {
    return Array.from(state.selected.values()).filter((item) => item.kind === kind).map((item) => item.id);
  }

  function resultMessage(action, data, skippedCount) {
    const key = { generate: 'generated', regenerate: 'regenerated', send: 'sent', change_type_electronic: 'changed', change_type_paper: 'changed', void: 'voided' }[action];
    const count = key && Array.isArray(data[key]) ? data[key].length : (data.receipt ? 1 : 0);
    const failed = Array.isArray(data.failed) ? data.failed.length : 0;
    const skipped = skippedCount + (Array.isArray(data.skipped) ? data.skipped.length : 0);
    return `已完成 ${count} 筆${failed ? `；失敗 ${failed} 筆` : ''}${skipped ? `；略過 ${skipped} 筆不適用資料` : ''}。`;
  }

  function postBulk(action, payload) {
    return api('/admin/receipts/bulk', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action }, payload)) });
  }

  function batchPrint(receiptIds, skipped, printWindow) {
    return postBulk('print', { receipt_ids: receiptIds }).then((response) => ({
      skipped: Number(response.headers.get('X-TPMA-Receipt-Skipped') || 0),
      blob: response.blob()
    })).then(async (result) => {
      const blob = await result.blob;
      const url = URL.createObjectURL(blob);
      const totalSkipped = skipped + result.skipped;
      const message = `已產生列印檔${totalSkipped ? `；略過 ${totalSkipped} 筆不適用或無可預覽檔案的資料` : ''}。`;
      if (printWindow) {
        printWindow.location.href = url;
        window.setTimeout(() => URL.revokeObjectURL(url), 60000);
        showMessage(message, 'success');
      } else {
        showPrintLink(url, message + ' 瀏覽器封鎖了彈出視窗，請改由此連結開啟：');
      }
    }).catch((error) => {
      if (printWindow && !printWindow.closed) printWindow.close();
      showMessage(error.message, 'error');
    });
  }

  function batchDownload(receiptIds, skipped) {
    return postBulk('download', { receipt_ids: receiptIds }).then((response) => ({
      skipped: Number(response.headers.get('X-TPMA-Receipt-Skipped') || 0),
      blob: response.blob()
    })).then(async (result) => {
      const blob = await result.blob;
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'tpma-receipts.pdf';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 60000);
      const totalSkipped = skipped + result.skipped;
      showMessage(`已開始下載收據檔${totalSkipped ? `；略過 ${totalSkipped} 筆不適用或無檔案的資料` : ''}。`, 'success');
    }).catch((error) => showMessage(error.message, 'error'));
  }

  function runBulk() {
    if (state.bulkInFlight) return;
    const action = el('tpma-receipt-bulk-action').value;
    if (!action) { showMessage('請先選擇批次操作。', 'error'); return; }
    if (!state.selected.size) { showMessage(cfg.strings.selectRows, 'error'); return; }
    const type = action === 'generate' ? 'pending' : 'receipt';
    const ids = selectedFor(type);
    const skipped = action === 'merge' ? 0 : state.selected.size - ids.length;
    const mergeOrderIds = action === 'merge' ? selectedFor('pending') : [];
    const mergeReceiptIds = action === 'merge' ? selectedFor('receipt') : [];
    if (action !== 'merge' && !ids.length) { showMessage(type === 'pending' ? cfg.strings.pendingOnly : cfg.strings.receiptOnly, 'error'); return; }
    if (action === 'merge' && mergeOrderIds.length + mergeReceiptIds.length < 2) { showMessage('合併開立至少需選擇兩筆不同的訂單或收據。', 'error'); return; }
    if (action === 'merge' && !window.confirm(cfg.strings.mergeConfirm)) return;
    if (action === 'change_type_electronic' && !window.confirm('確定將所選未寄收據改為電子嗎？會保留收據號並自動重新生成。')) return;
    if (action === 'change_type_paper' && !window.confirm('確定將所選未寄收據改為紙本嗎？會保留收據號並自動重新生成，之後需上傳掃描檔。')) return;
    if (action === 'void' && !window.confirm('確定作廢所選收據嗎？作廢後可重新開立。')) return;
    setBulkSubmitting(true);
    if (action === 'print') {
      const printWindow = window.open('', '_blank');
      if (printWindow) printWindow.document.title = 'TPMA 收據列印檔';
      batchPrint(ids, skipped, printWindow).finally(() => setBulkSubmitting(false));
      return;
    }
    if (action === 'download') {
      batchDownload(ids, skipped).finally(() => setBulkSubmitting(false));
      return;
    }
    const payload = action === 'merge'
      ? { order_ids: mergeOrderIds, receipt_ids: mergeReceiptIds }
      : (type === 'pending' ? { order_ids: ids } : { receipt_ids: ids });
    const requestAction = action === 'change_type_electronic' || action === 'change_type_paper' ? 'change_type' : action;
    if (requestAction === 'change_type') payload.receipt_type = action === 'change_type_paper' ? 'paper' : 'electronic';
    postBulk(requestAction, payload).then((response) => response.json()).then((data) => {
      showMessage(resultMessage(action, data, skipped), data.success === false ? 'error' : 'success');
      clearSelection();
      load();
    }).catch((error) => showMessage(error.message, 'error')).finally(() => setBulkSubmitting(false));
  }

  el('tpma-receipt-search').addEventListener('input', () => {
    clearSelection();
    window.clearTimeout(state.debounce);
    state.debounce = window.setTimeout(() => { state.page = 1; load(); }, 250);
  });
  ['tpma-receipt-type-filter', 'tpma-receipt-status-filter'].forEach((id) => el(id).addEventListener('change', () => { clearSelection(); state.page = 1; syncHeaderFilterControls(); load(); }));
  ['tpma-receipt-header-type-filter', 'tpma-receipt-header-status-filter'].forEach((id) => el(id).addEventListener('change', (event) => {
    const target = event.target.id === 'tpma-receipt-header-type-filter' ? 'tpma-receipt-type-filter' : 'tpma-receipt-status-filter';
    el(target).value = event.target.value;
  }));
  el('tpma-receipt-reset').addEventListener('click', () => {
    el('tpma-receipt-search').value = '';
    el('tpma-receipt-type-filter').value = '';
    el('tpma-receipt-status-filter').value = '';
    ['tpma-receipt-filter-number-value', 'tpma-receipt-filter-course-value', 'tpma-receipt-filter-course-date-from', 'tpma-receipt-filter-course-date-to', 'tpma-receipt-filter-heading-value', 'tpma-receipt-filter-amount-min', 'tpma-receipt-filter-amount-max'].forEach((id) => { el(id).value = ''; });
    clearSelection();
    state.page = 1;
    syncHeaderFilterControls();
    closeFilterMenus();
    load();
  });
  el('tpma-receipt-bulk-run').addEventListener('click', runBulk);
  el('tpma-receipt-select-all').addEventListener('change', (event) => {
    list.querySelectorAll('input[data-row-select]').forEach((checkbox) => {
      checkbox.checked = event.target.checked;
      const row = checkbox.closest('tr');
      if (checkbox.checked) state.selected.set(checkbox.dataset.key, { kind: row.dataset.kind, id: Number(row.dataset.id) });
      else state.selected.delete(checkbox.dataset.key);
    });
    updateSelectionCount();
  });
  document.querySelectorAll('.tpma-receipt-sort-button').forEach((button) => button.addEventListener('click', () => {
    changeSort(button.dataset.sortBy, button.dataset.sortBy === state.sortBy);
  }));
  el('tpma-receipt-mobile-sort-by').addEventListener('change', (event) => changeSort(event.target.value, false));
  el('tpma-receipt-mobile-sort-order').addEventListener('click', () => changeSort(state.sortBy, true));
  el('tpma-receipt-mobile-filter-apply').addEventListener('click', applyMobileFilters);
  el('tpma-receipt-mobile-filter-clear').addEventListener('click', () => {
    document.querySelectorAll('.tpma-receipt-mobile-filters-content input, .tpma-receipt-mobile-filters-content select').forEach((input) => { input.value = ''; });
    applyMobileFilters();
  });
  el('tpma-receipt-mobile-filters').addEventListener('toggle', (event) => {
    if (event.target.open) syncMobileFilterControls();
  });
  document.querySelectorAll('.tpma-receipt-filter-toggle').forEach((button) => button.addEventListener('click', () => {
    const menu = el(button.dataset.filterMenu);
    const opening = menu.hidden;
    closeFilterMenus(opening ? menu : null);
    menu.hidden = !opening;
    button.setAttribute('aria-expanded', String(opening));
    if (opening) window.setTimeout(() => menu.querySelector('input, select').focus(), 0);
  }));
  document.querySelectorAll('[data-filter-apply]').forEach((button) => button.addEventListener('click', applyHeaderFilters));
  document.querySelectorAll('[data-filter-clear]').forEach((button) => button.addEventListener('click', () => clearHeaderFilter(button.dataset.filterClear)));
  document.querySelectorAll('.tpma-receipt-filter-menu input').forEach((input) => input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); applyHeaderFilters(); }
  }));
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.tpma-receipt-filterable')) closeFilterMenus();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeFilterMenus();
  });

  function setScanDialogError(message) {
    if (!scanError) return;
    scanError.hidden = !message;
    scanError.textContent = message || '';
  }

  function setScanSubmitting(submitting) {
    if (scanSubmit) scanSubmit.disabled = submitting;
    if (scanCancel) scanCancel.disabled = submitting;
    if (scanFile) scanFile.disabled = submitting;
  }

  function openScanDialog(trigger) {
    if (!scanDialog || !scanForm || !scanFile) return;
    state.scanTrigger = trigger;
    state.scanReceipt = { id: trigger.dataset.receiptId, serial: trigger.dataset.receiptSerial };
    scanForm.reset();
    setScanDialogError('');
    setScanSubmitting(false);
    if (typeof scanDialog.showModal !== 'function') {
      showMessage('目前瀏覽器不支援掃描檔上傳視窗，請使用最新版瀏覽器。', 'error');
      state.scanTrigger = null;
      state.scanReceipt = null;
      return;
    }
    scanDialog.showModal();
    window.setTimeout(() => scanFile.focus(), 0);
  }

  function closeScanDialog() {
    if (!scanDialog) return;
    if (scanDialog.open) scanDialog.close();
  }

  if (scanDialog && scanForm && scanFile) {
    scanCancel.addEventListener('click', closeScanDialog);
    scanDialog.addEventListener('close', () => {
      setScanSubmitting(false);
      if (state.scanTrigger && document.body.contains(state.scanTrigger)) state.scanTrigger.focus();
      state.scanTrigger = null;
      state.scanReceipt = null;
    });
    scanDialog.addEventListener('cancel', (event) => {
      if (scanSubmit && scanSubmit.disabled) event.preventDefault();
    });
    scanForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!state.scanReceipt) return;
      const receipt = state.scanReceipt;
      const file = scanFile.files && scanFile.files[0];
      if (!file) {
        setScanDialogError('請先選擇紙本掃描檔（PDF、JPG 或 PNG）。');
        scanFile.focus();
        return;
      }
      setScanDialogError('');
      setScanSubmitting(true);
      const formData = new FormData();
      formData.append('scan', file);
      api(`/admin/receipts/${encodeURIComponent(receipt.id)}/scan`, { method: 'POST', body: formData })
        .then((response) => response.json())
        .then(() => {
          closeScanDialog();
          showMessage(`收據 ${receipt.serial} 的掃描檔已上傳。`, 'success');
          clearSelection();
          load();
        })
        .catch((error) => {
          setScanSubmitting(false);
          setScanDialogError(error.message);
        });
    });
  }
  updateSortControls();
  syncHeaderFilterControls();
  syncMobileFilterControls();
  window.addEventListener('resize', syncSortControlAvailability);
  window.addEventListener('unload', releasePrintFallbackUrl);
  load();
}());
