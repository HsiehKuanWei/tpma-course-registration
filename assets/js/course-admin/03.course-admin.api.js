// course-admin.api.js
// ======================================================================
// 【功能】API 層（只管與 WP REST 溝通）
// - 取得講師清單、課程清單
// - 儲存課程
// - 儲存講師
// - 成功後只回傳資料，是否更新畫面由其他模組決定
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const state = ns.state;
  const PublicAPI = w.TPMAPublic.api; // 引入共用 API

  /**
   * 取得講師清單
   * @returns {Promise<any>}
   */
  ns.apiGetLecturers = async function apiGetLecturers() {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/lecturers', { method: 'GET' }, state.nonce);
  };

  /**
   * 取得課程清單（含 sessions）
   * @returns {Promise<any>}
   */
  ns.apiGetCourses = async function apiGetCourses() {
    return await PublicAPI.getCourses(state.apiBase, state.nonce);
  };

  /**
   * 儲存講師（新增/更新）
   * @param {object} payload
   * @returns {Promise<any>} 回傳 API json
   */
  ns.apiSaveLecturer = async function apiSaveLecturer(payload) {
    const json = await PublicAPI.fetchJson(state.apiBase + '/admin/lecturer/save', {
      method: 'POST',
      body: JSON.stringify(payload)
    }, state.nonce);
    return { res: { ok: true }, json }; // 模擬 res.ok 以符合原有的 { res, json } 回傳格式
  };

  /**
   * 儲存課程（新增/更新）
   * @param {object} payload
   * @returns {Promise<any>} 回傳 API json
   */
  ns.apiSaveCourse = async function apiSaveCourse(payload) {
    const json = await PublicAPI.fetchJson(state.apiBase + '/admin/course/save', {
      method: 'POST',
      body: JSON.stringify(payload)
    }, state.nonce);
    return { res: { ok: true }, json }; // 模擬 res.ok 以符合原有的 { res, json } 回傳格式
  };

  ns.apiRemoveCourse = async function apiRemoveCourse(id) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/course/remove', {
      method: 'POST',
      body: JSON.stringify({ id: id })
    }, state.nonce);
  };

  ns.apiRestoreCourse = async function apiRestoreCourse(id) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/course/restore', {
      method: 'POST',
      body: JSON.stringify({ id: id })
    }, state.nonce);
  };

  ns.apiMergeCourse = async function apiMergeCourse(sourceId, targetId) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/course/merge', {
      method: 'POST',
      body: JSON.stringify({ source_id: sourceId, target_id: targetId })
    }, state.nonce);
  };

  ns.apiGetTutorSessionStatus = async function apiGetTutorSessionStatus(courseId) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/tutor/session/status?course_id=' + encodeURIComponent(courseId), { method: 'GET' }, state.nonce);
  };

  ns.apiPrepareTutorSession = async function apiPrepareTutorSession(sessionId) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/tutor/session/prepare', {
      method: 'POST', body: JSON.stringify({ session_id: sessionId })
    }, state.nonce);
  };

  ns.apiCreateOrLinkMeet = async function apiCreateOrLinkMeet(sessionId, meetPostId) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/tutor/session/meet', {
      method: 'POST', body: JSON.stringify({ session_id: sessionId, meet_post_id: meetPostId || 0 })
    }, state.nonce);
  };

  ns.apiRemoveLecturer = async function apiRemoveLecturer(id) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/lecturer/remove', {
      method: 'POST',
      body: JSON.stringify({ id: id })
    }, state.nonce);
  };

  ns.apiRestoreLecturer = async function apiRestoreLecturer(id) {
    return await PublicAPI.fetchJson(state.apiBase + '/admin/lecturer/restore', {
      method: 'POST',
      body: JSON.stringify({ id: id })
    }, state.nonce);
  };

  /**
   * 重新載入講師 + 課程
   * - 會更新 state.lecturers / state.allCourses
   * - 之後由呼叫端決定是否 applyFilters / render
   */
  ns.fetchAll = async function fetchAll() {
    const [lecturers, courses] = await Promise.all([
      ns.apiGetLecturers(),
      ns.apiGetCourses()
    ]);
    state.lecturers = lecturers;
    state.allCourses = courses;
    return { lecturers, courses };
  };

})(window);
