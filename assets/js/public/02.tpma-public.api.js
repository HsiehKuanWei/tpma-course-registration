// Shared API wrappers for TPMA applications.

(function(global){
'use strict';

global.TPMAPublic = global.TPMAPublic || {};
const API = global.TPMAPublic.api = global.TPMAPublic.api || {};

/**
 * 通用的 JSON fetch 函式，處理錯誤和 nonce。
 * @param {string} url - API 端點 URL。
 * @param {object} options - fetch 選項，包含 method, headers, body 等。
 * @param {string} nonce - WordPress REST API nonce。
 * @returns {Promise<object>} - 解析後的 JSON 資料。
 */
API.fetchJson = async function fetchJson(url, options, nonce){
  const defaultHeaders = {
    'X-WP-Nonce': nonce,
    'Content-Type': 'application/json'
  };

  const fetchOptions = {
    ...options,
    credentials: 'include',
    headers: {
      ...defaultHeaders,
      ...(options && options.headers)
    }
  };

  const res = await fetch(url, fetchOptions);
  if (!res.ok) {
    const errorData = await res.json().catch(() => null);
    const errorMessage = (errorData && errorData.message) ? errorData.message : `HTTP ${res.status}`;
    throw new Error(errorMessage);
  }
  return await res.json();
};

/**
 * 載入所有課程資料。
 * @param {string} apiBase - API 基礎 URL。
 * @param {string} nonce - WordPress REST API nonce。
 * @returns {Promise<Array>} - 課程列表。
 */
API.getCourses = async function getCourses(apiBase, nonce){
  try{
    const list = await API.fetchJson(`${apiBase}/admin/courses`, { method: 'GET' }, nonce);
    return Array.isArray(list) ? list : [];
  }catch(e){
    console.error('Failed to load courses:', e);
    return [];
  }
};

})(window);
