// Excel 匯出模組（依賴 SheetJS XLSX）

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const EXP = global.TPMARegAdmin.exportModule = global.TPMARegAdmin.exportModule || {};
const L = global.TPMARegAdmin.labels;

// ------------------------------------------------------------
// 工具：取得今日日期字串 YYYYMMDD
// ------------------------------------------------------------
function todayStr(){
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return y + m + day;
}

function formatAddress(row){
  if (row.address) return row.address;

  return [
    row.address_postcode,
    row.address_state,
    row.address_city,
    row.address_line1
  ].filter(function(v){
    return v != null && String(v).trim() !== '';
  }).join(' ');
}

function getSelectedStudentRows(ctx){
  const selectedIds = Array.from(document.querySelectorAll('.tpma-reg-select:checked')).map(function(cb){
    const card = cb.closest('.tpma-reg-card');
    return card && card.dataset ? String(card.dataset.id || '') : '';
  }).filter(Boolean);

  if (!selectedIds.length) return [];

  const selectedSet = new Set(selectedIds);
  return (ctx.data.currentRegs || []).filter(function(row){
    return selectedSet.has(String(row.id || ''));
  });
}

function getStudentExportRows(ctx){
  const selectedRows = getSelectedStudentRows(ctx);
  return selectedRows.length ? selectedRows : (ctx.data.currentRegs || []);
}

// ------------------------------------------------------------
// Modal 開關
// ------------------------------------------------------------
EXP.exportModalPlaceholder = null;

EXP.mountModal = function mountModal(overlay){
  if (!overlay || overlay.parentNode === document.body) return;
  const placeholder = document.createComment('tpma export modal placeholder');
  overlay.parentNode.insertBefore(placeholder, overlay);
  document.body.appendChild(overlay);
  EXP.exportModalPlaceholder = placeholder;
};

EXP.restoreModal = function restoreModal(overlay){
  const placeholder = EXP.exportModalPlaceholder;
  if (overlay && placeholder && placeholder.parentNode) {
    placeholder.parentNode.insertBefore(overlay, placeholder);
    placeholder.remove();
  }
  EXP.exportModalPlaceholder = null;
};

EXP.openModal = function openModal(ctx, defaultType){
  const overlay = document.getElementById('tpma-export-modal');
  if (!overlay) return;
  EXP.mountModal(overlay);
  defaultType = ['statistics', 'quiz_summary'].includes(defaultType) ? defaultType : 'students';

  // 更新筆數顯示
  const count = getStudentExportRows(ctx).length;
  const countEl = document.getElementById('tpma-export-student-count');
  if (countEl) countEl.textContent = count;

  // 重置為選單指定類型
  const radios = overlay.querySelectorAll('input[name="tpma-export-type"]');
  radios.forEach(r => { r.checked = (r.value === defaultType); });
  const statsOpts = document.getElementById('tpma-export-stats-options');
  if (statsOpts) statsOpts.style.display = defaultType === 'statistics' ? '' : 'none';

  overlay.classList.add('open');
  document.body.classList.add('tpma-reg-modal-open');
};

EXP.closeModal = function closeModal(){
  const overlay = document.getElementById('tpma-export-modal');
  if (overlay) overlay.classList.remove('open');
  document.body.classList.remove('tpma-reg-modal-open');
  EXP.restoreModal(overlay);
};

