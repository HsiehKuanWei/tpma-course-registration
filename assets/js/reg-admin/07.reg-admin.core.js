//把所有模塊「串起來」並提供對外入口

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const API = global.TPMARegAdmin.api;
const S = global.TPMARegAdmin.state;
const UI = global.TPMARegAdmin.ui;
const R = global.TPMARegAdmin.render;

function bootstrap(){
  const config = global.TPMARegAdminConfig || {};
  const apiBase = config.apiBase || '';
  const nonce = config.nonce || '';
  const orderEditBase = config.orderEditBase || '';
  const viewModeStorageKey = 'tpmaRegAdminViewMode';

  if (!apiBase || !nonce) {
    console.error('TPMA reg admin missing apiBase or nonce');
    return;
  }

  const ctx = {
    apiBase,
    nonce,
    orderEditBase,
    data: { allCourses: [], allRegs: [], currentRegs: [], currentGroups: [], currentPages: [] },
    state: S.create(),
    dom: {
      tbody: document.getElementById('tpma-reg-tbody'),
      selectAllHead: document.getElementById('tpma-select-all-head'),
      pagePrev: document.getElementById('tpma-page-prev'),
      pageNext: document.getElementById('tpma-page-next'),
      pageInfo: document.getElementById('tpma-page-info'),
      pagination: document.querySelector('.tpma-pagination'),
      viewModeNested: document.getElementById('tpma-view-mode-nested'),
      viewModeFlat: document.getElementById('tpma-view-mode-flat'),
      grid: document.querySelector('.tpma-reg-grid'),
      bulkToolbar: document.getElementById('tpma-bulk-toolbar'),
      bulkCount: document.getElementById('tpma-bulk-count'),
      bulkAction: document.getElementById('tpma-bulk-action'),
      bulkApply: document.getElementById('tpma-bulk-apply'),
      bulkClear: document.getElementById('tpma-bulk-clear'),
      bulkHint: document.getElementById('tpma-bulk-hint'),
      bulkResult: document.getElementById('tpma-bulk-result'),
      // Add references to header menu buttons and menus
      menuButtons: document.querySelectorAll('.tpma-th-menu-btn'),
      menus: document.querySelectorAll('.tpma-th-menu')
    },
    actions: {}
  };

  if (!ctx.dom.tbody || !ctx.dom.pageInfo || !ctx.dom.pagePrev || !ctx.dom.pageNext || !ctx.dom.selectAllHead) {
    console.error('TPMA reg admin missing required DOM nodes');
    return;
  }

  try{
    const savedViewMode = global.localStorage ? global.localStorage.getItem(viewModeStorageKey) : '';
    if (savedViewMode === 'flat' || savedViewMode === 'nested') {
      ctx.state.viewMode = savedViewMode;
    }
  }catch(e){
    // ignore storage failures
  }

  // wire actions used by render/ui
  ctx.actions.updateBatchButtonsEnabled = ()=> UI.updateBatchButtonsEnabled(ctx);
  ctx.actions.updatePaginationControls = ()=> UI.updatePaginationControls(ctx);
  ctx.actions.refresh = ()=> UI.refreshFromServer(ctx);
  ctx.actions.setViewMode = (mode)=>{
    if (mode !== 'flat' && mode !== 'nested') return;
    ctx.state.viewMode = mode;
    try{
      if (global.localStorage) {
        global.localStorage.setItem(viewModeStorageKey, mode);
      }
    }catch(e){
      // ignore storage failures
    }
    R.renderTable(ctx);
    UI.updateViewModeButtons(ctx);
  };

  UI.bind(ctx);
  if (global.TPMARegAdmin.exportModule) {
    global.TPMARegAdmin.exportModule.init(ctx);
  }
  ctx.state.isLoading = true;
  UI.applyFiltersAndRender(ctx); // Force initial render and state update

  (async function init(){
    await API.loadCourses(ctx);
    await UI.refreshFromServer(ctx);
  })();
}

global.TPMARegAdmin.bootstrap = bootstrap;

})(window);
