//放「不碰 DOM、不碰 API、純函式」的工具與格式化

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const U = global.TPMARegAdmin.utils = global.TPMARegAdmin.utils || {};

//HTML 跳脫處理， 將 &, <, >, ", ' 轉為安全字元， 用於任何會輸出到 innerHTML 的文字

U.esc = function esc(s){
  return (s == null ? '' : String(s)).replace(/[&<>"']/g, function(m){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
  });
};

//將 null / undefined 轉為空字串，其餘轉為字串
U.display = function display(v){ return v == null ? '' : String(v); };

//將 datetime 字串裁切至分鐘
U.trimToMinute = function trimToMinute(datetimeStr){
  if (!datetimeStr) return '';
  const s = String(datetimeStr);
  return s.length >= 16 ? s.substring(0,16) : s;
};

//金額格式化（轉為整數字串），移除逗號並四捨五入
U.formatAmount = function formatAmount(val){
  if (val == null || val === '') return '';
  const n = parseFloat(String(val).replace(/,/g,''));
  if (isNaN(n)) return String(val);
  return String(Math.round(n));
};

//星期顯示對照表（0=日 ～ 6=六）
U.dayNames = ['日','一','二','三','四','五','六'];

//授課場次顯示格式化
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

//安全建立 Date 物件， * 字串無法解析時回傳 null，避免拋錯
U.safeDate = function safeDate(s){
  try{
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }catch(e){
    return null;
  }
};

})(window);
