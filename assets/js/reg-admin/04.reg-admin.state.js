//資料狀態 + 篩選排序 + 分頁狀態

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const S = global.TPMARegAdmin.state = global.TPMARegAdmin.state || {};

S.create = function createState(){
  return {
    pageSize: 50,
    currentPage: 1,
    viewMode: 'nested',
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

S.getClassGroupKey = function getClassGroupKey(row){
  const courseId = row.course_id == null ? '' : String(row.course_id);
  const classDate = row.class_date == null ? '' : String(row.class_date);
  const lecturer = row.lecturer_code || row.lecturer_name || row.lecturer || '';
  return [courseId, classDate, lecturer].join('::');
};

S.buildClassGroups = function buildClassGroups(rows){
  const groups = [];
  const map = {};

  (rows || []).forEach(function(row){
    const key = S.getClassGroupKey(row);
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
    group.studentCount = group.rows.length;
  });

  return groups;
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
  return Math.max(1, (ctx.data.currentPages || []).length || 1);
};

S.getCurrentPageGroups = function getCurrentPageGroups(ctx){
  const totalPages = S.getTotalPages(ctx);
  if (ctx.state.currentPage > totalPages) {
    ctx.state.currentPage = totalPages;
  }
  if (ctx.state.currentPage < 1) {
    ctx.state.currentPage = 1;
  }

  return (ctx.data.currentPages || [])[ctx.state.currentPage - 1] || [];
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
  ctx.data.currentGroups = S.buildClassGroups(list);
  ctx.data.currentPages = S.paginateClassGroups(ctx.data.currentGroups, ctx.state.pageSize);
  ctx.state.currentPage = 1;
};

})(window);
