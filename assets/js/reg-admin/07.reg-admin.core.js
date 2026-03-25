//把所有模塊「串起來」並提供對外入口

(function(global){
'use strict';

global.TPMARegAdmin = global.TPMARegAdmin || {};
const API = global.TPMARegAdmin.api;
const S = global.TPMARegAdmin.state;
const UI = global.TPMARegAdmin.ui;

function bootstrap(){
  const config = global.TPMARegAdminConfig || {};
  const apiBase = config.apiBase || '';
  const nonce = config.nonce || '';
  const orderEditBase = config.orderEditBase || '';

  if (!apiBase || !nonce) {
    console.error('TPMA reg admin missing apiBase or nonce');
    return;
  }

  const ctx = {
    apiBase,
    nonce,
    orderEditBase,
    data: { allCourses: [], allRegs: [], currentRegs: [] },
    state: S.create(),
    dom: {
      tbody: document.getElementById('tpma-reg-tbody'),
      selectAllHead: document.getElementById('tpma-select-all-head'),
      pagePrev: document.getElementById('tpma-page-prev'),
      pageNext: document.getElementById('tpma-page-next'),
      pageInfo: document.getElementById('tpma-page-info'),
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

  // wire actions used by render/ui
  ctx.actions.updateBatchButtonsEnabled = ()=> UI.updateBatchButtonsEnabled(ctx);
  ctx.actions.updatePaginationControls = ()=> UI.updatePaginationControls(ctx);
  ctx.actions.refresh = ()=> UI.refreshFromServer(ctx);

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
