(function (w) {
    const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};
    const state = ns.state = ns.state || {
        apiBase: '',
        nonce: '',
        allCourses: [],
        lecturers: [],
        currentLecturerTargetSelect: null
    };
    const dom = ns.dom = ns.dom || {};
    const util = ns.util = ns.util || {};

    util.esc = function (s) {
        return (s || '').replace(/[&<>\"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    };

    util.parseDate = function (str) {
        if (!str) return null;
        const d = new Date(str);
        return isNaN(d.getTime()) ? null : d;
    };

    util.catCodeToLabel = function (code) {
        const catSelect = dom.filter?.cat;
        if (!catSelect || !code) return '';
        const opt = catSelect.querySelector('option[value="' + code + '"]');
        return opt ? opt.textContent || '' : '';
    };

    util.lecturerLabel = function (lecturer) {
        if (!lecturer) return '';
        return lecturer.name + (lecturer.title ? ' ' + lecturer.title : '');
    };

    util.lecturerLabelByCode = function (code) {
        if (!code) return '';
        const l = state.lecturers.find(x => x.code === code);
        return l ? util.lecturerLabel(l) : '';
    };

    util.formatSessionLabel = function (dtStr, durationMinutes) {
        if (!dtStr) return '';
        const d = util.parseDate(dtStr.replace(' ', 'T'));
        if (!d) return util.esc(dtStr);
        const weekdays = ['\u65e5', '\u4e00', '\u4e8c', '\u4e09', '\u56db', '\u4e94', '\u516d'];
        const y = d.getFullYear();
        const m = ('0' + (d.getMonth() + 1)).slice(-2);
        const day = ('0' + d.getDate()).slice(-2);
        const w = weekdays[d.getDay()];
        const hh = ('0' + d.getHours()).slice(-2);
        const mm = ('0' + d.getMinutes()).slice(-2);
        const dur = durationMinutes && durationMinutes > 0 ? durationMinutes : 180;
        const end = new Date(d.getTime() + dur * 60000);
        const eh = ('0' + end.getHours()).slice(-2);
        const em = ('0' + end.getMinutes()).slice(-2);
        return `${y}-${m}-${day}‹¬^${w}‹¬% ${hh}:${mm}~${eh}:${em}`;
    };

    ns.setConfig = function (config) {
        state.apiBase = config.apiBase || '';
        state.nonce = config.nonce || '';
    };

    ns.cacheDom = function () {
        dom.wrap = document.getElementById('tpma-course-admin');
        dom.courseList = document.getElementById('tpma-course-list');

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

        dom.modal = {
            backdrop: document.getElementById('tpma-lecturer-backdrop'),
            box: document.getElementById('tpma-lecturer-modal'),
            code: document.getElementById('tpma-lect-code'),
            name: document.getElementById('tpma-lect-name'),
            title: document.getElementById('tpma-lect-title'),
            sort: document.getElementById('tpma-lect-sort'),
            error: document.getElementById('tpma-lect-error'),
            btnSave: document.getElementById('tpma-lect-save-btn'),
            btnCancel: document.getElementById('tpma-lect-cancel-btn')
        };
    };
})(window);
