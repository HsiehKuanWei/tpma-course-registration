// course-admin.utils.js
// ======================================================================
// 【功能】放「不碰 DOM、不碰 API、純函式」的工具與格式化
// - HTML 跳脫、日期解析、講師顯示文字、場次日期時間顯示（含結束時間/星期）
// - 注意：此檔不應直接操作畫面、不應直接呼叫 REST API
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const util = ns.util = ns.util || {};

  /**
   * HTML 跳脫：避免把使用者輸入直接塞進 innerHTML 造成 XSS
   * @param {string} s
   * @returns {string}
   */
  util.esc = function esc(s) {
    return (s || '').replace(/[&<>\"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  };

  /**
   * 日期解析：輸入字串無法轉成有效日期時回傳 null
   * @param {string} str
   * @returns {Date|null}
   */
  util.parseDate = function parseDate(str) {
    if (!str) return null;
    const d = new Date(str);
    return isNaN(d.getTime()) ? null : d;
  };

  /**
   * 講師顯示文字：name + title（若有）
   * @param {object} lecturer
   * @returns {string}
   */
  util.lecturerLabel = function lecturerLabel(lecturer) {
    if (!lecturer) return '';
    return lecturer.name + (lecturer.title ? ' ' + lecturer.title : '');
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
    const d = util.parseDate(dtStr.replace(' ', 'T'));
    if (!d) return util.esc(dtStr);

    const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    const y = d.getFullYear();
    const m = ('0' + (d.getMonth() + 1)).slice(-2);
    const day = ('0' + d.getDate()).slice(-2);
    const wd = weekdays[d.getDay()];
    const hh = ('0' + d.getHours()).slice(-2);
    const mm = ('0' + d.getMinutes()).slice(-2);

    const dur = durationMinutes && durationMinutes > 0 ? durationMinutes : 180;
    const end = new Date(d.getTime() + dur * 60000);
    const eh = ('0' + end.getHours()).slice(-2);
    const em = ('0' + end.getMinutes()).slice(-2);

    // 使用 ASCII 括號避免某些環境的編碼問題
    return `${y}-${m}-${day} (${wd}) ${hh}:${mm}~${eh}:${em}`;
  };

})(window);
