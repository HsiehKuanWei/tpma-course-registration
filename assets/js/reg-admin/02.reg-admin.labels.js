//「代碼 → 顯示文字」的對照表

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const L = global.TPMARegAdmin.labels = global.TPMARegAdmin.labels || {};

L.STATUS_LABELS = {
  cert_pending: '待發證',
  completed:    '已結訓',
  hold:         '保留中',
  hold_refunded: '待退款'
};

L.PAYMENT_STATUS_LABELS = {
  'pending':        '待付款 (WC)',
  'processing':     '待核帳 (WC)',
  'on-hold':        '尚未付款 (WC)',
  'completed':      '已完成 (WC)',
  'cancelled':      '已取消 (WC)',
  'refunded':       '已退款 (WC)',
  'failed':         '失敗 (WC)',
  'checkout-draft': '草稿 (WC)'
};

L.RECEIPT_TYPE_LABELS = { electronic: '電子', paper: '紙本' };

L.RECEIPT_STATUS_LABELS = {
  pending: '待開立',
  auto:    '已開立待寄（自動）',
  manual:  '已開立待寄（手動）',
  sent:    '已寄出'
};

L.statusLabel = function(code){ return L.STATUS_LABELS[code] || code || ''; };
L.paymentStatusLabel = function(code){ return L.PAYMENT_STATUS_LABELS[code] || code || ''; };
L.receiptTypeLabel = function(code){ return L.RECEIPT_TYPE_LABELS[code] || code || ''; };
L.receiptStatusLabel = function(code){ return L.RECEIPT_STATUS_LABELS[code] || code || ''; };

})(window);
