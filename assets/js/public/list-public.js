const DateUtil = (window.TPMAPublic = window.TPMAPublic || {}).datetime || {};
const Util = (window.TPMAPublic = window.TPMAPublic || {}).util || {};

const TPMA_CR_API_BASE_PHP = window.TPMAPublicConfig?.apiBase || '';
const REG_FORM_URL = window.TPMAPublicConfig?.formUrl || '';
const TPMA_API_BASE = Util.getApiBase(TPMA_CR_API_BASE_PHP);
const UPCOMING_HIDE_DAYS = 7;
const LOW_ENROLLMENT_THRESHOLD = 3;
const UPCOMING_HIDE_MS = UPCOMING_HIDE_DAYS * 24 * 60 * 60 * 1000;
const DEBUG_HIDE_REASON = true;

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

function isSameSessionDate(startRaw, nowTs) {
  if (!startRaw) return false;
  const start = DateUtil.safeDate ? DateUtil.safeDate(startRaw) : new Date(String(startRaw).replace(' ', 'T'));
  if (!start || isNaN(start.getTime())) return false;
  const now = new Date(nowTs);
  return start.getFullYear() === now.getFullYear()
    && start.getMonth() === now.getMonth()
    && start.getDate() === now.getDate();
}

function shouldHideLowEnrollment(row, nowTs) {
  let reason = '';
  let shouldHide = false;
  const isSessionToday = isSameSessionDate(row.start_raw, nowTs);

  if (!isSessionToday && row.visibility_override === 'force_hide') {
    shouldHide = true;
    reason = 'force hidden';
  } else if (!isSessionToday && row.visibility_override === 'force_show') {
    reason = 'force shown';
  } else if (!row.start_ts) {
    reason = 'missing start_ts';
  } else {
    const diff = row.start_ts - nowTs;
    if (diff < 0) {
      reason = 'already started';
    } else if (diff >= UPCOMING_HIDE_MS) {
      reason = 'not within hide window';
    } else if (row.registration_count < LOW_ENROLLMENT_THRESHOLD) {
      shouldHide = true;
      reason = 'low enrollment within hide window';
    } else {
      reason = 'enrollment meets threshold';
    }
  }

  if (DEBUG_HIDE_REASON) {
    const startIso = row.start_ts ? new Date(row.start_ts).toISOString() : '';
    const diffMs = row.start_ts ? (row.start_ts - nowTs) : null;
    console.debug('[TPMA hide-check]', {
      course_id: row.course_id,
      session_id: row.session_id,
      course_name: row.course_name,
      start_raw: row.start_raw,
      start_ts: row.start_ts,
      start_iso: startIso,
      diff_ms: diffMs,
      is_session_today: isSessionToday,
      visibility_override: row.visibility_override,
      registration_count: row.registration_count,
      threshold: LOW_ENROLLMENT_THRESHOLD,
      hide_window_days: UPCOMING_HIDE_DAYS,
      should_hide: shouldHide,
      reason
    });
  }

  return shouldHide;
}

