// course-admin.render.js
// ======================================================================
// 【功能】畫面渲染（DOM 組裝）
// - 渲染課程列表
// - 渲染課程「檢視」與「編輯」模式
// - 生成/重建講師下拉選單、課程名稱篩選選單
// - 新增/移除課程場次輸入列
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const state = ns.state;
  const dom = ns.dom;
  const util = ns.util;

  /**
   * 建立「講師篩選」下拉選單
   */
  ns.buildLecturerFilter = function buildLecturerFilter() {
    const lec = dom.filter.lec;
    if (!lec) return;
    lec.innerHTML = '<option value="">全部講師</option>';
    state.lecturers.forEach(l => {
      const opt = document.createElement('option');
      opt.value = l.code;
      opt.textContent = util.lecturerLabel(l);
      lec.appendChild(opt);
    });
  };

  /**
   * 重建「課程編輯表單內」講師 select（保留既有選取值）
   * @param {HTMLSelectElement} sel
   */
  ns.rebuildLecturerSelect = function rebuildLecturerSelect(sel) {
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = '<option value="">選擇講師</option>';
    state.lecturers.forEach(l => {
      const opt = document.createElement('option');
      opt.value = l.code;
      opt.textContent = util.lecturerLabel(l);
      sel.appendChild(opt);
    });
    if (current) sel.value = current;
  };

  /**
   * 建立「課程名稱」篩選下拉選單（依目前 filtered courses 的 unique 名稱集合）
   * @param {Array<object>} courses
   */
  ns.buildCourseNameFilter = function buildCourseNameFilter(courses) {
    const course = dom.filter.course;
    if (!course) return;
    const current = course.value;
    course.innerHTML = '<option value="">全部課程名稱</option>';
    const names = new Set();
    courses.forEach(c => { if (c.course_name) names.add(c.course_name); });
    Array.from(names).sort().forEach(name => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      course.appendChild(opt);
    });
    if (current && names.has(current)) course.value = current;
  };

  /**
   * 渲染課程卡片列表
   * @param {Array<object>} list
   */
