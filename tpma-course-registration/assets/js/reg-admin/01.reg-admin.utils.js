// Shared wrappers for reg-admin utilities; uses TPMAPublic helpers when available.

(function(global){
'use strict';

const PublicUtil = (global.TPMAPublic = global.TPMAPublic || {}).util || {};

global.TPMARegAdmin = global.TPMARegAdmin || {};
const U = global.TPMARegAdmin.utils = global.TPMARegAdmin.utils || {};

// re-export shared helpers so existing calls keep working
U.esc = PublicUtil.esc;
U.display = PublicUtil.display;
U.trimToMinute = PublicUtil.trimToMinute;
U.formatAmount = PublicUtil.formatAmount;
U.dayNames = PublicUtil.dayNames;
U.safeDate = PublicUtil.safeDate;

//授課場次顯示格式化
U.formatSessionDisplay = function formatSessionDisplay(sessionDatetime, durationMinutes){
  const info = PublicUtil.buildSessionRange(sessionDatetime, durationMinutes);
  if (!info) return '';
  const prefix = info.weekday ? `${info.date}‹¬^${info.weekday}‹¬%` : info.date;
  const range = info.end ? `${info.start}~${info.end}` : info.start;
  return range ? `${prefix} ${range}` : prefix;
};

})(window);
