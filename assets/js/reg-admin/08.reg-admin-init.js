//只負責「啟動」，不放業務邏輯

(function(global){
    'use strict';

    function onReady(fn){
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function bindMailModal(){
        const btnMailTpl = document.getElementById('tpma-btn-mail-templates');
        if (btnMailTpl && global.TPMA_MailModal && typeof global.TPMA_MailModal.open === 'function') {
            btnMailTpl.addEventListener('click', function() {
                global.TPMA_MailModal.open('registration_notice');
            });
        }
    }

    function boot(){
        if (global.TPMARegAdmin && typeof global.TPMARegAdmin.bootstrap === 'function') {
            global.TPMARegAdmin.bootstrap();
        }
        bindMailModal();
    }

    onReady(boot);
})(window);
