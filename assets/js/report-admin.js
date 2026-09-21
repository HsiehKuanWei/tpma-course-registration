(function(global){
'use strict';

const cfg = global.TPMAReportAdminConfig || {};
const state = {
  charts: {},
  initializedFilters: false,
  overviewGroup: 'course',
  unopenedGroup: 'course',
  overviewMetrics: {
    learners: true,
    class_count: true,
    total_revenue: true,
    lecturer_fee: true,
    net_revenue: true
  },
  unopenedMetrics: {
    learners: true,
    class_count: true,
    total_revenue: true,
    lecturer_fee: true,
    net_revenue: true
  },
  expanded: {
    overview: {},
    unopened: {}
  },
  lastData: null
};

const labels = {
  payment: {
    legacy: '舊資料',
    unknown: '未指定',
    pending: '待付款',
    'on-hold': '未付款',
    processing: '待核帳',
    completed: '已付款',
    cancelled: '已取消',
    refunded: '已退款',
    failed: '失敗',
    'checkout-draft': '草稿'
  },
  registration: {
    unknown: '未指定',
    cert_pending: '待發證',
    completed: '已結訓',
    hold: '保留中',
    hold_refunded: '待退款',
    postpay: '課後付款',
    cancelled: '已取消'
  }
};

const metricDefs = {
  learners: { label: '人數', type: 'number', color: '#0f6c7b', axis: 'y' },
  class_count: { label: '班數', type: 'number', color: '#2563eb', axis: 'y' },
  total_revenue: { label: '總收入', type: 'money', color: '#f59e0b', axis: 'y1' },
  lecturer_fee: { label: '講師費', type: 'money', color: '#ef4444', axis: 'y1' },
  net_revenue: { label: '淨收入', type: 'money', color: '#22c55e', axis: 'y1' }
};

function $(id){
  return document.getElementById(id);
}

function money(value){
  const n = Number(value || 0);
  return '$' + n.toLocaleString('zh-TW', { maximumFractionDigits: 0 });
}

function number(value){
  return Number(value || 0).toLocaleString('zh-TW');
}

function ratio(active, total){
  const actualTotal = total == null || total === '' ? active : total;
  return number(active) + ' / ' + number(actualTotal);
}

function text(value){
  return value == null || value === '' ? '0' : String(value);
}

function esc(value){
  return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
    return ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'})[ch];
  });
}

async function fetchJson(url){
  const res = await fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers: { 'X-WP-Nonce': cfg.nonce || '' }
  });
  const data = await res.json().catch(function(){ return null; });
  if (!res.ok) {
    throw new Error((data && data.message) ? data.message : '讀取報表失敗');
  }
  return data || {};
}

function query(){
  const params = new URLSearchParams();
  [
    ['year', $('tpma-report-year')],
    ['month', $('tpma-report-month')],
    ['date_from', $('tpma-report-date-from')],
    ['date_to', $('tpma-report-date-to')],
    ['course_id', $('tpma-report-course')],
    ['lecturer_code', $('tpma-report-lecturer')],
    ['payment_status', $('tpma-report-payment-status')],
    ['status', $('tpma-report-status')]
  ].forEach(function(pair){
    const el = pair[1];
    if (el && el.value !== '') params.set(pair[0], el.value);
  });
  params.set('_tpma_refresh', Date.now());
  return params.toString();
}

function setState(message, isError){
  const el = $('tpma-report-state');
  if (!el) return;
  el.textContent = message || '';
  el.classList.toggle('is-error', !!isError);
}

function fillFilters(data){
  if (state.initializedFilters) return;
  state.initializedFilters = true;
  const available = data.available || {};
  const currentYear = String((new Date()).getFullYear());
  const activeYear = data.filters && data.filters.year ? String(data.filters.year) : currentYear;
  const yearEl = $('tpma-report-year');
  if (yearEl) {
    const yearSet = new Set((Array.isArray(available.years) && available.years.length ? available.years : [activeYear]).map(function(y){ return String(y); }));
    if (activeYear) yearSet.add(activeYear);
    const years = Array.from(yearSet).sort(function(a, b){ return Number(b) - Number(a); });
    yearEl.innerHTML = '<option value="">全部年度</option>' + years.map(function(y){
      const selected = String(y) === activeYear ? ' selected' : '';
      return '<option value="' + esc(y) + '"' + selected + '>' + esc(y) + '</option>';
    }).join('');
  }
  const courseEl = $('tpma-report-course');
  if (courseEl) {
    courseEl.innerHTML = '<option value="">全部課程</option>' + (available.courses || []).map(function(c){
      const suffix = String(c.is_active) === '0' ? '（停用）' : '';
      return '<option value="' + esc(c.id) + '">' + esc((c.course_name || '調整中') + suffix) + '</option>';
    }).join('');
  }
  const lecturerEl = $('tpma-report-lecturer');
  if (lecturerEl) {
    lecturerEl.innerHTML = '<option value="">全部講師</option>' + (available.lecturers || []).map(function(l){
      return '<option value="' + esc(l.lecturer_code) + '">' + esc(l.lecturer || l.lecturer_code) + '</option>';
    }).join('');
  }
}

