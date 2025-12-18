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
  const state = ns.state;
  const dom = ns.dom;

  /**
   * 綁定頁面事件
   */
  ns.initEvents = function initEvents() {
    const filters = dom.filter;
    const header = dom.header || {};

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
        const tbody = dom.courseList;
        const tr = document.createElement('tr');
        tr.className = 'tpma-course-row';
        tr.dataset.id = '';
        tr.innerHTML = `
          <td>-</td>
          <td>-</td>
          <td>（新課程）</td>
          <td>-</td>
          <td>
            <button type="button" class="tpma-btn tpma-view-btn">收合</button>
            <button type="button" class="tpma-btn tpma-edit-btn">編輯</button>
          </td>
        `;

        const trDetail = document.createElement('tr');
        trDetail.className = 'tpma-course-detail-row';
        trDetail.style.display = '';
        trDetail.dataset.id = '';
        const tdDetail = document.createElement('td');
        tdDetail.className = 'tpma-course-detail-cell';
        tdDetail.colSpan = 5;
        trDetail.appendChild(tdDetail);

        const detailDiv = document.createElement('div');
        detailDiv.className = 'tpma-course-item';
        detailDiv.dataset.id = '';
        detailDiv._data = empty;
        tdDetail.appendChild(detailDiv);

        if (tbody.firstChild) {
          tbody.insertBefore(tr, tbody.firstChild);
          tbody.insertBefore(trDetail, tr.nextSibling);
        } else {
          tbody.appendChild(tr);
          tbody.appendChild(trDetail);
        }

        const viewBtn = tr.querySelector('.tpma-view-btn');
        if (viewBtn) {
          viewBtn.addEventListener('click', () => {
            const expanded = trDetail.style.display === 'none';
            trDetail.style.display = expanded ? '' : 'none';
            viewBtn.textContent = expanded ? '收合' : '詳細';
          });
        }

        ns.renderCourseEdit(detailDiv);
      });
    }

    // ===== 表頭選單：開關 / 排序 / 清除 =====
    const closeAllMenus = (except) => {
      (header.menus || []).forEach(m => {
        if (except && m === except) return;
        m.classList.remove('open');
      });
    };

    // ===== 表頭選單：開關 / 排序 / 清除 =====
    document.addEventListener('click', e => {
      const btn = e.target;
      // 開啟/關閉欄位篩選選單
      const menuTarget = btn.getAttribute('data-menu-target');
      if (menuTarget) {
        e.stopPropagation();
        const menu = document.getElementById(menuTarget);
        if (!menu) return;

        // 關閉其他開啟中的 menu
        document.querySelectorAll('.tpma-th-menu.open').forEach(m => {
          if (m !== menu) m.classList.remove('open');
        });

        menu.classList.toggle('open');
        return;
      }

      // 排序
      const sortKey = btn.getAttribute('data-sort');
      if (sortKey) {
        const [field, dir] = sortKey.split('-');
        state.sort.field = field || '';
        state.sort.dir = dir || 'asc';
        ns.applyFilters();
        return;
      }

      // 清除篩選
      const clearKey = btn.getAttribute('data-clear');
      if (clearKey) {
        if (clearKey === 'course_code') {
          state.sort.field = '';
          state.sort.dir = 'asc';
        } else if (clearKey === 'course_name') {
          filters.course.value = '';
          state.sort.field = '';
          state.sort.dir = 'asc';
        } else if (clearKey === 'lecturer') {
          filters.lec.value = '';
          state.sort.field = '';
          state.sort.dir = 'asc';
        } else if (clearKey === 'category') {
          filters.cat.value = '';
          state.sort.field = '';
          state.sort.dir = 'asc';
        }
        ns.applyFilters();
        return;
      }
    });

    // 點擊表格外關閉所有篩選選單
    document.addEventListener('click', e => {
      const menus = document.querySelectorAll('.tpma-th-menu.open');
      menus.forEach(menu => {
        if (!menu.contains(e.target) && !isFilterButton(e.target)) {
          menu.classList.remove('open');
        }
      });
    });

    function isFilterButton(el) {
      return el.classList && el.classList.contains('tpma-th-menu-btn');
    }

    // Modal 按鈕事件
    const lecturerModalBackdrop = document.getElementById('tpma-lecturer-backdrop');
    const lecturerModal = document.getElementById('tpma-lecturer-modal');
    const lecturerCancelBtns = document.querySelectorAll('#tpma-lecturer-modal #tpma-lect-cancel-btn');
    const lecturerSaveBtn = document.getElementById('tpma-lect-save-btn');

    if (lecturerCancelBtns) {
        lecturerCancelBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                lecturerModalBackdrop.classList.remove('open');
                lecturerModal.classList.remove('open');
                ns.closeLecturerModal(); // Call original close logic
            });
        });
    }
    if (lecturerModalBackdrop) {
        lecturerModalBackdrop.addEventListener('click', (e) => {
            if (e.target === lecturerModalBackdrop) { // Only close if backdrop itself is clicked
                lecturerModalBackdrop.classList.remove('open');
                lecturerModal.classList.remove('open');
                ns.closeLecturerModal(); // Call original close logic
            }
        });
    }
    if (lecturerSaveBtn) {
        lecturerSaveBtn.addEventListener('click', ns.saveLecturerFromModal);
    }
  };

  ns.updateHeaderMenuStates = function updateHeaderMenuStates() {
    const header = dom.header || {};
    const map = {
      course_code: { sortFields: ['course_code'] },
      category: { sortFields: ['category_code'], filterEl: dom.filter?.cat },
      course_name: { sortFields: ['course_name'], filterEl: dom.filter?.course },
      lecturer: { sortFields: ['lecturer_code'], filterEl: dom.filter?.lec }
    };

    (header.menuButtons || []).forEach(btn => {
      const key = btn.getAttribute('data-menu-toggle');
      const cfg = map[key];
      if (!cfg) return;
      const hasFilter = !!(cfg.filterEl && cfg.filterEl.value);
      const hasSort = !!(state.sort?.field && cfg.sortFields.includes(state.sort.field));
      if (hasFilter || hasSort) btn.classList.add('tpma-filter-active');
      else btn.classList.remove('tpma-filter-active');
    });
  };

})(window);
