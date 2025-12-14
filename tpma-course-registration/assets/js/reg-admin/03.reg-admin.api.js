//專門負責「跟 WP REST API 溝通」

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const API = global.TPMARegAdmin.api = global.TPMARegAdmin.api || {};

API.fetchJson = async function fetchJson(url, options){
  const res = await fetch(url, options || {});
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return await res.json();
};

API.loadCourses = async function loadCourses(ctx){
  try{
    const list = await API.fetchJson(ctx.apiBase + '/admin/courses', {
      credentials: 'include',
      headers: { 'X-WP-Nonce': ctx.nonce }
    });
    ctx.data.allCourses = Array.isArray(list) ? list : [];
  }catch(e){
    console.error(e);
    ctx.data.allCourses = [];
  }
};

API.loadRegistrations = async function loadRegistrations(ctx){
  const list = await API.fetchJson(ctx.apiBase + '/admin/registrations', {
    credentials: 'include',
    headers: { 'X-WP-Nonce': ctx.nonce }
  });
  return Array.isArray(list) ? list : [];
};

API.updateRegistration = async function updateRegistration(ctx, payload){
  const res = await fetch(ctx.apiBase + '/admin/registration/update', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': ctx.nonce
    },
    credentials: 'include',
    body: JSON.stringify(payload)
  });
  const data = await res.json().catch(()=>null);
  if (!res.ok || !data || !data.success) {
    const msg = (data && data.message) ? data.message : '更新失敗';
    throw new Error(msg);
  }
  return data;
};

})(window);
