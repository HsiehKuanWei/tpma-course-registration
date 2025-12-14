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

  /**
   * 取得講師清單
   * @returns {Promise<any>}
   */
  ns.apiGetLecturers = async function apiGetLecturers() {
    const res = await fetch(state.apiBase + '/admin/lecturers', {
      credentials: 'include',
      headers: { 'X-WP-Nonce': state.nonce }
    });
    return res.json();
  };

  /**
   * 取得課程清單（含 sessions）
   * @returns {Promise<any>}
   */
  ns.apiGetCourses = async function apiGetCourses() {
    const res = await fetch(state.apiBase + '/admin/courses', {
      credentials: 'include',
      headers: { 'X-WP-Nonce': state.nonce }
    });
    return res.json();
  };

  /**
   * 儲存講師（新增/更新）
   * @param {object} payload
   * @returns {Promise<any>} 回傳 API json
   */
  ns.apiSaveLecturer = async function apiSaveLecturer(payload) {
    const res = await fetch(state.apiBase + '/admin/lecturer/save', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': state.nonce
      },
      credentials: 'include',
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    return { res, json };
  };

  /**
   * 儲存課程（新增/更新）
   * @param {object} payload
   * @returns {Promise<any>} 回傳 API json
   */
  ns.apiSaveCourse = async function apiSaveCourse(payload) {
    const res = await fetch(state.apiBase + '/admin/course/save', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': state.nonce
      },
      credentials: 'include',
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    return { res, json };
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
