(function (w) {
    const ns = w.TPMACourseAdmin = w.TPMACourseAdmin || {};

    ns.init = function (config) {
        ns.setConfig(config || {});
        ns.cacheDom();
        ns.initEvents();
        ns.fetchAll();
    };

    if (w.TPMACourseAdminConfig) {
        ns.init(w.TPMACourseAdminConfig);
    }
})(window);