function renderKpis(kpis){
  const el = $('tpma-report-kpis');
  if (!el) return;
  const items = [
    ['總人次', ratio(kpis.total_learners, kpis.total_learners_all)],
    ['總收入', money(kpis.total_revenue)],
    ['課程數', number(kpis.course_count)],
    ['總班數', ratio(kpis.class_count, kpis.class_count_total)],
    ['講師費', money(kpis.lecturer_fee_total)],
    ['淨收入', money(Number(kpis.total_revenue || 0) - Number(kpis.lecturer_fee_total || 0))],
    ['公司數', number(kpis.company_count)],
    ['待付款/核帳', number(kpis.pending_payment_count)],
    ['已結訓率', text(kpis.completed_rate) + '%']
  ];
  el.innerHTML = items.map(function(item){
    return '<article class="tpma-report-kpi"><span>' + esc(item[0]) + '</span><strong>' + esc(item[1]) + '</strong></article>';
  }).join('');
}

function destroyChart(id){
  if (state.charts[id]) {
    state.charts[id].destroy();
    delete state.charts[id];
  }
  const canvas = $(id);
  const box = canvas && canvas.parentNode;
  if (box) {
    box.querySelectorAll('.tpma-chart-fallback').forEach(function(el){ el.remove(); });
    canvas.hidden = false;
  }
}

function chart(id, config){
  const canvas = $(id);
  if (!canvas) return;
  destroyChart(id);
  if (!global.Chart) {
    renderChartFallback(canvas, config);
    return;
  }
  state.charts[id] = new global.Chart(canvas, config);
}

function renderChartFallback(canvas, config){
  const box = canvas.parentNode;
  if (!box) return;
  canvas.hidden = true;
  const data = config && config.data ? config.data : {};
  const chartLabels = Array.isArray(data.labels) ? data.labels : [];
  const dataset = data.datasets && data.datasets[0] ? data.datasets[0] : {};
  const values = Array.isArray(dataset.data) ? dataset.data.map(function(v){ return Number(v || 0); }) : [];
  const max = Math.max.apply(null, values.concat([1]));
  const fallback = document.createElement('div');
  fallback.className = 'tpma-chart-fallback';
  fallback.setAttribute('role', 'img');
  fallback.setAttribute('aria-label', '圖表套件未載入，改以簡易長條圖呈現。');
  fallback.innerHTML = chartLabels.slice(0, 12).map(function(label, index){
    const value = values[index] || 0;
    const width = Math.max(2, Math.round((value / max) * 100));
    return '<div class="tpma-chart-fallback-row"><span>' + esc(label) + '</span><div><i style="width:' + width + '%"></i></div><strong>' + esc(number(value)) + '</strong></div>';
  }).join('') || '<p class="tpma-report-empty">圖表套件未載入，且目前沒有可視化資料。</p>';
  box.appendChild(fallback);
}

function formatMetricValue(metric, value){
  if (metric.type === 'money') return money(value);
  if (metric.type === 'percent') return text(value) + '%';
  return number(value);
}

function chartTooltipLabel(ctx){
  const dataset = ctx.dataset || {};
  const metric = metricDefs[dataset.metricKey] || {};
  const horizontal = ctx.chart && ctx.chart.options && ctx.chart.options.indexAxis === 'y';
  const value = horizontal ? ctx.parsed.x : ctx.parsed.y;
  if (dataset.countRemainder) {
    const total = Array.isArray(dataset.totalData) ? Number(dataset.totalData[ctx.dataIndex] || 0) : 0;
    const active = Array.isArray(dataset.actualData) ? Number(dataset.actualData[ctx.dataIndex] || 0) : Math.max(0, total - Number(value || 0));
    return (dataset.label || '') + '：' + formatMetricValue(metric, value) + '，總量 ' + ratio(active, total);
  }
  if ((dataset.metricKey === 'learners' || dataset.metricKey === 'class_count') && Array.isArray(dataset.totalData)) {
    return (dataset.label || '') + '：' + ratio(value, dataset.totalData[ctx.dataIndex]);
  }
  return (dataset.label || '') + '：' + formatMetricValue(metric, value);
}

