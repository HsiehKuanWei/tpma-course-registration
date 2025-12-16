// Shared helpers for admin pages (reg-admin, course-admin, etc.)
// - Safe HTML escaping
// - Common date/time formatting utilities (delegates to TPMAPublic.datetime)
// - Lightweight number/string helpers

(function (global) {
'use strict';

  const ns = global.TPMAPublic = global.TPMAPublic || {};
  const datetime = ns.datetime = ns.datetime || {};
  const util = ns.util = ns.util || {};

  const pad2 = datetime.pad2 || ((n) => (n < 10 ? '0' + n : String(n)));

  /**
   * HTML escape to keep innerHTML safe.
   * @param {string} s
   * @returns {string}
   */
  util.esc = function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>\"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  };

  /**
   * Null/undefined safe display string.
   * @param {any} v
   * @returns {string}
   */
  util.display = function display(v) {
    return v == null ? '' : String(v);
  };

  /**
   * Trim datetime string to minute precision (YYYY-MM-DD HH:MM).
   * @param {string} datetimeStr
   * @returns {string}
   */
  util.trimToMinute = function trimToMinute(datetimeStr) {
    if (!datetimeStr) return '';
    const s = String(datetimeStr);
    return s.length >= 16 ? s.substring(0, 16) : s;
  };

  /**
   * Format amount by stripping commas and rounding.
   * @param {string|number} val
   * @returns {string}
   */
  util.formatAmount = function formatAmount(val) {
    if (val == null || val === '') return '';
    const n = parseFloat(String(val).replace(/,/g, ''));
    if (isNaN(n)) return String(val);
    return String(Math.round(n));
  };

  /**
   * Parse a date string safely; accepts "YYYY-MM-DD HH:MM" or ISO strings.
   * Delegates to TPMAPublic.datetime when available.
   * @param {string} s
   * @returns {Date|null}
   */
  util.safeDate = datetime.safeDate || function safeDate(s) {
    try {
      const d = new Date(String(s).replace(' ', 'T'));
      return isNaN(d.getTime()) ? null : d;
    } catch (e) {
      return null;
    }
  };

  util.dayNames = datetime.dayNames || ['日', '一', '二', '三', '四', '五', '六'];

  /**
   * Build a session range object (date, weekday, start/end HH:MM).
   * Delegates to TPMAPublic.datetime when available.
   * @param {string} dtStr
   * @param {number} durationMinutes
   * @returns {{date:string, weekday:string, start:string, end:string}|null}
   */
  util.buildSessionRange = function buildSessionRange(dtStr, durationMinutes) {
    if (datetime.buildSessionRange) {
      return datetime.buildSessionRange(dtStr, durationMinutes);
    }
    const start = util.safeDate(dtStr);
    if (!start) return null;
    const dur = parseInt(durationMinutes, 10);
    const hasDuration = !isNaN(dur) && dur > 0;
    const end = hasDuration ? new Date(start.getTime() + dur * 60000) : null;

    return {
      date: `${start.getFullYear()}/${pad2(start.getMonth() + 1)}/${pad2(start.getDate())}`,
      weekday: util.dayNames[start.getDay()] || '',
      start: `${pad2(start.getHours())}:${pad2(start.getMinutes())}`,
      end: end ? `${pad2(end.getHours())}:${pad2(end.getMinutes())}` : ''
    };
    };

  /**
   * Dynamically determine the API base URL.
   * Checks URL query param 'api_base', then window.TPMA_API_BASE, then fallback.
   * @param {string} fallbackBase - The default API base from PHP.
   * @returns {string}
   */
  util.getApiBase = function getApiBase(fallbackBase) {
    const qsBase = new URLSearchParams(location.search).get("api_base");
    const windowBase = global.TPMA_API_BASE;
    const defaultFallback = `${location.origin}/wp-json/tpma/v1`;
    return qsBase || windowBase || fallbackBase || defaultFallback;
  };

})(window);
