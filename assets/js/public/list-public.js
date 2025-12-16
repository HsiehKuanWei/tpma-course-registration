const DateUtil = (window.TPMAPublic = window.TPMAPublic || {}).datetime || {};
const Util = (window.TPMAPublic = window.TPMAPublic || {}).util || {};

const TPMA_CR_API_BASE_PHP = window.TPMAPublicConfig?.apiBase || '';
const REG_FORM_URL = window.TPMAPublicConfig?.formUrl || '';
const TPMA_API_BASE = Util.getApiBase(TPMA_CR_API_BASE_PHP);

function formatClassTime(startRaw, durationMinutes) {
  if (DateUtil.formatRange) {
    const formatted = DateUtil.formatRange(startRaw, durationMinutes);
    if (formatted) return formatted;
  }
  if (!startRaw) return '時間待定';
  const start = new Date(startRaw.replace(' ', 'T'));
  if (isNaN(start.getTime())) return startRaw;

  const mins = parseInt(durationMinutes || 0, 10);
  const end = new Date(start.getTime() + mins * 60000);
  const pad2 = (n) => n.toString().padStart(2, '0');
  const weekdayMap = ['日', '一', '二', '三', '四', '五', '六'];

  const y = start.getFullYear();
  const mo = pad2(start.getMonth() + 1);
  const d = pad2(start.getDate());
  const w = weekdayMap[start.getDay()];
  const sh = pad2(start.getHours());
  const sm = pad2(start.getMinutes());
  const eh = pad2(end.getHours());
  const em = pad2(end.getMinutes());

  // YYYY/MM/DD（Week）HH:MM~HH:MM
  return `${y}/${mo}/${d}（${w}） ${sh}:${sm}~${eh}:${em}`;
}

function getStartTimestamp(startRaw) {
  if (DateUtil.getTimestamp) {
    return DateUtil.getTimestamp(startRaw);
  }
  if (!startRaw) return 0;
  const d = new Date(startRaw.replace(' ', 'T'));
  return isNaN(d.getTime()) ? 0 : d.getTime();
}

/**
 * 產生報名網址：
 * - 用 course_id + session_id 帶到報名表
 * - 報名表端可用 URLSearchParams 讀取 course_id、session_id
 *   並自動選定下拉式選單
 */
function buildRegUrl(row) {
  const params = new URLSearchParams();
  if (row.course_id) params.set('course_id', row.course_id);
  if (row.session_id) params.set('session_id', row.session_id);
  const qs = params.toString();
  return qs ? `${REG_FORM_URL}?${qs}` : REG_FORM_URL;
}

// ===== 資料狀態 =====
let allRows = [];     // 原始資料列（每一筆為一個「課程 x 場次」）
let filteredRows = []; // 套用篩選/排序後的結果

const filters = {
  time: '',
  name: '',
  lecturer: ''
};

const sortState = {
  key: null,  // 'time' | 'name' | 'lecturer'
  dir: 'asc'  // 'asc' | 'desc'
};

// ===== 載入課程資料 =====
async function loadCourseRows() {
  const statusEl = document.getElementById('tpma-status');
  const tbody = document.getElementById('tpma-course-tbody');

  statusEl.textContent = '載入課程中...';
  tbody.innerHTML = `
    <tr><td colspan="4" class="tpma-loading-row">載入課程中...</td></tr>
  `;

  try {
    const res = await fetch(TPMA_API_BASE + '/courses');
    if (!res.ok) {
        let errorMsg = `HTTP Error: ${res.status} ${res.statusText}`;
        try {
            const errorData = await res.json();
            if (errorData.message) {
                errorMsg = errorData.message;
            } else if (errorData.code) {
                errorMsg = `${errorData.code}: ${errorData.message}`;
            }
        } catch (jsonError) {
            // If JSON parsing fails, use the default HTTP error message
        }
        throw new Error(errorMsg);
    }
    const data = await res.json();

    const rows = [];
    (data || []).forEach(row => {
      const courseId = row.course_id;
      if (!courseId) return;

      // 優先使用 session_datetime，若沒有再用 class_date
      const startRaw = row.session_datetime || row.class_date || '';
      const duration = row.duration_minutes || 0;
      const lecturerName = row.lecturer || row.lecturers_name || '';
      const sessionId = row.session_id || '';

      rows.push({
        course_id: courseId,
        session_id: sessionId,
        course_name: row.course_name || '',
        lecturer: lecturerName,
        start_raw: startRaw,
        duration_minutes: duration,
        start_ts: getStartTimestamp(startRaw),
        display_time: formatClassTime(startRaw, duration)
      });
    });

    allRows = rows;
    filteredRows = [...allRows];
    applyFilterAndSort();
    statusEl.textContent = `共 ${filteredRows.length} 筆課程場次`;
  } catch (e) {
    console.error(e);
    statusEl.textContent = `課程載入失敗：${e.message}。請稍後再試。`;
    tbody.innerHTML = `
      <tr><td colspan="4" class="tpma-loading-row">課程載入失敗：${e.message}</td></tr>
    `;
  }
}