function baseOptions(horizontal){
  return {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: horizontal ? 'y' : 'x',
    plugins: {
      legend: { position: 'bottom' },
      tooltip: { mode: 'nearest', intersect: true, callbacks: { label: chartTooltipLabel } }
    },
    scales: horizontal
      ? { x: { beginAtZero: true }, y: { ticks: { autoSkip: false, font: { size: 13 } } } }
      : { y: { beginAtZero: true, ticks: { autoSkip: false } }, x: { ticks: { autoSkip: false, maxRotation: 0 } } }
  };
}

function dualAxisOptions(horizontal, useSecondaryAxis){
  const options = baseOptions(horizontal);
  if (horizontal) {
    options.scales = {
      x: { beginAtZero: true, stacked: true },
      y: { stacked: true, ticks: { autoSkip: false, font: { size: 13 } }, grid: { color: 'rgba(15, 23, 42, .08)', lineWidth: 1 } }
    };
    if (useSecondaryAxis) {
      options.scales.xMoney = { beginAtZero: true, position: 'top', grid: { drawOnChartArea: false } };
    }
  } else {
    options.scales = {
      y: { beginAtZero: true },
      y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
    };
  }
  return options;
}

function table(id, headers, rows, totalRow){
  const el = $(id);
  if (!el) return;
  if (!rows || !rows.length) {
    el.innerHTML = '<tbody><tr><td class="tpma-report-empty">沒有資料</td></tr></tbody>';
    return;
  }
  const footer = totalRow && totalRow.length ? '<tfoot><tr class="tpma-report-total">' + totalRow.map(function(cell){ return '<td>' + esc(cell) + '</td>'; }).join('') + '</tr></tfoot>' : '';
  el.innerHTML = '<thead><tr>' + headers.map(function(h){ return '<th>' + esc(h) + '</th>'; }).join('') + '</tr></thead><tbody>' +
    rows.map(function(row){
      return '<tr>' + row.map(function(cell){ return '<td>' + esc(cell) + '</td>'; }).join('') + '</tr>';
    }).join('') + '</tbody>' + footer;
}

function labelFor(type, value){
  return (labels[type] && labels[type][value]) || value || '調整中';
}

function metricTotalKey(key){
  if (key === 'learners') return 'learners_total';
  if (key === 'class_count') return 'class_count_total';
  return '';
}

function countRemainderColor(key){
  return key === 'learners' ? '#a7e3ea' : '#bfdbfe';
}

function setChartHeight(chartId, rowCount, datasetCount){
  const canvas = $(chartId);
  const box = canvas && canvas.parentNode;
  if (!box) return;
  const rows = Math.max(1, Number(rowCount || 0));
  const datasets = Math.max(1, Number(datasetCount || 1));
  const rowHeight = Math.max(96, 52 + (datasets * 22));
  const height = Math.max(420, Math.min(3200, 112 + (rows * rowHeight)));
  box.style.height = height + 'px';
}

function selectedMetrics(scope){
  const flags = scope === 'unopened' ? state.unopenedMetrics : state.overviewMetrics;
  return Object.keys(metricDefs).filter(function(key){ return !!flags[key]; });
}

function overviewDatasets(groups, metrics, useSecondaryAxis){
  return metrics.reduce(function(datasets, key){
    const def = metricDefs[key];
    const isMoney = def.type === 'money';
    const totalKey = metricTotalKey(key);
    const actualData = groups.map(function(r){ return Number(r[key] || 0); });
    const totalData = totalKey ? groups.map(function(r){ return Number(r[totalKey] == null ? r[key] : r[totalKey]); }) : null;
    const base = {
      label: def.label,
      metricKey: key,
      data: actualData,
      totalData: totalData,
      backgroundColor: def.color,
      borderColor: def.color,
      borderWidth: 2,
      borderSkipped: false,
      maxBarThickness: 24,
      categoryPercentage: 0.82,
      barPercentage: 1,
      stack: key,
      xAxisID: useSecondaryAxis && isMoney ? 'xMoney' : 'x'
    };
    if (totalKey) {
      base.label = def.label + '（不含取消）';
      base.stack = key;
      datasets.push(base);
      datasets.push({
        label: def.label + '（取消/未開）',
        metricKey: key,
        countRemainder: true,
        actualData: actualData,
        totalData: totalData,
        data: totalData.map(function(total, index){ return Math.max(0, Number(total || 0) - Number(actualData[index] || 0)); }),
        backgroundColor: countRemainderColor(key),
        borderColor: countRemainderColor(key),
        borderWidth: 1,
        borderSkipped: false,
        maxBarThickness: 24,
        categoryPercentage: 0.82,
        barPercentage: 1,
        stack: key,
        xAxisID: 'x'
      });
      return datasets;
    }
    datasets.push(base);
    return datasets;
  }, []);
}

