// course-admin.course-save.js
// ======================================================================
// 【功能】課程儲存流程（介於 UI 與 API 之間）
// - 從「課程編輯卡片」讀取表單欄位
// - 做必填檢查與錯誤顯示
// - 組合 payload 後呼叫 apiSaveCourse()
// - 成功後重新 fetchAll() 並 applyFilters() 更新列表
// ======================================================================

(function (w) {
  'use strict';

  const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
  const state = ns.state;
  const dom = ns.dom;
  const util = ns.util;

  /**
   * 儲存課程（由 renderCourseEdit() 的「儲存」按鈕呼叫）
   * @param {HTMLElement} div 課程卡片容器（內含 data-field 表單）
   */
  ns.saveCourse = async function saveCourse(div) {
    const id = div.dataset.id ? parseInt(div.dataset.id, 10) : 0;

    // 讀取表單欄位值
    const getVal = (field) => {
      const el = div.querySelector('[data-field="' + field + '"]');
      return el ? el.value.trim() : '';
    };

    // 清除舊的錯誤樣式
    div.querySelectorAll('.tpma-invalid').forEach(el => el.classList.remove('tpma-invalid'));
    const saveError = div.querySelector('.tpma-save-error');
    if (saveError) { saveError.style.display = 'none'; saveError.textContent = ''; }

    // 必填檢查
    const course_name = getVal('course_name');
    const category_code = getVal('category_code');
    const lecturer_code = getVal('lecturer_code');

    let hasError = false;
    if (!course_name) {
      const el = div.querySelector('[data-field="course_name"]');
      if (el) el.classList.add('tpma-invalid');
      hasError = true;
    }
    if (!category_code) {
      const el = div.querySelector('[data-field="category_code"]');
      if (el) el.classList.add('tpma-invalid');
      hasError = true;
    }
    if (!lecturer_code) {
      const el = div.querySelector('[data-field="lecturer_code"]');
      if (el) el.classList.add('tpma-invalid');
      hasError = true;
    }

    if (hasError) {
      if (saveError) {
        saveError.textContent = '請填寫必填欄位';
        saveError.style.display = 'block';
      }
      return;
    }

    if (!id) {
      const inactiveSameCourse = state.allCourses.find(c => {
        return parseInt(c.is_active, 10) === 0
          && String(c.course_name || '').trim() === course_name
          && String(c.lecturer_code || '') === String(lecturer_code || '');
      });
      if (inactiveSameCourse) {
        const lecturerText = util.lecturerLabelByCode(lecturer_code) || lecturer_code;
        const ok = w.confirm(`已有停用課程「${course_name}」（講師：${lecturerText}），是否恢復此課程？`);
        if (!ok) return;
        try {
          await ns.apiRestoreCourse(inactiveSameCourse.id);
          await ns.fetchAll();
          ns.buildLecturerFilter();
          ns.applyFilters();
          w.alert('已恢復課程');
        } catch (e) {
          if (saveError) {
            saveError.textContent = e.message || '恢復課程失敗';
            saveError.style.display = 'block';
          }
        }
        return;
      }
    }

    // 收集場次
    const sessions = [];
    div.querySelectorAll('.tpma-session-row').forEach(row => {
      const input = row.querySelector('input[type="datetime-local"]');
      const visibilityEl = row.querySelector('.tpma-session-visibility');
      const recordingFromEl = row.querySelector('.tpma-recording-from');
      const recordingUntilEl = row.querySelector('.tpma-recording-until');
      const v = input ? input.value.trim() : '';
      if (v) {
        sessions.push({
          id: parseInt(row.dataset.sessionId || '0', 10) || 0,
          is_active: parseInt(row.dataset.isActive || '1', 10) === 0 ? 0 : 1,
          datetime: v,
          visibility_override: visibilityEl ? visibilityEl.value : '',
          recording_available_from: recordingFromEl ? recordingFromEl.value.trim() : '',
          recording_available_until: recordingUntilEl ? recordingUntilEl.value.trim() : ''
        });
      }
    });

    // 其他欄位
    const isActiveEl = div.querySelector('[data-field="is_active"]');
    const is_active = isActiveEl ? (parseInt(isActiveEl.value, 10) || 0) : 1;

    const durationHours = parseFloat(getVal('duration_hours') || '3') || 3;
    const duration_minutes = Math.round(durationHours * 60);

    // 組合 payload（與後端 API 對接）
    const payload = {
      id: id,
      course_code: getVal('course_code'),
      course_name: course_name,
      category_code: category_code,
      category: util.catCodeToLabel(category_code),
      lecturer_code: lecturer_code,
      lecturer: util.lecturerLabelByCode(lecturer_code),
      intro: getVal('intro'),
      outline: getVal('outline'),
      duration_minutes: duration_minutes,
      is_active: is_active,
      sessions: sessions
    };

    try {
      const { res, json } = await ns.apiSaveCourse(payload);

      if (!res.ok || !json || !json.success) {
        const msg = (json && json.message) ? json.message : '儲存失敗';
        if (saveError) {
          saveError.textContent = msg;
          saveError.style.display = 'block';
        }
        return;
      }

      w.alert('已儲存課程 ' + (json.course_code || payload.course_code || ''));

      // 重新載入資料並刷新畫面
      await ns.fetchAll();
      ns.buildLecturerFilter();
      ns.applyFilters();
    } catch (e) {
      if (saveError) {
        saveError.textContent = '儲存失敗，請稍後再試';
        saveError.style.display = 'block';
      }
    }
  };

})(window);
