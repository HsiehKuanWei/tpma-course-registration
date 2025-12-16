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
  ns.renderCourses = function renderCourses(list) {
    const tbody = dom.courseList;
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!list || list.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5">沒有符合條件的課程</td></tr>';
      return;
    }

    const catLabel = (c) => c.category || util.catCodeToLabel(c.category_code || '');
    const lecLabel = (c) => util.lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
    const showText = (v) => (v == null || v === '' ? '-' : String(v));

    list.forEach(c => {
      const tr = document.createElement('tr');
      tr.className = 'tpma-course-row';
      tr.dataset.id = c.id || '';

      const tdCode = document.createElement('td');
      tdCode.textContent = showText(c.course_code);
      tr.appendChild(tdCode);

      const tdCat = document.createElement('td');
      tdCat.textContent = showText(catLabel(c));
      tr.appendChild(tdCat);

      const tdName = document.createElement('td');
      tdName.textContent = showText(c.course_name);
      tr.appendChild(tdName);

      const tdLec = document.createElement('td');
      tdLec.textContent = showText(lecLabel(c));
      tr.appendChild(tdLec);

      const tdAct = document.createElement('td');
      tdAct.innerHTML = '<button type="button" class="tpma-btn tpma-view-btn">詳細</button>'
        + '<button type="button" class="tpma-btn tpma-edit-btn">編輯</button>';
      tr.appendChild(tdAct);

      tbody.appendChild(tr);

      const trDetail = document.createElement('tr');
      trDetail.className = 'tpma-course-detail-row';
      trDetail.style.display = 'none';
      trDetail.dataset.id = c.id || '';
      const tdDetail = document.createElement('td');
      tdDetail.className = 'tpma-course-detail-cell';
      tdDetail.colSpan = 5;
      trDetail.appendChild(tdDetail);
      tbody.appendChild(trDetail);

      const detailDiv = document.createElement('div');
      detailDiv.className = 'tpma-course-item';
      detailDiv.dataset.id = c.id || '';
      detailDiv._data = c;
      tdDetail.appendChild(detailDiv);
      ns.renderCourseView(detailDiv, false);

      const viewBtn = tdAct.querySelector('.tpma-view-btn');
      const editBtn = tdAct.querySelector('.tpma-edit-btn');

      const setExpanded = (expanded) => {
        trDetail.style.display = expanded ? '' : 'none';
        if (viewBtn) viewBtn.textContent = expanded ? '收合' : '詳細';
      };

      if (viewBtn) {
        viewBtn.addEventListener('click', () => {
          const expanded = trDetail.style.display === 'none';
          if (expanded) ns.renderCourseView(detailDiv, false);
          setExpanded(expanded);
        });
      }

      if (editBtn) {
        editBtn.addEventListener('click', () => {
          setExpanded(true);
          ns.renderCourseEdit(detailDiv);
        });
      }
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
      sessionsHtml = `<span class="value">尚未設定上課時段</span>`;
    } else if (sessions && sessions.length) {
      sessionsHtml = '<ul>';
      sessions.forEach(s => {
        const label = util.formatSessionLabel(s.session_datetime, c.duration_minutes);
        sessionsHtml += '<li>' + util.esc(label) + (s.is_active ? '' : '（關閉）') + '</li>';
      });
      sessionsHtml += '</ul>';
    } else {
      sessionsHtml = `<span class="value">日期範圍內沒有場次</span>`;
    }

    const catText = c.category || util.catCodeToLabel(c.category_code || '');
    const lecText = util.lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
    const isClosed = parseInt(c.is_active, 10) === 0;

    div.innerHTML = `
      <div class="tpma-tags">
        ${c.course_code ? '<span>課程代碼 ' + util.esc(c.course_code) + '</span>' : ''}
        ${catText ? '<span>分類 ' + util.esc(catText) + '</span>' : ''}
        ${lecText ? '<span>講師 ' + util.esc(lecText) + '</span>' : ''}
        ${isClosed ? '<span style="color:#c00;">[課程已關閉]</span>' : ''}
      </div>

      <label>課程名稱</label>
      <div class="value">${util.esc(c.course_name || '')}</div>

      <label>課程簡介</label>
      <div class="value">${util.esc(c.intro || '')}</div>

      <label>課程大綱（支援 Markdown）</label>
      <div class="tpma-outline-view">${outlineHtml || '<span class="value">尚未填寫</span>'}</div>

      <label>上課時段</label>
      <div class="tpma-course-dates">
        ${sessionsHtml}
        ${hasExtra && allSessions.length > 0
          ? `<button class="tpma-btn tpma-toggle-dates">${showAllDates ? '收合場次' : '顯示全部場次'}</button>`
          : ''
        }
      </div>

      <label>最後更新</label>
      <div class="value">${util.esc(c.updated_at || '')}</div>

      <div class="tpma-row-actions">
        <button class="tpma-btn tpma-edit">編輯</button>
      </div>
    `;

    const editBtn = div.querySelector('.tpma-edit');
    if (editBtn) editBtn.onclick = () => ns.renderCourseEdit(div);

    const toggleBtn = div.querySelector('.tpma-toggle-dates');
    if (toggleBtn) toggleBtn.onclick = () => ns.renderCourseView(div, !showAllDates);
  };

  /**
   * 在編輯畫面新增一列「場次 datetime-local + 移除」輸入
   * @param {HTMLElement} container
   * @param {string} raw 原始 datetime（可為 "YYYY-MM-DD HH:MM" 或 "YYYY-MM-DDTHH:MM"）
   */
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
  ns.renderCourseEdit = function renderCourseEdit(div) {
    const c = div._data;
    const sessions = c._all_sessions || c.sessions || [];
    const isClosed = parseInt(c.is_active, 10) === 0;

    div.innerHTML = `
      <div class="tpma-tags">
        ${isClosed ? '<span style="color:#c00;">[課程已關閉]</span>' : ''}
      </div>

      <label>課程代碼（可用講師代碼 + 分類代碼）</label>
      <input type="text" data-field="course_code" value="${util.esc(c.course_code || '')}" placeholder="例如 LEC01-A1">

      <label>課程名稱 <span class="tpma-required-label">必填</span></label>
      <input type="text" data-field="course_name" value="${util.esc(c.course_name || '')}">

      <label>課程分類 <span class="tpma-required-label">必填</span></label>
      <select data-field="category_code">
        ${dom.filter.cat ? dom.filter.cat.innerHTML : '<option value="">--</option>'}
      </select>

      <label>講師 <span class="tpma-required-label">必填</span></label>
      <div>
        <select data-field="lecturer_code"></select>
        <button type="button" class="tpma-btn tpma-add-lecturer">新增講師</button>
      </div>

      <label>課程簡介</label>
      <textarea rows="3" data-field="intro">${util.esc(c.intro || '')}</textarea>

      <label>課程大綱（Markdown）</label>
      <textarea rows="5" data-field="outline">${util.esc(c.outline || '')}</textarea>

      <label>單堂時數</label>
      <select data-field="duration_hours">
        <option value="3" ${!c.duration_minutes || c.duration_minutes == 180 ? 'selected' : ''}>3 小時</option>
        <option value="2" ${c.duration_minutes == 120 ? 'selected' : ''}>2 小時</option>
        <option value="4" ${c.duration_minutes == 240 ? 'selected' : ''}>4 小時</option>
        ${c.duration_minutes && ![120, 180, 240].includes(parseInt(c.duration_minutes, 10))
          ? `<option value="${(parseInt(c.duration_minutes, 10) / 60).toFixed(1)}" selected>${(parseInt(c.duration_minutes, 10) / 60).toFixed(1)} 小時</option>` : ''
        }
      </select>

      <label>上課時段</label>
      <div class="tpma-course-dates" data-field="sessions"></div>
      <div class="tpma-bulk">
        以 YYYY-MM-DD HH:MM 每行一筆<br>
        <textarea rows="3" class="tpma-bulk-input" placeholder="例如 2025-03-01 09:00"></textarea>
        <button type="button" class="tpma-btn tpma-bulk-apply">套用</button>
      </div>

      <div class="tpma-row-actions">
        <label>課程狀態</label>
        <select data-field="is_active">
          <option value="1" ${isClosed ? '' : 'selected'}>開啟</option>
          <option value="0" ${isClosed ? 'selected' : ''}>關閉</option>
        </select>
      </div>

      <div class="tpma-row-actions">
        <button class="tpma-btn tpma-save">儲存</button>
        <button class="tpma-btn tpma-cancel">取消</button>
        <div class="tpma-error tpma-save-error" style="display:none;"></div>
      </div>
    `;

    // 反填分類
    const catSel = div.querySelector('[data-field="category_code"]');
    if (catSel && c.category_code) catSel.value = c.category_code;

    // 反填講師 + 綁新增講師 modal
    const lecSel = div.querySelector('[data-field="lecturer_code"]');
    if (lecSel) {
      ns.rebuildLecturerSelect(lecSel);
      if (c.lecturer_code) lecSel.value = c.lecturer_code;
    }

    // 場次列表
    const datesWrap = div.querySelector('.tpma-course-dates[data-field="sessions"]');
    if (datesWrap) {
      if (sessions.length) {
        sessions.forEach(s => ns.addSessionRow(datesWrap, s.session_datetime));
      } else {
        ns.addSessionRow(datesWrap, '');
      }
    }

    // 批次貼上場次
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

    const addLectBtn = div.querySelector('.tpma-add-lecturer');
    if (addLectBtn && lecSel) {
      addLectBtn.onclick = () => ns.openLecturerModal(lecSel);
    }

    // 儲存 / 取消
    const saveBtn = div.querySelector('.tpma-save');
    const cancelBtn = div.querySelector('.tpma-cancel');
    if (saveBtn) saveBtn.onclick = () => ns.saveCourse(div);
    if (cancelBtn) cancelBtn.onclick = () => {
      if (!div.dataset.id) {
        const trDetail = div.closest && div.closest('tr.tpma-course-detail-row');
        const tr = trDetail ? trDetail.previousElementSibling : null;
        if (tr && tr.classList && tr.classList.contains('tpma-course-row')) tr.remove();
        if (trDetail) trDetail.remove();
        return;
      }
      ns.renderCourseView(div, false);
    };
  };

})(window);