function renderMetricOverview(chartId, groups, scope){
  const metrics = selectedMetrics(scope);
  const hasMoney = metrics.some(function(key){ return metricDefs[key] && metricDefs[key].type === 'money'; });
  const hasCount = metrics.some(function(key){ return metricDefs[key] && metricDefs[key].type !== 'money'; });
  const useSecondaryAxis = hasMoney && hasCount;
  setChartHeight(chartId, groups.length, metrics.length);
  chart(chartId, {
    type: 'bar',
    data: {
      labels: groups.map(function(r){ return r.label || '調整中'; }),
      datasets: overviewDatasets(groups, metrics, useSecondaryAxis)
    },
    options: dualAxisOptions(true, useSecondaryAxis)
  });
}

function detailRegistrationsRows(detail){
  const regs = Array.isArray(detail.registrations) ? detail.registrations : [];
  if (!regs.length) return '';
  return regs.map(function(reg){
    return '<tr class="tpma-report-registration-row">' +
      '<td></td><td>' + esc(reg.reg_no || '') + '</td><td>' + esc(reg.student_name || '') + '</td>' +
      '<td>' + esc(reg.company_name || '') + '</td><td>' + esc(labelFor('registration', reg.status)) + '</td>' +
      '<td>' + esc(labelFor('payment', reg.payment)) + '</td><td></td><td>' + esc(money(reg.amount)) + '</td><td></td><td></td><td></td>' +
    '</tr>';
  }).join('');
}

function nestedOverviewTable(containerId, groups, scope){
  const el = $(containerId);
  if (!el) return;
  if (!groups || !groups.length) {
    el.innerHTML = '<table class="tpma-report-nested-table"><tbody><tr><td class="tpma-report-empty">沒有資料</td></tr></tbody></table>';
    return;
  }

  const totals = groups.reduce(function(sum, r){
    sum.learners += Number(r.learners || 0);
    sum.learners_total += Number(r.learners_total == null ? r.learners : r.learners_total);
    sum.class_count += Number(r.class_count || 0);
    sum.class_count_total += Number(r.class_count_total == null ? r.class_count : r.class_count_total);
    sum.total_revenue += Number(r.total_revenue || 0);
    sum.lecturer_fee += Number(r.lecturer_fee || 0);
    sum.net_revenue += Number(r.net_revenue || 0);
    return sum;
  }, { learners: 0, learners_total: 0, class_count: 0, class_count_total: 0, total_revenue: 0, lecturer_fee: 0, net_revenue: 0 });

  el.innerHTML = '<table class="tpma-report-nested-table">' +
    '<thead><tr><th>展開</th><th>群組 / 明細</th><th>課程代碼</th><th>講師</th><th>日期</th><th>人數<br><small>不含 / 含取消</small></th><th>班數<br><small>不含 / 含取消</small></th><th>總收入</th><th>講師費</th><th>淨收入</th><th>原因</th></tr></thead>' +
    '<tbody>' + groups.map(function(group, groupIndex){
      const key = group.key || String(groupIndex);
      const expanded = !!state.expanded[scope][key];
      const details = Array.isArray(group.details) ? group.details : [];
      const detailRows = details.map(function(detail){
        return '<tr>' +
          '<td></td><td>' + esc(detail.course || detail.label || '') + '</td><td>' + esc(detail.course_code || '') + '</td>' +
          '<td>' + esc(detail.lecturer || '調整中') + '</td><td>' + esc(detail.class_date || '未排班') + '</td>' +
          '<td>' + esc(ratio(detail.learners, detail.learners_total)) + '</td><td>' + esc(ratio(detail.class_count, detail.class_count_total)) + '</td>' +
          '<td>' + esc(money(detail.total_revenue)) + '</td><td>' + esc(money(detail.lecturer_fee)) + '</td>' +
          '<td>' + esc(money(detail.net_revenue)) + '</td><td>' + esc(detail.reason || '') + '</td>' +
        '</tr>' + detailRegistrationsRows(detail);
      }).join('');
      return '<tr class="tpma-report-group-row">' +
        '<td><button type="button" class="tpma-report-toggle-row" data-scope="' + esc(scope) + '" data-key="' + esc(key) + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">' + (expanded ? '收合' : '展開') + '</button></td>' +
        '<td><strong>' + esc(group.label || '調整中') + '</strong></td><td></td><td></td><td></td>' +
        '<td>' + esc(ratio(group.learners, group.learners_total)) + '</td><td>' + esc(ratio(group.class_count, group.class_count_total)) + '</td>' +
        '<td>' + esc(money(group.total_revenue)) + '</td><td>' + esc(money(group.lecturer_fee)) + '</td>' +
        '<td>' + esc(money(group.net_revenue)) + '</td><td></td></tr>' +
        '<tr class="tpma-report-detail-row" ' + (expanded ? '' : 'hidden') + '><td colspan="11"><table>' +
        '<thead><tr><th></th><th>課程 / 報名編號</th><th>課程代碼 / 學員</th><th>講師 / 公司</th><th>日期 / 狀態</th><th>人數 / 付款</th><th>班數</th><th>分攤金額</th><th>講師費</th><th>淨收入</th><th>原因</th></tr></thead><tbody>' +
        detailRows + '</tbody></table></td></tr>';
    }).join('') + '</tbody><tfoot><tr class="tpma-report-total"><td></td><td>總計</td><td></td><td></td><td></td><td>' + esc(ratio(totals.learners, totals.learners_total)) + '</td><td>' + esc(ratio(totals.class_count, totals.class_count_total)) + '</td><td>' + esc(money(totals.total_revenue)) + '</td><td>' + esc(money(totals.lecturer_fee)) + '</td><td>' + esc(money(totals.net_revenue)) + '</td><td></td></tr></tfoot></table>';
}

