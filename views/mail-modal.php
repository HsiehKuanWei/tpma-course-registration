<?php
if (!defined('ABSPATH')) { exit; }

// 嘗試使用外部傳進來的 $apiBase / $restNonce，沒有就自己算
if (empty($apiBase)) {
    $apiBase = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
}
if (empty($restNonce)) {
    $restNonce = wp_create_nonce('wp_rest');
}
?>
<style>
.tpma-mail-backdrop {
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.35);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
}
.tpma-mail-modal {
    background:#fff;
    width:900px;
    max-width:95vw;
    max-height:90vh;
    border-radius:8px;
    box-shadow:0 4px 18px rgba(0,0,0,.2);
    display:flex;
    flex-direction:column;
    overflow:hidden;
    font-size:13px;
}
.tpma-mail-modal-header {
    padding:8px 12px;
    border-bottom:1px solid #ddd;
    display:flex;
    justify-content:space-between;
    align-items:center;
    background:#f8f9fa;
}
.tpma-mail-modal-body {
    display:flex;
    gap:8px;
    padding:8px 12px;
    overflow:auto;
}
.tpma-mail-modal-col {
    flex:1 1 0;
    min-width:0;
}
.tpma-mail-modal-footer {
    padding:8px 12px;
    border-top:1px solid #ddd;
    text-align:right;
    background:#f8f9fa;
}
.tpma-mail-input, .tpma-mail-select, .tpma-mail-textarea {
    width:100%;
    box-sizing:border-box;
    padding:4px 6px;
    border:1px solid #ccc;
    border-radius:3px;
    font-size:13px;
}
.tpma-mail-textarea {
    min-height:200px;
    font-family:monospace;
}
.tpma-mail-label {
    font-weight:bold;
    margin-top:4px;
    margin-bottom:2px;
    display:block;
}
.tpma-mail-preview {
    border:1px solid #ddd;
    border-radius:3px;
    padding:6px;
    background:#fff;
    min-height:200px;
    overflow:auto;
}
.tpma-mail-tag {
    display:inline-block;
    padding:1px 4px;
    border-radius:3px;
    border:1px solid #ddd;
    margin:1px;
    background:#f1f3f5;
    font-size:11px;
}
.tpma-mail-btn {
    display:inline-block;
    padding:4px 10px;
    border-radius:3px;
    border:1px solid #007cba;
    background:#007cba;
    color:#fff;
    font-size:13px;
    cursor:pointer;
}
.tpma-mail-btn.secondary {
    border-color:#ccc;
    background:#fff;
    color:#333;
}
.tpma-mail-btn + .tpma-mail-btn {
    margin-left:4px;
}
@media (max-width:768px){
    .tpma-mail-modal {
        width:100vw;
        height:100vh;
        border-radius:0;
    }
    .tpma-mail-modal-body {
        flex-direction:column;
    }
}
</style>

