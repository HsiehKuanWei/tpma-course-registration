//資料狀態 + 篩選排序 + 分頁狀態

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const S = global.TPMARegAdmin.state = global.TPMARegAdmin.state || {};

S.create = function createState(){
  const now = new Date();
  return {
    pageSize: 50,
    currentPage: 1,
    viewMode: 'nested',
    nestedMonth: now.getMonth() + 1,
    filter: {
      q: '',
      course_id: '',
      class_date: '',
      class_date_from: '',
      class_date_to: '',
      status: '',
      receipt_status: '',
      receipt_type: '',
      created_from: '',
      created_to: '',
      remit_from: '',
      remit_to: '',
      test_state: '',
      payment_status: ''
    },
    sort: { field: 'created_at', dir: 'desc' }
  };
};

S.getTestState = function getTestState(row){
  const score = row.test_score;
  if (score == null || String(score).trim() === '') return 'notyet';
  return 'done';
};

S.isCancelledRegistration = function isCancelledRegistration(row){
  if (row && row.counts_for_class != null) {
    return String(row.counts_for_class) === '0' || row.counts_for_class === false;
  }

  const values = [
    row && row.status,
    row && row.status_label,
    row && row.payment_status,
    row && row.payment_status_label,
    row && row.order_status,
    row && row.order_status_label
  ];

  return values.some(function(value){
    const text = String(value || '').toLowerCase();
    return text === 'cancelled' ||
      text === 'wc-cancelled' ||
      text.indexOf('已取消') !== -1;
  });
};

S.getCountedRows = function getCountedRows(rows){
  return (rows || []).filter(function(row){
    return !S.isCancelledRegistration(row);
  });
};

S.getNormalizedClassDateKey = function getNormalizedClassDateKey(ctx, row){
  const classDate = row && row.class_date != null ? String(row.class_date).trim() : '';
  if (!classDate) return '';

  if (classDate.length > 10 && classDate.indexOf(' ') !== -1) {
    return classDate;
  }

  const sessionDt = S.findSessionDatetimeForRow(ctx, row);
  return sessionDt ? String(sessionDt).trim() : classDate;
};

S.getClassGroupKey = function getClassGroupKey(ctx, row){
  const courseId = row.course_id == null ? '' : String(row.course_id);
  const classDate = S.getNormalizedClassDateKey(ctx, row);
  const lecturer = row.lecturer_name || row.lecturer || row.lecturer_code || '';
  return [courseId, classDate, lecturer].join('::');
};

S.getCourseHoursForRow = function getCourseHoursForRow(ctx, row){
  const tryParse = function(v){
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };

  if (row && row.class_hours){ const n = tryParse(row.class_hours); if (n > 0) return n; }
  if (row && row.course_hours){ const n = tryParse(row.course_hours); if (n > 0) return n; }
  if (row && row.hours){ const n = tryParse(row.hours); if (n > 0) return n; }
  if (row && row.duration_minutes){ const n = tryParse(row.duration_minutes); if (n > 0) return n / 60; }

  const courses = (ctx && ctx.data && ctx.data.allCourses) || [];
  if (courses.length && row && row.course_id) {
    const course = courses.find(function(c){ return String(c.id) === String(row.course_id); });
    if (course) {
      if (course.class_hours){ const n = tryParse(course.class_hours); if (n > 0) return n; }
      if (course.course_hours){ const n = tryParse(course.course_hours); if (n > 0) return n; }
      if (course.hours){ const n = tryParse(course.hours); if (n > 0) return n; }
      if (course.duration_minutes){ const n = tryParse(course.duration_minutes); if (n > 0) return n / 60; }
    }
  }

  return 3;
};

S.findSessionDatetimeForRow = function findSessionDatetimeForRow(ctx, row){
  const courses = (ctx && ctx.data && ctx.data.allCourses) || [];
  if (!courses.length || !row || !row.course_id || !row.class_date) return null;

  const course = courses.find(function(c){ return String(c.id) === String(row.course_id); });
  if (!course || !Array.isArray(course.sessions) || !course.sessions.length) return null;

  if (row.session_id) {
    const linked = course.sessions.find(function(s){ return String(s.id) === String(row.session_id); });
    if (linked && linked.session_datetime) return linked.session_datetime;
  }

  const dateOnly = String(row.class_date).substring(0,10);
  const sameDay = course.sessions.find(function(s){
    return s.session_datetime && String(s.session_datetime).substring(0,10) === dateOnly;
  });
  return sameDay ? sameDay.session_datetime : null;
};