function renderOverviewSections(data){
  const overview = data.overview || {};
  const groups = overview[state.overviewGroup] || [];
  renderMetricOverview('tpma-chart-operations-overview', groups, 'overview');
  nestedOverviewTable('tpma-table-operations-overview', groups, 'overview');

  const unopenedOverview = data.unopened_overview || {};
  const unopenedGroups = unopenedOverview[state.unopenedGroup] || [];
  renderMetricOverview('tpma-chart-unopened', unopenedGroups, 'unopened');
  nestedOverviewTable('tpma-table-unopened', unopenedGroups, 'unopened');
}

function renderPeriodAnalysis(data){
  const yearlyAnalysis = (data.analysis && data.analysis.yearly) || [];
  const yearlyTotals = yearlyAnalysis.reduce(function(sum, r){
    sum.learners += Number(r.learners || 0);
    sum.learners_total += Number(r.learners_total == null ? r.learners : r.learners_total);
    sum.class_count += Number(r.class_count || 0);
    sum.class_count_total += Number(r.class_count_total == null ? r.class_count : r.class_count_total);
    sum.total_revenue += Number(r.total_revenue || r.revenue || 0);
    sum.lecturer_fee += Number(r.lecturer_fee || 0);
    sum.net_revenue += Number(r.net_revenue || 0);
    return sum;
  }, { learners: 0, learners_total: 0, class_count: 0, class_count_total: 0, total_revenue: 0, lecturer_fee: 0, net_revenue: 0 });
  chart('tpma-chart-year-analysis', {
    type: 'bar',
    data: {
      labels: yearlyAnalysis.map(function(r){ return r.period; }),
      datasets: [
        { label: '人數', metricKey: 'learners', data: yearlyAnalysis.map(function(r){ return r.learners || 0; }), totalData: yearlyAnalysis.map(function(r){ return r.learners_total == null ? r.learners : r.learners_total; }), backgroundColor: '#0f6c7b', borderColor: '#0f6c7b', borderWidth: 2, yAxisID: 'y' },
        { label: '班數', metricKey: 'class_count', data: yearlyAnalysis.map(function(r){ return r.class_count || 0; }), totalData: yearlyAnalysis.map(function(r){ return r.class_count_total == null ? r.class_count : r.class_count_total; }), backgroundColor: '#2563eb', borderColor: '#2563eb', borderWidth: 2, yAxisID: 'y' },
        { label: '總收入', metricKey: 'total_revenue', data: yearlyAnalysis.map(function(r){ return r.total_revenue || r.revenue || 0; }), borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderWidth: 4, pointRadius: 4, pointHoverRadius: 6, tension: .25, type: 'line', yAxisID: 'y1' },
        { label: '淨收入', metricKey: 'net_revenue', data: yearlyAnalysis.map(function(r){ return r.net_revenue || 0; }), borderColor: '#22c55e', backgroundColor: '#22c55e', borderWidth: 4, pointRadius: 4, pointHoverRadius: 6, tension: .25, type: 'line', yAxisID: 'y1' }
      ]
    },
    options: dualAxisOptions(false)
  });
  table('tpma-table-year-analysis', ['年度', '人數（不含/含取消）', '班數（不含/含取消）', '總收入', '講師費', '淨收入'], yearlyAnalysis.map(function(r){
    return [r.period, ratio(r.learners, r.learners_total), ratio(r.class_count, r.class_count_total), money(r.total_revenue || r.revenue), money(r.lecturer_fee), money(r.net_revenue)];
  }), ['總計', ratio(yearlyTotals.learners, yearlyTotals.learners_total), ratio(yearlyTotals.class_count, yearlyTotals.class_count_total), money(yearlyTotals.total_revenue), money(yearlyTotals.lecturer_fee), money(yearlyTotals.net_revenue)]);

  const monthlyAnalysis = (data.analysis && data.analysis.monthly) || [];
  const monthlyYear = data.analysis && data.analysis.monthly_year ? String(data.analysis.monthly_year) : '';
  const monthlyTotals = monthlyAnalysis.reduce(function(sum, r){
    sum.learners += Number(r.learners || 0);
    sum.learners_total += Number(r.learners_total == null ? r.learners : r.learners_total);
    sum.class_count += Number(r.class_count || 0);
    sum.class_count_total += Number(r.class_count_total == null ? r.class_count : r.class_count_total);
    sum.total_revenue += Number(r.total_revenue || r.revenue || 0);
    sum.lecturer_fee += Number(r.lecturer_fee || 0);
    sum.net_revenue += Number(r.net_revenue || 0);
    return sum;
  }, { learners: 0, learners_total: 0, class_count: 0, class_count_total: 0, total_revenue: 0, lecturer_fee: 0, net_revenue: 0 });
  chart('tpma-chart-month-analysis', {
    type: 'bar',
    data: {
      labels: monthlyAnalysis.map(function(r){ return monthlyYear && String(r.period).indexOf(monthlyYear + '-') === 0 ? String(r.period).slice(5) + '月' : r.period; }),
      datasets: [
        { label: '人數', metricKey: 'learners', data: monthlyAnalysis.map(function(r){ return r.learners || 0; }), totalData: monthlyAnalysis.map(function(r){ return r.learners_total == null ? r.learners : r.learners_total; }), backgroundColor: '#0f6c7b', borderColor: '#0f6c7b', borderWidth: 2, yAxisID: 'y' },
        { label: '班數', metricKey: 'class_count', data: monthlyAnalysis.map(function(r){ return r.class_count || 0; }), totalData: monthlyAnalysis.map(function(r){ return r.class_count_total == null ? r.class_count : r.class_count_total; }), backgroundColor: '#2563eb', borderColor: '#2563eb', borderWidth: 2, yAxisID: 'y' },
        { label: '總收入', metricKey: 'total_revenue', data: monthlyAnalysis.map(function(r){ return r.total_revenue || r.revenue || 0; }), borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderWidth: 4, pointRadius: 4, pointHoverRadius: 6, tension: .25, type: 'line', yAxisID: 'y1' },
        { label: '淨收入', metricKey: 'net_revenue', data: monthlyAnalysis.map(function(r){ return r.net_revenue || 0; }), borderColor: '#22c55e', backgroundColor: '#22c55e', borderWidth: 4, pointRadius: 4, pointHoverRadius: 6, tension: .25, type: 'line', yAxisID: 'y1' }
      ]
    },
    options: dualAxisOptions(false)
  });
  table('tpma-table-month-analysis', ['月份', '人數（不含/含取消）', '班數（不含/含取消）', '總收入', '講師費', '淨收入'], monthlyAnalysis.map(function(r){
    return [r.period, ratio(r.learners, r.learners_total), ratio(r.class_count, r.class_count_total), money(r.total_revenue || r.revenue), money(r.lecturer_fee), money(r.net_revenue)];
  }), ['總計', ratio(monthlyTotals.learners, monthlyTotals.learners_total), ratio(monthlyTotals.class_count, monthlyTotals.class_count_total), money(monthlyTotals.total_revenue), money(monthlyTotals.lecturer_fee), money(monthlyTotals.net_revenue)]);
}

