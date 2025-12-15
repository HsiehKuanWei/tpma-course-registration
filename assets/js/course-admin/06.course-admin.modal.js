// course-admin.modal.js
// ======================================================================
// 【功能】講師新增/編輯 Modal 的 UI 行為
// - 開啟/關閉 modal
// - 讀取 modal 表單欄位並送出儲存（透過 apiSaveLecturer）
// - 成功後更新 state.lecturers、重建相關 select/filter
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const state = ns.state;
  const dom = ns.dom;
  const util = ns.util;

  /**
   * 開啟「新增講師」modal
   * @param {HTMLSelectElement|null} targetSelect 儲存成功後要回填的講師 select
   */
  ns.openLecturerModal = function openLecturerModal(targetSelect) {
    state.currentLecturerTargetSelect = targetSelect || null;
    const m = dom.modal;
    if (!m.box) return;

    // 清空欄位
    m.code.value = '';
    m.name.value = '';
    m.title.value = '';
    m.sort.value = '';
    m.error.style.display = 'none';
    m.error.textContent = '';

    m.backdrop.style.display = 'block';
    m.box.style.display = 'block';
    m.code.focus();
  };

  /**
   * 關閉 modal
   */
  ns.closeLecturerModal = function closeLecturerModal() {
    const m = dom.modal;
    if (!m.box) return;
    m.backdrop.style.display = 'none';
    m.box.style.display = 'none';
    state.currentLecturerTargetSelect = null;
  };

  /**
   * 從 modal 儲存講師
   * - 會做必填檢查、排序衝突處理
   * - 成功：更新 state.lecturers、重建 filter/select、回填選取值
   */
  ns.saveLecturerFromModal = async function saveLecturerFromModal() {
    const m = dom.modal;
    if (!m.box) return;

    m.error.style.display = 'none';
    m.error.textContent = '';

    const code = m.code.value.trim();
    const name = m.name.value.trim();
    const title = m.title.value.trim();
    const sortStr = m.sort.value.trim();

    if (!code || !name) {
      m.error.textContent = '請填寫講師代碼與姓名';
      m.error.style.display = 'block';
      return;
    }

    let sortVal = null;
    if (sortStr !== '') {
      sortVal = parseInt(sortStr, 10);
      if (isNaN(sortVal)) {
        m.error.textContent = '排序需為數字';
        m.error.style.display = 'block';
        return;
      }
    }

    // 若排序衝突，詢問是否往後推
    let shiftSort = 0;
    if (sortVal !== null) {
      const hasConflict = state.lecturers.some(l => parseInt(l.sort_order, 10) === sortVal);
      if (hasConflict) {
        const ok = w.confirm(`排序 ${sortVal} 已存在，是否將既有排序往後推一位？`);
        if (!ok) {
          m.error.textContent = '已取消新增講師';
          m.error.style.display = 'block';
          return;
        }
        shiftSort = 1;
      }
    }

    try {
      const { res, json } = await ns.apiSaveLecturer({
        code: code,
        name: name,
        title: title,
        sort_order: sortVal !== null ? sortVal : null,
        shift_sort: shiftSort
      });

      if (!res.ok || !json || !json.success) {
        const msg = (json && json.message) ? json.message : '儲存失敗';
        m.error.textContent = msg;
        m.error.style.display = 'block';
        w.alert(msg);
        return;
      }

      if (json.lecturer) {
        // 更新 state.lecturers（去重 + 排序）
        state.lecturers = state.lecturers.filter(l => l.code !== json.lecturer.code);
        state.lecturers.push(json.lecturer);
        state.lecturers.sort((a, b) => {
          const sa = parseInt(a.sort_order, 10) || 0;
          const sb = parseInt(b.sort_order, 10) || 0;
          if (sa === sb) return (a.name || '').localeCompare(b.name || '');
          return sa - sb;
        });

        // 重建篩選講師下拉
        ns.buildLecturerFilter();

        // 若有目標 select（課程編輯中），重建並回填
        if (state.currentLecturerTargetSelect) {
          ns.rebuildLecturerSelect(state.currentLecturerTargetSelect);
          state.currentLecturerTargetSelect.value = json.lecturer.code;
        }

        w.alert('已儲存講師');
      }

      ns.closeLecturerModal();
    } catch (e) {
      m.error.textContent = '儲存失敗，請稍後再試';
      m.error.style.display = 'block';
    }
  };

})(window);
