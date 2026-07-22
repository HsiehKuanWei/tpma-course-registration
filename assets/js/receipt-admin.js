/* global TPMAReceiptAdminConfig */
(function () {
  'use strict';

  const cfg = window.TPMAReceiptAdminConfig || {};
  const state = { page: 1, perPage: 20, selected: new Map(), pagination: null, debounce: null };
  const el = (id) => document.getElementById(id);
  const list = el('tpma-receipt-list');
  if (!list || !cfg.apiBase) return;

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
    const box = el('tpma-receipt-message');
    if (!box) return;
    box.hidden = !message;
    box.className = 'notice inline ' + (type === 'error' ? 'notice-error' : 'notice-success');
    box.querySelector('p').textContent = message || '';
  }

  function showPrintLink(url, message) {
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
  }

  function clearSelection() {
    state.selected.clear();
    list.querySelectorAll('input[data-row-select]').forEach((checkbox) => { checkbox.checked = false; });
    updateSelectionCount();
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

  function renderRows(items) {
    if (!items.length) {
      list.innerHTML = '<tr><td colspan="6">沒有符合條件的收據或待開立訂單。</td></tr>';
      updateSelectionCount();
      return;
    }
    list.innerHTML = items.map((item) => {
      const key = `${item.kind}:${item.kind === 'pending' ? item.order_id : item.receipt_id}`;
      const selected = state.selected.has(key) ? ' checked' : '';
      const number = item.kind === 'receipt'
        ? (item.preview_url
          ? `<a href="${escapeHtml(item.preview_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.serial)}</a>`
          : `<span>${escapeHtml(item.serial)}</span>`)
        : `<span class="tpma-receipt-pending">尚未開立</span>`;
      return `<tr data-kind="${escapeHtml(item.kind)}" data-id="${escapeHtml(item.kind === 'pending' ? item.order_id : item.receipt_id)}">
        <th scope="row" class="check-column"><input type="checkbox" data-row-select data-key="${escapeHtml(key)}"${selected} aria-label="選取 ${escapeHtml(item.serial)}"></th>
        <td>${number}${orderLines(item.orders)}</td>
        <td>${courseLines(item.courses)}</td>
        <td>${heading(item)}</td>
        <td class="amount">${escapeHtml(item.amount_formatted)} 元</td>
        <td class="tpma-receipt-status">${escapeHtml(item.display_type)}<span class="separator">／</span>${escapeHtml(item.display_status)}</td>
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
      status: el('tpma-receipt-status-filter').value
    };
  }

  function load() {
    const params = new URLSearchParams(Object.assign(filters(), { page: state.page, per_page: state.perPage }));
    list.innerHTML = '<tr><td colspan="6">載入中…</td></tr>';
    api('/admin/receipts/list?' + params.toString()).then((response) => response.json()).then((data) => {
      renderRows(data.items || []);
      renderPagination(data.pagination || { page: 1, total_pages: 1, total: 0 });
    }).catch((error) => {
      list.innerHTML = '<tr><td colspan="6">無法載入收據資料。</td></tr>';
      showMessage(error.message, 'error');
    });
  }

  function selectedFor(kind) {
    return Array.from(state.selected.values()).filter((item) => item.kind === kind).map((item) => item.id);
  }

  function resultMessage(action, data, skippedCount) {
    const key = { generate: 'generated', regenerate: 'regenerated', send: 'sent' }[action];
    const count = key && Array.isArray(data[key]) ? data[key].length : (data.receipt ? 1 : 0);
    const failed = Array.isArray(data.failed) ? data.failed.length : 0;
    const skipped = skippedCount + (Array.isArray(data.skipped) ? data.skipped.length : 0);
    return `已完成 ${count} 筆${failed ? `；失敗 ${failed} 筆` : ''}${skipped ? `；略過 ${skipped} 筆不適用資料` : ''}。`;
  }

  function postBulk(action, payload) {
    return api('/admin/receipts/bulk', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action }, payload)) });
  }

  function batchPrint(receiptIds, skipped, printWindow) {
    postBulk('print', { receipt_ids: receiptIds }).then((response) => ({
      skipped: Number(response.headers.get('X-TPMA-Receipt-Skipped') || 0),
      blob: response.blob()
    })).then(async (result) => {
      const blob = await result.blob;
      const url = URL.createObjectURL(blob);
      window.setTimeout(() => URL.revokeObjectURL(url), 60000);
      const totalSkipped = skipped + result.skipped;
      const message = `已產生列印檔${totalSkipped ? `；略過 ${totalSkipped} 筆不適用或無有效檔案的資料` : ''}。`;
      if (printWindow) {
        printWindow.location.href = url;
        showMessage(message, 'success');
      } else {
        showPrintLink(url, message + ' 瀏覽器封鎖了彈出視窗，請改由此連結開啟：');
      }
    }).catch((error) => {
      if (printWindow && !printWindow.closed) printWindow.close();
      showMessage(error.message, 'error');
    });
  }

  function runBulk() {
    const action = el('tpma-receipt-bulk-action').value;
    if (!action) { showMessage('請先選擇批次操作。', 'error'); return; }
    if (!state.selected.size) { showMessage(cfg.strings.selectRows, 'error'); return; }
    const pendingActions = ['generate', 'merge'];
    const type = pendingActions.includes(action) ? 'pending' : 'receipt';
    const ids = selectedFor(type);
    const skipped = state.selected.size - ids.length;
    if (!ids.length) { showMessage(type === 'pending' ? cfg.strings.pendingOnly : cfg.strings.receiptOnly, 'error'); return; }
    if (action === 'merge' && ids.length < 2) { showMessage('合併開立至少需選擇兩筆待開立訂單。', 'error'); return; }
    if (action === 'merge' && !window.confirm(cfg.strings.mergeConfirm)) return;
    if (action === 'print') {
      const printWindow = window.open('', '_blank');
      if (printWindow) printWindow.document.title = 'TPMA 收據列印檔';
      batchPrint(ids, skipped, printWindow);
      return;
    }
    const payload = type === 'pending' ? { order_ids: ids } : { receipt_ids: ids };
    postBulk(action, payload).then((response) => response.json()).then((data) => {
      showMessage(resultMessage(action, data, skipped), data.success === false ? 'error' : 'success');
      clearSelection();
      load();
    }).catch((error) => showMessage(error.message, 'error'));
  }

  el('tpma-receipt-search').addEventListener('input', () => {
    clearSelection();
    window.clearTimeout(state.debounce);
    state.debounce = window.setTimeout(() => { state.page = 1; load(); }, 250);
  });
  ['tpma-receipt-type-filter', 'tpma-receipt-status-filter'].forEach((id) => el(id).addEventListener('change', () => { clearSelection(); state.page = 1; load(); }));
  el('tpma-receipt-reset').addEventListener('click', () => {
    el('tpma-receipt-search').value = '';
    el('tpma-receipt-type-filter').value = '';
    el('tpma-receipt-status-filter').value = '';
    clearSelection();
    state.page = 1;
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
  load();
}());