function renderFinanceOverview(data){
  const finance = data.finance && data.finance.overview ? data.finance.overview : {};
  const totals = finance.totals || {};
  const statuses = finance.payment_statuses || [];
  const rows = [{ label: '總計', learners: totals.learners, learners_total: totals.learners_total, class_count: totals.class_count, class_count_total: totals.class_count_total, total_revenue: totals.total_revenue, lecturer_fee: totals.lecturer_fee, net_revenue: totals.net_revenue }].concat(statuses.map(function(r){
    return {
      label: labelFor('payment', r.label),
      learners: r.learners,
      learners_total: r.learners_total,
      class_count: r.class_count || '',
      class_count_total: r.class_count_total || '',
      total_revenue: r.total_revenue,
      lecturer_fee: r.lecturer_fee,
      net_revenue: r.net_revenue
    };
  }));
  chart('tpma-chart-finance-overview', {
    type: 'bar',
    data: {
      labels: rows.map(function(r){ return r.label; }),
      datasets: [
        { label: '總收入', metricKey: 'total_revenue', data: rows.map(function(r){ return r.total_revenue || 0; }), backgroundColor: '#f59e0b', borderColor: '#f59e0b', borderWidth: 2, borderSkipped: false, maxBarThickness: 28 },
        { label: '講師費', metricKey: 'lecturer_fee', data: rows.map(function(r){ return r.lecturer_fee || 0; }), backgroundColor: '#ef4444', borderColor: '#ef4444', borderWidth: 2, borderSkipped: false, maxBarThickness: 28 },
        { label: '淨收入', metricKey: 'net_revenue', data: rows.map(function(r){ return r.net_revenue || 0; }), backgroundColor: '#22c55e', borderColor: '#22c55e', borderWidth: 2, borderSkipped: false, maxBarThickness: 28 }
      ]
    },
    options: baseOptions(false)
  });
  table('tpma-table-finance-overview', ['付款狀態', '人數（不含/含取消）', '班數（不含/含取消）', '總收入', '講師費', '淨收入'], rows.map(function(r){
    return [r.label, ratio(r.learners, r.learners_total), r.class_count === '' ? '' : ratio(r.class_count, r.class_count_total), money(r.total_revenue), money(r.lecturer_fee), money(r.net_revenue)];
  }), ['總計', ratio(totals.learners, totals.learners_total), ratio(totals.class_count, totals.class_count_total), money(totals.total_revenue), money(totals.lecturer_fee), money(totals.net_revenue)]);
}

