//專門負責「跟 WP REST API 溝通」

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const API = global.TPMARegAdmin.api = global.TPMARegAdmin.api || {};
const PublicAPI = global.TPMAPublic.api; // 引入共用 API

API.loadCourses = async function loadCourses(ctx){
  try{
    ctx.data.allCourses = await PublicAPI.getCourses(ctx.apiBase, ctx.nonce);
  }catch(e){
    console.error(e);
    ctx.data.allCourses = [];
  }
};

API.loadRegistrations = async function loadRegistrations(ctx){
  // A receipt-type change is immediately reflected in this list. Add a
  // request marker so browser/proxy caches cannot return the prior row state.
  const url = ctx.apiBase + '/admin/registrations?_tpma_refresh=' + Date.now();
  const list = await PublicAPI.fetchJson(url, { method: 'GET' }, ctx.nonce);
  return Array.isArray(list) ? list : [];
};

API.updateRegistration = async function updateRegistration(ctx, payload){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/registration/update', {
    method: 'POST',
    body: JSON.stringify(payload)
  }, ctx.nonce);

  if (!data || !data.success) {
    const msg = (data && data.message) ? data.message : '更新失敗';
    throw new Error(msg);
  }
  return data;
};

API.bulkRegistrations = async function bulkRegistrations(ctx, payload){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/registrations/bulk', {
    method: 'POST',
    body: JSON.stringify(payload)
  }, ctx.nonce);

  if (!data || data.success === false) {
    const msg = (data && data.message) ? data.message : '批次操作失敗';
    throw new Error(msg);
  }
  return data;
};

API.regeneratePortal = async function regeneratePortal(ctx, regId, regenerate){
  return await PublicAPI.fetchJson(ctx.apiBase + '/admin/magic-links/regenerate', {
    method: 'POST', body: JSON.stringify({
      reg_id: parseInt(regId, 10) || 0,
      regenerate: !!regenerate
    })
  }, ctx.nonce);
};

API.getOrderReceipt = async function getOrderReceipt(ctx, orderId){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/order/' + (parseInt(orderId, 10) || 0), {
    method: 'GET'
  }, ctx.nonce);
  return data && data.receipt ? data.receipt : null;
};

API.generateReceipt = async function generateReceipt(ctx, orderId){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/generate', {
    method: 'POST', body: JSON.stringify({ order_id: parseInt(orderId, 10) || 0 })
  }, ctx.nonce);
  return data && data.receipt ? data.receipt : null;
};

API.mergeReceipts = async function mergeReceipts(ctx, orderIds){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/merge', {
    method: 'POST', body: JSON.stringify({ order_ids: orderIds })
  }, ctx.nonce);
  return data && data.receipt ? data.receipt : null;
};

API.regenerateReceipt = async function regenerateReceipt(ctx, receiptId){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/' + (parseInt(receiptId, 10) || 0) + '/regenerate', {
    method: 'POST', body: JSON.stringify({})
  }, ctx.nonce);
  return data && data.receipt ? data.receipt : null;
};

API.changeReceiptType = async function changeReceiptType(ctx, receiptId, receiptType){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/' + (parseInt(receiptId, 10) || 0) + '/type', {
    method: 'POST', body: JSON.stringify({ receipt_type: receiptType })
  }, ctx.nonce);
  if (!data || !data.success || !data.receipt) {
    throw new Error((data && data.message) ? data.message : '收據方式變更失敗');
  }
  return data.receipt;
};

API.voidReceipt = async function voidReceipt(ctx, receiptId){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/' + (parseInt(receiptId, 10) || 0) + '/void', {
    method: 'POST', body: JSON.stringify({})
  }, ctx.nonce);
  if (!data || !data.success || !data.receipt) {
    throw new Error((data && data.message) ? data.message : '收據作廢失敗');
  }
  return data.receipt;
};

API.sendReceipt = async function sendReceipt(ctx, receiptId, force){
  const data = await PublicAPI.fetchJson(ctx.apiBase + '/admin/receipts/' + (parseInt(receiptId, 10) || 0) + '/send', {
    method: 'POST', body: JSON.stringify({ force: !!force })
  }, ctx.nonce);
  if (!data || !data.success || !data.receipt) {
    throw new Error((data && data.message) ? data.message : '收據寄發失敗');
  }
  return data.receipt;
};

API.uploadReceiptScan = async function uploadReceiptScan(ctx, receiptId, file){
  const form = new FormData();
  form.append('scan', file);
  const res = await fetch(ctx.apiBase + '/admin/receipts/' + (parseInt(receiptId, 10) || 0) + '/scan', {
    method: 'POST', credentials: 'include', headers: { 'X-WP-Nonce': ctx.nonce }, body: form
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data || data.success === false) {
    throw new Error((data && data.message) ? data.message : '上傳收據掃描檔失敗');
  }
  return data.receipt || null;
};

API.receiptBlob = async function receiptBlob(ctx, receiptId, download){
  const suffix = download ? '?download=1' : '';
  const res = await fetch(ctx.apiBase + '/admin/receipts/' + (parseInt(receiptId, 10) || 0) + '/file' + suffix, {
    method: 'GET', credentials: 'include', headers: { 'X-WP-Nonce': ctx.nonce }
  });
  if (!res.ok) {
    const data = await res.json().catch(() => null);
    throw new Error((data && data.message) ? data.message : ('無法讀取收據檔案（HTTP ' + res.status + '）'));
  }
  return await res.blob();
};

API.receiptBulk = async function receiptBulk(ctx, payload){
  const res = await fetch(ctx.apiBase + '/admin/receipts/bulk', {
    method: 'POST', credentials: 'include', headers: {
      'X-WP-Nonce': ctx.nonce,
      'Content-Type': 'application/json'
    }, body: JSON.stringify(payload)
  });
  const type = res.headers.get('content-type') || '';
  if (!res.ok) {
    const data = await res.json().catch(() => null);
    throw new Error((data && data.message) ? data.message : '批次收據操作失敗');
  }
  if (type.indexOf('application/pdf') !== -1) return {
    blob: await res.blob(),
    skipped: parseInt(res.headers.get('X-TPMA-Receipt-Skipped') || '0', 10) || 0
  };
  // 批次生成／重生成可能部分成功；保留伺服器回傳明細讓 UI 顯示失敗項目。
  return await res.json();
};

API.preparePdfWindow = function preparePdfWindow(){
  const popup = global.open('', '_blank');
  if (!popup) {
    throw new Error('瀏覽器封鎖了預覽視窗，請允許此網站開啟新視窗後再試。');
  }
  try { popup.opener = null; } catch (e) {}
  return popup;
};

API.closePdfWindow = function closePdfWindow(popup){
  try {
    if (popup && !popup.closed) popup.close();
  } catch (e) {}
};

API.openPdfBlob = function openPdfBlob(blob, popup){
  if (!(blob instanceof Blob)) throw new Error('收據檔案格式錯誤');
  if (!popup || popup.closed) throw new Error('預覽視窗已關閉，請重新操作。');
  const url = URL.createObjectURL(blob);
  popup.location.href = url;
  global.setTimeout(function(){ URL.revokeObjectURL(url); }, 60000);
};

})(window);