<div class="tpma-mail-backdrop" id="tpma-mail-backdrop">
    <div class="tpma-mail-modal">
        <div class="tpma-mail-modal-header">
            <div>
                <strong>信件模板設定</strong>
                <span id="tpma-mail-current-key" style="margin-left:8px;color:#666;"></span>
            </div>
            <button type="button" class="tpma-mail-btn secondary" id="tpma-mail-btn-close">關閉</button>
        </div>
        <div class="tpma-mail-modal-body">
            <div class="tpma-mail-modal-col">
                <label class="tpma-mail-label">模板選擇</label>
                <select class="tpma-mail-select" id="tpma-mail-template-select"></select>

                <label class="tpma-mail-label">寄件人名稱 (from_name)</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-from-name">

                <label class="tpma-mail-label">寄件人信箱 (from_email)</label>
                <input type="email" class="tpma-mail-input" id="tpma-mail-from-email">

                <label class="tpma-mail-label">預設 CC (逗號分隔)</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-default-cc">

                <label class="tpma-mail-label">預設 BCC (逗號分隔)</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-default-bcc">

                <label class="tpma-mail-label">使用廣告區塊</label>
                <select class="tpma-mail-select" id="tpma-mail-use-ad">
                    <option value="0">不使用</option>
                    <option value="1">使用</option>
                </select>

                <label class="tpma-mail-label">廣告 key</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-ad-key">

                <div style="margin-top:6px;">
                    <span class="tpma-mail-label" style="margin-bottom:2px;">可用變數 (context)</span>
                    <div id="tpma-mail-vars"></div>
                </div>
            </div>

            <div class="tpma-mail-modal-col">
                <label class="tpma-mail-label">主旨</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-subject">

                <label class="tpma-mail-label">內文 (HTML，支援 {{變數}})</label>
                <textarea class="tpma-mail-textarea" id="tpma-mail-body"></textarea>
            </div>

            <div class="tpma-mail-modal-col">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="tpma-mail-label" style="margin-bottom:0;">預覽</span>
                    <button type="button" class="tpma-mail-btn secondary" id="tpma-mail-btn-refresh-preview">重新預覽</button>
                </div>
                <div class="tpma-mail-preview" id="tpma-mail-preview"></div>
            </div>
        </div>
        <div class="tpma-mail-modal-footer">
            <button type="button" class="tpma-mail-btn secondary" id="tpma-mail-btn-send-test">寄送測試信</button>
            <button type="button" class="tpma-mail-btn" id="tpma-mail-btn-save">儲存設定</button>
        </div>
    </div>
</div>