// ------------------------------------------------------------
// 匯出一：課程學員資料
// ------------------------------------------------------------
EXP.exportStudents = function exportStudents(rows){
  const labels = L || {};

  const headers = [
    '報名時間', '報名編號', '課程名稱', '授課日期',
    '學員姓名', '部門', '職稱', '行動電話', 'Email',
    '公司抬頭', '統一編號', '承辦人', '承辦Email', '聯絡電話',
    '地址',
    '付款狀態', '報名狀態', '收據方式', '收據狀態',
    '匯款金額', '匯款帳號', '匯款日期',
    '測驗成績', '證書編號', '備註'
  ];

  const data = rows.map(function(r){
    return [
      r.created_at   || '',
      r.reg_no       || '',
      r.course_name  || '',
      r.class_date   ? String(r.class_date).substring(0, 10) : '',
      r.student_name || '',
      r.department   || '',
      r.job_title    || '',
      r.mobile       || '',
      r.emails       || '',
      r.company_name || '',
      r.tax_id       || '',
      r.contact_name || '',
      r.contact_email || '',
      r.phone        || '',
      formatAddress(r),
      labels.paymentStatusLabel ? labels.paymentStatusLabel(r.payment_status) : (r.payment_status || ''),
      labels.statusLabel        ? labels.statusLabel(r.status)                : (r.status || ''),
      labels.receiptTypeLabel   ? labels.receiptTypeLabel(r.receipt_type)     : (r.receipt_type || ''),
      labels.receiptStatusLabel ? labels.receiptStatusLabel(r.receipt_status) : (r.receipt_status || ''),
      r.remit_amount   != null ? r.remit_amount   : '',
      r.remit_account  || '',
      r.remit_paid_at  ? String(r.remit_paid_at).substring(0, 10) : '',
      r.test_score     != null ? r.test_score     : '',
      r.certificate_id || '',
      r.note           || ''
    ];
  });

  const sheetData = [headers].concat(data);
  const ws = XLSX.utils.aoa_to_sheet(sheetData);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, '學員資料');
  XLSX.writeFile(wb, '學員資料_' + todayStr() + '.xlsx');
};

// ------------------------------------------------------------
// 匯出二：統計報表
// ------------------------------------------------------------
EXP.exportStatistics = function exportStatistics(allRows, dateFrom, dateTo){
  if (!dateFrom && !dateTo) {
    alert('請輸入授課日期範圍（至少填一個日期）');
    return;
  }

  // 篩選出日期範圍內的資料
  var filtered = allRows.filter(function(r){
    var d = (r.class_date || '').substring(0, 10);
    if (!d) return false;
    if (dateFrom && d < dateFrom) return false;
    if (dateTo   && d > dateTo)   return false;
    return true;
  });

  if (!filtered.length) {
    alert('指定日期範圍內無資料');
    return;
  }

// 按公司分組，保持首次出現順序
  var companyKeys = []; // 保持順序
  var groups = {};      // key -> { company_name, rows }

  filtered.forEach(function(r){
    var key = r.company_name || '（無公司）';
    if (!groups[key]) {
      companyKeys.push(key);
      groups[key] = {
        company_name: r.company_name || '（無公司）',
        rows:         []
      };
    }
    groups[key].rows.push(r);
  });

  var headers = ['公司抬頭', '人次', '金額'];
  var sheetData = [headers];

  var totalCompanies = companyKeys.length;
  var totalStudents  = 0;
  var totalRevenue   = 0;

  companyKeys.forEach(function(key){
    var grp = groups[key];
    
    // 計算人次（就是行數）
    var companyCount = grp.rows.length;
    
    // 計算金額：按不同的 order_id，確保同一訂單只算一次
    var orderIdSet = {};
    var companyRevenue = 0;
    
    grp.rows.forEach(function(r){
      var orderId = r.woocommerce_order_id || '';
      // 如果同一訂單首次出現，才累加金額
      if (orderId && !orderIdSet[orderId]) {
        orderIdSet[orderId] = true;
        var amt = parseFloat(r.order_total) || 0;
        companyRevenue += amt;
      } else if (!orderId) {
        // 沒有 order_id 的記錄，每筆都算一次
        var amt = parseFloat(r.order_total) || 0;
        companyRevenue += amt;
      }
      totalStudents++;
    });
    totalRevenue += companyRevenue;

    sheetData.push([
      grp.company_name,
      companyCount,
      companyRevenue || ''
    ]);
  });

  // 空行 + 總計列
  sheetData.push(['', '', '']);
  sheetData.push(['總計', totalCompanies + ' 家公司 / ' + totalStudents + ' 人次', totalRevenue || '']);

  var ws = XLSX.utils.aoa_to_sheet(sheetData);
  var wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, '統計報表');

  var suffix = (dateFrom || 'all') + '_' + (dateTo || 'all');
  XLSX.writeFile(wb, '統計報表_' + suffix + '.xlsx');
};

function sheetName(name, used){
  var clean = String(name || '測驗摘要').replace(/[\[\]\:\*\?\/\\]/g, ' ').replace(/\s+/g, ' ').trim();
  if (!clean) clean = '測驗摘要';
  clean = clean.substring(0, 31);
  var base = clean;
  var index = 2;
  while (used[clean]) {
    var suffix = '_' + index;
    clean = base.substring(0, 31 - suffix.length) + suffix;
    index++;
  }
  used[clean] = true;
  return clean;
}

