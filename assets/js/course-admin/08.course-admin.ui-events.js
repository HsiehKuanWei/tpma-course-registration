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
 // 核心修正：「新增課程」按鈕邏輯
// 新增課程按鈕事件 (Grid 修正版)
    if (dom.buttons.addCourse) {
      dom.buttons.addCourse.addEventListener('click', () => {
        // 重要：在 Grid 模式下，dom.courseList 通常是 #tpma-course-tbody (雖然叫 tbody 但它現在是 div)
        const body = dom.courseList; 
        if (!body) {
          console.error('找不到課程列表容器 (dom.courseList)');
          return;
        }

        // 1. 建立外層 Card 容器 (div)
        const card = document.createElement('div');
        card.className = 'tpma-course-card';
        
        // 2. 建立摘要列 (Summary Grid)
        const summary = document.createElement('div');
        summary.className = 'tpma-course-card-summary tpma-course-grid-layout';
        summary.innerHTML = `
          <div class="tpma-course-cell">-</div>
          <div class="tpma-course-cell">-</div>
          <div class="tpma-course-cell">（新課程）</div>
          <div class="tpma-course-cell">-</div>
          <div class="tpma-course-cell">
            <div class="tpma-cell-wrap">
              <button type="button" class="tpma-btn tpma-view-btn">收合</button>
            </div>
          </div>
        `;
        card.appendChild(summary);

        // 3. 建立詳情區塊容器 (Details)
        const details = document.createElement('div');
        details.className = 'tpma-course-card-details open'; // 預設打開編輯
        card.appendChild(details);

        // 4. 建立內部渲染目標 div
        const detailDiv = document.createElement('div');
        detailDiv.className = 'tpma-course-item';
        
        // 初始化空白資料 (對應 renderCourseEdit 所需的資料格式)
        detailDiv._data = {
          id: '',
          course_code: '',
          course_name: '',
          category_code: '',
          lecturer_code: '',
          duration_minutes: 180,
          is_active: 1,
          sessions: []
        };
        details.appendChild(detailDiv);

        // 5. 將卡片插入到列表的最上方 (firstChild)
        body.insertBefore(card, body.firstChild);

        // 6. 呼叫渲染函數，把編輯表單畫進去
        if (ns.renderCourseEdit) {
          ns.renderCourseEdit(detailDiv);
        } else {
          console.error('找不到 ns.renderCourseEdit 函數');
        }

        // 7. 平滑滾動到新增的位置
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
        const fieldMap = {
          lecturer: 'lecturer_code',
          category: 'category_code'
        };
        state.sort = state.sort || { field: '', dir: 'asc' };
        state.sort.field = fieldMap[field] || field || '';
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
      const target = btn.getAttribute('data-menu-target') || '';
      const key = btn.getAttribute('data-menu-toggle') || target.replace(/^menu-/, '');
      const cfg = map[key];
      if (!cfg) return;
      const hasFilter = !!(cfg.filterEl && cfg.filterEl.value);
      const hasSort = !!(state.sort?.field && cfg.sortFields.includes(state.sort.field));
      if (hasFilter || hasSort) btn.classList.add('tpma-filter-active');
      else btn.classList.remove('tpma-filter-active');
    });
  };

  ns.removeCourse = async function removeCourse(courseId) {
    const id = parseInt(courseId, 10);
    if (!id) return;
    const course = state.allCourses.find(c => String(c.id) === String(id));
    const name = course ? (course.course_name || course.course_code || id) : id;
    if (!w.confirm(`確定要移除課程「${name}」？此操作會將課程設為停用，不會刪除報名或場次。`)) return;

    try {
      await ns.apiRemoveCourse(id);
      await ns.fetchAll();
      ns.buildLecturerFilter();
      ns.applyFilters();
      w.alert('已移除課程');
    } catch (e) {
      w.alert(e.message || '移除課程失敗');
    }
  };

  ns.removeSelectedLecturer = async function removeSelectedLecturer(selectEl) {
    if (!selectEl || !selectEl.value) {
      w.alert('請先選擇要移除的講師');
      return;
    }
    const lecturer = state.lecturers.find(l => l.code === selectEl.value);
    if (!lecturer) {
      w.alert('找不到講師資料');
      return;
    }
    const label = ns.util.lecturerLabel(lecturer) || lecturer.code;
    if (!w.confirm(`確定要移除講師「${label}」？既有課程仍會保留此講師文字，但新增/編輯下拉將不再顯示。`)) return;

    try {
      await ns.apiRemoveLecturer(lecturer.id);
      selectEl.value = '';
      await ns.fetchAll();
      ns.buildLecturerFilter();
      ns.rebuildLecturerSelect(selectEl);
      ns.applyFilters();
      w.alert('已移除講師');
    } catch (e) {
      w.alert(e.message || '移除講師失敗');
    }
  };

  ns.openMergeCourseModal = function openMergeCourseModal(sourceId) {
    const source = state.allCourses.find(c => String(c.id) === String(sourceId));
    if (!source) {
      w.alert('找不到來源課程');
      return;
    }

    let backdrop = document.getElementById('tpma-course-merge-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'tpma-course-merge-backdrop';
      backdrop.className = 'tpma-modal-backdrop';
      backdrop.innerHTML = `
        <div id="tpma-course-merge-modal" class="tpma-modal">
          <div class="tpma-modal-header">
            <h3>合併課程</h3>
            <button type="button" class="tpma-modal-close-btn" data-merge-close>×</button>
          </div>
          <div class="tpma-modal-content">
            <label>來源課程</label>
            <input type="text" id="tpma-merge-source-label" readonly>
            <label>目標課程</label>
            <select id="tpma-merge-target"></select>
            <div class="tpma-error" id="tpma-merge-error" style="display:none;"></div>
          </div>
          <div class="tpma-modal-footer">
            <button type="button" class="tpma-btn secondary" data-merge-close>取消</button>
            <button type="button" class="tpma-btn" id="tpma-merge-confirm">合併課程</button>
          </div>
        </div>
      `;
      document.body.appendChild(backdrop);
      backdrop.addEventListener('click', e => {
        if (e.target === backdrop || e.target.hasAttribute('data-merge-close')) {
          backdrop.classList.remove('open');
          backdrop.querySelector('#tpma-course-merge-modal')?.classList.remove('open');
        }
      });
    }

    const modal = backdrop.querySelector('#tpma-course-merge-modal');
    const sourceLabel = backdrop.querySelector('#tpma-merge-source-label');
    const targetSel = backdrop.querySelector('#tpma-merge-target');
    const errorEl = backdrop.querySelector('#tpma-merge-error');
    const confirmBtn = backdrop.querySelector('#tpma-merge-confirm');

    sourceLabel.value = `${source.course_code || source.id} ${source.course_name || ''}`.trim();
    targetSel.innerHTML = '<option value="">選擇目標課程</option>';
    state.allCourses
      .filter(c => String(c.id) !== String(sourceId) && parseInt(c.is_active, 10) !== 0)
      .forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.course_code || c.id} ${c.course_name || ''}`.trim();
        targetSel.appendChild(opt);
      });
    errorEl.style.display = 'none';
    errorEl.textContent = '';

    confirmBtn.onclick = async () => {
      const targetId = parseInt(targetSel.value, 10);
      if (!targetId) {
        errorEl.textContent = '請選擇目標課程';
        errorEl.style.display = 'block';
        return;
      }
      const target = state.allCourses.find(c => String(c.id) === String(targetId));
      const targetName = target ? (target.course_name || target.course_code || targetId) : targetId;
      if (!w.confirm(`確定將「${source.course_name || source.id}」合併到「${targetName}」？來源課程會刪除，報名與不重複場次會移到目標課程。`)) return;

      confirmBtn.disabled = true;
      try {
        const json = await ns.apiMergeCourse(sourceId, targetId);
        await ns.fetchAll();
        ns.buildLecturerFilter();
        ns.applyFilters();
        backdrop.classList.remove('open');
        modal.classList.remove('open');
        w.alert(`已合併課程，搬移報名 ${json.moved_regs || 0} 筆，場次 ${json.moved_sessions || 0} 筆`);
      } catch (e) {
        errorEl.textContent = e.message || '合併課程失敗';
        errorEl.style.display = 'block';
      } finally {
        confirmBtn.disabled = false;
      }
    };

    backdrop.classList.add('open');
    modal.classList.add('open');
  };

})(window);
