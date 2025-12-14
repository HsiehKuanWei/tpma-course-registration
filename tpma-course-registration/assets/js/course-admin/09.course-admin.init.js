// course-admin.init.js
// ======================================================================
// 【功能】啟動入口（相當於 reg-admin-init 的角色）
// - setConfig()：讀取 wp_localize_script 注入的設定
// - cacheDom()：快取 DOM
// - initEvents()：綁定事件
// - fetchAll()：初次載入資料 → buildLecturerFilter() → applyFilters()
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const dom = ns.dom;

  /**
   * 初始化（供外部呼叫）
   * @param {object} config
   */
  ns.init = async function init(config) {
    ns.setConfig(config || {});
    ns.cacheDom();
    ns.initEvents();

    try {
      await ns.fetchAll();
      ns.buildLecturerFilter();
      ns.applyFilters();
    } catch (e) {
      console.error(e);
      if (dom.courseList) dom.courseList.innerHTML = '<p>載入失敗，請稍後再試</p>';
    }
  };

  // 若 wp_localize_script 已提供 config，直接自動啟動
  if (w.TPMACourseAdminConfig) {
    ns.init(w.TPMACourseAdminConfig);
  }

})(window);
