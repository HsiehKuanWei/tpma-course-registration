//資料狀態 + 篩選排序 + 分頁狀態

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const S = global.TPMARegAdmin.state = global.TPMARegAdmin.state || {};
const U = global.TPMARegAdmin.utils;
const L = global.TPMARegAdmin.labels;

S.create = function createState(){
  return {
    pageSize: 50,
    currentPage: 1,
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

S.apply = function applyFiltersAndSort(ctx){
  let list = ctx.data.allRegs.slice();
  const f = ctx.state.filter;
  const sort = ctx.state.sort;

  if (f.q) {
    const q = f.q.toLowerCase();
    list = list.filter(r=>{
      const fields = [r.reg_no, r.student_name, r.contact_name, r.company_name];
      return fields.some(v => v && String(v).toLowerCase().includes(q));
    });
  }
  if (f.course_id) list = list.filter(r => String(r.course_id) === String(f.course_id));

  if (f.class_date) {
    const target = f.class_date;
    list = list.filter(r => (r.class_date || '').substring(0,10) === target);
  }
  if (f.class_date_from || f.class_date_to) {
    list = list.filter(r=>{
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
    list = list.filter(r=>{
      const v = r.created_at || '';
      if (!v) return false;
      const d = v.substring(0,10);
      if (f.created_from && d < f.created_from) return false;
      if (f.created_to && d > f.created_to) return false;
      return true;
    });
  }

  if (f.remit_from || f.remit_to) {
    list = list.filter(r=>{
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
    list.sort((a,b)=>{
      const va = (a[field] == null ? '' : String(a[field]));
      const vb = (b[field] == null ? '' : String(b[field]));
      if (va < vb) return dir === 'asc' ? -1 : 1;
      if (va > vb) return dir === 'asc' ? 1 : -1;
      return 0;
    });
  }

  ctx.data.currentRegs = list;
  ctx.state.currentPage = 1;
};

})(window);
