<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>
<style>
.tpma-wrap { font-size:13px; }
.tpma-filter-row {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-bottom:8px;
    align-items:center;
}
.tpma-filter-row input,
.tpma-filter-row select {
    padding:3px 6px;
    font-size:13px;
}
.tpma-btn {
    padding:3px 8px;
    font-size:12px;
    cursor:pointer;
    margin:0 4px 4px 0;
}
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
    width:100%;
    box-sizing:border-box;
    font-size:13px;
    padding:3px 4px;
}
.tpma-course-dates { margin-top:4px; }
.tpma-session-row {
    display:flex;
    align-items:center;
    gap:4px;
    margin-bottom:4px;
}
.tpma-session-row input[type="datetime-local"] {
    flex:1;
    padding:3px 4px;
    font-size:13px;
}
.tpma-outline-view {
    border:1px solid #eee;
    padding:4px;
    background:#fafafa;
    margin-top:2px;
}
.tpma-tags {
    margin-bottom:4px;
    color:#333;
    font-weight:bold;
    display:flex;
    flex-wrap:wrap;
    gap:12px;
}
.tpma-bulk {
    margin-top:4px;
    font-size:12px;
    color:#666;
}
.tpma-error {
    color:#c00;
    font-size:12px;
    margin-top:2px;
}
.tpma-required-label {
    display:inline-block;
    margin-left:4px;
    padding:0 3px;
    font-size:10px;
    background:#d9534f;
    color:#fff;
    border-radius:2px;
}
.tpma-invalid {
    border-color:#d9534f !important;
    background:#fff5f5;
}