<script>
(function(){
    const apiBase   = <?php echo json_encode($apiBase); ?>;
    const restNonce = <?php echo json_encode($restNonce); ?>;

    let state = {
        templates : {},
        config    : {},
        currentKey: null,
    };

    const elBackdrop = document.getElementById('tpma-mail-backdrop');
    const elKey      = document.getElementById('tpma-mail-current-key');
    const elSelect   = document.getElementById('tpma-mail-template-select');
    const elFromName = document.getElementById('tpma-mail-from-name');
    const elFromMail = document.getElementById('tpma-mail-from-email');
    const elDefCc    = document.getElementById('tpma-mail-default-cc');
    const elDefBcc   = document.getElementById('tpma-mail-default-bcc');
    const elUseAd    = document.getElementById('tpma-mail-use-ad');
    const elAdKey    = document.getElementById('tpma-mail-ad-key');
    const elVars     = document.getElementById('tpma-mail-vars');
    const elSubject  = document.getElementById('tpma-mail-subject');
    const elBody     = document.getElementById('tpma-mail-body');
    const elPreview  = document.getElementById('tpma-mail-preview');

    async function api(path, method = 'GET', data) {
        const opt = {
            method,
            headers: {
                'X-WP-Nonce': restNonce,
                'Content-Type': 'application/json',
            },
        };
        if (data) opt.body = JSON.stringify(data);
        const res = await fetch(apiBase + path, opt);
        if (!res.ok) {
            throw new Error('API error: ' + res.status);
        }
        return await res.json();
    }

    async function loadConfigAndTemplates(){
        const data = await api('/mail/templates', 'GET');
        state.templates = data.templates || {};
        state.config    = data.config || {};
        renderTemplateOptions();
    }

    function renderTemplateOptions(){
        elSelect.innerHTML = '';
        Object.keys(state.templates).forEach(function(key){
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = key;
            elSelect.appendChild(opt);
        });
        if (!state.currentKey && elSelect.options.length) {
            state.currentKey = elSelect.options[0].value;
        }
        if (state.currentKey) {
            elSelect.value = state.currentKey;
            fillFormByKey(state.currentKey);
        }
    }

    function fillFormByKey(key){
        state.currentKey = key;
        elKey.textContent = '(' + key + ')';

        const tpl   = state.templates[key] || {};
        const cfg   = (state.config.templates && state.config.templates[key]) || {};
        const fromN = state.config.from_name || '';
        const fromE = state.config.from_email || '';

        elFromName.value = fromN;
        elFromMail.value = fromE;
        elDefCc.value    = (cfg.default_cc || []).join(',');
        elDefBcc.value   = (cfg.default_bcc || []).join(',');
        elUseAd.value    = cfg.use_ad ? '1' : '0';
        elAdKey.value    = cfg.ad_key || '';

        elSubject.value  = tpl.subject || '';
        elBody.value     = tpl.body_html || '';

        renderVarsHint();
        refreshPreview();
    }

    function renderVarsHint(){
        // 實際變數集合你可以依照模板內容 / context 自行調整，這裡先列出常用
        const keys = ['reg_id','reg_no','course_id','course_name','class_date','student_name','company_name','class_link'];
        elVars.innerHTML = '';
        keys.forEach(k => {
            const span = document.createElement('span');
            span.className = 'tpma-mail-tag';
            span.textContent = '{{' + k + '}}';
            elVars.appendChild(span);
        });
    }

    async function refreshPreview(){
        if (!state.currentKey) return;
        const payload = {
            template_key: state.currentKey,
            subject     : elSubject.value,
            body_html   : elBody.value,
            // 給一組 demo context；未來你可以做成可編輯
            context     : {
                reg_id: 123,
                reg_no: 'R2025-0001',
                course_id: 1,
                course_name: '示範課程',
                class_date: '2025/01/01 09:00~12:00',
                student_name: '示範學員',
                company_name: '示範公司',
                class_link: 'https://example.com/meet',
            }
        };
        const data = await api('/mail/preview', 'POST', payload);
        elPreview.innerHTML = '<strong>' + (data.subject || '') + '</strong><hr />' + (data.body_html || '');
    }

    async function saveAll(){
        if (!state.currentKey) return;

        // 更新目前正在編輯的模板內容
        const key = state.currentKey;
        if (!state.templates[key]) state.templates[key] = {};
        state.templates[key].subject   = elSubject.value;
        state.templates[key].body_html = elBody.value;

        // 更新 config
        state.config.from_name  = elFromName.value;
        state.config.from_email = elFromMail.value;

        if (!state.config.templates) state.config.templates = {};
        const ccArr  = elDefCc.value.split(',').map(s => s.trim()).filter(Boolean);
        const bccArr = elDefBcc.value.split(',').map(s => s.trim()).filter(Boolean);

        state.config.templates[key] = {
            default_cc : ccArr,
            default_bcc: bccArr,
            use_ad     : (elUseAd.value === '1'),
            ad_key     : elAdKey.value.trim(),
        };

        await api('/mail/templates', 'POST', {
            templates: state.templates,
            config   : state.config,
        });

        alert('已儲存信件模板與設定');
    }

    async function sendTest(){
        const email = window.prompt('請輸入要寄送測試信的 Email：');
        if (!email) return;
        await api('/mail/send-test', 'POST', {
            to: email,
            template_key: state.currentKey,
        });
        alert('已送出測試信（請稍後檢查信箱）');
    }

    // 綁定事件
    document.getElementById('tpma-mail-btn-close').addEventListener('click', () => {
        elBackdrop.style.display = 'none';
    });
    elSelect.addEventListener('change', () => {
        fillFormByKey(elSelect.value);
    });
    document.getElementById('tpma-mail-btn-refresh-preview').addEventListener('click', () => {
        refreshPreview().catch(e => alert(e.message));
    });
    document.getElementById('tpma-mail-btn-save').addEventListener('click', () => {
        saveAll().catch(e => alert(e.message));
    });
    document.getElementById('tpma-mail-btn-send-test').addEventListener('click', () => {
        sendTest().catch(e => alert(e.message));
    });

    // 對外暴露簡單 API：open()
    window.TPMA_MailModal = {
        open(defaultKey){
            elBackdrop.style.display = 'flex';
            loadConfigAndTemplates()
                .then(() => {
                    if (defaultKey && state.templates[defaultKey]) {
                        state.currentKey = defaultKey;
                        fillFormByKey(defaultKey);
                    }
                })
                .catch(e => alert('載入信件模板失敗：' + e.message));
        }
    };
})();
</script>