ns.renderCourses = function renderCourses(list){
  const body = dom.courseList; // 這裡現在是 <div id="tpma-course-tbody">
  if (!body) return;
  body.innerHTML = '';

  if (!list || list.length === 0) {
    body.innerHTML = '<div class="tpma-empty-row">沒有符合條件的課程</div>';
    return;
  }

  const catLabel = (c) => c.category || util.catCodeToLabel(c.category_code || '');
  const lecLabel = (c) => util.lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
  const showText = (v) => (v == null || v === '' ? '-' : String(v));

  list.forEach(c => {
    const card = document.createElement('div');
    card.className = 'tpma-course-card';
    card.dataset.id = c.id || '';

    // summary（grid 一列）
    const summary = document.createElement('div');
    summary.className = 'tpma-course-card-summary tpma-course-grid-layout';

    const cCode = document.createElement('div');
    cCode.className = 'tpma-course-cell';
    cCode.innerHTML = '<div class="tpma-cell-wrap">' + util.esc(showText(c.course_code)) + '</div>';
    summary.appendChild(cCode);

    const cName = document.createElement('div');
    cName.className = 'tpma-course-cell';
    cName.innerHTML = '<div class="tpma-cell-wrap">' + util.esc(showText(c.course_name)) + '</div>';
    summary.appendChild(cName);

    const cLec = document.createElement('div');
    cLec.className = 'tpma-course-cell';
    cLec.innerHTML = '<div class="tpma-cell-wrap">' + util.esc(showText(lecLabel(c))) + '</div>';
    summary.appendChild(cLec);

    const cCat = document.createElement('div');
    cCat.className = 'tpma-course-cell';
    cCat.innerHTML = '<div class="tpma-cell-wrap">' + util.esc(showText(catLabel(c))) + '</div>';
    summary.appendChild(cCat);

    const cAct = document.createElement('div');
    cAct.className = 'tpma-course-cell';
    cAct.innerHTML = '<div class="tpma-cell-wrap"><button type="button" class="tpma-btn tpma-view-btn">詳細</button></div>';
    summary.appendChild(cAct);

    card.appendChild(summary);

    // details（展開區）
    const details = document.createElement('div');
    details.className = 'tpma-course-card-details';
    details.dataset.id = c.id || '';
    card.appendChild(details);

    // 沿用你原本的 detailDiv（renderCourseView / renderCourseEdit 依賴 div._data）
    const detailDiv = document.createElement('div');
    detailDiv.className = 'tpma-course-item';
    detailDiv.dataset.id = c.id || '';
    detailDiv._data = c;
    details.appendChild(detailDiv);

    ns.renderCourseView(detailDiv, false);

    const viewBtn = cAct.querySelector('.tpma-view-btn');

    const setExpanded = (expanded) => {
      if (expanded) details.classList.add('open');
      else details.classList.remove('open');
      if (viewBtn) viewBtn.textContent = expanded ? '收合' : '詳細';
    };

    if (viewBtn) {
      viewBtn.addEventListener('click', () => {
        const expanded = !details.classList.contains('open');
        if (expanded) ns.renderCourseView(detailDiv, false);
        setExpanded(expanded);
      });
    }

    body.appendChild(card);
  });
};


  /**
   * 單一課程：檢視模式
   * @param {HTMLElement} div
   * @param {boolean} showAllDates
   */
  ns.renderCourseView = function renderCourseView(div, showAllDates) {
    const c = div._data;
    const allSessions = c._all_sessions || c.sessions || [];
    const visibleSessions = c._visible_sessions || [];
    const sessions = showAllDates ? allSessions : visibleSessions;
    const hasExtra = allSessions.length > visibleSessions.length;

    const outline = c.outline || '';
    let outlineHtml = util.esc(outline);
    try {
      if (w.marked && outline) outlineHtml = w.marked.parse(outline);
    } catch (e) { /* ignore */ }

    let sessionsHtml = '';
    if (!allSessions.length) {
      sessionsHtml = `<span class="value">尚無任何開課時段</span>`;
    } else if (sessions && sessions.length) {
      sessionsHtml = '<ul>';
      sessions.forEach(s => {
        const label = util.formatSessionLabel(s.session_datetime, c.duration_minutes);
        sessionsHtml += '<li>' + util.esc(label) + (s.is_active ? '' : '（已停用）') + '</li>';
      });
      sessionsHtml += '</ul>';
    } else {
      sessionsHtml = `<span class="value">目前篩選無時段</span>`;
    }

    const catText = c.category || util.catCodeToLabel(c.category_code || '');
    const lecText = util.lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
    const isClosed = parseInt(c.is_active, 10) === 0;

    div.innerHTML = `
      <div class="tpma-reg-detail-container">
        <h2 class="text-xl font-semibold mb-4 border-b pb-2">課程代碼：<span id="detail-course-code">${util.esc(c.course_code || 'N/A')}</span> 詳細資料</h2>

        <!-- 區塊 1: 課程基本資料 -->
        <div class="tpma-reg-detail-section" id="section-course-basic">
          <div class="tpma-detail-field">
            <label>課程名稱</label>
            <span class="value">${util.esc(c.course_name || '')}</span>
          </div>
          <div class="tpma-detail-field">
            <label>課程分類</label>
            <span class="value">${util.esc(catText || '-')}</span>
          </div>
          <div class="tpma-detail-field">
            <label>授課講師</label>
            <span class="value">${util.esc(lecText || '-')}</span>
          </div>
          <div class="tpma-detail-field">
            <label>單堂時數</label>
            <span class="value">${c.duration_minutes ? (c.duration_minutes / 60).toFixed(1) + ' 小時' : '-'}</span>
          </div>
          <div class="tpma-detail-field">
            <label>課程狀態</label>
            <span class="value">${isClosed ? '已關閉' : '開啟中'}</span>
          </div>
          <div class="tpma-detail-field">
            <label>最後更新</label>
            <span class="value">${util.esc(c.updated_at || '')}</span>
          </div>
        </div>

        <!-- 區塊 2: 課程簡介與大綱 -->
        <div class="tpma-reg-detail-section" id="section-course-content">
          <div class="tpma-detail-field col-span-full">
            <label>課程簡介</label>
            <span class="value">${util.esc(c.intro || '') || '尚無內容'}</span>
          </div>
          <div class="tpma-detail-field col-span-full">
            <label>課程大綱</label>
            <div class="value tpma-outline-view">${outlineHtml || '尚無內容'}</div>
          </div>
        </div>

        <!-- 區塊 3: 開課時段 -->
        <div class="tpma-reg-detail-section" id="section-course-sessions">
          <div class="tpma-detail-field col-span-full">
            <label>開課時段</label>
            <div class="value tpma-course-dates">
              ${sessionsHtml}
              ${hasExtra && allSessions.length > 0
                ? `<button class="tpma-btn tpma-toggle-dates">${showAllDates ? '收合時段' : '顯示所有時段'}</button>`
                : ''
              }
            </div>
          </div>
        </div>

        <div class="tpma-reg-detail-actions">
          <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-edit-course-${c.id}">編輯課程</button>
        </div>
      </div>
    `;

    const editBtn = div.querySelector(`#tpma-btn-edit-course-${c.id}`);
    if (editBtn) editBtn.onclick = () => ns.renderCourseEdit(div);

    const toggleBtn = div.querySelector('.tpma-toggle-dates');
    if (toggleBtn) toggleBtn.onclick = () => ns.renderCourseView(div, !showAllDates);
  };

  ns.addSessionRow = function addSessionRow(container, raw) {
    let val = '';
    if (raw) {
      const dt = raw.replace('T', ' ').trim();
      const d = util.parseDate(dt.replace(' ', 'T'));
      if (d) {
        const y = d.getFullYear();
        const m = ('0' + (d.getMonth() + 1)).slice(-2);
        const day = ('0' + d.getDate()).slice(-2);
        const hh = ('0' + d.getHours()).slice(-2);
        const mm = ('0' + d.getMinutes()).slice(-2);
        val = `${y}-${m}-${day}T${hh}:${mm}`;
      }
    }
    const row = document.createElement('div');
    row.className = 'tpma-session-row';
    row.innerHTML = `
      <input type="datetime-local" value="${val}">
      <button type="button" class="tpma-btn tpma-session-remove">移除</button>
    `;
    row.querySelector('.tpma-session-remove').onclick = () => row.remove();
    container.appendChild(row);
  };

  /**
   * 單一課程：編輯模式
   * - 會生成表單欄位
   * - 綁定「新增講師」「儲存」「取消」「批次套用場次」等事件
   * @param {HTMLElement} div
   */