S.getGroupBaseRow = function getGroupBaseRow(group){
  return (group && group.rows && group.rows[0]) || group || {};
};

S.isAdjustingGroup = function isAdjustingGroup(group){
  const row = S.getGroupBaseRow(group);
  const courseId = row && row.course_id;
  const classDate = row && row.class_date;
  const isAdjustingCourse = (
    courseId === 'adjusting' || courseId === 0 || courseId === '0' || courseId === '' || courseId == null
  );
  const isAdjustingDate = (
    classDate === 'adjusting' || classDate === '' || classDate == null
  );
  return isAdjustingCourse || isAdjustingDate;
};

S.getGroupSortTimestamp = function getGroupSortTimestamp(ctx, group){
  const row = S.getGroupBaseRow(group);
  let raw = row && row.class_date ? String(row.class_date).trim() : '';
  if (!raw) return Number.MAX_SAFE_INTEGER;

  if (raw.length <= 10 || raw.indexOf(' ') === -1) {
    const sessionDt = S.findSessionDatetimeForRow(ctx, row);
    if (sessionDt) raw = String(sessionDt).trim();
  }

  let ts = Date.parse(raw.replace(' ', 'T'));
  if (!isNaN(ts)) return ts;

  const dateOnly = raw.substring(0,10);
  ts = Date.parse(dateOnly + 'T00:00:00');
  return isNaN(ts) ? Number.MAX_SAFE_INTEGER : ts;
};

S.isGroupCompleted = function isGroupCompleted(ctx, group){
  const row = S.getGroupBaseRow(group);
  const startTs = S.getGroupSortTimestamp(ctx, row);
  if (!isFinite(startTs) || startTs === Number.MAX_SAFE_INTEGER) return false;

  const hours = S.getCourseHoursForRow(ctx, row);
  const durationMs = hours > 0 ? hours * 60 * 60 * 1000 : 0;
  return (startTs + durationMs) < Date.now();
};

S.buildClassGroups = function buildClassGroups(ctx, rows){
  const groups = [];
  const map = {};

  (rows || []).forEach(function(row){
    const key = S.getClassGroupKey(ctx, row);
    if (!map[key]) {
      map[key] = {
        key: key,
        course_id: row.course_id,
        class_date: row.class_date,
        course_name: row.course_name,
        lecturer_name: row.lecturer_name || row.lecturer || '',
        lecturer: row.lecturer || row.lecturer_name || '',
        rows: []
      };
      groups.push(map[key]);
    }
    map[key].rows.push(row);
  });

  groups.forEach(function(group){
    group.studentCount = S.getCountedRows(group.rows).length;
  });

  return groups;
};

S.canIncludeCourseOnlyGroups = function canIncludeCourseOnlyGroups(filter){
  const f = filter || {};
  if (f.status || f.receipt_status || f.receipt_type || f.created_from || f.created_to) return false;
  if (f.remit_from || f.remit_to || f.test_state || f.payment_status) return false;
  return true;
};

S.buildCourseOnlyGroups = function buildCourseOnlyGroups(ctx, existingGroups){
  if (!S.canIncludeCourseOnlyGroups(ctx && ctx.state ? ctx.state.filter : {})) return [];

  const filter = (ctx && ctx.state && ctx.state.filter) || {};
  const courses = (ctx && ctx.data && ctx.data.allCourses) || [];
  const existing = new Set((existingGroups || []).map(function(group){ return group.key; }));
  const groups = [];

  courses.forEach(function(course){
    const courseId = course && course.id != null ? String(course.id) : '';
    if (!courseId) return;
    if (filter.course_id && String(filter.course_id) !== courseId) return;

    const sessions = Array.isArray(course.sessions) ? course.sessions : [];
    sessions.forEach(function(session){
      if (!session || !session.session_datetime) return;
      if (String(session.is_active) === '0') return;

      const classDate = String(session.session_datetime);
      const dateOnly = classDate.substring(0,10);
      if (filter.class_date && filter.class_date !== dateOnly) return;
      if (filter.class_date_from && dateOnly < filter.class_date_from) return;
      if (filter.class_date_to && dateOnly > filter.class_date_to) return;

      const q = String(filter.q || '').trim().toLowerCase();
      const lecturerText = course.lecturer || course.lecturer_name || course.lecturer_code || '';
      if (q) {
        const haystack = [
          course.course_name,
          course.course_code,
          lecturerText
        ].filter(Boolean).join(' ').toLowerCase();
        if (!haystack.includes(q)) return;
      }

      const baseRow = {
        course_id: course.id,
        class_date: classDate,
        course_name: course.course_name || '',
        lecturer_name: course.lecturer_name || course.lecturer || course.lecturer_code || '',
        lecturer: lecturerText,
        lecturer_code: course.lecturer_code || '',
        duration_minutes: course.duration_minutes || '',
        class_hours: course.class_hours || '',
        course_hours: course.course_hours || '',
        hours: course.hours || ''
      };

      const key = S.getClassGroupKey(ctx, baseRow);
      if (existing.has(key)) return;
      existing.add(key);

      groups.push({
        key: key,
        course_id: baseRow.course_id,
        class_date: baseRow.class_date,
        course_name: baseRow.course_name,
        lecturer_name: baseRow.lecturer_name,
        lecturer: baseRow.lecturer,
        lecturer_code: baseRow.lecturer_code,
        duration_minutes: baseRow.duration_minutes,
        class_hours: baseRow.class_hours,
        course_hours: baseRow.course_hours,
        hours: baseRow.hours,
        rows: [],
        studentCount: 0
      });
    });
  });

  return groups;
};