EXP.exportQuizSummary = async function exportQuizSummary(ctx){
  const rows = getStudentExportRows(ctx);
  const ids = rows.map(function(row){ return parseInt(row.id, 10) || 0; }).filter(Boolean);
  if (!ids.length) {
    alert('沒有可匯出的學員資料');
    return false;
  }

  const data = await (global.TPMARegAdmin.api || {}).exportQuizSummary(ctx, ids);
  const sheets = data && Array.isArray(data.sheets) ? data.sheets : [];
  if (!sheets.length) {
    alert('沒有可匯出的測驗摘要');
    return false;
  }

  const wb = XLSX.utils.book_new();
  const used = {};
  sheets.forEach(function(sheet){
    const headers = Array.isArray(sheet.headers) ? sheet.headers : [];
    const body = Array.isArray(sheet.rows) ? sheet.rows : [];
    if (!headers.length) return;
    const ws = XLSX.utils.aoa_to_sheet([headers].concat(body));
    XLSX.utils.book_append_sheet(wb, ws, sheetName(sheet.name, used));
  });
  if (!wb.SheetNames.length) {
    alert('沒有可匯出的測驗摘要');
    return false;
  }
  XLSX.writeFile(wb, '測驗摘要_' + todayStr() + '.xlsx');
  return true;
};

// ------------------------------------------------------------
// 初始化：綁定事件
// ------------------------------------------------------------
EXP.init = function init(ctx){
  var overlay = document.getElementById('tpma-export-modal');
  if (!overlay) return;

  // 開啟 modal
  var openBtn = document.getElementById('tpma-btn-export');
  if (openBtn) openBtn.addEventListener('click', function(){
    EXP.openModal(ctx);
  });

  // 關閉 modal
  var closeBtn  = document.getElementById('tpma-export-modal-close');
  var cancelBtn = document.getElementById('tpma-export-cancel');
  function closeHandler(){ EXP.closeModal(); }
  if (closeBtn)  closeBtn.addEventListener('click',  closeHandler);
  if (cancelBtn) cancelBtn.addEventListener('click', closeHandler);

  // 點擊遮罩外部關閉
  overlay.addEventListener('click', function(e){
    if (e.target === overlay) EXP.closeModal();
  });

  // radio 切換：顯示/隱藏統計報表日期選項
  overlay.querySelectorAll('input[name="tpma-export-type"]').forEach(function(radio){
    radio.addEventListener('change', function(){
      var statsOpts = document.getElementById('tpma-export-stats-options');
      if (statsOpts) statsOpts.style.display = (this.value === 'statistics') ? '' : 'none';
    });
  });

  // 確認匯出
  var confirmBtn = document.getElementById('tpma-export-confirm');
  if (confirmBtn) confirmBtn.addEventListener('click', async function(){
    var checked = overlay.querySelector('input[name="tpma-export-type"]:checked');
    if (!checked) return;

    if (checked.value === 'students') {
      EXP.exportStudents(getStudentExportRows(ctx));
      EXP.closeModal();
    } else if (checked.value === 'quiz_summary') {
      try {
        confirmBtn.disabled = true;
        confirmBtn.textContent = '匯出中…';
        const ok = await EXP.exportQuizSummary(ctx);
        if (ok) EXP.closeModal();
      } catch (e) {
        alert(e.message || '測驗摘要匯出失敗');
      } finally {
        confirmBtn.disabled = false;
        confirmBtn.textContent = '確認匯出';
      }
    } else if (checked.value === 'statistics') {
      var dateFrom = (document.getElementById('tpma-export-stats-from') || {}).value || '';
      var dateTo   = (document.getElementById('tpma-export-stats-to')   || {}).value || '';
      EXP.exportStatistics(ctx.data.allRegs || [], dateFrom, dateTo);
      // exportStatistics 內部有提示，只在成功後關閉
      if (dateFrom || dateTo) {
        var hasData = (ctx.data.allRegs || []).some(function(r){
          var d = (r.class_date || '').substring(0, 10);
          if (!d) return false;
          if (dateFrom && d < dateFrom) return false;
          if (dateTo   && d > dateTo)   return false;
          return true;
        });
        if (hasData) EXP.closeModal();
      }
    }
  });
};

})(window);
