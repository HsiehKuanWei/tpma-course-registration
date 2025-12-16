// course-admin.filters.js
// ======================================================================
// 【功能】資料運算層（篩選/模式/日期區間）
// - 讀取篩選器的值
// - 依分類/講師/課程名/關鍵字/日期範圍/模式 過濾課程
// - 產生 _all_sessions / _visible_sessions / _is_closed 供 render 使用
// - 最終呼叫 renderCourses() 更新畫面
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const state = ns.state;
  const dom = ns.dom;
  const util = ns.util;

  /**
   * 依目前篩選條件，計算 filtered courses 並渲染
   */
  ns.applyFilters = function applyFilters() {
    const filters = dom.filter;

    const q = (filters.q?.value || '').trim().toLowerCase();
    const cat = filters.cat?.value || '';
    const lecCode = filters.lec?.value || '';
    const courseName = filters.course?.value || '';
    const df = filters.dateFrom?.value ? new Date(filters.dateFrom.value + 'T00:00:00') : null;
    const dt = filters.dateTo?.value ? new Date(filters.dateTo.value + 'T23:59:59') : null;
    const mode = filters.mode?.value || '';
    const hasDateFilter = !!(df || dt);
    const now = new Date();

    // 先依下拉條件過濾（分類/講師/課程名）
    let filtered = state.allCourses.slice();
    filtered = filtered.filter(c => {
      if (cat && c.category_code !== cat) return false;
      if (lecCode && c.lecturer_code !== lecCode) return false;
      if (courseName && c.course_name !== courseName) return false;
      return true;
    });

    // 課程名稱篩選選單跟著更新（避免選到不存在的名稱）
    ns.buildCourseNameFilter(filtered);
    if (filters.course && filters.course.options && filters.course.options[0]) {
      filters.course.options[0].textContent = '全部課程名稱';
    }

    // 關鍵字搜尋（課程代碼/課程名/分類/講師）
    if (q) {
      filtered = filtered.filter(c => {
        const catLabel = c.category || util.catCodeToLabel(c.category_code || '');
        const lecText = c.lecturer || util.lecturerLabelByCode(c.lecturer_code || '');
        const text = [c.course_code, c.course_name, catLabel, lecText].join(' ').toLowerCase();
        return text.includes(q);
      });
    }

    // 計算各課程可見場次（日期範圍或模式）
    filtered = filtered.map(c => {
      const sessions = Array.isArray(c.sessions) ? c.sessions : [];
      const allSessions = sessions.slice();
      let visibleSessions = sessions.slice();
      const isClosed = parseInt(c.is_active, 10) === 0;

      if (hasDateFilter) {
        visibleSessions = visibleSessions.filter(s => {
          const sd = util.parseDate(s.session_datetime.replace(' ', 'T'));
          if (!sd) return false;
          if (df && sd < df) return false;
          if (dt && sd > dt) return false;
          return true;
        });
      } else if (mode === 'scheduled_future') {
        visibleSessions = visibleSessions.filter(s => {
          const sd = util.parseDate(s.session_datetime.replace(' ', 'T'));
          return sd && sd >= now;
        });
      } else {
        // 預設：只留下能解析的場次（避免壞資料）
        visibleSessions = visibleSessions.filter(s => util.parseDate(s.session_datetime.replace(' ', 'T')));
      }

      return Object.assign({}, c, {
        _all_sessions: allSessions,
        _visible_sessions: visibleSessions,
        _is_closed: isClosed
      });
    });

    // 模式：只看未來有排課 / 只看開啟課程 / 日期範圍下必須有可見場次
    filtered = filtered.filter(c => {
      const hasSessions = (c._all_sessions || []).length > 0;
      const hasFuture = (c._all_sessions || []).some(s => {
        const sd = util.parseDate(s.session_datetime.replace(' ', 'T'));
        return sd && sd >= new Date();
      });

      if (mode === 'scheduled_future') {
        if (!hasFuture) return false;
        if (hasDateFilter && (!c._visible_sessions || !c._visible_sessions.length)) return false;
        return true;
      }

      if (mode === 'open_only' && c._is_closed) return false;

      if (hasDateFilter) {
        if (!hasSessions) return true;
        return c._visible_sessions && c._visible_sessions.length > 0;
      }

      return true;
    });

    const sortField = state.sort?.field || '';
    const sortDir = (state.sort?.dir || 'asc').toLowerCase() === 'desc' ? 'desc' : 'asc';
    if (sortField) {
      const dirFactor = sortDir === 'desc' ? -1 : 1;
      filtered.sort((a, b) => {
        const toText = (x) => (x == null ? '' : String(x));

        let va = '';
        let vb = '';
        if (sortField === 'course_code') {
          va = toText(a.course_code);
          vb = toText(b.course_code);
        } else if (sortField === 'category_code') {
          va = toText(a.category || util.catCodeToLabel(a.category_code || ''));
          vb = toText(b.category || util.catCodeToLabel(b.category_code || ''));
        } else if (sortField === 'course_name') {
          va = toText(a.course_name);
          vb = toText(b.course_name);
        } else if (sortField === 'lecturer_code') {
          va = toText(a.lecturer || util.lecturerLabelByCode(a.lecturer_code || ''));
          vb = toText(b.lecturer || util.lecturerLabelByCode(b.lecturer_code || ''));
        } else {
          return 0;
        }

        return va.localeCompare(vb, 'zh-Hant') * dirFactor;
      });
    }

    ns.renderCourses(filtered);
    if (typeof ns.updateHeaderMenuStates === 'function') ns.updateHeaderMenuStates();
  };

})(window);
