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
  const list = await PublicAPI.fetchJson(ctx.apiBase + '/admin/registrations', { method: 'GET' }, ctx.nonce);
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

})(window);