function formatDelta(metric){
  const delta = Number(metric.delta || 0);
  const sign = delta > 0 ? '+' : '';
  const value = formatMetricValue(metric, delta);
  const rate = metric.delta_rate == null ? '' : '（' + sign + text(metric.delta_rate) + '%）';
  return sign + value + rate;
}

function renderComparisonCard(chartId, tableId, comparison){
  comparison = comparison || {};
  const metrics = Array.isArray(comparison.metrics) ? comparison.metrics : [];
  const currentLabel = comparison.current_label || '目前期間';
  const previousLabel = comparison.previous_label || '比較期間';
  const changeValues = metrics.map(function(r){
    if (r.type === 'percent') return Number(r.delta || 0);
    return r.delta_rate == null ? 0 : Number(r.delta_rate || 0);
  });

  chart(chartId, {
    type: 'bar',
    data: {
      labels: metrics.map(function(r){ return r.label; }),
      datasets: [{
        label: '變化率 / 百分點',
        data: changeValues,
        backgroundColor: changeValues.map(function(v){ return v >= 0 ? '#0f6c7b' : '#ef4444'; })
      }]
    },
    options: Object.assign(baseOptions(true), {
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx){
              const metric = metrics[ctx.dataIndex] || {};
              const suffix = metric.type === 'percent' ? ' 個百分點' : '%';
              return '變化：' + text(ctx.parsed.x) + suffix;
            }
          }
        }
      }
    })
  });

  table(tableId, ['指標', currentLabel, previousLabel, '差異'], metrics.map(function(r){
    return [
      r.label,
      formatMetricValue(r, r.current),
      formatMetricValue(r, r.previous),
      formatDelta(r)
    ];
  }));
}

function renderCharts(data){
  state.lastData = data;
  renderPeriodAnalysis(data);
  renderOverviewSections(data);
  renderFinanceOverview(data);
  const comparisons = data.comparisons || {};
  renderComparisonCard('tpma-chart-year-comparison', 'tpma-table-year-comparison', comparisons.year);
  renderComparisonCard('tpma-chart-month-comparison', 'tpma-table-month-comparison', comparisons.month);
  renderComparisonCard('tpma-chart-period-comparison', 'tpma-table-period-comparison', comparisons.period);
}

async function load(){
  try {
    setState('載入中...', false);
    const data = await fetchJson((cfg.apiBase || '') + '/admin/reports/summary?' + query());
    fillFilters(data);
    renderKpis(data.kpis || {});
    renderCharts(data);
    setState('最後更新：' + (new Date()).toLocaleString('zh-TW'), false);
  } catch (e) {
    setState(e.message || '讀取報表失敗', true);
  }
}

function clearFilters(){
  ['tpma-report-month', 'tpma-report-date-from', 'tpma-report-date-to', 'tpma-report-course', 'tpma-report-lecturer', 'tpma-report-payment-status', 'tpma-report-status'].forEach(function(id){
    const el = $(id);
    if (el) el.value = '';
  });
  const year = $('tpma-report-year');
  if (year) year.value = String((new Date()).getFullYear());
}