// Tag only uses enrollment count, not time window.
function getEnrollmentTagHtml(row) {
  const isConfirmed = row.registration_count >= LOW_ENROLLMENT_THRESHOLD;
  const text = isConfirmed ? '確定開課' : '招生中';
  const className = isConfirmed ? 'tpma-tag-confirmed' : 'tpma-tag-open';
  return `<span class="tpma-course-tag ${className}">${text}</span>`;
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
const pageState = {
  pageSize: 10,
  currentPage: 1
};
const paginationDom = {
  prev: document.getElementById('tpma-page-prev'),
  next: document.getElementById('tpma-page-next'),
  info: document.getElementById('tpma-page-info')
};

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
  const listContainer = document.getElementById('tpma-course-list-container');

  statusEl.textContent = '載入課程中...';
  listContainer.innerHTML = `
    <div class="tpma-loading-row">載入課程中...</div>
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
      const regCountRaw = row.registration_count ?? row.reg_count ?? row.registrations_count ?? 0;
      const regCount = parseInt(regCountRaw, 10);
      const registrationCount = Number.isNaN(regCount) ? 0 : regCount;

      rows.push({
        course_id: courseId,
        session_id: sessionId,
        course_name: row.course_name || '',
        lecturer: lecturerName,
        start_raw: startRaw,
        duration_minutes: duration,
        start_ts: getStartTimestamp(startRaw),
        display_time: formatClassTime(startRaw, duration),
        registration_count: registrationCount,
        visibility_override: row.visibility_override || '',
        category: row.category || '', // 新增課程分類
        intro: row.intro || '',       // 新增課程簡介
        outline: row.outline || ''    // 新增課程大綱
      });
    });

    allRows = rows;
    filteredRows = [...allRows];
    pageState.currentPage = 1;
    applyFilterAndSort();
    statusEl.textContent = `共 ${filteredRows.length} 筆課程場次`;
  } catch (e) {
    console.error(e);
    statusEl.textContent = `課程載入失敗：${e.message}。請稍後再試。`;
    listContainer.innerHTML = `
      <div class="tpma-loading-row">課程載入失敗：${e.message}</div>
    `;
    filteredRows = [];
    pageState.currentPage = 1;
    updatePaginationControls();
  }
}

// ===== 篩選 + 排序 =====
function applyFilterAndSort() {
  const nowTs = Date.now();
  // 篩選
  let rows = allRows.filter(r => {
    if (shouldHideLowEnrollment(r, nowTs)) return false;
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
  pageState.currentPage = 1;
  renderCourseList(); // 改為呼叫 renderCourseList
}

function updatePaginationControls() {
  const total = filteredRows.length;
  const totalPages = Math.max(1, Math.ceil(total / pageState.pageSize));
  if (pageState.currentPage > totalPages) pageState.currentPage = totalPages;

  const start = total === 0 ? 0 : (pageState.currentPage - 1) * pageState.pageSize + 1;
  const end = total === 0 ? 0 : Math.min(pageState.currentPage * pageState.pageSize, total);

  if (paginationDom.info) {
    paginationDom.info.textContent = `第 ${pageState.currentPage} / ${totalPages} 頁，顯示 ${start}-${end} 筆，共 ${total} 筆`;
  }
  if (paginationDom.prev) paginationDom.prev.disabled = pageState.currentPage <= 1;
  if (paginationDom.next) paginationDom.next.disabled = pageState.currentPage >= totalPages;
}

function renderCourseList() { // 函式名稱從 renderTableBody 改為 renderCourseList
  const listContainer = document.getElementById('tpma-course-list-container');

  if (!filteredRows.length) {
    listContainer.innerHTML = `
      <div class="tpma-empty-row">目前沒有符合條件的課程場次</div>
    `;
    updatePaginationControls();
    return;
  }

  const total = filteredRows.length;
  const totalPages = Math.max(1, Math.ceil(total / pageState.pageSize));
  if (pageState.currentPage > totalPages) pageState.currentPage = totalPages;
  const startIndex = (pageState.currentPage - 1) * pageState.pageSize;
  const endIndex = Math.min(startIndex + pageState.pageSize, total);

  const pageRows = filteredRows.slice(startIndex, endIndex);

  const html = pageRows.map(r => {
    const regUrl = buildRegUrl(r);
    const escCourseName = Util.esc(r.course_name);
    const escLecturer = Util.esc(r.lecturer);
    const escTime = Util.esc(r.display_time);
    const escCategory = Util.esc(r.category);
    const enrollmentTagHtml = getEnrollmentTagHtml(r);

    // Markdown 渲染
    const introHtml = r.intro ? marked.parse(r.intro) : '無';
    const outlineHtml = r.outline ? marked.parse(r.outline) : '無';

    return `
      <div class="tpma-course-card" data-session-id="${r.session_id}">
        <div class="tpma-card-summary tpma-list-grid-layout">
          <div class="tpma-course-time">${escTime}</div>
          <div class="tpma-course-name">${escCourseName}${enrollmentTagHtml}</div>
          <div class="tpma-course-lecturer">${escLecturer}</div>
          <div class="tpma-actions">
            <button class="tpma-btn tpma-btn-reg" onclick="window.open('${regUrl}', '_blank');">
              線上報名
            </button>
            <button class="tpma-btn tpma-btn-details" data-session-id="${r.session_id}">
              詳細
            </button>
          </div>
        </div>
        <div class="tpma-card-details">
          <div class="tpma-details-header">
            <h3>${escCourseName}</h3>
          </div>
          <div class="tpma-details-meta">
            <div><strong>課程分類:</strong> <span>${escCategory}</span></div>
            <div><strong>授課講師:</strong> <span>${escLecturer}</span></div>
            <div><strong>開課時段:</strong> <span>${escTime}</span></div>
          </div>
          <div class="tpma-details-section">
            <h4>課程簡介</h4>
            <div class="tpma-md-content">${introHtml}</div>
          </div>
          <div class="tpma-details-section">
            <h4>課程大綱</h4>
            <div class="tpma-md-content">${outlineHtml}</div>
          </div>
          <div class="tpma-details-actions">
            <button class="tpma-btn" onclick="window.open('${regUrl}', '_blank');">
              線上報名
            </button>
          </div>
        </div>
      </div>
    `;
  }).join('');
  listContainer.innerHTML = html;
  updatePaginationControls();
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

  // 詳細模式按鈕
  if (btn.classList.contains('tpma-btn-details')) {
    const sessionId = btn.getAttribute('data-session-id');
    const courseCard = btn.closest('.tpma-course-card'); // 找到最近的卡片
    if (courseCard) {
      courseCard.classList.toggle('is-open');
      btn.textContent = courseCard.classList.contains('is-open') ? '收合' : '詳細';
    }
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

// ===== 分頁按鈕 =====
if (paginationDom.prev) {
  paginationDom.prev.addEventListener('click', () => {
    if (pageState.currentPage > 1) {
      pageState.currentPage -= 1;
      renderCourseList(); // 改為呼叫 renderCourseList
    }
  });
}
if (paginationDom.next) {
  paginationDom.next.addEventListener('click', () => {
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageState.pageSize));
    if (pageState.currentPage < totalPages) {
      pageState.currentPage += 1;
      renderCourseList(); // 改為呼叫 renderCourseList
    }
  });
}

// ===== 手機版篩選面板切換 =====
const mobileFilterBtn = document.getElementById('tpma-mobile-filter-btn');
const mobileFilterPanel = document.getElementById('tpma-mobile-filter-panel');

if (mobileFilterBtn && mobileFilterPanel) {
  mobileFilterBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    mobileFilterPanel.classList.toggle('open');
    
    // 更新按鈕文字以反映狀態
    const isOpen = mobileFilterPanel.classList.contains('open');
    mobileFilterBtn.textContent = isOpen 
      ? '隱藏篩選 ▲' 
      : '篩選與排序 ▼';
  });
  
  // 點擊面板外時關閉面板
  document.addEventListener('click', (e) => {
    const isClickInPanel = mobileFilterPanel.contains(e.target);
    const isClickOnBtn = mobileFilterBtn.contains(e.target);
    
    if (!isClickInPanel && !isClickOnBtn && mobileFilterPanel.classList.contains('open')) {
      mobileFilterPanel.classList.remove('open');
      mobileFilterBtn.textContent = '篩選與排序 ▼';
    }
  });
}

// ===== 初始化 =====
loadCourseRows();