/**
   * 單一課程：編輯模式 (修正後的 Grid 版本)
   * - 會生成表單欄位
   * - 綁定「新增講師」「儲存」「取消」「批次套用場次」等事件
   * @param {HTMLElement} div 
   */
  ns.renderCourseEdit = function renderCourseEdit(div) {
    const c = div._data;
    const sessions = c._all_sessions || c.sessions || [];
    const isClosed = parseInt(c.is_active, 10) === 0;

    div.innerHTML = `
      <div class="tpma-reg-detail-container">
        <h2 class="text-xl font-semibold mb-4 border-b pb-2">課程代碼：<span id="detail-course-code">${util.esc(c.course_code || 'N/A')}</span> 編輯資料</h2>

        <div class="tpma-reg-detail-section edit-mode" id="section-course-basic">
          <div class="tpma-detail-field">
            <label>課程代碼（可用講師代碼 + 分類代碼）</label>
            <input type="text" data-field="course_code" value="${util.esc(c.course_code || '')}" placeholder="例如 LEC01-A1">
          </div>
          <div class="tpma-detail-field">
            <label>課程名稱 <span class="tpma-required-label">必填</span></label>
            <input type="text" data-field="course_name" value="${util.esc(c.course_name || '')}">
          </div>
          <div class="tpma-detail-field">
            <label>課程分類 <span class="tpma-required-label">必填</span></label>
            <select data-field="category_code">
              ${dom.filter.cat ? dom.filter.cat.innerHTML : '<option value="">--</option>'}
            </select>
          </div>
          <div class="tpma-detail-field">
            <label>講師 <span class="tpma-required-label">必填</span></label>
            <div class="flex items-center gap-2">
              <select data-field="lecturer_code" class="flex-grow"></select>
              <button type="button" class="tpma-btn tpma-add-lecturer">新增講師</button>
            </div>
          </div>
          <div class="tpma-detail-field">
            <label>單堂時數</label>
            <select data-field="duration_hours">
              <option value="3" ${!c.duration_minutes || c.duration_minutes == 180 ? 'selected' : ''}>3 小時</option>
              <option value="2" ${c.duration_minutes == 120 ? 'selected' : ''}>2 小時</option>
              <option value="4" ${c.duration_minutes == 240 ? 'selected' : ''}>4 小時</option>
              ${c.duration_minutes && ![120, 180, 240].includes(parseInt(c.duration_minutes, 10))
                ? `<option value="${(parseInt(c.duration_minutes, 10) / 60).toFixed(1)}" selected>${(parseInt(c.duration_minutes, 10) / 60).toFixed(1)} 小時</option>` : ''
              }
            </select>
          </div>
          <div class="tpma-detail-field">
            <label>課程狀態</label>
            <select data-field="is_active">
              <option value="1" ${isClosed ? '' : 'selected'}>開啟</option>
              <option value="0" ${isClosed ? 'selected' : ''}>關閉</option>
            </select>
          </div>
        </div>

        <div class="tpma-reg-detail-section edit-mode" id="section-course-content">
          <div class="tpma-detail-field col-span-full">
            <label>課程簡介</label>
            <textarea rows="3" data-field="intro">${util.esc(c.intro || '')}</textarea>
          </div>
          <div class="tpma-detail-field col-span-full">
            <label>課程大綱（Markdown）</label>
            <textarea rows="5" data-field="outline">${util.esc(c.outline || '')}</textarea>
          </div>
        </div>

        <div class="tpma-reg-detail-section edit-mode" id="section-course-sessions">
          <div class="tpma-detail-field col-span-full">
            <label>上課時段</label>
            <div class="tpma-course-dates" data-field="sessions"></div>
            <div class="tpma-bulk mt-4">
              以 YYYY-MM-DD HH:MM 每行一筆<br>
              <textarea rows="3" class="tpma-bulk-input" placeholder="例如 2025-03-01 09:00"></textarea>
              <button type="button" class="tpma-btn tpma-bulk-apply mt-2">套用</button>
            </div>
          </div>
        </div>

        <div class="tpma-reg-detail-actions">
          <button class="tpma-btn" id="tpma-btn-save-course-${c.id}">儲存變更</button>
          <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-cancel-course-edit-${c.id}">取消編輯</button>
        </div>
        <div class="tpma-error tpma-save-error" style="display:none;"></div>
      </div>
    `;

    // 1) 反填分類
    const catSel = div.querySelector('[data-field="category_code"]');
    if (catSel && c.category_code) catSel.value = c.category_code;

    // 2) 反填講師 + 綁新增講師 modal
    const lecSel = div.querySelector('[data-field="lecturer_code"]');
    if (lecSel) {
      ns.rebuildLecturerSelect(lecSel);
      if (c.lecturer_code) lecSel.value = c.lecturer_code;
    }

    // 3) 場次列表
    const datesWrap = div.querySelector('.tpma-course-dates[data-field="sessions"]');
    if (datesWrap) {
      if (sessions.length) {
        sessions.forEach(s => ns.addSessionRow(datesWrap, s.session_datetime));
      } else {
        ns.addSessionRow(datesWrap, '');
      }
    }

    // 4) 批次貼上場次
    const bulkArea = div.querySelector('.tpma-bulk-input');
    const bulkBtn = div.querySelector('.tpma-bulk-apply');
    if (bulkBtn && bulkArea && datesWrap) {
      bulkBtn.onclick = () => {
        const lines = bulkArea.value.split(/\r?\n/).map(l => l.trim()).filter(l => l);
        lines.forEach(line => {
          const m = line.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
          if (m) ns.addSessionRow(datesWrap, m[1] + ' ' + m[2]);
        });
        bulkArea.value = '';
      };
    }

    // 5) 新增講師按鈕
    const addLectBtn = div.querySelector('.tpma-add-lecturer');
    if (addLectBtn && lecSel) {
      addLectBtn.onclick = () => ns.openLecturerModal(lecSel);
    }

    // 6) 儲存事件
    const saveBtn = div.querySelector(`#tpma-btn-save-course-${c.id}`);
    if (saveBtn) saveBtn.onclick = () => ns.saveCourse(div);

    // 7) 取消事件 (核心修正點：尋找 .tpma-course-card 而非 tr)
    const cancelBtn = div.querySelector(`#tpma-btn-cancel-course-edit-${c.id}`);
    if (cancelBtn) {
      cancelBtn.onclick = () => {
        // 如果是「新增課程」產生的臨時卡片 (id 為空或是字串 "undefined")
        if (!c.id || c.id === "undefined") {
          const card = div.closest('.tpma-course-card');
          if (card) card.remove();
        } else {
          // 一般編輯則回歸檢視模式
          ns.renderCourseView(div, false);
        }
      };
    }
  };

})(window);
