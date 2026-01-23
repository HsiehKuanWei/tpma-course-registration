//「代碼 → 顯示文字」的對照表

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const L = global.TPMARegAdmin.labels = global.TPMARegAdmin.labels || {};

const enums = global.TPMARegAdmin.enums || {};
const asEnum = (v)=> (v && typeof v === 'object') ? v : null;

L.STATUS_LABELS = asEnum(enums.regStatus) || {};
L.PAYMENT_STATUS_LABELS = asEnum(enums.wcStatus) || {};
L.RECEIPT_TYPE_LABELS = asEnum(enums.receiptType) || {};
L.RECEIPT_STATUS_LABELS = asEnum(enums.receiptStatus) || {};

L.statusLabel = function(code){ return L.STATUS_LABELS[code] || code || ''; };
L.paymentStatusLabel = function(code){ return L.PAYMENT_STATUS_LABELS[code] || code || ''; };
L.receiptTypeLabel = function(code){ return L.RECEIPT_TYPE_LABELS[code] || code || ''; };
L.receiptStatusLabel = function(code){ return L.RECEIPT_STATUS_LABELS[code] || code || ''; };

})(window);