S.sortNestedGroups = function sortNestedGroups(ctx, groups){
  return (groups || []).slice().sort(function(a, b){
    const ta = S.getGroupSortTimestamp(ctx, a);
    const tb = S.getGroupSortTimestamp(ctx, b);
    if (ta !== tb) return ta - tb;

    const courseA = String((a && a.course_name) || '');
    const courseB = String((b && b.course_name) || '');
    const byCourse = courseA.localeCompare(courseB, 'zh-Hant');
    if (byCourse !== 0) return byCourse;

    const lecturerA = String((a && (a.lecturer_name || a.lecturer)) || '');
    const lecturerB = String((b && (b.lecturer_name || b.lecturer)) || '');
    return lecturerA.localeCompare(lecturerB, 'zh-Hant');
  });
};

S.getGroupMonth = function getGroupMonth(ctx, group){
  const ts = S.getGroupSortTimestamp(ctx, group);
  if (!isFinite(ts) || ts === Number.MAX_SAFE_INTEGER) return 0;
  return new Date(ts).getMonth() + 1;
};

S.buildNestedMonths = function buildNestedMonths(ctx, groups){
  const months = {};
  for (let month = 1; month <= 12; month += 1) {
    months[month] = [];
  }
  months.adjusting = [];

  (groups || []).forEach(function(group){
    if (S.isAdjustingGroup(group)) {
      if ((group.rows || []).length > 0) {
        months.adjusting.push(group);
      }
      return;
    }

    const month = S.getGroupMonth(ctx, group);
    if (months[month]) months[month].push(group);
  });

  return months;
};

S.getNestedMonthGroups = function getNestedMonthGroups(ctx){
  const month = (ctx.state && ctx.state.nestedMonth) || 0;
  const months = (ctx.data && ctx.data.nestedMonths) || {};
  if (month === 'adjusting') return months.adjusting || [];
  return months[month] || [];
};

S.paginateClassGroups = function paginateClassGroups(groups, maxLearners){
  const pages = [];
  let page = [];
  let pageCount = 0;
  const pageLimit = Math.max(1, parseInt(maxLearners, 10) || 50);

  (groups || []).forEach(function(group){
    const size = (group.rows || []).length;
    if (!page.length) {
      page.push(group);
      pageCount = size;
      return;
    }

    if (pageCount + size > pageLimit) {
      pages.push(page);
      page = [group];
      pageCount = size;
      return;
    }

    page.push(group);
    pageCount += size;
  });

  if (page.length) {
    pages.push(page);
  }

  return pages;
};

S.getTotalPages = function getTotalPages(ctx){
  if (ctx.state && ctx.state.viewMode === 'nested') return 1;
  const pages = ctx.data.currentPages || [];
  return Math.max(1, pages.length || 1);
};

S.getCurrentPageGroups = function getCurrentPageGroups(ctx){
  const totalPages = S.getTotalPages(ctx);
  if (ctx.state.currentPage > totalPages) {
    ctx.state.currentPage = totalPages;
  }
  if (ctx.state.currentPage < 1) {
    ctx.state.currentPage = 1;
  }

  if (ctx.state && ctx.state.viewMode === 'nested') {
    return S.getNestedMonthGroups(ctx);
  }
  const pages = ctx.data.currentPages || [];
  return pages[ctx.state.currentPage - 1] || [];
};

