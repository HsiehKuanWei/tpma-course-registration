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
<div class="tpma-modal-backdrop" id="tpma-mail-backdrop">
    <div class="tpma-modal">
        <div class="tpma-modal-header">
            <h3>信件模板設定 <span id="tpma-mail-current-key" style="margin-left:8px;font-weight:normal;"></span></h3>
            <button type="button" class="tpma-modal-close-btn" id="tpma-mail-btn-close">×</button>
        </div>
        <div class="tpma-modal-content">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <label style="margin-bottom:0;">模板選擇</label>
                <button type="button" class="tpma-btn secondary" id="tpma-mail-btn-add-template">新增模板</button>
            </div>
            <select id="tpma-mail-template-select"></select>

            <label style="margin-top:15px;">寄件人名稱 (from_name)</label>
            <input type="text" id="tpma-mail-from-name">

            <label>寄件人信箱 (from_email)</label>
            <input type="email" id="tpma-mail-from-email">

            <label>副本 (逗號分隔)</label>
            <input type="text" id="tpma-mail-default-cc">

            <label>密件副本 (逗號分隔)</label>
            <input type="text" id="tpma-mail-default-bcc">

            <label>主旨</label>
            <input type="text" id="tpma-mail-subject">

            <label>內文 (HTML，支援 {{變數}})</label>
            <textarea id="tpma-mail-body"></textarea>				
            
            <label>使用廣告區塊</label>
            <select id="tpma-mail-use-ad">
                <option value="0">不使用</option>
                <option value="1">使用</option>
            </select>

            <label>廣告 key</label>
            <input type="text" id="tpma-mail-ad-key">
            <label>廣告內容 (對應上方廣告 key)</label>
            <textarea id="tpma-mail-ad-html" rows="4"></textarea>

            <label>共通尾巴 HTML (common_footer_html)</label>
            <textarea id="tpma-mail-common-footer" rows="4"></textarea>
            <div style="margin-top:12px;">
                <span style="font-weight:600;margin-bottom:4px;display:block;">可用變數 (context)</span>
                <div id="tpma-mail-vars"></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:15px;">
                <span style="font-weight:600;">預覽</span>
                <button type="button" class="tpma-btn secondary" id="tpma-mail-btn-refresh-preview">重新預覽</button>
            </div>
            <div class="tpma-mail-preview" id="tpma-mail-preview" style="margin-top:10px;border:1px solid var(--tpma-medium-gray);border-radius:4px;padding:10px;background:#fefefe;min-height:200px;overflow:auto;"></div>
        </div>
        <div class="tpma-modal-footer">
            <button type="button" class="tpma-btn secondary" id="tpma-mail-btn-send-test">寄送測試信</button>
            <button type="button" class="tpma-btn" id="tpma-mail-btn-save">儲存設定</button>
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
    const elModal = document.getElementById('tpma-mail-backdrop').querySelector('.tpma-modal'); // Select the modal inside the backdrop
    const elKey = document.getElementById('tpma-mail-current-key');
    const elSelect = elModal.querySelector('#tpma-mail-template-select');
    const elBtnAddTemplate = elModal.querySelector('#tpma-mail-btn-add-template');
    const elFromName = elModal.querySelector('#tpma-mail-from-name');
    const elFromMail = elModal.querySelector('#tpma-mail-from-email');
    const elDefCc = elModal.querySelector('#tpma-mail-default-cc');
    const elDefBcc = elModal.querySelector('#tpma-mail-default-bcc');
    const elUseAd = elModal.querySelector('#tpma-mail-use-ad');
    const elAdKey = elModal.querySelector('#tpma-mail-ad-key');
    const elVars = elModal.querySelector('#tpma-mail-vars');
    const elSubject = elModal.querySelector('#tpma-mail-subject');
    const elBody = elModal.querySelector('#tpma-mail-body');
    const elPreview = elModal.querySelector('#tpma-mail-preview');
    const elAdHtml = elModal.querySelector('#tpma-mail-ad-html');
    const elFooter = elModal.querySelector('#tpma-mail-common-footer');

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

    async function loadConfigAndTemplates() {
        const data = await api('/mail/templates', 'GET');
        state.templates = data.templates || {};
        state.config = data.config || {};
        renderTemplateOptions();
    }

    function renderTemplateOptions() {
        elSelect.innerHTML = '';
        Object.keys(state.templates).forEach(function(key) {
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

    function fillFormByKey(key) {
        state.currentKey = key;
        elKey.textContent = '(' + key + ')';

        const tpl = state.templates[key] || {};
        const cfg = (state.config.templates && state.config.templates[key]) || {};
        const fromN = state.config.from_name || '';
        const fromE = state.config.from_email || '';

        elFromName.value = fromN;
        elFromMail.value = fromE;
        elDefCc.value = (cfg.default_cc || []).join(',');
        elDefBcc.value = (cfg.default_bcc || []).join(',');
        elUseAd.value = cfg.use_ad ? '1' : '0';
        elAdKey.value = cfg.ad_key || '';

        const ads = state.config.ads || {};
        const adKey = cfg.ad_key || '';
        const adCfg = ads[adKey] || {};
        elAdHtml.value = adCfg.html || '';

        // 共通尾巴 HTML
        elFooter.value = state.config.common_footer_html || '';

        elSubject.value = tpl.subject || '';
        elBody.value = tpl.body_html || '';

        renderVarsHint();
        refreshPreview();
    }

    function renderVarsHint() {
        // 實際變數集合與後端 build_context 提供的變數一致
        const keys = [
            'course_id',
            'session_id',
            'course_name',
            'class_date',
            'session_datetime',
            'learners', // 注意：此為陣列，直接顯示可能不美觀，需在模板中自行處理
            'learner_count',
            'remit_amount_per_learner',
            'total_order_amount',
            'source',
            'note',
            'order_id',
            'order_number',
            'order_status',
            'order_total',
            'currency',
            'payment_method',
            'payment_method_title',
            'billing_name',
            'billing_email',
            'billing_phone',
            'shipping_name',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_postcode',
            'view_order_url',
            'order_received_url',
            'pay_url',
            // extra_context 暫時留空，若有新增變數可在此處添加
        ];
        elVars.innerHTML = '';
        keys.forEach(k => {
            const span = document.createElement('span');
            span.className = 'tpma-mail-tag';
            span.textContent = '{{' + k + '}}';
            elVars.appendChild(span);
        });
    }


    async function refreshPreview() {
        if (!state.currentKey) return;
        const payload = {
            template_key: state.currentKey,
            subject: elSubject.value,
            body_html: elBody.value,
            // 給一組 demo context；未來你可以做成可編輯
            context: {
                reg_no: '2025A12001',
                created_at: '2025-12-01 10:00:00',
                course_id: 1,
                course_name: '示範課程：董事會 vs 經營團隊',
                class_date: '2025/12/16（二） 13:30~16:30',
                class_date_raw: '2025-12-16',
                course_hours: 3,
                lecturer_name: '示範講師 教授',
                student_name: '示範學員',
                job_title: '部員',
                company_name: '示範公司',
                tax_id: '12345678',
                department: '企劃部',
                phone: '07-1234567',
                mobile: '0912-345-678',
                emails: 'student@example.com',
                receiver: '收件人示範',
                address: '高雄市鳳山區博愛路529號12樓',
                receipt_type: 'paper',
                source: '官網報名',
                note: '備註示範文字',
                contact_name: '示範承辦人',
                contact_email: 'contact@example.com',
                remit_paid_at: '2025-12-05',
                remit_amount: 3000,
                status: 'pending',
            }
        };
        const data = await api('/mail/preview', 'POST', payload);
        elPreview.innerHTML = '<strong>' + (data.subject || '') + '</strong><hr />' + (data.body_html || '');
    }

    async function saveAll() {
        if (!state.currentKey) return;

        // 更新目前正在編輯的模板內容
        const key = state.currentKey;
        if (!state.templates[key]) state.templates[key] = {};
        state.templates[key].subject = elSubject.value;
        state.templates[key].body_html = elBody.value;

        // 更新 config
        state.config.from_name = elFromName.value;
        state.config.from_email = elFromMail.value;

        if (!state.config.templates) state.config.templates = {};
        const ccArr = elDefCc.value.split(',').map(s => s.trim()).filter(Boolean);
        const bccArr = elDefBcc.value.split(',').map(s => s.trim()).filter(Boolean);

        state.config.templates[key] = {
            default_cc: ccArr,
            default_bcc: bccArr,
            use_ad: (elUseAd.value === '1'),
            ad_key: elAdKey.value.trim(),
        };

        // 廣告內容與開關
        if (!state.config.ads) state.config.ads = {};
        const adKey = elAdKey.value.trim();
        if (adKey) {
            if (!state.config.ads[adKey]) {
                state.config.ads[adKey] = { enabled: true, html: '' };
            }
            state.config.ads[adKey].enabled = (elUseAd.value === '1');
            state.config.ads[adKey].html = elAdHtml.value;
        }

        // 共通尾巴
        state.config.common_footer_html = elFooter.value;

        await api('/mail/templates', 'POST', {
            templates: state.templates,
            config: state.config,
        });

        alert('已儲存信件模板與設定');
    }

    async function sendTest() {
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
        elBackdrop.classList.remove('open');
        elModal.classList.remove('open');
    });
    elSelect.addEventListener('change', () => {
        fillFormByKey(elSelect.value);
    });
    elBtnAddTemplate.addEventListener('click', () => {
        const newKey = window.prompt('請輸入新模板的名稱 (英文小寫，例如：new_template)：');
        if (newKey) {
            if (state.templates[newKey]) {
                alert('模板名稱已存在，請使用其他名稱。');
                return;
            }
            state.templates[newKey] = { subject: '', body_html: '' };
            if (!state.config.templates) state.config.templates = {};
            state.config.templates[newKey] = {
                default_cc: [],
                default_bcc: [],
                use_ad: false,
                ad_key: '',
            };
            renderTemplateOptions();
            state.currentKey = newKey;
            fillFormByKey(newKey);
        }
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
        open(defaultKey) {
            elBackdrop.classList.add('open');
            elModal.classList.add('open');
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
