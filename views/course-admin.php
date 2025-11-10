<?php
if (!defined('ABSPATH')) {
    exit;
}
.tpma-badge-required {
    display:inline-block;
    padding:1px 4px;
    margin-left:4px;
    font-size:10px;
    background:#c00;
    color:#fff;
    border-radius:3px;
}
.tpma-error {
    border-color:#c00 !important;
    background:#fff4f4;
}
.tpma-lecturer-select-row {
    display:flex;
    gap:6px;
    align-items:center;
    margin-bottom:4px;
}
.tpma-lecturer-select-row select {
    flex:1;
}
.tpma-lecturer-form {
    border:1px solid #ddd;
    background:#f9f9f9;
    padding:8px;
    margin-bottom:8px;
    font-size:12px;
}
.tpma-lecturer-form-title {
    margin:0 0 6px;
    font-weight:bold;
}
.tpma-lecturer-form-row {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    align-items:center;
    margin-bottom:6px;
}
.tpma-lecturer-form-row label {
    font-weight:bold;
    margin:0;
}
.tpma-lecturer-form-row input {
    flex:1;
    min-width:140px;
    padding:3px 4px;
    font-size:13px;
}
.tpma-lecturer-form-hint {
    font-size:12px;
    color:#666;
}
.tpma-lecturer-form-actions {
    display:flex;
    gap:6px;
}
.tpma-filter-row input,
.tpma-filter-row select { padding:3px 6px; font-size:13px; }
.tpma-btn { padding:3px 8px; font-size:12px; cursor:pointer; margin:0 4px 4px 0; }
.tpma-course-item {
    border:1px solid #ddd;
    padding:8px;
    margin-bottom:8px;
    font-size:13px;
}
.tpma-course-item label { display:block; font-weight:bold; margin-top:4px; }
.tpma-course-item .value { margin-top:2px; white-space:pre-wrap; }
.tpma-course-item input,
.tpma-course-item textarea,
.tpma-course-item select {
    width:100%; box-sizing:border-box; font-size:13px; padding:3px 4px;
}
.tpma-course-dates { margin-top:4px; }
.tpma-session-row { display:flex; align-items:center; gap:4px; margin-bottom:4px; }
.tpma-session-row input[type="datetime-local"] { flex:1; padding:3px 4px; font-size:13px; }
.tpma-outline-view { border:1px solid #eee; padding:4px; background:#fafafa; margin-top:2px; }
.tpma-tags { margin-bottom:4px; color:#333; font-weight:bold; display:flex; flex-wrap:wrap; gap:12px; }
.tpma-bulk { margin-top:4px; font-size:12px; color:#666; }
.tpma-label-inline { display:inline-flex; align-items:center; gap:3px; font-size:12px; }
.tpma-badge-required {
    display:inline-block;
    padding:1px 4px;
    margin-left:4px;
    font-size:10px;
    background:#c00;
    color:#fff;
    border-radius:3px;
}
.tpma-error {
    border-color:#c00 !important;
    background:#fff4f4;
}
</style>

<div id="tpma-course-admin" class="tpma-wrap">
    <div class="tpma-filter-row">
        <input type="text" id="tpma-filter-q" placeholder="關鍵字：課程編號 / 課程名稱 / 類別 / 講師（即時篩選）">
        <select id="tpma-filter-category">
            <option value="">全部類別</option>
            <optgroup label="核心課程">
                <option value="A1">董事的法律義務與責任</option>
                <option value="A2">董事會的架構與運作</option>
                <option value="A3">提升董事會績效</option>
                <option value="A4">財務、會計</option>
                <option value="A5">永續發展</option>
            </optgroup>
            <optgroup label="專業課程">
                <option value="B1">董事會成員和管理團隊之間的關係與合作</option>
                <option value="B2">董事與股東會事務</option>
                <option value="B3">公司所屬產業之業務、商務</option>
                <option value="B4">風險管理、內部控制、數位治理</option>    function lecturerLabelByCode(code) {
        if (!code) return '';
        const l = lecturers.find(x => x.code === code);
        return l ? lecturerLabel(l) : '';
    }
    function normalizeLecturer(l) {
        if (!l) return null;
        const sort = parseInt(l.sort_order, 10);
        return {
            id: l.id,
            code: l.code,
            name: l.name,
            title: l.title || '',
            sort_order: Number.isNaN(sort) ? null : sort
        };
    }
    function sortLecturers() {
        if (!Array.isArray(lecturers)) {
            lecturers = [];
            return;
        }
        lecturers.sort((a, b) => {
            const sa = typeof a.sort_order === 'number' ? a.sort_order : Number.MAX_SAFE_INTEGER;
            const sb = typeof b.sort_order === 'number' ? b.sort_order : Number.MAX_SAFE_INTEGER;
            if (sa !== sb) return sa - sb;
            return (a.name || '').localeCompare(b.name || '', 'zh-Hant');
        });
    }
    function populateLecturerSelect(selectEl, selectedValue) {
        if (!selectEl) return;
        const target = selectedValue !== undefined ? selectedValue : selectEl.value;
        selectEl.innerHTML = '<option value="">請選擇講師</option>';
        lecturers.forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.code;
            opt.textContent = lecturerLabel(l);
            selectEl.appendChild(opt);
        });
        if (target && lecturers.some(l => l.code === target)) {
            selectEl.value = target;
        }
    }
            const lecData = await lecRes.json();
            lecturers = Array.isArray(lecData)
                ? lecData.map(normalizeLecturer).filter(Boolean)
                : [];
            sortLecturers();

            allCourses = await courseRes.json();

            buildLecturerFilter();
            applyFilters();
        } catch (e) {
    function buildLecturerFilter() {
        if (!$lec) return;
        const current = $lec.value;
        $lec.innerHTML = '<option value="">全部講師</option>';
        lecturers.forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.code;
            opt.textContent = lecturerLabel(l);
            $lec.appendChild(opt);
        });
    async function fetchLecturerList() {
        const res = await fetch(apiBase + '/admin/lecturers', {
            credentials: 'include',
            headers: { 'X-WP-Nonce': wpRestNonce }
        });

        if (!res.ok) {
            throw new Error('講師列表載入失敗');
        }

        const data = await res.json();
        return Array.isArray(data)
            ? data.map(normalizeLecturer).filter(Boolean)
            : [];
    }

    async function refreshLecturers(options = {}) {
        const { selectElement = null, targetCode = null } = options;
        const list = await fetchLecturerList();
        lecturers = list;
        sortLecturers();
        buildLecturerFilter();

        document.querySelectorAll('[data-field="lecturer_code"]').forEach(sel => {
            const previous = sel === selectElement && targetCode ? targetCode : sel.value;
            populateLecturerSelect(sel, previous);
        });
    }

    async function fetchAll() {
        try {
            const [lecturerList, courseRes] = await Promise.all([
                fetchLecturerList(),
                fetch(apiBase + '/admin/courses', {
                    credentials:'include',
                    headers:{'X-WP-Nonce':wpRestNonce}
                })
            ]);

            if (!courseRes.ok) throw new Error('API error');

            lecturers = lecturerList;

            <option value="all_active">全部（不含停課）</option>
            <option value="all_with_inactive">全部（含停課）</option>
            <option value="scheduled">已安排場次（有未來日期）</option>
        </select>

        <span style="font-size:12px;color:#666;">
            不選日期時：依上方模式顯示課程，預設僅列出開課中課程，可切換含停課或僅有未來場次。
        </span>
    </div>

    <div class="tpma-filter-row">
        <button class="tpma-btn" id="tpma-add-course">新增課程</button>
        <button class="tpma-btn" id="tpma-reset-filter">重置篩選</button>
    </div>

    <div id="tpma-course-list">
        <p>載入中...</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
(function(){
    const apiBase = '<?php echo $apiBase; ?>';
    const wpRestNonce = '<?php echo $restNonce; ?>';

    const wrap = document.getElementById('tpma-course-admin');
    if (!wrap) return;

    let allCourses = [];
    let lecturers = [];

    const courseListEl   = document.getElementById('tpma-course-list');
    const $q             = document.getElementById('tpma-filter-q');
    const $cat           = document.getElementById('tpma-filter-category');
    const $lec           = document.getElementById('tpma-filter-lecturer');
    const $course        = document.getElementById('tpma-filter-course');
    const $dFrom         = document.getElementById('tpma-filter-date-from');
    const $dTo           = document.getElementById('tpma-filter-date-to');
    const $scheduleMode  = document.getElementById('tpma-filter-schedule-mode');
    const $btnAdd        = document.getElementById('tpma-add-course');
    const $btnReset      = document.getElementById('tpma-reset-filter');

    function esc(s){
        return (s || '').replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }
    function parseDate(str){
        if(!str) return null;
        const d = new Date(str);
        return isNaN(d.getTime()) ? null : d;
    }
    function catCodeToLabel(code) {
        switch(code) {
            case 'A1': return '董事的法律義務與責任';
            case 'A2': return '董事會的架構與運作';
            case 'A3': return '提升董事會績效';
            case 'A4': return '財務、會計';
            case 'A5': return '永續發展';
            case 'B1': return '董事會成員和管理團隊之間的關係與合作';
            case 'B2': return '董事與股東會事務';
            case 'B3': return '公司所屬產業之業務、商務';
            case 'B4': return '風險管理、內部控制、數位治理';
            case 'B5': return '其他';
        }
        return '';
    }
    function lecturerLabel(l) {
        if (!l) return '';
        return l.name + (l.title ? ' ' + l.title : '');
    }
    function lecturerLabelByCode(code) {
        if (!code) return '';
        const l = lecturers.find(x => x.code === code);
        return l ? lecturerLabel(l) : '';
    }
    function formatSessionDisplay(datetimeStr, durationMinutes, isActiveFlag) {
        const d = parseDate(datetimeStr.replace(' ','T'));
        if (!d) return esc(datetimeStr || '');
        const add = n => (n < 10 ? '0'+n : ''+n);
        const duration = durationMinutes && durationMinutes > 0 ? durationMinutes : 180;
        const end = new Date(d.getTime() + duration * 60000);

        const y = d.getFullYear();
        const m = add(d.getMonth()+1);
        const day = add(d.getDate());
        const hh = add(d.getHours());
        const mm = add(d.getMinutes());
        const eh = add(end.getHours());
        const em = add(end.getMinutes());
        const w = '日一二三四五六'[d.getDay()];
        const status = String(isActiveFlag) === '0' ? '（停用）' : '';

        return `${y}-${m}-${day}（${w}） ${hh}:${mm}～${eh}:${em}${status}`;
    }

    async function fetchAll() {
        try {
            const [lecRes, courseRes] = await Promise.all([
                fetch(apiBase + '/admin/lecturers', {
                    credentials:'include',
                    headers:{'X-WP-Nonce':wpRestNonce}
                }),
                fetch(apiBase + '/admin/courses', {
                    credentials:'include',
                    headers:{'X-WP-Nonce':wpRestNonce}
                })
            ]);

            if (!lecRes.ok || !courseRes.ok) throw new Error('API error');

            lecturers = await lecRes.json();
            allCourses = await courseRes.json();

            buildLecturerFilter();
            applyFilters();
        } catch (e) {
            console.error(e);
            courseListEl.innerHTML = '<p>載入失敗，請重新整理或檢查登入狀態。</p>';
        }
    }

            <label>講師<span class="tpma-badge-required">必填</span></label>
            <div class="tpma-lecturer-select-row">
                <select data-field="lecturer_code">
                    <option value="">請選擇講師</option>
                </select>
                <button type="button" class="tpma-btn tpma-add-lecturer">新增講師</button>
            </div>
            <div class="tpma-lecturer-form" data-lecturer-form style="display:none;">
                <p class="tpma-lecturer-form-title">新增講師</p>
                <div class="tpma-lecturer-form-row">
                    <label>講師姓名<span class="tpma-badge-required">必填</span></label>
                    <input type="text" data-lecturer-field="name" placeholder="請輸入講師姓名">
                </div>
                <div class="tpma-lecturer-form-row">
                    <label>職稱</label>
                    <input type="text" data-lecturer-field="title" placeholder="請輸入講師職稱">
                </div>
                <div class="tpma-lecturer-form-row">
                    <label>講師代碼<span class="tpma-badge-required">必填</span></label>
                    <input type="text" data-lecturer-field="code" placeholder="請輸入講師代碼">
                </div>
                <div class="tpma-lecturer-form-row">
                    <label>顯示序號</label>
                    <input type="number" data-lecturer-field="sort_order" placeholder="數字越小排序越前">
                    <span class="tpma-lecturer-form-hint">留空則自動排在最後</span>
                </div>
                <div class="tpma-lecturer-form-actions">
                    <button type="button" class="tpma-btn tpma-lecturer-save">儲存講師</button>
                    <button type="button" class="tpma-btn tpma-lecturer-cancel">取消</button>
                </div>
            </div>
        const lecSel = div.querySelector('[data-field="lecturer_code"]');
        populateLecturerSelect(lecSel, c.lecturer_code || '');
        setupLecturerForm(div, lecSel);
    function addSessionRow(container, datetimeStr) {
        const value = datetimeStr
            ? datetimeStr.replace(' ', 'T').slice(0,16)
            : '';
        const row = document.createElement('div');
        row.className = 'tpma-session-row';
        row.innerHTML = `
            <input type="datetime-local" value="${value}">
            <button type="button" class="tpma-btn tpma-session-remove">刪除</button>
        `;
        const btn = row.querySelector('.tpma-session-remove');
        if (btn) btn.addEventListener('click', () => row.remove());
        container.appendChild(row);
    }

    function setupLecturerForm(div, lecSel) {
        const toggleBtn = div.querySelector('.tpma-add-lecturer');
        const form = div.querySelector('[data-lecturer-form]');
        if (!toggleBtn || !form) return;

        const nameInput = form.querySelector('[data-lecturer-field="name"]');
        const titleInput = form.querySelector('[data-lecturer-field="title"]');
        const codeInput = form.querySelector('[data-lecturer-field="code"]');
        const sortInput = form.querySelector('[data-lecturer-field="sort_order"]');
        const saveBtn = form.querySelector('.tpma-lecturer-save');
        const cancelBtn = form.querySelector('.tpma-lecturer-cancel');

        const resetForm = () => {
            [nameInput, titleInput, codeInput, sortInput].forEach(input => {
                if (input) input.value = '';
            });
        };

        toggleBtn.addEventListener('click', () => {
            const isVisible = form.style.display === 'block';
            form.style.display = isVisible ? 'none' : 'block';
            if (!isVisible && nameInput) {
                nameInput.focus();
            }
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                resetForm();
                form.style.display = 'none';
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                if (saveBtn.disabled) return;

                const name = (nameInput?.value || '').trim();
                const title = (titleInput?.value || '').trim();
                const code = (codeInput?.value || '').trim();
                const sortRaw = (sortInput?.value || '').trim();

                if (!name || !code) {
                    alert('請輸入講師姓名與講師代碼。');
                    if (!name && nameInput) nameInput.focus();
                    else if (!code && codeInput) codeInput.focus();
                    return;
                }

                if (lecturers.some(l => l.code === code)) {
                    alert('講師代碼已存在，請重新輸入。');
                    if (codeInput) codeInput.focus();
                    return;
                }

                let sortVal = null;
                if (sortRaw !== '') {
                    const parsed = parseInt(sortRaw, 10);
                    if (Number.isNaN(parsed)) {
                        alert('顯示序號請輸入數字。');
                        if (sortInput) sortInput.focus();
                        return;
                    }
                    sortVal = parsed;
                }

                let shiftSort = false;
                if (sortVal !== null) {
                    const hasDupSort = lecturers.some(l => typeof l.sort_order === 'number' && l.sort_order === sortVal);
                    if (hasDupSort) {
                        const confirmShift = confirm('顯示序號已存在，是否將現有排序往後移一位？');
                        if (!confirmShift) {
                            return;
                        }
                        shiftSort = true;
                    }
                }

                const originalText = saveBtn.textContent;
                saveBtn.disabled = true;
                saveBtn.textContent = '儲存中...';

                try {
                    const newLecturer = await createLecturer({
                        id: 0,
                        code: code,
                        name: name,
                        title: title,
                        sort_order: sortVal,
                        shift_sort: shiftSort
                    });

                    if (!newLecturer) {
                        throw new Error('講師新增失敗');
                    }

                    lecturers.push(newLecturer);
                    sortLecturers();
                    buildLecturerFilter();

                    document.querySelectorAll('[data-field="lecturer_code"]').forEach(sel => {
                        const currentValue = sel === lecSel ? newLecturer.code : sel.value;
                        populateLecturerSelect(sel, currentValue);
                    });

                    if (lecSel) {
                        lecSel.value = newLecturer.code;
                    }

                    resetForm();
                    form.style.display = 'none';
                    alert('講師已新增。');
                } catch (err) {
                    const msg = err && err.message ? err.message : '講師新增失敗';
                    alert(msg);
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = originalText;
                }
            });
        }
    }

    async function createLecturer(payload) {
        try {
            const res = await fetch(apiBase + '/admin/lecturer/save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpRestNonce
                },
                credentials: 'include',
                body: JSON.stringify(payload)
            });

            const result = await res.json();
            if (!res.ok) {
                const msg = result && (result.message || (result.data && result.data.message))
                    ? result.message || result.data.message
                    : '講師新增失敗';
                throw new Error(msg);
            }

            if (!result || !result.success || !result.lecturer) {
                const msg = result && result.message ? result.message : '講師新增失敗';
                throw new Error(msg);
            }

            return normalizeLecturer(result.lecturer);
        } catch (err) {
            if (err instanceof Error) throw err;
            throw new Error('講師新增失敗');
        }
    }
        const current = $course.value;
        $course.innerHTML = '<option value="">全部課程名稱</option>';
        const names = new Set();
        courses.forEach(c => {
            if (c.course_name) names.add(c.course_name);
        });
        Array.from(names).sort().forEach(name => {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;                    let refreshed = false;
                    try {
                        await refreshLecturers({ selectElement: lecSel, targetCode: newLecturer.code });
                        refreshed = true;
                    } catch (refreshErr) {
                        console.error(refreshErr);
                    }
                    if (!refreshed) {
                        lecturers.push(newLecturer);
                        sortLecturers();
                        buildLecturerFilter();
                        document.querySelectorAll('[data-field="lecturer_code"]').forEach(sel => {
                            const currentValue = sel === lecSel ? newLecturer.code : sel.value;
                            populateLecturerSelect(sel, currentValue);
                        });

                        if (lecSel) {
                            lecSel.value = newLecturer.code;
                        }
        }
    }

    function applyFilters() {
        if (!Array.isArray(allCourses)) return;

        const q = ($q?.value || '').trim().toLowerCase();
        const cat = $cat?.value || '';
        const lecCode = $lec?.value || '';
        const courseName = $course?.value || '';
        const df = $dFrom?.value ? new Date($dFrom.value + 'T00:00:00') : null;
        const dt = $dTo?.value ? new Date($dTo.value + 'T23:59:59') : null;
        const hasDateFilter = !!(df || dt);
        const now = new Date();
        const scheduleMode = $scheduleMode ? $scheduleMode.value : 'all_active'; // all_active | all_with_inactive | scheduled

        let filtered = allCourses.slice();

        // 類別 / 講師 / 課程名稱
        filtered = filtered.filter(c => {
            if (cat && c.category_code !== cat) return false;
            if (lecCode && c.lecturer_code !== lecCode) return false;
            if (courseName && c.course_name !== courseName) return false;
            return true;
        });

        // 依 scheduleMode 處理停課 / 安排條件
        if (scheduleMode === 'all_active' || scheduleMode === 'scheduled') {
            filtered = filtered.filter(c => String(c.is_active) !== '0');
        }
        // all_with_inactive：不過濾 is_active

        // sessions 預先運算
        filtered = filtered.map(c => {
            const sessions = Array.isArray(c.sessions) ? c.sessions : [];
            const futureSessions = sessions.filter(s => {
                const sd = parseDate(s.session_datetime.replace(' ','T'));
                return sd && sd >= now;
            });

            let rangeSessions = sessions;
            if (hasDateFilter) {
                rangeSessions = sessions.filter(s => {
                    const sd = parseDate(s.session_datetime.replace(' ','T'));
                    if (!sd) return false;
                    if (df && sd < df) return false;
                    if (dt && sd > dt) return false;
                    return true;
                });
            }

            return Object.assign({}, c, {
                _all_sessions: sessions,
                _future_sessions: futureSessions,
                _range_sessions: rangeSessions
            });
        });

        // scheduleMode = scheduled：僅顯示有未來場次的開課中課程
        if (scheduleMode === 'scheduled') {
            filtered = filtered.filter(c => (c._future_sessions || []).length > 0);
        }

        // 關鍵字
        if (q) {
            filtered = filtered.filter(c => {
                const catLabel = c.category || catCodeToLabel(c.category_code || '');
                const lecText = lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
                const text = [
                    c.course_code,
                    c.course_name,
                    catLabel,
                    lecText
                ].join(' ').toLowerCase();
                return text.includes(q);
            });
        }

        renderCourses(filtered);
    }

    function renderCourses(list) {
        courseListEl.innerHTML = '';

        if (!list || list.length === 0) {
            courseListEl.innerHTML = '<p>查無符合條件的課程。</p>';
            return;
        }

        list.forEach(c => {
            const div = document.createElement('div');
            div.className = 'tpma-course-item';
            div.dataset.id = c.id || '';
            div._data = c;
            renderCourseView(div, false);
            courseListEl.appendChild(div);
        });
    }

    function renderCourseView(div, showAllDates) {
        const c = div._data;
        const allSessions = c._all_sessions || c.sessions || [];
        const futureSessions = c._future_sessions || [];
        const useSessions = showAllDates ? allSessions : futureSessions;
        const hasExtra = allSessions.length > futureSessions.length;

        const outline = c.outline || '';
        let outlineHtml = esc(outline);
        try {
            if (window.marked && outline) {
                outlineHtml = marked.parse(outline);
            }
        } catch(e){}

        const duration = parseInt(c.duration_minutes || 180, 10) || 180;
        const catText = c.category || catCodeToLabel(c.category_code || '');
        const lecText = lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
        const statusText = String(c.is_active) === '0' ? '已停課' : '開課中';

        let sessionsHtml = '';
        if (allSessions.length === 0) {
            sessionsHtml = '<span class="value">（尚未設定任何授課日期）</span>';
        } else if (useSessions.length) {
            sessionsHtml = '<ul>';
            useSessions.forEach(s => {
                sessionsHtml += '<li>' + esc(formatSessionDisplay(s.session_datetime, duration, s.is_active)) + '</li>';
            });
            sessionsHtml += '</ul>';
        } else {
            sessionsHtml = '<span class="value">（目前無符合條件的場次，可切換查看全部日期）</span>';
        }

        div.innerHTML = `
            <div class="tpma-tags">
                <span>課程編號：${esc(c.course_code || '')}</span>
                ${catText ? `<span>類別：${esc(catText)}</span>` : ''}
                ${lecText ? `<span>講師：${esc(lecText)}</span>` : ''}
                <span>狀態：${esc(statusText)}</span>
                <span>授課時長：${duration} 分鐘</span>
            </div>

            <label>課程名稱</label>
            <div class="value">${esc(c.course_name || '')}</div>

            <label>課程簡介</label>
            <div class="value">${esc(c.intro || '')}</div>

            <label>課程大綱（Markdown 已渲染）</label>
            <div class="tpma-outline-view">${outlineHtml || '<span class="value">（尚未填寫）</span>'}</div>

            <label>授課日期時間</label>
            <div class="tpma-course-dates">
                ${sessionsHtml}
                ${hasExtra && allSessions.length > 0
                    ? `<button class="tpma-btn tpma-toggle-dates">${showAllDates ? '只看未來日期' : '顯示全部日期'}</button>`
                    : ''
                }
            </div>

            <label>更新日期</label>
            <div class="value">${esc(c.updated_at || '')}</div>

            <div class="tpma-row-actions">
                <button class="tpma-btn tpma-edit">編輯</button>
            </div>
        `;

        const editBtn = div.querySelector('.tpma-edit');
        if (editBtn) editBtn.addEventListener('click', () => renderCourseEdit(div));

        const toggleBtn = div.querySelector('.tpma-toggle-dates');
        if (toggleBtn) toggleBtn.addEventListener('click', () => renderCourseView(div, !showAllDates));
    }

    function renderCourseEdit(div) {
        const c = div._data;
        const sessions = c._all_sessions || c.sessions || [];
        const duration = parseInt(c.duration_minutes || 180, 10) || 180;

        div.innerHTML = `
            <label>課程編號（留空則依講師＋類別自動產生）</label>
            <input type="text" data-field="course_code" value="${esc(c.course_code || '')}">

            <label>課程名稱<span class="tpma-badge-required">必填</span></label>
            <input type="text" data-field="course_name" value="${esc(c.course_name || '')}">

            <label>課程類別<span class="tpma-badge-required">必填</span></label>
            <select data-field="category_code">
                <option value="">請選擇</option>
                <optgroup label="核心課程">
                    <option value="A1">董事的法律義務與責任</option>
                    <option value="A2">董事會的架構與運作</option>
                    <option value="A3">提升董事會績效</option>
                    <option value="A4">財務、會計</option>
                    <option value="A5">永續發展</option>
                </optgroup>
                <optgroup label="專業課程">
                    <option value="B1">董事會成員和管理團隊之間的關係與合作</option>
                    <option value="B2">董事與股東會事務</option>
                    <option value="B3">公司所屬產業之業務、商務</option>
                    <option value="B4">風險管理、內部控制、數位治理</option>
                    <option value="B5">其他</option>
                </optgroup>
            </select>

            <label>講師<span class="tpma-badge-required">必填</span></label>
            <select data-field="lecturer_code">
                <option value="">請選擇講師</option>
            </select>

            <label>課程狀態</label>
            <select data-field="is_active">
                <option value="1"${String(c.is_active) !== '0' ? ' selected' : ''}>開課中</option>
                <option value="0"${String(c.is_active) === '0' ? ' selected' : ''}>已停課</option>
            </select>

            <label>授課時長</label>
            <select data-field="duration_minutes">
                <option value="60">60 分鐘</option>
                <option value="90">90 分鐘</option>
                <option value="120">120 分鐘</option>
                <option value="150">150 分鐘</option>
                <option value="180">180 分鐘（預設）</option>
                <option value="210">210 分鐘</option>
                <option value="240">240 分鐘</option>
            </select>

            <label>課程簡介</label>
            <textarea rows="3" data-field="intro">${esc(c.intro || '')}</textarea>

            <label>課程大綱（Markdown 原始內容）</label>
            <textarea rows="5" data-field="outline">${esc(c.outline || '')}</textarea>

            <label>授課日期時間（多筆，可手選或貼上，格式：YYYY-MM-DD HH:MM）</label>
            <div class="tpma-course-dates" data-field="sessions"></div>
            <div class="tpma-bulk">
                一行一筆，例如：2025-03-01 09:00<br>
                <textarea rows="3" class="tpma-bulk-input" placeholder="在此貼上多筆日期時間，輸入完畢點匯入"></textarea>
                <button type="button" class="tpma-btn tpma-bulk-apply">匯入</button>
            </div>

            <div class="tpma-row-actions">
                <button class="tpma-btn tpma-save">儲存</button>
                <button class="tpma-btn tpma-cancel">取消</button>
            </div>
        `;

        // 類別
        const catSel = div.querySelector('[data-field="category_code"]');
        if (c.category_code) catSel.value = c.category_code;

        // 講師
        const lecSel = div.querySelector('[data-field="lecturer_code"]');
        lecturers.forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.code;
            opt.textContent = lecturerLabel(l);
            lecSel.appendChild(opt);
        });
        if (c.lecturer_code) lecSel.value = c.lecturer_code;

        // 時長
        const durSel = div.querySelector('[data-field="duration_minutes"]');
        const dur = [60,90,120,150,180,210,240].includes(duration) ? duration : 180;
        durSel.value = String(dur);

        // 場次
        const datesWrap = div.querySelector('.tpma-course-dates[data-field="sessions"]');
        if (sessions.length) {
            sessions.forEach(s => addSessionRow(datesWrap, s.session_datetime));
        } else {
            addSessionRow(datesWrap, '');
        }

        // 匯入多筆
        const bulkArea = div.querySelector('.tpma-bulk-input');
        const bulkBtn  = div.querySelector('.tpma-bulk-apply');
        if (bulkBtn) {
            bulkBtn.addEventListener('click', () => {
                const lines = bulkArea.value.split(/\r?\n/).map(l => l.trim()).filter(l => l);
                lines.forEach(line => {
                    const m = line.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
                    if (m) addSessionRow(datesWrap, m[1] + ' ' + m[2] + ':00');
                });
                bulkArea.value = '';
            });
        }

        const saveBtn = div.querySelector('.tpma-save');
        const cancelBtn = div.querySelector('.tpma-cancel');
        if (saveBtn) saveBtn.addEventListener('click', () => saveCourse(div));
        if (cancelBtn) cancelBtn.addEventListener('click', () => renderCourseView(div, false));
    }

    function addSessionRow(container, datetimeStr) {
        const value = datetimeStr
            ? datetimeStr.replace(' ', 'T').slice(0,16)
            : '';
        const row = document.createElement('div');
        row.className = 'tpma-session-row';
        row.innerHTML = `
            <input type="datetime-local" value="${value}">
            <button type="button" class="tpma-btn tpma-session-remove">刪除</button>
        `;
        const btn = row.querySelector('.tpma-session-remove');
        if (btn) btn.addEventListener('click', () => row.remove());
        container.appendChild(row);
    }

    function validateRequired(div) {
        let ok = true;
        const reqFields = ['course_name', 'category_code', 'lecturer_code'];
        reqFields.forEach(f => {
            const el = div.querySelector(`[data-field="${f}"]`);
            if (!el) return;
            el.classList.remove('tpma-error');
            const v = (el.value || '').trim();
            if (!v) {
                el.classList.add('tpma-error');
                ok = false;
            }
        });
        if (!ok) alert('請填寫標記為「必填」的欄位。');
        return ok;
    }

    async function saveCourse(div) {
        if (!validateRequired(div)) return;

        const id = div.dataset.id ? parseInt(div.dataset.id,10) : 0;
        const getVal = (field) => {
            const el = div.querySelector(`[data-field="${field}"]`);
            return el ? el.value.trim() : '';
        };

        const category_code = getVal('category_code');
        const lecturer_code = getVal('lecturer_code');
        const catLabel = catCodeToLabel(category_code);
        const lecText  = lecturerLabelByCode(lecturer_code);

        const duration_minutes = parseInt(getVal('duration_minutes') || '180', 10) || 180;
        const is_active = parseInt(getVal('is_active') || '1', 10) === 0 ? 0 : 1;

        const sessions = [];
        div.querySelectorAll('.tpma-session-row input[type="datetime-local"]').forEach(input => {
            const v = input.value.trim();
            if (v) sessions.push({ datetime: v });
        });

        const payload = {
            id: id,
            course_code: getVal('course_code'),
            course_name: getVal('course_name'),
            category_code: category_code,
            category: catLabel,
            lecturer_code: lecturer_code,
            lecturer: lecText,
            intro: getVal('intro'),
            outline: getVal('outline'),
            is_active: is_active,
            duration_minutes: duration_minutes,
            sessions: sessions
        };

        try {
            const res = await fetch(apiBase + '/admin/course/save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpRestNonce
                },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            const result = await res.json();
            if (res.ok && result && result.success) {
                alert('已儲存，課程編號：' + (result.course_code || payload.course_code || ''));
                fetchAll();
            } else {
                alert((result && result.message) ? result.message : '儲存失敗');
            }
        } catch (e) {
            console.error(e);
            alert('儲存失敗（請查看主控台或 debug.log）。');
        }
    }

    // 篩選即時觸發
    [$q,$cat,$lec,$course,$dFrom,$dTo,$scheduleMode].forEach(el => {
        if (!el) return;
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
    });

    // 重置
    if ($btnReset) {
        $btnReset.addEventListener('click', () => {
            if ($q) $q.value = '';
            if ($cat) $cat.value = '';
            if ($lec) $lec.value = '';
            if ($course) $course.value = '';
            if ($dFrom) $dFrom.value = '';
            if ($dTo) $dTo.value = '';
            if ($scheduleMode) $scheduleMode.value = 'all_active';
            applyFilters();
        });
    }

    // 新增課程
    if ($btnAdd) {
        $btnAdd.addEventListener('click', () => {
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
                _future_sessions: []
            };
            const div = document.createElement('div');
            div.className = 'tpma-course-item';
            div.dataset.id = '';
            div._data = empty;
            courseListEl.prepend(div);
            renderCourseEdit(div);
        });
    }

    fetchAll();
})();
</script>
