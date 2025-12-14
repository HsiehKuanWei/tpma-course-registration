(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const U = global.TPMARegAdmin.utils = global.TPMARegAdmin.utils || {};

U.esc = function esc(s){
  return (s == null ? '' : String(s)).replace(/[&<>"']/g, function(m){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
  });
};

U.display = function display(v){ return v == null ? '' : String(v); };

U.trimToMinute = function trimToMinute(datetimeStr){
  if (!datetimeStr) return '';
  const s = String(datetimeStr);
  return s.length >= 16 ? s.substring(0,16) : s;
};

U.formatAmount = function formatAmount(val){
  if (val == null || val === '') return '';
  const n = parseFloat(String(val).replace(/,/g,''));
  if (isNaN(n)) return String(val);
  return String(Math.round(n));
};

U.dayNames = ['日','一','二','三','四','五','六'];

U.formatSessionDisplay = function formatSessionDisplay(sessionDatetime, durationMinutes){
  if (!sessionDatetime) return '';
  const d = sessionDatetime.substring(0,10);
  const t = sessionDatetime.substring(11,16);
  const start = new Date(d + 'T' + t + ':00');
  const end = new Date(start.getTime() + (durationMinutes || 0) * 60000);
  const endHH = String(end.getHours()).padStart(2,'0');
  const endMM = String(end.getMinutes()).padStart(2,'0');
  const wd = '日一二三四五六'[start.getDay()];
  return `${d}（${wd}） ${t}~${endHH}:${endMM}`;
};

U.safeDate = function safeDate(s){
  try{
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }catch(e){
    return null;
  }
};

})(window);
