// course-admin.ui-events.js
// ======================================================================
// 【功能】事件綁定（UI 行為）
// - 綁定所有篩選器 input/change → applyFilters()
// - 重設篩選條件
// - 新增空白課程卡片並切到編輯模式
// - modal 取消/背景點擊/儲存按鈕
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const dom = ns.dom;

  /**
   * 綁定頁面事件
   */
  ns.initEvents = function initEvents() {
    const filters = dom.filter;

    // 篩選器：任一欄位變更就重新套用篩選
    [filters.q, filters.cat, filters.lec, filters.course, filters.dateFrom, filters.dateTo, filters.mode].forEach(el => {
      if (!el) return;
      el.addEventListener('input', ns.applyFilters);
      el.addEventListener('change', ns.applyFilters);
    });

    // 重設篩選
    if (dom.buttons.reset) {
      dom.buttons.reset.addEventListener('click', () => {
        filters.q.value = '';
        filters.cat.value = '';
        filters.lec.value = '';
        filters.course.value = '';
        filters.dateFrom.value = '';
        filters.dateTo.value = '';
        filters.mode.value = 'open_only';
        ns.applyFilters();
      });
    }

    // 新增課程（建立空白資料，prepend 一張卡片並進入編輯）
    if (dom.buttons.addCourse && dom.courseList) {
      dom.buttons.addCourse.addEventListener('click', () => {
        const empty = {
          id: '',
          course_code: '',
          course_name: '',
          category: '',
          category_code: '',
          lecturer: '',
          lecturer_code: '',
          intro: '',
          outline: '',
          updated_at: '',
          is_active: 1,
          duration_minutes: 180,
          sessions: [],
          _all_sessions: [],
          _visible_sessions: []
        };
        const div = document.createElement('div');
        div.className = 'tpma-course-item';
        div.dataset.id = '';
        div._data = empty;
        dom.courseList.prepend(div);
        ns.renderCourseEdit(div);
      });
    }

    // modal 按鈕
    if (dom.modal.btnCancel) dom.modal.btnCancel.addEventListener('click', ns.closeLecturerModal);
    if (dom.modal.backdrop) dom.modal.backdrop.addEventListener('click', ns.closeLecturerModal);
    if (dom.modal.btnSave) dom.modal.btnSave.addEventListener('click', ns.saveLecturerFromModal);
  };

})(window);
