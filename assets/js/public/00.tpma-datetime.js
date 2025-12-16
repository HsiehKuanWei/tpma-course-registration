(function (global) {
'use strict';

  const root = global.TPMAPublic = global.TPMAPublic || {};
  const datetime = root.datetime = root.datetime || {};

  const dayNames = ['日', '一', '二', '三', '四', '五', '六'];
  const pad2 = (n) => (n < 10 ? '0' + n : String(n));

  datetime.dayNames = dayNames.slice();
  datetime.pad2 = pad2;

  /**
   * Parse datetime string safely; accepts "YYYY-MM-DD HH:MM" or ISO strings.
   * @param {string} s
   * @returns {Date|null}
   */
  datetime.safeDate = function safeDate(s) {
    try {
      const d = new Date(String(s || '').replace(' ', 'T'));
      return isNaN(d.getTime()) ? null : d;
    } catch (e) {
      return null;
    }
  };

  /**
   * Build a session range object (date, weekday, start/end HH:MM).
   * @param {string} dtStr
   * @param {number} durationMinutes
   * @returns {{date:string, weekday:string, start:string, end:string}|null}
   */
  datetime.buildSessionRange = function buildSessionRange(dtStr, durationMinutes) {
    const start = datetime.safeDate(dtStr);
    if (!start) return null;
    const dur = parseInt(durationMinutes, 10);
    const hasDuration = !isNaN(dur) && dur > 0;
    const end = hasDuration ? new Date(start.getTime() + dur * 60000) : null;

    return {
      date: `${start.getFullYear()}/${pad2(start.getMonth() + 1)}/${pad2(start.getDate())}`,
      weekday: dayNames[start.getDay()] || '',
      start: `${pad2(start.getHours())}:${pad2(start.getMinutes())}`,
      end: end ? `${pad2(end.getHours())}:${pad2(end.getMinutes())}` : ''
    };
  };

  /**
   * Format datetime with optional duration using the standard pattern:
   * YYYY/MM/DD（Week）HH:MM~HH:MM
   * When duration is missing/0 => YYYY/MM/DD（Week）HH:MM
   * @param {string} dtStr
   * @param {number} durationMinutes
   * @param {{multiLine?:boolean, separator?:string}} [opts]
   * @returns {string}
   */
  datetime.formatRange = function formatRange(dtStr, durationMinutes, opts) {
    const info = datetime.buildSessionRange(dtStr, durationMinutes);
    if (!info) return '';
    const sep = (opts && opts.multiLine) ? (opts.separator || '\n') : ' ';
    const prefix = info.weekday ? `${info.date}（${info.weekday}）` : info.date;
    const timePart = info.end ? `${info.start}~${info.end}` : info.start;
    return timePart ? `${prefix}${sep}${timePart}` : prefix;
  };

  /**
   * Format datetime without duration: YYYY/MM/DD（Week）HH:MM
   * @param {string} dtStr
   * @param {{multiLine?:boolean, separator?:string}} [opts]
   * @returns {string}
   */
  datetime.formatSingle = function formatSingle(dtStr, opts) {
    return datetime.formatRange(dtStr, 0, opts);
  };

  /**
   * Get timestamp from date string; returns 0 for invalid.
   * @param {string} dtStr
   * @returns {number}
   */
  datetime.getTimestamp = function getTimestamp(dtStr) {
    const d = datetime.safeDate(dtStr);
    return d ? d.getTime() : 0;
  };

})(window);