/* Modal：新增講師 */
.tpma-modal-backdrop {
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.4);
    display:none;
    z-index:9998;
}
.tpma-modal {
    position:fixed;
    top:50%;
    left:50%;
    transform:translate(-50%,-50%);
    background:#fff;
    border-radius:4px;
    box-shadow:0 4px 15px rgba(0,0,0,.25);
    width:360px;
    max-width:95%;
    padding:10px;
    z-index:9999;
    display:none;
    font-size:13px;
}
.tpma-modal h3 {
    margin:0 0 6px;
    font-size:15px;
}
.tpma-modal label {
    display:block;
    margin-top:6px;
    font-weight:bold;
}
.tpma-modal input[type="text"],
.tpma-modal input[type="number"] {
    width:100%;
    padding:3px 4px;
    box-sizing:border-box;
    font-size:12px;
}
.tpma-modal-actions {
    margin-top:8px;
    text-align:right;
}
.tpma-modal .tpma-error {
    margin-top:4px;
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
                <option value="B4">風險管理、內部控制、數位治理</option>
                <option value="B5">其他</option>
            </optgroup>
        </select>

        <select id="tpma-filter-lecturer">
            <option value="">全部講師</option>
        </select>

        <select id="tpma-filter-course">
            <option value="">全部課程名稱</option>
        </select>
    </div>

    <div class="tpma-filter-row">
        <span>授課日期篩選：</span>
        <input type="date" id="tpma-filter-date-from">
        <span>～</span>
        <input type="date" id="tpma-filter-date-to">

        <select id="tpma-filter-mode">
            <option value="open_only">全部（不含停課）</option>
            <option value="with_closed">全部（含停課）</option>
            <option value="scheduled_future">已安排場次（有未來日期）</option>
        </select>

        <span style="font-size:12px;color:#666;">
            不選日期時依模式顯示課程：預設僅列出開課中，可切換含停課或僅有未來場次。
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

<!-- 新增講師 Modal -->
<div id="tpma-lecturer-backdrop" class="tpma-modal-backdrop"></div>
<div id="tpma-lecturer-modal" class="tpma-modal">
    <h3>新增講師</h3>
    <label>講師代碼<span class="tpma-required-label">必填</span></label>
    <input type="text" id="tpma-lect-code" placeholder="例：HSSA">
    <label>講師姓名<span class="tpma-required-label">必填</span></label>
    <input type="text" id="tpma-lect-name" placeholder="講師姓名">
    <label>講師稱謂</label>
    <input type="text" id="tpma-lect-title" placeholder="例：律師 / 教授">
    <label>講師排序（數字，可留空自動帶入）</label>
    <input type="number" id="tpma-lect-sort" placeholder="例：10">

    <div class="tpma-error" id="tpma-lect-error" style="display:none;"></div>

    <div class="tpma-modal-actions">
        <button type="button" class="tpma-btn" id="tpma-lect-cancel-btn">取消</button>
        <button type="button" class="tpma-btn" id="tpma-lect-save-btn">儲存講師</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
(function(){
    const apiBase = '<?php echo $apiBase; ?>';
    const wpRestNonce = '<?php echo $restNonce; ?>';

    let allCourses = [];
    let lecturers = [];
    let currentLecturerTargetSelect = null; // 當前正在編輯課程的講師下拉（用來回填新講師）

    const courseListEl = document.getElementById('tpma-course-list');

    const $q      = document.getElementById('tpma-filter-q');
    const $cat    = document.getElementById('tpma-filter-category');
    const $lec    = document.getElementById('tpma-filter-lecturer');
    const $course = document.getElementById('tpma-filter-course');
    const $dFrom  = document.getElementById('tpma-filter-date-from');
    const $dTo    = document.getElementById('tpma-filter-date-to');
    const $mode   = document.getElementById('tpma-filter-mode');

    const modalBackdrop = document.getElementById('tpma-lecturer-backdrop');
    const modal = document.getElementById('tpma-lecturer-modal');
    const mCode = document.getElementById('tpma-lect-code');
    const mName = document.getElementById('tpma-lect-name');
    const mTitle= document.getElementById('tpma-lect-title');
    const mSort = document.getElementById('tpma-lect-sort');
    const mErr  = document.getElementById('tpma-lect-error');
    const mBtnSave = document.getElementById('tpma-lect-save-btn');
    const mBtnCancel = document.getElementById('tpma-lect-cancel-btn');

    function esc(s){
        return (s || '').replace(/[&<>"']/g, function(m){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
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

    function openLecturerModal(targetSelect) {
        currentLecturerTargetSelect = targetSelect || null;
        mCode.value = '';
        mName.value = '';
        mTitle.value = '';
        mSort.value = '';
        mErr.style.display = 'none';
        mErr.textContent = '';

        modalBackdrop.style.display = 'block';
        modal.style.display = 'block';
        mCode.focus();
    }

    function closeLecturerModal() {
        modalBackdrop.style.display = 'none';
        modal.style.display = 'none';
        currentLecturerTargetSelect = null;
    }

    async function saveLecturerFromModal() {
        mErr.style.display = 'none';
        mErr.textContent = '';

        const code = mCode.value.trim();
        const name = mName.value.trim();
        const title= mTitle.value.trim();
        const sortStr = mSort.value.trim();

        if (!code || !name) {
            mErr.textContent = '講師代碼與姓名為必填';
            mErr.style.display = 'block';
            return;
        }

        let sortVal = null;
        if (sortStr !== '') {
            sortVal = parseInt(sortStr, 10);
            if (isNaN(sortVal)) {
                mErr.textContent = '排序必須是數字或留空';
                mErr.style.display = 'block';
                return;
            }
        }

        let shiftSort = 0;
        if (sortVal !== null) {
            const hasConflict = lecturers.some(l => parseInt(l.sort_order,10) === sortVal);
            if (hasConflict) {
                const ok = window.confirm(
                    '排序值 ' + sortVal + ' 已存在。\n' +
                    '是否將目前排序為 ' + sortVal + ' 以及之後的講師序號全部往後移一位，讓出 ' + sortVal + ' 給新講師？'
                );
                if (!ok) {
                    mErr.textContent = '請修改排序數字，或再次送出同意自動後移。';
                    mErr.style.display = 'block';
                    return;
                }
                shiftSort = 1;
            }
        }

        try {
            const res = await fetch(apiBase + '/admin/lecturer/save', {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-WP-Nonce':wpRestNonce
                },
                credentials:'include',
                body:JSON.stringify({
                    code: code,
                    name: name,
                    title: title,
                    sort_order: sortVal !== null ? sortVal : null,
                    shift_sort: shiftSort
                })
            });

            const json = await res.json();

            if (!res.ok || !json || !json.success) {
                const msg = (json && json.message) ? json.message : '儲存失敗';
                mErr.textContent = msg;
                mErr.style.display = 'block';
                return;
            }

            if (json.lecturer) {
                // 更新本地 lecturers
                lecturers = lecturers.filter(l => l.code !== json.lecturer.code);
                lecturers.push(json.lecturer);
                lecturers.sort((a,b) => {
                    const sa = parseInt(a.sort_order,10) || 0;
                    const sb = parseInt(b.sort_order,10) || 0;
                    if (sa === sb) {
                        return (a.name ||'').localeCompare(b.name ||'');
                    }
                    return sa - sb;
                });

                // 重建篩選用下拉
                buildLecturerFilter();

                // 若有當前課程的講師下拉，注入新講師並選取
                if (currentLecturerTargetSelect) {
                    rebuildLecturerSelect(currentLecturerTargetSelect);
                    currentLecturerTargetSelect.value = json.lecturer.code;
                }
            }

            closeLecturerModal();
        } catch (e) {
            mErr.textContent = '講師儲存失敗，請稍後再試';
            mErr.style.display = 'block';
        }
    }

    async function fetchAll() {
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

        lecturers = await lecRes.json();
        allCourses = await courseRes.json();

        buildLecturerFilter();
        applyFilters();
    }

    function buildLecturerFilter() {
        if (!$lec) return;
        $lec.innerHTML = '<option value="">全部講師</option>';
        lecturers.forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.code;
            opt.textContent = lecturerLabel(l);
            $lec.appendChild(opt);
        });
    }

    function rebuildLecturerSelect(sel) {
        if (!sel) return;
        const current = sel.value;
        sel.innerHTML = '<option value="">請選擇講師</option>';
        lecturers.forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.code;
            opt.textContent = lecturerLabel(l);
            sel.appendChild(opt);
        });
        if (current) sel.value = current;
    }

    function buildCourseNameFilter(courses) {
        const current = $course.value;
        $course.innerHTML = '<option value="">全部課程名稱</option>';
        const names = new Set();
        courses.forEach(c => { if (c.course_name) names.add(c.course_name); });
        Array.from(names).sort().forEach(name => {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            $course.appendChild(opt);
        });
        if (current && names.has(current)) $course.value = current;
    }

    function formatSessionLabel(dtStr, durationMinutes) {
        if (!dtStr) return '';
        const d = parseDate(dtStr.replace(' ','T'));
        if (!d) return esc(dtStr);
        const weekdays = ['日','一','二','三','四','五','六'];
        const y = d.getFullYear();
        const m = ('0'+(d.getMonth()+1)).slice(-2);
        const day = ('0'+d.getDate()).slice(-2);
        const w = weekdays[d.getDay()];
        const hh = ('0'+d.getHours()).slice(-2);
        const mm = ('0'+d.getMinutes()).slice(-2);
        const dur = durationMinutes && durationMinutes > 0 ? durationMinutes : 180;
        const end = new Date(d.getTime() + dur * 60000);
        const eh = ('0'+end.getHours()).slice(-2);
        const em = ('0'+end.getMinutes()).slice(-2);
        return `${y}-${m}-${day}（${w}） ${hh}:${mm}~${eh}:${em}`;
    }

    function applyFilters() {
        const q = $q.value.trim().toLowerCase();
        const cat = $cat.value;
        const lecCode = $lec.value;
        const courseName = $course.value;
        const df = $dFrom.value ? new Date($dFrom.value + 'T00:00:00') : null;
        const dt = $dTo.value ? new Date($dTo.value + 'T23:59:59') : null;
        const mode = $mode.value;
        const hasDateFilter = !!(df || dt);
        const now = new Date();

        let filtered = allCourses.slice();

        // 類別 / 講師 / 課程名稱
        filtered = filtered.filter(c => {
            if (cat && c.category_code !== cat) return false;
            if (lecCode && c.lecturer_code !== lecCode) return false;
            if (courseName && c.course_name !== courseName) return false;
            return true;
        });

        buildCourseNameFilter(filtered);

        // 關鍵字
        if (q) {
            filtered = filtered.filter(c => {
                const catLabel = c.category || catCodeToLabel(c.category_code || '');
                const lecText = c.lecturer || lecturerLabelByCode(c.lecturer_code || '');
                const text = [
                    c.course_code,
                    c.course_name,
                    catLabel,
                    lecText
                ].join(' ').toLowerCase();
                return text.includes(q);
            });
        }

        // sessions 加工
        filtered = filtered.map(c => {
            const sessions = Array.isArray(c.sessions) ? c.sessions : [];
            const allSessions = sessions.slice();
            let visibleSessions = sessions.slice();
            const isClosed = parseInt(c.is_active,10) === 0;

            if (hasDateFilter) {
                visibleSessions = visibleSessions.filter(s => {
                    const sd = parseDate(s.session_datetime.replace(' ','T'));
                    if (!sd) return false;
                    if (df && sd < df) return false;
                    if (dt && sd > dt) return false;
                    return true;
                });
            } else if (mode === 'scheduled_future') {
                visibleSessions = visibleSessions.filter(s => {
                    const sd = parseDate(s.session_datetime.replace(' ','T'));
                    return sd && sd >= now;
                });
            } else {
                visibleSessions = visibleSessions.filter(s => parseDate(s.session_datetime.replace(' ','T')));
            }

            return Object.assign({}, c, {
                _all_sessions: allSessions,
                _visible_sessions: visibleSessions,
                _is_closed: isClosed
            });
        });

        // 依模式決定課程是否顯示
        filtered = filtered.filter(c => {
            const hasSessions = (c._all_sessions || []).length > 0;
            const hasFuture = (c._all_sessions || []).some(s => {
                const sd = parseDate(s.session_datetime.replace(' ','T'));
                return sd && sd >= new Date();
            });

            if (mode === 'scheduled_future') {
                if (!hasFuture) return false;
                if (hasDateFilter && (!c._visible_sessions || !c._visible_sessions.length)) return false;
                return true;
            }

            if (mode === 'open_only' && c._is_closed) {
                return false;
            }

            if (hasDateFilter) {
                if (!hasSessions) return true;
                return c._visible_sessions && c._visible_sessions.length > 0;
            }

            return true;
        });

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
        const visibleSessions = c._visible_sessions || [];
        const sessions = showAllDates ? allSessions : visibleSessions;
        const hasExtra = allSessions.length > visibleSessions.length;
        const outline = c.outline || '';
        let outlineHtml = esc(outline);
        try { if (window.marked && outline) { outlineHtml = marked.parse(outline); } } catch(e){}

        let sessionsHtml = '';
        if (!allSessions.length) {
            sessionsHtml = '<span class="value">（尚未設定任何日期，課程仍可使用）</span>';
        } else if (sessions && sessions.length) {
            sessionsHtml = '<ul>';
            sessions.forEach(s => {
                sessionsHtml += '<li>' + esc(formatSessionLabel(s.session_datetime, c.duration_minutes)) + (s.is_active ? '' : '（停用）') + '</li>';
            });
            sessionsHtml += '</ul>';
        } else {
            sessionsHtml = '<span class="value">（目前篩選條件下無符合的日期場次）</span>';
        }

        const catText = c.category || catCodeToLabel(c.category_code || '');
        const lecText = lecturerLabelByCode(c.lecturer_code || '') || c.lecturer || '';
        const isClosed = parseInt(c.is_active,10) === 0;

        div.innerHTML = `
            <div class="tpma-tags">
                ${c.course_code ? '<span>課程編號：' + esc(c.course_code) + '</span>' : ''}
                ${catText ? '<span>類別：' + esc(catText) + '</span>' : ''}
                ${lecText ? '<span>講師：' + esc(lecText) + '</span>' : ''}
                ${isClosed ? '<span style="color:#c00;">[已停課]</span>' : ''}
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
                    ? `<button class="tpma-btn tpma-toggle-dates">${showAllDates ? '僅顯示符合條件場次' : '顯示全部場次'}</button>`
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
        if (editBtn) editBtn.onclick = () => renderCourseEdit(div);

        const toggleBtn = div.querySelector('.tpma-toggle-dates');
        if (toggleBtn) toggleBtn.onclick = () => renderCourseView(div, !showAllDates);
    }

    function renderCourseEdit(div) {
        const c = div._data;
        const sessions = c._all_sessions || c.sessions || [];
        const isClosed = parseInt(c.is_active,10) === 0;

        div.innerHTML = `
            <div class="tpma-tags">
                ${c.course_code ? '<span>課程編號：' + esc(c.course_code) + '</span>' : '<span>課程編號：儲存時自動產生</span>'}
                ${isClosed ? '<span style="color:#c00;">[已停課]</span>' : ''}
            </div>

            <label>課程名稱 <span class="tpma-required-label">必填</span></label>
            <input type="text" data-field="course_name" value="${esc(c.course_name || '')}">

            <label>課程類別 <span class="tpma-required-label">必填</span></label>
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

            <label>講師 <span class="tpma-required-label">必填</span></label>
            <div>
                <select data-field="lecturer_code"></select>
                <button type="button" class="tpma-btn tpma-add-lecturer">＋新增講師</button>
            </div>

            <label>課程簡介</label>
            <textarea rows="3" data-field="intro">${esc(c.intro || '')}</textarea>

            <label>課程大綱（Markdown 原始內容）</label>
            <textarea rows="5" data-field="outline">${esc(c.outline || '')}</textarea>

			<label>授課時長（小時）</label>
			<select data-field="duration_hours">
				<option value="2" ${!c.duration_minutes || c.duration_minutes==180?'selected':''}>3 小時</option>
				<option value="3" ${c.duration_minutes==120?'selected':''}>2 小時</option>
				<option value="4" ${c.duration_minutes==240?'selected':''}>4 小時</option>
				${c.duration_minutes && ![120,180,240].includes(parseInt(c.duration_minutes,10))
					? `<option value="${(parseInt(c.duration_minutes,10)/60).toFixed(1)}" selected>${(parseInt(c.duration_minutes,10)/60).toFixed(1)} 小時（現有值）</option>` : ''
				}
			</select>

            <label>授課日期時間（多筆，可手選或貼上）</label>
            <div class="tpma-course-dates" data-field="sessions"></div>
            <div class="tpma-bulk">
                一行一筆，格式：YYYY-MM-DD HH:MM，例如：2025-03-01 09:00<br>
                <textarea rows="3" class="tpma-bulk-input" placeholder="在此貼上多筆日期時間，輸入完畢，請點匯入"></textarea>
                <button type="button" class="tpma-btn tpma-bulk-apply">匯入</button>
            </div>

            <div class="tpma-row-actions">
                <label>課程狀態</label>
                <select data-field="is_active">
                    <option value="1" ${isClosed ? '' : 'selected'}>公開中</option>
                    <option value="0" ${isClosed ? 'selected' : ''}>已停課</option>
                </select>
            </div>

            <div class="tpma-row-actions">
                <button class="tpma-btn tpma-save">儲存</button>
                <button class="tpma-btn tpma-cancel">取消</button>
                <div class="tpma-error tpma-save-error" style="display:none;"></div>
            </div>
        `;

        const catSel = div.querySelector('[data-field="category_code"]');
        if (catSel && c.category_code) catSel.value = c.category_code;

        // 講師下拉
        const lecSel = div.querySelector('[data-field="lecturer_code"]');
        if (lecSel) {
            rebuildLecturerSelect(lecSel);
            if (c.lecturer_code) lecSel.value = c.lecturer_code;
        }

        // 場次
        const datesWrap = div.querySelector('.tpma-course-dates[data-field="sessions"]');
        if (datesWrap) {
            if (sessions.length) {
                sessions.forEach(s => addSessionRow(datesWrap, s.session_datetime));
            } else {
                addSessionRow(datesWrap, '');
            }
        }

        // bulk 匯入
        const bulkArea = div.querySelector('.tpma-bulk-input');
        const bulkBtn  = div.querySelector('.tpma-bulk-apply');
        if (bulkBtn && bulkArea && datesWrap) {
            bulkBtn.onclick = () => {
                const lines = bulkArea.value.split(/\r?\n/).map(l => l.trim()).filter(l => l);
                lines.forEach(line => {
                    const m = line.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
                    if (m) addSessionRow(datesWrap, m[1] + ' ' + m[2]);
                });
                bulkArea.value = '';
            };
        }

        // 新增講師（開 Modal）
        const addLectBtn = div.querySelector('.tpma-add-lecturer');
        if (addLectBtn && lecSel) {
            addLectBtn.onclick = () => openLecturerModal(lecSel);
        }

        const saveBtn = div.querySelector('.tpma-save');
        const cancelBtn = div.querySelector('.tpma-cancel');
        if (saveBtn) saveBtn.onclick = () => saveCourse(div);
        if (cancelBtn) cancelBtn.onclick = () => renderCourseView(div, false);
    }

    function addSessionRow(container, raw) {
        let val = '';
        if (raw) {
            const dt = raw.replace('T',' ').trim();
            // 轉成 datetime-local value
            const d = parseDate(dt.replace(' ','T'));
            if (d) {
                const y = d.getFullYear();
                const m = ('0'+(d.getMonth()+1)).slice(-2);
                const day = ('0'+d.getDate()).slice(-2);
                const hh = ('0'+d.getHours()).slice(-2);
                const mm = ('0'+d.getMinutes()).slice(-2);
                val = `${y}-${m}-${day}T${hh}:${mm}`;
            }
        }
        const row = document.createElement('div');
        row.className = 'tpma-session-row';
        row.innerHTML = `
            <input type="datetime-local" value="${val}">
            <button type="button" class="tpma-btn tpma-session-remove">刪除</button>
        `;
        row.querySelector('.tpma-session-remove').onclick = () => row.remove();
        container.appendChild(row);
    }

    async function saveCourse(div) {
        const id = div.dataset.id ? parseInt(div.dataset.id,10) : 0;
        const getVal = (field) => {
            const el = div.querySelector('[data-field="'+field+'"]');
            return el ? el.value.trim() : '';
        };

        div.querySelectorAll('.tpma-invalid').forEach(el => el.classList.remove('tpma-invalid'));
        const saveError = div.querySelector('.tpma-save-error');
        if (saveError) { saveError.style.display='none'; saveError.textContent=''; }

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
                saveError.textContent = '請確認「課程名稱／課程類別／講師」皆已填寫。';
                saveError.style.display = 'block';
            }
            return;
        }

        const sessions = [];
        div.querySelectorAll('.tpma-session-row input[type="datetime-local"]').forEach(input => {
            const v = input.value.trim();
            if (v) sessions.push({ datetime: v });
        });

        const isActiveEl = div.querySelector('[data-field="is_active"]');
        const is_active = isActiveEl ? parseInt(isActiveEl.value, 10) || 0 : 1;
		const durationHours = parseFloat(getVal('duration_hours') || '3') || 3;
		const duration = Math.round(durationHours * 60);

        const payload = {
            id: id,
            course_code: getVal('course_code'),
            course_name: course_name,
            category_code: category_code,
            category: catCodeToLabel(category_code),
            lecturer_code: lecturer_code,
            lecturer: lecturerLabelByCode(lecturer_code),
            intro: getVal('intro'),
            outline: getVal('outline'),
            duration_minutes: duration,
            is_active: is_active,
            sessions: sessions
        };

        try {
            const res = await fetch(apiBase + '/admin/course/save', {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-WP-Nonce':wpRestNonce
                },
                credentials:'include',
                body:JSON.stringify(payload)
            });
            const result = await res.json();

            if (!res.ok || !result || !result.success) {
                const msg = (result && result.message) ? result.message : '儲存失敗';
                if (saveError) {
                    saveError.textContent = msg;
                    saveError.style.display = 'block';
                }
                return;
            }

            alert('已儲存，課程編號：' + (result.course_code || payload.course_code || ''));

            await fetchAll();
        } catch (e) {
            if (saveError) {
                saveError.textContent = '儲存發生錯誤，請稍後重試。';
                saveError.style.display = 'block';
            }
        }
    }

    // 篩選事件
    [$q,$cat,$lec,$course,$dFrom,$dTo,$mode].forEach(el => {
        if (!el) return;
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
    });

    const resetBtn = document.getElementById('tpma-reset-filter');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            $q.value = '';
            $cat.value = '';
            $lec.value = '';
            $course.value = '';
            $dFrom.value = '';
            $dTo.value = '';
            $mode.value = 'open_only';
            applyFilters();
        });
    }

    const addCourseBtn = document.getElementById('tpma-add-course');
    if (addCourseBtn) {
        addCourseBtn.addEventListener('click', () => {
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
            courseListEl.prepend(div);
            renderCourseEdit(div);
        });
    }

    // Modal 按鈕事件
    mBtnCancel.addEventListener('click', closeLecturerModal);
    modalBackdrop.addEventListener('click', closeLecturerModal);
    mBtnSave.addEventListener('click', saveLecturerFromModal);

    // 初次載入
    fetchAll().catch(e => {
        console.error(e);
        courseListEl.innerHTML = '<p>載入失敗，請重新整理。</p>';
    });
})();
</script>
