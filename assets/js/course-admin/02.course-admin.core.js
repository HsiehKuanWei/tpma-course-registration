// course-admin.core.js
// ======================================================================
// 【功能】核心命名空間 / 狀態 / DOM 快取 / 設定注入
// - 建立 TPMACourseAdmin namespace
// - 集中保存 state（API base、nonce、課程清單、講師清單…）
// - 快取管理頁面會用到的 DOM 節點（避免每次 querySelector）
// - 提供部分「需讀 state/dom」的 helper（例如代碼轉顯示文字）
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};

  /**
   * 共享狀態：其他模組都從這裡讀寫
   */
  const state = ns.state = ns.state || {
    apiBase: '',
    nonce: '',
    allCourses: [],
    lecturers: [],
    currentLecturerTargetSelect: null,
    sort: { field: '', dir: 'asc' }
  };

  /**
   * DOM 快取：cacheDom() 後把常用節點放這裡
   */
  const dom = ns.dom = ns.dom || {};

  /**
   * 工具：由 course-admin.utils.js 提供（此處確保存在）
   */
  const util = ns.util = ns.util || {};

  /**
   * 分類代碼 → 顯示文字（從分類下拉選單的 option 取得）
   * @param {string} code
   * @returns {string}
   */
  util.catCodeToLabel = function catCodeToLabel(code) {
    const catSelect = dom.filter?.cat;
    if (!catSelect || !code) return '';
    const opt = catSelect.querySelector('option[value="' + code + '"]');
    return opt ? (opt.textContent || '') : '';
  };

  /**
   * 講師代碼 → 顯示文字
   * @param {string} code
   * @returns {string}
   */
  util.lecturerLabelByCode = function lecturerLabelByCode(code) {
    if (!code) return '';
    const l = state.lecturers.find(x => x.code === code);
    return l ? util.lecturerLabel(l) : '';
  };

  /**
   * 注入設定（通常由 wp_localize_script 提供）
   * @param {{apiBase?:string, nonce?:string}} config
   */
  ns.setConfig = function setConfig(config) {
    state.apiBase = (config && config.apiBase) ? config.apiBase : '';
    state.nonce = (config && config.nonce) ? config.nonce : '';
  };

  /**
   * 快取 DOM 節點（只在初始化跑一次）
   * - 管理頁容器
   * - 篩選器
   * - 按鈕
   * - 講師新增 modal 元件
   */
  ns.cacheDom = function cacheDom() {
    dom.wrap = document.getElementById('tpma-course-admin');
    dom.courseTable = document.getElementById('tpma-course-table');
    dom.courseList = document.getElementById('tpma-course-tbody');

    dom.filter = {
      q: document.getElementById('tpma-filter-q'),
      cat: document.getElementById('tpma-filter-category'),
      lec: document.getElementById('tpma-filter-lecturer'),
      course: document.getElementById('tpma-filter-course'),
      dateFrom: document.getElementById('tpma-filter-date-from'),
      dateTo: document.getElementById('tpma-filter-date-to'),
      mode: document.getElementById('tpma-filter-mode')
    };

    dom.buttons = {
      reset: document.getElementById('tpma-reset-filter'),
      addCourse: document.getElementById('tpma-add-course')
    };

    dom.header = {
      menuButtons: Array.from(document.querySelectorAll('.tpma-th-menu-btn')),
      menus: Array.from(document.querySelectorAll('.tpma-th-menu')),
      clearCategory: document.getElementById('tpma-btn-clear-category'),
      clearCourse: document.getElementById('tpma-btn-clear-course'),
      clearLecturer: document.getElementById('tpma-btn-clear-lecturer')
    };

    dom.modal = {
      backdrop: document.getElementById('tpma-lecturer-backdrop'),
      box: document.getElementById('tpma-lecturer-modal'),
      code: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-code'),
      name: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-name'),
      title: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-title'),
      sort: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-sort'),
      error: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-error'),
      btnSave: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-save-btn'),
      btnCancel: document.getElementById('tpma-lecturer-modal').querySelector('#tpma-lect-cancel-btn') // This will select the first one, which is in the header
    };
  };

})(window);