// ===== 篩選 + 排序 =====
function applyFilterAndSort() {
  const tbody = document.getElementById('tpma-course-tbody');

  // 篩選
  let rows = allRows.filter(r => {
    // time
    if (filters.time) {
      const t = filters.time.toLowerCase();
      if (!r.display_time.toLowerCase().includes(t)) return false;
    }
    // name
    if (filters.name) {
      const t = filters.name.toLowerCase();
      if (!r.course_name.toLowerCase().includes(t)) return false;
    }
    // lecturer
    if (filters.lecturer) {
      const t = filters.lecturer.toLowerCase();
      if (!r.lecturer.toLowerCase().includes(t)) return false;
    }
    return true;
  });

  // 排序
  if (sortState.key) {
    const dirFactor = sortState.dir === 'desc' ? -1 : 1;
    rows.sort((a, b) => {
      let va, vb;
      if (sortState.key === 'time') {
        va = a.start_ts;
        vb = b.start_ts;
      } else if (sortState.key === 'name') {
        va = a.course_name || '';
        vb = b.course_name || '';
      } else if (sortState.key === 'lecturer') {
        va = a.lecturer || '';
        vb = b.lecturer || '';
      } else {
        return 0;
      }

      if (typeof va === 'number' && typeof vb === 'number') {
        return (va - vb) * dirFactor;
      }
      return va.toString().localeCompare(vb.toString(), 'zh-Hant') * dirFactor;
    });
  }

  filteredRows = rows;
  renderTableBody();
}

function renderTableBody() {
  const tbody = document.getElementById('tpma-course-tbody');

  if (!filteredRows.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="tpma-empty-row">目前沒有符合條件的課程場次</td>
      </tr>
    `;
    return;
  }

  const html = filteredRows.map(r => {
    const regUrl = buildRegUrl(r);
    const escCourseName = Util.esc(r.course_name);
    const escLecturer = Util.esc(r.lecturer);
    const escTime = Util.esc(r.display_time);

    return `
      <tr>
        <td>${escTime}</td>
        <td>${escCourseName}</td>
        <td>${escLecturer}</td>
        <td>
          <a href="${regUrl}" target="_blank" rel="noopener" class="tpma-reg-link tpma-btn">
            線上報名
          </a>
        </td>
      </tr>
    `;
  }).join('');
  tbody.innerHTML = html;
}

// ===== 篩選輸入事件 =====
document.addEventListener('input', e => {
  if (e.target.id === 'filter-time') {
    filters.time = e.target.value.trim();
    applyFilterAndSort();
  }
  if (e.target.id === 'filter-name') {
    filters.name = e.target.value.trim();
    applyFilterAndSort();
  }
  if (e.target.id === 'filter-lecturer') {
    filters.lecturer = e.target.value.trim();
    applyFilterAndSort();
  }
});

// ===== 排序 / 清除按鈕事件 =====
document.addEventListener('click', e => {
  const btn = e.target;
  // 排序
  const sortKey = btn.getAttribute('data-sort');
  if (sortKey) {
    if (sortKey === 'time-asc')      { sortState.key = 'time';      sortState.dir = 'asc'; }
    else if (sortKey === 'time-desc'){ sortState.key = 'time';      sortState.dir = 'desc'; }
    else if (sortKey === 'name-asc'){ sortState.key = 'name';      sortState.dir = 'asc'; }
    else if (sortKey === 'name-desc'){ sortState.key = 'name';      sortState.dir = 'desc'; }
    else if (sortKey === 'lecturer-asc'){ sortState.key = 'lecturer'; sortState.dir = 'asc'; }
    else if (sortKey === 'lecturer-desc'){ sortState.key = 'lecturer'; sortState.dir = 'desc'; }

    applyFilterAndSort();
    return;
  }

  // 清除篩選
  const clearKey = btn.getAttribute('data-clear');
  if (clearKey) {
    if (clearKey === 'time') {
      filters.time = '';
      const input = document.getElementById('filter-time');
      if (input) input.value = '';
    } else if (clearKey === 'name') {
      filters.name = '';
      const input = document.getElementById('filter-name');
      if (input) input.value = '';
    } else if (clearKey === 'lecturer') {
      filters.lecturer = '';
      const input = document.getElementById('filter-lecturer');
      if (input) input.value = '';
    }
    applyFilterAndSort();
    return;
  }

  // 開啟/關閉欄位篩選選單
  const menuTarget = btn.getAttribute('data-menu-target');
  if (menuTarget) {
    e.stopPropagation();
    const menu = document.getElementById(menuTarget);
    if (!menu) return;

    // 關閉其他開啟中的 menu
    document.querySelectorAll('.tpma-th-menu.open').forEach(m => {
      if (m !== menu) m.classList.remove('open');
    });

    menu.classList.toggle('open');
    return;
  }
});

// 點擊表格外關閉所有篩選選單
document.addEventListener('click', e => {
  const menus = document.querySelectorAll('.tpma-th-menu.open');
  menus.forEach(menu => {
    if (!menu.contains(e.target) && !isFilterButton(e.target)) {
      menu.classList.remove('open');
    }
  });
});

function isFilterButton(el) {
  return el.classList && el.classList.contains('tpma-th-menu-btn');
}

// ===== 初始化 =====
loadCourseRows();
