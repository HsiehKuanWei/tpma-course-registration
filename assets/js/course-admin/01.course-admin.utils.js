// course-admin.utils.js
// ======================================================================
// 課程管理頁面的共享輔助函數。依賴 TPMAPublic（如果存在）。
// 用於轉義和日期/時間格式化。
// ======================================================================

(function (w) {
  'use strict';

  const PublicUtil = (w.TPMAPublic = w.TPMAPublic || {}).util || {};
  const DateUtil = (w.TPMAPublic = w.TPMAPublic || {}).datetime || {};
  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const state = ns.state = ns.state || {};
  const util = ns.util = ns.util || {};

  /**
   * HTML 跳脫：避免把使用者輸入直接塞進 innerHTML 造成 XSS
   * @param {string} s
   * @returns {string}
   */
  util.esc = PublicUtil.esc;

  /**
   * 日期解析：輸入字串無法轉成有效日期時回傳 null
   * @param {string} str
   * @returns {Date|null}
   */
  util.parseDate = PublicUtil.safeDate;

  /**
   * 講師顯示文字：name + title（若有）
   * @param {object} lecturer
   * @returns {string}
   */
  util.lecturerLabel = function lecturerLabel(lecturer) {
    if (!lecturer) return '';
    return PublicUtil.display(lecturer.name) + (lecturer.title ? ' ' + PublicUtil.display(lecturer.title) : '');
  };

  /**
   * 場次顯示文字：YYYY-MM-DD (週) HH:MM~HH:MM
   * - 會依 durationMinutes 計算結束時間
   * - dtStr 可為 "YYYY-MM-DD HH:MM" 或類似可解析格式
   * @param {string} dtStr
   * @param {number} durationMinutes
   * @returns {string}
   */
  util.formatSessionLabel = function formatSessionLabel(dtStr, durationMinutes) {
    if (!dtStr) return '';

    if (DateUtil.formatRange) {
      const formatted = DateUtil.formatRange(dtStr, durationMinutes || 180);
      if (formatted) return formatted;
    }

    const info = PublicUtil.buildSessionRange(dtStr, durationMinutes || 180);
    if (!info) return util.esc(dtStr);
    const range = info.end ? `${info.start}~${info.end}` : info.start;
    const wd = info.weekday ? `（${info.weekday}）` : '';
    return `${info.date}${wd} ${range}`;
  };

  util.getSessionVisibilityLabel = function getSessionVisibilityLabel(mode) {
    if (mode === 'force_show') return '強制顯示';
    if (mode === 'force_hide') return '強制隱藏';
    return '自動判斷';
  };

  util.buildRegUrl = function buildRegUrl(courseId, sessionId) {
    const base = state.formUrl || '';
    if (!base) return '';
    const params = new URLSearchParams();
    if (courseId) params.set('course_id', courseId);
    if (sessionId) params.set('session_id', sessionId);
    const qs = params.toString();
    return qs ? `${base}${base.includes('?') ? '&' : '?'}${qs}` : base;
  };

})(window);