S.getCurrentPageRows = function getCurrentPageRows(ctx){
  const groups = S.getCurrentPageGroups(ctx);
  const rows = [];
  groups.forEach(function(group){
    (group.rows || []).forEach(function(row){
      rows.push(row);
    });
  });
  return rows;
};

S.getPaginationMeta = function getPaginationMeta(ctx){
  const totalRows = (ctx.data.currentRegs || []).length;
  const totalPages = S.getTotalPages(ctx);
  const currentPage = Math.min(Math.max(1, ctx.state.currentPage), totalPages);
  const currentRows = S.getCurrentPageRows(ctx);

  let start = 0;
  for (let i = 0; i < currentPage - 1; i += 1) {
    const groups = (ctx.data.currentPages || [])[i] || [];
    groups.forEach(function(group){
      start += (group.rows || []).length;
    });
  }

  return {
    totalRows: totalRows,
    totalPages: totalPages,
    currentPage: currentPage,
    pageRows: currentRows.length,
    start: totalRows === 0 ? 0 : start + 1,
    end: totalRows === 0 ? 0 : start + currentRows.length
  };
};

S.apply = function applyFiltersAndSort(ctx){
  let list = ctx.data.allRegs.slice();
  const f = ctx.state.filter;
  const sort = ctx.state.sort;

  if (f.q) {
    const q = f.q.toLowerCase();
    list = list.filter(function(r){
      const fields = [r.reg_no, r.student_name, r.contact_name, r.company_name];
      return fields.some(function(v){
        return v && String(v).toLowerCase().includes(q);
      });
    });
  }
  if (f.course_id) list = list.filter(r => String(r.course_id) === String(f.course_id));

  if (f.class_date) {
    const target = f.class_date;
    list = list.filter(r => (r.class_date || '').substring(0,10) === target);
  }
  if (f.class_date_from || f.class_date_to) {
    list = list.filter(function(r){
      const d = (r.class_date || '').substring(0,10);
      if (!d) return false;
      if (f.class_date_from && d < f.class_date_from) return false;
      if (f.class_date_to && d > f.class_date_to) return false;
      return true;
    });
  }

  if (f.status) list = list.filter(r => String(r.status) === String(f.status));
  if (f.receipt_status) list = list.filter(r => String(r.receipt_status) === String(f.receipt_status));
  if (f.receipt_type) list = list.filter(r => String(r.receipt_type) === String(f.receipt_type));

  if (f.test_state) {
    list = list.filter(r => S.getTestState(r) === f.test_state);
  }

  if (f.created_from || f.created_to) {
    list = list.filter(function(r){
      const v = r.created_at || '';
      if (!v) return false;
      const d = v.substring(0,10);
      if (f.created_from && d < f.created_from) return false;
      if (f.created_to && d > f.created_to) return false;
      return true;
    });
  }

  if (f.remit_from || f.remit_to) {
    list = list.filter(function(r){
      const d = (r.remit_paid_at || '').substring(0,10);
      if (!d) return false;
      if (f.remit_from && d < f.remit_from) return false;
      if (f.remit_to && d > f.remit_to) return false;
      return true;
    });
  }

  if (f.payment_status) list = list.filter(r => String(r.payment_status) === String(f.payment_status));

  const field = sort.field;
  const dir = sort.dir;
  if (field) {
    list.sort(function(a, b){
      const va = (a[field] == null ? '' : String(a[field]));
      const vb = (b[field] == null ? '' : String(b[field]));
      if (va < vb) return dir === 'asc' ? -1 : 1;
      if (va > vb) return dir === 'asc' ? 1 : -1;
      return 0;
    });
  }

  ctx.data.currentRegs = list;
  ctx.data.currentGroups = S.buildClassGroups(ctx, list);
  ctx.data.currentPages = S.paginateClassGroups(ctx.data.currentGroups, ctx.state.pageSize);
  ctx.data.nestedGroups = S.sortNestedGroups(ctx, ctx.data.currentGroups.concat(S.buildCourseOnlyGroups(ctx, ctx.data.currentGroups)));
  ctx.data.nestedMonths = S.buildNestedMonths(ctx, ctx.data.nestedGroups);
  ctx.state.currentPage = 1;
};

})(window);
