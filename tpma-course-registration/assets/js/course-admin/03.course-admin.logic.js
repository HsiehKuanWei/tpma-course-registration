(function (w) {
    const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
    const state = ns.state;
    const dom = ns.dom;
    const util = ns.util;

    ns.applyFilters = function () {
        const filters = dom.filter;
        const q = (filters.q?.value || '').trim().toLowerCase();
        const cat = filters.cat?.value || '';
        const lecCode = filters.lec?.value || '';
        const courseName = filters.course?.value || '';
        const df = filters.dateFrom?.value ? new Date(filters.dateFrom.value + 'T00:00:00') : null;
        const dt = filters.dateTo?.value ? new Date(filters.dateTo.value + 'T23:59:59') : null;
        const mode = filters.mode?.value || '';
        const hasDateFilter = !!(df || dt);
        const now = new Date();

        let filtered = state.allCourses.slice();

        filtered = filtered.filter(c => {
            if (cat && c.category_code !== cat) return false;
            if (lecCode && c.lecturer_code !== lecCode) return false;
            if (courseName && c.course_name !== courseName) return false;
            return true;
        });

        ns.buildCourseNameFilter(filtered);

        if (q) {
            filtered = filtered.filter(c => {
                const catLabel = c.category || util.catCodeToLabel(c.category_code || '');
                const lecText = c.lecturer || util.lecturerLabelByCode(c.lecturer_code || '');
                const text = [
                    c.course_code,
                    c.course_name,
                    catLabel,
                    lecText
                ].join(' ').toLowerCase();
                return text.includes(q);
            });
        }

        filtered = filtered.map(c => {
            const sessions = Array.isArray(c.sessions) ? c.sessions : [];
            const allSessions = sessions.slice();
            let visibleSessions = sessions.slice();
            const isClosed = parseInt(c.is_active, 10) === 0;

            if (hasDateFilter) {
                visibleSessions = visibleSessions.filter(s => {
                    const sd = util.parseDate(s.session_datetime.replace(' ', 'T'));
                    if (!sd) return false;
                    if (df && sd < df) return false;
                    if (dt && sd > dt) return false;
                    return true;
                });
            } else if (mode === 'scheduled_future') {
                visibleSessions = visibleSessions.filter(s => {
                    const sd = util.parseDate(s.session_datetime.replace(' ', 'T'));
                    return sd && sd >= now;
                });
            } else {
                visibleSessions = visibleSessions.filter(s => util.parseDate(s.session_datetime.replace(' ', 'T')));
            }

            return Object.assign({}, c, {
                _all_sessions: allSessions,
                _visible_sessions: visibleSessions,
                _is_closed: isClosed
            });
        });

        filtered = filtered.filter(c => {
            const hasSessions = (c._all_sessions || []).length > 0;
            const hasFuture = (c._all_sessions || []).some(s => {
                const sd = util.parseDate(s.session_datetime.replace(' ', 'T'));
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

        ns.renderCourses(filtered);
    };

    ns.openLecturerModal = function (targetSelect) {
        state.currentLecturerTargetSelect = targetSelect || null;
        const m = dom.modal;
        if (!m.box) return;
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

    ns.closeLecturerModal = function () {
        const m = dom.modal;
        if (!m.box) return;
        m.backdrop.style.display = 'none';
        m.box.style.display = 'none';
        state.currentLecturerTargetSelect = null;
    };

    ns.saveLecturerFromModal = async function () {
        const m = dom.modal;
        if (!m.box) return;

        m.error.style.display = 'none';
        m.error.textContent = '';

        const code = m.code.value.trim();
        const name = m.name.value.trim();
        const title = m.title.value.trim();
        const sortStr = m.sort.value.trim();

        if (!code || !name) {
            m.error.textContent = 'Šª>†,®„¯œ‡›¬Š^Ø†\"†??‡,§†¨.†­®';
            m.error.style.display = 'block';
            return;
        }

        let sortVal = null;
        if (sortStr !== '') {
            sortVal = parseInt(sortStr, 10);
            if (isNaN(sortVal)) {
                m.error.textContent = '‘Z\'†§?†¨.‚ÿ^‘~_‘,†--‘^-‡T‡c§';
                m.error.style.display = 'block';
                return;
            }
        }

        let shiftSort = 0;
        if (sortVal !== null) {
            const hasConflict = state.lecturers.some(l => parseInt(l.sort_order, 10) === sortVal);
            if (hasConflict) {
                const ok = w.confirm(
                    '‘Z\'†§?†?¬ ' + sortVal + ' †úý†-~†o\"a?,\n' +
                    '‘~_†?Ý†øØ‡>r†%?‘Z\'†§?‡,§ ' + sortVal + ' „¯†?S„1<†_O‡s,Šª>†,®†§?ŠTY†.\"‚Ÿ\"†_?†_O‡¯„,?„«?‹¬OŠr\"†Ø§ ' + sortVal + ' ‡æÝ‘-øŠª>†,®‹¬Y'
                );
                if (!ok) {
                    m.error.textContent = 'Š®<„¨r‘\"1‘Z\'†§?‘,†--‹¬O‘^-†+?‘ª­‚??†Ø§†?O‘,?ŠØ¦†<†_O‡¯a?,';
                    m.error.style.display = 'block';
                    return;
                }
                shiftSort = 1;
            }
        }

        try {
            const res = await fetch(state.apiBase + '/admin/lecturer/save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': state.nonce
                },
                credentials: 'include',
                body: JSON.stringify({
                    code: code,
                    name: name,
                    title: title,
                    sort_order: sortVal !== null ? sortVal : null,
                    shift_sort: shiftSort
                })
            });

            const json = await res.json();

            if (!res.ok || !json || !json.success) {
                const msg = (json && json.message) ? json.message : '†,ý†-~†ñ‘-';
                m.error.textContent = msg;
                m.error.style.display = 'block';
                w.alert(msg);
                return;
            }

            if (json.lecturer) {
                state.lecturers = state.lecturers.filter(l => l.code !== json.lecturer.code);
                state.lecturers.push(json.lecturer);
                state.lecturers.sort((a, b) => {
                    const sa = parseInt(a.sort_order, 10) || 0;
                    const sb = parseInt(b.sort_order, 10) || 0;
                    if (sa === sb) {
                        return (a.name || '').localeCompare(b.name || '');
                    }
                    return sa - sb;
                });

                ns.buildLecturerFilter();

                if (state.currentLecturerTargetSelect) {
                    ns.rebuildLecturerSelect(state.currentLecturerTargetSelect);
                    state.currentLecturerTargetSelect.value = json.lecturer.code;
                }

                w.alert('Šª>†,®†úý†,ý†-~');
            }

            ns.closeLecturerModal();
        } catch (e) {
            m.error.textContent = 'Šª>†,®†,ý†-~†ñ‘-‹¬OŠ®<‡\"?†_O†+?ŠcÝ';
            m.error.style.display = 'block';
        }
    };

    ns.fetchAll = async function () {
        try {
            const [lecRes, courseRes] = await Promise.all([
                fetch(state.apiBase + '/admin/lecturers', {
                    credentials: 'include',
                    headers: { 'X-WP-Nonce': state.nonce }
                }),
                fetch(state.apiBase + '/admin/courses', {
                    credentials: 'include',
                    headers: { 'X-WP-Nonce': state.nonce }
                })
            ]);

            state.lecturers = await lecRes.json();
            state.allCourses = await courseRes.json();

            ns.buildLecturerFilter();
            ns.applyFilters();
        } catch (e) {
            console.error(e);
            if (dom.courseList) {
                dom.courseList.innerHTML = '<p>Š¬%†.†ñ‘-‹¬OŠ®<‚Ø?‘-ø‘' + '‡?+a?,</p>';
            }
        }
    };

    ns.saveCourse = async function (div) {
        const id = div.dataset.id ? parseInt(div.dataset.id, 10) : 0;
        const getVal = (field) => {
            const el = div.querySelector('[data-field="' + field + '"]');
            return el ? el.value.trim() : '';
        };

        div.querySelectorAll('.tpma-invalid').forEach(el => el.classList.remove('tpma-invalid'));
        const saveError = div.querySelector('.tpma-save-error');
        if (saveError) { saveError.style.display = 'none'; saveError.textContent = ''; }

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
                saveError.textContent = 'Š®<‡›§Š¦?a?OŠ¦ý‡\"<†??‡\"ñ‹¬?Š¦ý‡\"<‚­z†^‹¬?Šª>†,®a??‡s+†úý†­®†_®a?,';
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
            category: util.catCodeToLabel(category_code),
            lecturer_code: lecturer_code,
            lecturer: util.lecturerLabelByCode(lecturer_code),
            intro: getVal('intro'),
            outline: getVal('outline'),
            duration_minutes: duration,
            is_active: is_active,
            sessions: sessions
        };

        try {
            const res = await fetch(state.apiBase + '/admin/course/save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': state.nonce
                },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            const result = await res.json();

            if (!res.ok || !result || !result.success) {
                const msg = (result && result.message) ? result.message : '†,ý†-~†ñ‘-';
                if (saveError) {
                    saveError.textContent = msg;
                    saveError.style.display = 'block';
                }
                return;
            }

            w.alert('†úý†,ý†-~‹¬OŠ¦ý‡\"<‡ú\"ŠTY‹¬s' + (result.course_code || payload.course_code || ''));
            await ns.fetchAll();
        } catch (e) {
            if (saveError) {
                saveError.textContent = '†,ý†-~‡T¬‡\"Y‚O_Š¦‹¬OŠ®<‡\"?†_O‚Ø?ŠcÝa?,';
                saveError.style.display = 'block';
            }
        }
    };

    ns.initEvents = function () {
        const filters = dom.filter;
        [filters.q, filters.cat, filters.lec, filters.course, filters.dateFrom, filters.dateTo, filters.mode].forEach(el => {
            if (!el) return;
            el.addEventListener('input', ns.applyFilters);
            el.addEventListener('change', ns.applyFilters);
        });

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

        if (dom.modal.btnCancel) dom.modal.btnCancel.addEventListener('click', ns.closeLecturerModal);
        if (dom.modal.backdrop) dom.modal.backdrop.addEventListener('click', ns.closeLecturerModal);
        if (dom.modal.btnSave) dom.modal.btnSave.addEventListener('click', ns.saveLecturerFromModal);
    };
})(window);