function printReportCard(card){
  if (!card) return;
  const title = card.querySelector('h2') ? card.querySelector('h2').textContent : 'TPMA 報表';
  const cloned = card.cloneNode(true);
  cloned.querySelectorAll('.tpma-report-print-button').forEach(function(btn){ btn.remove(); });
  cloned.querySelectorAll('.tpma-report-card-toolbar').forEach(function(toolbar){ toolbar.remove(); });
  const originalCanvases = card.querySelectorAll('canvas');
  const clonedCanvases = cloned.querySelectorAll('canvas');
  originalCanvases.forEach(function(canvas, index){
    const clonedCanvas = clonedCanvases[index];
    if (!clonedCanvas) return;
    try {
      const img = document.createElement('img');
      img.src = canvas.toDataURL('image/png');
      img.alt = canvas.getAttribute('aria-label') || title;
      img.style.maxWidth = '100%';
      img.style.height = 'auto';
      clonedCanvas.parentNode.replaceChild(img, clonedCanvas);
    } catch (e) {}
  });

  let printRoot = document.getElementById('tpma-report-print-root');
  if (!printRoot) {
    printRoot = document.createElement('div');
    printRoot.id = 'tpma-report-print-root';
    printRoot.className = 'tpma-report-print-root';
    document.body.appendChild(printRoot);
  }
  printRoot.innerHTML = '<h1>' + esc(title) + '</h1>';
  printRoot.appendChild(cloned);
  document.body.classList.add('tpma-report-printing-card');
  setTimeout(function(){
    global.print();
    setTimeout(function(){
      document.body.classList.remove('tpma-report-printing-card');
      printRoot.innerHTML = '';
    }, 500);
  }, 50);
}

function initPrintButtons(){
  document.querySelectorAll('.tpma-report-card').forEach(function(card){
    if (card.querySelector('.tpma-report-print-button')) return;
    const heading = card.querySelector('h2');
    if (!heading) return;
    const header = document.createElement('div');
    header.className = 'tpma-report-card-header';
    heading.parentNode.insertBefore(header, heading);
    header.appendChild(heading);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tpma-report-print-button';
    button.textContent = '列印';
    button.addEventListener('click', function(){ printReportCard(card); });
    header.appendChild(button);
  });
}

function initTabs(){
  document.querySelectorAll('[data-report-tab]').forEach(function(btn){
    btn.addEventListener('click', function(){
      const key = btn.getAttribute('data-report-tab');
      document.querySelectorAll('[data-report-tab]').forEach(function(item){
        const active = item === btn;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.querySelectorAll('[data-report-panel]').forEach(function(panel){
        panel.classList.toggle('is-active', panel.getAttribute('data-report-panel') === key);
      });
      Object.keys(state.charts).forEach(function(id){ state.charts[id].resize(); });
    });
  });
}

function rerenderCurrentData(){
  if (state.lastData) renderCharts(state.lastData);
}

function initOverviewControls(){
  const overviewGroup = $('tpma-overview-group');
  if (overviewGroup) {
    overviewGroup.addEventListener('change', function(){
      state.overviewGroup = overviewGroup.value === 'lecturer' ? 'lecturer' : 'course';
      rerenderCurrentData();
    });
  }
  const unopenedGroup = $('tpma-unopened-group');
  if (unopenedGroup) {
    unopenedGroup.addEventListener('change', function(){
      state.unopenedGroup = unopenedGroup.value === 'lecturer' ? 'lecturer' : 'course';
      rerenderCurrentData();
    });
  }
  document.querySelectorAll('.tpma-overview-metric, .tpma-unopened-metric').forEach(function(input){
    input.addEventListener('change', function(){
      const key = input.getAttribute('data-metric');
      const target = input.classList.contains('tpma-unopened-metric') ? state.unopenedMetrics : state.overviewMetrics;
      if (metricDefs[key]) target[key] = input.checked;
      rerenderCurrentData();
    });
  });
  document.addEventListener('click', function(event){
    const btn = event.target && event.target.closest ? event.target.closest('.tpma-report-toggle-row') : null;
    if (!btn) return;
    const scope = btn.getAttribute('data-scope') === 'unopened' ? 'unopened' : 'overview';
    const key = btn.getAttribute('data-key') || '';
    state.expanded[scope][key] = !state.expanded[scope][key];
    rerenderCurrentData();
  });
}

document.addEventListener('DOMContentLoaded', function(){
  initPrintButtons();
  initTabs();
  initOverviewControls();
  const apply = $('tpma-report-apply');
  const refresh = $('tpma-report-refresh');
  const clear = $('tpma-report-clear');
  if (apply) apply.addEventListener('click', load);
  if (refresh) refresh.addEventListener('click', load);
  if (clear) clear.addEventListener('click', function(){ clearFilters(); load(); });
  ['tpma-report-year', 'tpma-report-month'].forEach(function(id){
    const el = $(id);
    if (el) el.addEventListener('change', load);
  });
  load();
});

})(window);
