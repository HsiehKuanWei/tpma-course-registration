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
    state.lecturers.filter(l => parseInt(l.is_active, 10) !== 0).forEach(l => {
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
  ns.rebuildLecturerSelect = function rebuildLecturerSelect(sel, selectedCode) {
    if (!sel) return;
    const current = selectedCode || sel.value;
    sel.innerHTML = '<option value="">選擇講師</option>';
    state.lecturers.filter(l => parseInt(l.is_active, 10) !== 0 || l.code === current).forEach(l => {
      const opt = document.createElement('option');
      opt.value = l.code;
      opt.textContent = util.lecturerLabel(l) + (parseInt(l.is_active, 10) === 0 ? '（已停用）' : '');
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
    const tutorOwnsContent = !!c.tutor_enabled;
    const allSessions = c._all_sessions || c.sessions || [];
    const visibleSessions = c._visible_sessions || [];
    const sessions = showAllDates ? allSessions : visibleSessions;
    const hasExtra = allSessions.length > visibleSessions.length;

    const intro = c.intro || '';
    const outline = c.outline || '';
    const introHtml = tutorOwnsContent
      ? (c.intro_rendered || '')
      : util.esc(intro);
    let outlineHtml = tutorOwnsContent
      ? (c.outline_rendered || '')
      : util.esc(outline);
    if (!tutorOwnsContent) {
      try {
        if (w.marked && outline) outlineHtml = w.marked.parse(outline);
      } catch (e) { /* ignore */ }
    }

    let sessionsHtml = '';
    if (!allSessions.length) {
      sessionsHtml = `<span class="value">尚無任何開課時段</span>`;
    } else if (sessions && sessions.length) {
      sessionsHtml = '<ul>';
      sessions.forEach(s => {
        const label = util.formatSessionLabel(s.session_datetime, c.duration_minutes);
        const regUrl = util.buildRegUrl(c.id, s.id);
        const visibility = s.visibility_override || '';
        const visibilityLabel = util.getSessionVisibilityLabel(visibility);
        const visibilityClass = visibility ? ` tpma-session-override-${visibility}` : '';
        const sessionLabelHtml = regUrl
          ? `<a class="tpma-session-link" href="${util.esc(regUrl)}" target="_blank" rel="noopener noreferrer">${util.esc(label)}</a>`
          : util.esc(label);
        sessionsHtml += '<li>'
          + sessionLabelHtml
          + (s.is_active ? '' : '（已停用）')
          + `<span class="tpma-session-override-badge${visibilityClass}">${util.esc(visibilityLabel)}</span>`
          + `<span class="tpma-session-tutor-status">${s.tutor_resources_cleaned_at ? 'Tutor 資源已清理，Google 日曆保留' : (s.tutor_meet_post_id ? 'Meet 已連結' : 'Meet 未建立（展開後可手動重試）')}</span>`
          + (s.recording_available_from && s.recording_available_until
            ? `<span class="tpma-session-recording-status">錄播：${util.esc(s.recording_available_from)} ～ ${util.esc(s.recording_available_until)}</span>`
            : '')
          + '</li>';
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
            <div class="value tpma-rich-content">${introHtml || '尚無內容'}</div>
          </div>
          <div class="tpma-detail-field col-span-full">
            <label>課程大綱</label>
            <div class="value tpma-outline-view tpma-rich-content">${outlineHtml || '尚無內容'}</div>
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
          <button class="tpma-btn tpma-btn-secondary" id="tpma-btn-merge-course-${c.id}">合併課程</button>
          <button class="tpma-btn tpma-btn-danger" id="tpma-btn-remove-course-${c.id}">移除課程</button>
        </div>
      </div>
    `;

    const editBtn = div.querySelector(`#tpma-btn-edit-course-${c.id}`);
    if (editBtn) editBtn.onclick = () => ns.renderCourseEdit(div);

    const mergeBtn = div.querySelector(`#tpma-btn-merge-course-${c.id}`);
    if (mergeBtn) mergeBtn.onclick = () => ns.openMergeCourseModal(c.id);

    const removeBtn = div.querySelector(`#tpma-btn-remove-course-${c.id}`);
    if (removeBtn) removeBtn.onclick = () => ns.removeCourse(c.id);

    const toggleBtn = div.querySelector('.tpma-toggle-dates');
    if (toggleBtn) toggleBtn.onclick = () => ns.renderCourseView(div, !showAllDates);
  };

  ns.addSessionRow = function addSessionRow(container, sessionData) {
    const session = sessionData && typeof sessionData === 'object'
      ? sessionData
      : { session_datetime: sessionData || '', visibility_override: '' };
    const raw = session.session_datetime || '';
    const visibility = session.visibility_override || '';
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
    const row = document.createElement('details');
    row.className = 'tpma-session-row';
    row.open = !session.id;
    row.dataset.sessionId = session.id || '';
    row.dataset.isActive = parseInt(session.is_active, 10) === 0 ? '0' : '1';
    row.dataset.meetLinked = session.tutor_meet_post_id ? '1' : '0';
    row.dataset.topicLinked = session.tutor_topic_id || session.tutor_topic_edit_url ? '1' : '0';
    row.dataset.resourcesCleaned = session.tutor_resources_cleaned_at ? '1' : '0';
    const recordingFrom = (session.recording_available_from || '').replace(' ', 'T').slice(0, 16);
    const recordingUntil = (session.recording_available_until || '').replace(' ', 'T').slice(0, 16);
    const deliveryMode = ['live', 'recorded', 'hybrid'].includes(session.delivery_mode) ? session.delivery_mode : 'live';
    const inactiveBadge = row.dataset.isActive === '0'
      ? '<span class="tpma-session-badge tpma-session-badge-warning">已停用</span>'
      : '';
    const meetBadge = session.tutor_resources_cleaned_at
      ? '<span class="tpma-session-badge tpma-session-badge-warning tpma-session-meet-badge">Tutor 資源已清理，Google 日曆保留</span>'
      : (session.tutor_meet_post_id
      ? '<span class="tpma-session-badge tpma-session-meet-badge tpma-session-badge-success">Meet 已連結</span>'
      : '<span class="tpma-session-badge tpma-session-meet-badge">Meet 未建立（可手動重試）</span>');
    row.innerHTML = `
      <summary class="tpma-session-summary">
        <span class="tpma-session-summary-main">
          <strong class="tpma-session-summary-date">尚未設定日期</strong>
        </span>
        <span class="tpma-session-summary-badges">
          <span class="tpma-session-badge tpma-session-visibility-badge"></span>
          ${meetBadge}
          ${inactiveBadge}
        </span>
      </summary>
      <div class="tpma-session-body">
        <div class="tpma-session-fields">
          <label class="tpma-session-field">
            <span>課程型態</span>
            <select class="tpma-delivery-mode">
              <option value="live" ${deliveryMode === 'live' ? 'selected' : ''}>直播</option>
              <option value="recorded" ${deliveryMode === 'recorded' ? 'selected' : ''}>錄播</option>
              <option value="hybrid" ${deliveryMode === 'hybrid' ? 'selected' : ''}>直播＋錄播</option>
            </select>
          </label>
          <label class="tpma-session-field tpma-session-date-field">
            <span>上課日期與時間</span>
            <input type="datetime-local" value="${val}">
          </label>
          <label class="tpma-session-field">
            <span>前台顯示狀態</span>
            <select class="tpma-session-visibility">
              <option value="" ${visibility === '' ? 'selected' : ''}>自動判斷</option>
              <option value="force_show" ${visibility === 'force_show' ? 'selected' : ''}>強制顯示</option>
              <option value="force_hide" ${visibility === 'force_hide' ? 'selected' : ''}>強制隱藏</option>
            </select>
          </label>
          <label class="tpma-session-field tpma-session-recording-field">
            <span>錄播開始</span>
            <input type="datetime-local" class="tpma-recording-from" value="${util.esc(recordingFrom)}">
          </label>
          <label class="tpma-session-field tpma-session-recording-field">
            <span>錄播截止</span>
            <input type="datetime-local" class="tpma-recording-until" value="${util.esc(recordingUntil)}">
          </label>
        </div>
        <div class="tpma-session-tutor-panel" data-session-id="${util.esc(session.id || '')}">
          <div>
            <span class="tpma-session-panel-label">Tutor LMS</span>
            <span class="tpma-session-tutor-status">${session.tutor_resources_cleaned_at ? 'Tutor 資源已清理，Google 日曆保留' : (session.tutor_meet_post_id ? 'Meet 已連結' : (session.tutor_topic_edit_url ? '場次內容已準備' : '場次內容尚未準備'))}</span>
          </div>
          <div class="tpma-session-tutor-actions">
            <button type="button" class="tpma-btn tpma-btn-outline tpma-session-prepare" ${session.id ? '' : 'disabled'} ${row.dataset.topicLinked === '1' || row.dataset.resourcesCleaned === '1' ? 'hidden' : ''}>建立 Tutor 場次章節</button>
            <button type="button" class="tpma-btn tpma-btn-outline tpma-session-meet" ${session.id ? '' : 'disabled'} ${session.tutor_meet_post_id || row.dataset.resourcesCleaned === '1' ? 'hidden' : ''}>重新嘗試建立／連結 Meet</button>
            <a class="tpma-btn tpma-btn-outline tpma-session-tutor-edit${session.tutor_topic_edit_url ? '' : ' is-disabled'}" href="${util.esc(session.tutor_topic_edit_url || '#')}" target="_blank" rel="noopener noreferrer" aria-disabled="${session.tutor_topic_edit_url ? 'false' : 'true'}">編輯 Tutor 場次</a>
          </div>
        </div>
        <div class="tpma-session-actions">
          <a href="#" class="tpma-btn tpma-btn-outline tpma-session-reg-link" target="_blank" rel="noopener noreferrer">開啟報名表</a>
          <button type="button" class="tpma-btn tpma-btn-danger-outline tpma-session-remove">移除此場次</button>
        </div>
      </div>
    `;
    const dateInput = row.querySelector('.tpma-session-date-field input');
    const deliverySelect = row.querySelector('.tpma-delivery-mode');
    const visibilitySelect = row.querySelector('.tpma-session-visibility');
    const updateSummary = () => {
      const dateLabel = row.querySelector('.tpma-session-summary-date');
      const visibilityBadge = row.querySelector('.tpma-session-visibility-badge');
      const dateValue = dateInput ? dateInput.value : '';
      if (dateLabel) {
        dateLabel.textContent = util.formatSessionHeading(dateValue);
      }
      if (visibilityBadge) {
        visibilityBadge.textContent = util.getSessionVisibilityLabel(visibilitySelect ? visibilitySelect.value : '');
      }
    };
    if (dateInput) dateInput.addEventListener('change', () => {
      updateSummary();
      ns.sortSessionRows(container);
    });
    if (visibilitySelect) visibilitySelect.addEventListener('change', updateSummary);
    updateSummary();
    const updateModeFields = () => {
      const mode = deliverySelect ? deliverySelect.value : 'live';
      row.querySelectorAll('.tpma-session-recording-field').forEach(el => { el.hidden = mode === 'live'; });
      const meetButton = row.querySelector('.tpma-session-meet');
      if (meetButton && row.dataset.meetLinked !== '1') meetButton.hidden = row.dataset.resourcesCleaned === '1';
    };
    if (deliverySelect) deliverySelect.addEventListener('change', updateModeFields);
    updateModeFields();
    const updateRegLink = () => {
      const cardDiv = container.closest('.tpma-course-item');
      const courseId = cardDiv?.dataset?.id || '';
      const href = util.buildRegUrl(courseId, session.id || '');
      const linkEl = row.querySelector('.tpma-session-reg-link');
      if (!linkEl) return;
      if (href) {
        linkEl.href = href;
        linkEl.classList.remove('is-disabled');
        linkEl.setAttribute('aria-disabled', 'false');
      } else {
        linkEl.href = '#';
        linkEl.classList.add('is-disabled');
        linkEl.setAttribute('aria-disabled', 'true');
      }
    };
    updateRegLink();
    const prepareBtn = row.querySelector('.tpma-session-prepare');
    const meetBtn = row.querySelector('.tpma-session-meet');
    const tutorLink = row.querySelector('.tpma-session-tutor-edit');
    const tutorStatus = row.querySelector('.tpma-session-tutor-status');
    if (prepareBtn && session.id) {
      prepareBtn.onclick = async () => {
        const originalText = prepareBtn.textContent;
        prepareBtn.disabled = true;
        prepareBtn.textContent = '準備中…';
        try {
          const result = await ns.apiPrepareTutorSession(session.id);
          if (tutorLink && result.edit_url) {
            tutorLink.href = result.edit_url;
            tutorLink.classList.remove('is-disabled');
            tutorLink.setAttribute('aria-disabled', 'false');
          }
          if (tutorStatus && row.dataset.meetLinked !== '1') tutorStatus.textContent = 'Tutor 場次內容已準備';
          row.dataset.topicLinked = '1';
          prepareBtn.hidden = true;
        } catch (e) {
          w.alert(e.message || '無法準備 Tutor 場次內容');
        } finally {
          prepareBtn.disabled = false;
          prepareBtn.textContent = originalText;
        }
      };
    }
    if (meetBtn && session.id) {
      meetBtn.onclick = async () => {
        const originalText = meetBtn.textContent;
        meetBtn.disabled = true;
        meetBtn.textContent = '處理中…';
        try {
          const cardDiv = container.closest('.tpma-course-item');
          const courseId = cardDiv?.dataset?.id || '';
          const status = await ns.apiGetTutorSessionStatus(courseId);
          const current = (status.sessions || []).find(item => parseInt(item.id, 10) === parseInt(session.id, 10));
          let meetId = 0;
          if (current && Array.isArray(current.candidates) && current.candidates.length > 1) {
            const choices = current.candidates.map(item => `${item.id}: ${item.title}`).join('\n');
            meetId = parseInt(w.prompt(`找到多筆同時間 Meet，請輸入要連結的 ID：\n${choices}`, current.candidates[0].id), 10) || 0;
            if (!meetId) return;
          }
          const result = await ns.apiCreateOrLinkMeet(session.id, meetId);
          row.dataset.meetLinked = '1';
          if (tutorStatus) tutorStatus.textContent = 'Meet 已連結';
          const meetBadgeEl = row.querySelector('.tpma-session-meet-badge');
          if (meetBadgeEl) {
            meetBadgeEl.textContent = 'Meet 已連結';
            meetBadgeEl.classList.add('tpma-session-badge-success');
          }
          meetBtn.hidden = true;
          if (tutorLink && result.topic_edit_url) {
            tutorLink.href = result.topic_edit_url;
            tutorLink.classList.remove('is-disabled');
            tutorLink.setAttribute('aria-disabled', 'false');
          }
          if (result.meet_url) w.alert('Meet 已建立／連結：' + result.meet_url);
        } catch (e) {
          w.alert(e.message || 'Meet 建立／連結失敗');
        } finally {
          meetBtn.disabled = false;
          meetBtn.textContent = originalText;
        }
      };
    }
    row.querySelector('.tpma-session-remove').onclick = () => {
      row.remove();
    };
    container.appendChild(row);
    ns.sortSessionRows(container);
    return row;
  };

  ns.sortSessionRows = function sortSessionRows(container) {
    if (!container) return;
    const rows = Array.from(container.querySelectorAll(':scope > .tpma-session-row'));
    rows.sort((a, b) => {
      const aValue = a.querySelector('.tpma-session-date-field input')?.value || '';
      const bValue = b.querySelector('.tpma-session-date-field input')?.value || '';
      if (!aValue && !bValue) return 0;
      if (!aValue) return 1;
      if (!bValue) return -1;
      return aValue.localeCompare(bValue);
    });
    rows.forEach(row => container.appendChild(row));
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
    const tutorOwnsContent = !!c.tutor_enabled;
    const fieldSuffix = String(c.id || 'new').replace(/[^a-zA-Z0-9_-]/g, '');
    const tutorIntroHtml = c.intro_rendered || '';
    const tutorOutlineHtml = c.outline_rendered || '';
    const topicResources = Array.isArray(c.tutor_topic_resources) ? c.tutor_topic_resources : [];
    const topicResourcesHtml = topicResources.length
      ? topicResources.map(topic => {
          const type = ['general', 'recording', 'quiz'].includes(topic.resource_type) ? topic.resource_type : 'general';
          const quizCount = parseInt(topic.quiz_count, 10) || 0;
          const quizConnection = type !== 'quiz'
            ? ''
            : topic.quiz_connection === 'connected'
              ? `<small class="tpma-topic-resource-status is-connected">測驗已連線（${quizCount} 份）</small>`
              : '<small class="tpma-topic-resource-status is-missing">測驗未連線：請先在 Tutor 建立測驗，再儲存本頁。</small>';
          return `<label class="tpma-topic-resource-row">
            <span>${util.esc(topic.title || `Topic ${topic.topic_id}`)}</span>
            <select class="tpma-topic-resource-select" data-topic-id="${parseInt(topic.topic_id, 10) || 0}">
              <option value="general" ${type === 'general' ? 'selected' : ''}>一般內容（直播／錄播皆可）</option>
              <option value="recording" ${type === 'recording' ? 'selected' : ''}>正式錄播（僅錄播權限）</option>
              <option value="quiz" ${type === 'quiz' ? 'selected' : ''}>測驗（依測驗時窗）</option>
            </select>
            ${quizConnection}
          </label>`;
        }).join('')
      : '<p class="tpma-empty-content">尚無可設定的 Tutor 章節；場次章節由系統自動管理。</p>';

    div.innerHTML = `
      <div class="tpma-reg-detail-container tpma-course-edit-container">
        <h2 class="text-xl font-semibold mb-4 border-b pb-2">課程代碼：<span id="detail-course-code">${util.esc(c.course_code || 'N/A')}</span> 編輯資料</h2>

        <div class="tpma-reg-detail-section edit-mode tpma-course-basic-grid" id="section-course-basic">
          <div class="tpma-section-heading col-span-full">
            <h3>基本資料</h3>
            <p>設定課程識別、分類、講師與開放狀態。</p>
          </div>
          <div class="tpma-detail-field">
            <label for="tpma-course-code-${fieldSuffix}">課程代碼（可用講師代碼 + 分類代碼）</label>
            <input id="tpma-course-code-${fieldSuffix}" type="text" data-field="course_code" value="${util.esc(c.course_code || '')}" placeholder="例如 LEC01-A1">
          </div>
          <div class="tpma-detail-field">
            <label for="tpma-course-name-${fieldSuffix}">課程名稱 <span class="tpma-required-label">必填</span></label>
            <input id="tpma-course-name-${fieldSuffix}" type="text" data-field="course_name" value="${util.esc(c.course_name || '')}">
          </div>
          <div class="tpma-detail-field">
            <label for="tpma-course-category-${fieldSuffix}">課程分類 <span class="tpma-required-label">必填</span></label>
            <select id="tpma-course-category-${fieldSuffix}" data-field="category_code">
              ${dom.filter.cat ? dom.filter.cat.innerHTML : '<option value="">--</option>'}
            </select>
          </div>
          <div class="tpma-detail-field">
            <label for="tpma-course-lecturer-${fieldSuffix}">講師 <span class="tpma-required-label">必填</span></label>
            <div class="tpma-lecturer-control">
              <select id="tpma-course-lecturer-${fieldSuffix}" data-field="lecturer_code" class="flex-grow"></select>
              <div class="tpma-inline-actions">
                <button type="button" class="tpma-btn tpma-btn-outline tpma-add-lecturer">新增講師</button>
                <button type="button" class="tpma-btn tpma-btn-danger-outline tpma-remove-lecturer">移除講師</button>
              </div>
            </div>
          </div>
          <div class="tpma-detail-field">
            <label for="tpma-course-duration-${fieldSuffix}">單堂時數</label>
            <select id="tpma-course-duration-${fieldSuffix}" data-field="duration_hours">
              <option value="3" ${!c.duration_minutes || c.duration_minutes == 180 ? 'selected' : ''}>3 小時</option>
              <option value="2" ${c.duration_minutes == 120 ? 'selected' : ''}>2 小時</option>
              <option value="4" ${c.duration_minutes == 240 ? 'selected' : ''}>4 小時</option>
              ${c.duration_minutes && ![120, 180, 240].includes(parseInt(c.duration_minutes, 10))
                ? `<option value="${(parseInt(c.duration_minutes, 10) / 60).toFixed(1)}" selected>${(parseInt(c.duration_minutes, 10) / 60).toFixed(1)} 小時</option>` : ''
              }
            </select>
          </div>
          <div class="tpma-detail-field">
            <label for="tpma-course-active-${fieldSuffix}">課程狀態</label>
            <select id="tpma-course-active-${fieldSuffix}" data-field="is_active">
              <option value="1" ${isClosed ? '' : 'selected'}>開啟</option>
              <option value="0" ${isClosed ? 'selected' : ''}>關閉</option>
            </select>
          </div>
        </div>

        <div class="tpma-reg-detail-section edit-mode" id="section-course-content">
          ${tutorOwnsContent ? `
            <div class="col-span-full tpma-tutor-content-notice">
              <div class="tpma-tutor-content-header">
                <div>
                  <strong>內容由 Tutor LMS 管理</strong>
                  <p>此處提供格式化預覽，內容修改請前往 Tutor。</p>
                </div>
                ${c.tutor_edit_url ? `<a class="tpma-btn" href="${util.esc(c.tutor_edit_url)}" target="_blank" rel="noopener noreferrer">前往 Tutor 編輯</a>` : '<span class="tpma-tutor-content-pending">請先儲存課程以建立 Tutor 課程</span>'}
              </div>
              ${c.tutor_content_sync_error ? `<p class="tpma-error" role="alert">${util.esc(c.tutor_content_sync_error)}</p>` : ''}
            </div>
            <input type="hidden" data-field="intro" value="${util.esc(c.intro || '')}">
            <input type="hidden" data-field="outline" value="${util.esc(c.outline || '')}">
            <section class="tpma-content-preview col-span-full" aria-labelledby="tpma-intro-heading-${fieldSuffix}">
              <h3 id="tpma-intro-heading-${fieldSuffix}">課程簡介</h3>
              <div class="tpma-rich-content">${tutorIntroHtml || '<p class="tpma-empty-content">尚無內容</p>'}</div>
            </section>
            <section class="tpma-content-preview col-span-full" aria-labelledby="tpma-outline-heading-${fieldSuffix}">
              <h3 id="tpma-outline-heading-${fieldSuffix}">課程大綱</h3>
              <div class="tpma-rich-content">${tutorOutlineHtml || '<p class="tpma-empty-content">尚無內容</p>'}</div>
            </section>` : `
            <div class="tpma-section-heading col-span-full">
              <h3>課程內容</h3>
              <p>簡介使用純文字；大綱支援 Markdown。</p>
            </div>
            <div class="tpma-detail-field col-span-full">
              <label for="tpma-course-intro-${fieldSuffix}">課程簡介</label>
              <textarea id="tpma-course-intro-${fieldSuffix}" rows="3" data-field="intro">${util.esc(c.intro || '')}</textarea>
            </div>
            <div class="tpma-detail-field col-span-full">
              <label for="tpma-course-outline-${fieldSuffix}">課程大綱（Markdown）</label>
              <textarea id="tpma-course-outline-${fieldSuffix}" rows="5" data-field="outline">${util.esc(c.outline || '')}</textarea>
            </div>`}
        </div>

        ${tutorOwnsContent ? `<div class="tpma-reg-detail-section edit-mode" id="section-topic-resources">
          <div class="tpma-section-heading col-span-full">
            <h3>Tutor 章節權限</h3>
            <p>含影片的章節預設仍是一般內容；只有明確指定「正式錄播」才會對直播學員隱藏。</p>
          </div>
          <div class="tpma-topic-resource-list col-span-full">${topicResourcesHtml}</div>
        </div>` : ''}

        <div class="tpma-reg-detail-section edit-mode" id="section-course-sessions">
          <div class="tpma-detail-field col-span-full">
            <div class="tpma-section-heading">
              <h3>上課場次</h3>
              <p>展開個別卡片設定錄播、Tutor 與報名操作。</p>
            </div>
            <div class="tpma-course-dates" data-field="sessions"></div>
            <button type="button" class="tpma-session-add" aria-label="新增場次">
              <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              <span>新增場次</span>
            </button>
            <div class="tpma-bulk tpma-bulk-session-entry mt-4">
              <label for="tpma-course-bulk-${fieldSuffix}">批次新增場次</label>
              <p>每行輸入一筆，格式為 YYYY-MM-DD HH:MM。</p>
              <textarea id="tpma-course-bulk-${fieldSuffix}" rows="3" class="tpma-bulk-input" placeholder="例如 2025-03-01 09:00"></textarea>
              <button type="button" class="tpma-btn tpma-btn-outline tpma-bulk-apply mt-2">套用場次</button>
            </div>
          </div>
        </div>

        <div class="tpma-reg-detail-actions tpma-course-edit-actions">
          <button class="tpma-btn" id="tpma-btn-save-course-${c.id}">儲存變更</button>
          <button class="tpma-btn tpma-btn-outline" id="tpma-btn-cancel-course-edit-${c.id}">取消編輯</button>
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
      ns.rebuildLecturerSelect(lecSel, c.lecturer_code || '');
      if (c.lecturer_code) lecSel.value = c.lecturer_code;
    }

    // 3) 場次列表
    const datesWrap = div.querySelector('.tpma-course-dates[data-field="sessions"]');
    if (datesWrap) {
      if (sessions.length) {
        sessions.forEach(s => ns.addSessionRow(datesWrap, s));
      } else {
        ns.addSessionRow(datesWrap, { session_datetime: '', visibility_override: '' });
      }
    }

    const addSessionBtn = div.querySelector('.tpma-session-add');
    if (addSessionBtn && datesWrap) {
      addSessionBtn.onclick = () => {
        const row = ns.addSessionRow(datesWrap, {
          session_datetime: '',
          delivery_mode: 'live',
          visibility_override: '',
          is_active: 1
        });
        const dateInput = row?.querySelector('.tpma-session-date-field input');
        if (dateInput) dateInput.focus();
      };
    }

    // 4) 批次貼上場次
    const bulkArea = div.querySelector('.tpma-bulk-input');
    const bulkBtn = div.querySelector('.tpma-bulk-apply');
    if (bulkBtn && bulkArea && datesWrap) {
      bulkBtn.onclick = () => {
        const lines = bulkArea.value.split(/\r?\n/).map(l => l.trim()).filter(l => l);
        lines.forEach(line => {
          const m = line.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
          if (m) ns.addSessionRow(datesWrap, { session_datetime: m[1] + ' ' + m[2], visibility_override: '' });
        });
        bulkArea.value = '';
      };
    }

    // 5) 新增講師按鈕
    const addLectBtn = div.querySelector('.tpma-add-lecturer');
    if (addLectBtn && lecSel) {
      addLectBtn.onclick = () => ns.openLecturerModal(lecSel);
    }

    const removeLectBtn = div.querySelector('.tpma-remove-lecturer');
    if (removeLectBtn && lecSel) {
      removeLectBtn.onclick = () => ns.removeSelectedLecturer(lecSel);
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
