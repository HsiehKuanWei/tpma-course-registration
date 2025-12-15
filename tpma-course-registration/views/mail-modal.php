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
/* 保持不變 */
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

/* === 核心變動：將 .tpma-mail-modal-body 改為單縱列 === */
.tpma-mail-modal-body {
    /* 移除 display: flex; 以實現單縱列佈局 */
    /* 保持間距和內邊距 */
    gap:8px;
    padding:8px 12px;
    overflow:auto;
}
/* 移除 .tpma-mail-modal-col 的 flex 相關規則，使其成為普通區塊元素並佔滿可用寬度 */
.tpma-mail-modal-col {
    /* 移除 flex:1 1 0; min-width:0; */
    /* 加上下邊距以區隔不同內容區塊，因為原本的三個縱列現在堆疊在一起 */
    margin-bottom: 8px; 
}
/* 讓最後一個 .tpma-mail-modal-col 沒有下邊距 */
.tpma-mail-modal-col:last-child {
    margin-bottom: 0;
}
/* 保持不變 */
.tpma-mail-modal-footer {
    padding:8px 12px;
    border-top:1px solid #ddd;
    text-align:right;
    background:#f8f9fa;
}
/* 保持不變 */
.tpma-mail-input, .tpma-mail-select, .tpma-mail-textarea {
    width:100%;
    box-sizing:border-box;
    padding:4px 6px;
    border:1px solid #ccc;
    border-radius:3px;
    font-size:13px;
}
/* 保持不變 */
.tpma-mail-textarea {
    min-height:200px;
    font-family:monospace;
}
/* 保持不變 */
.tpma-mail-label {
    font-weight:bold;
    margin-top:4px;
    margin-bottom:2px;
    display:block;
}
/* 保持不變 */
.tpma-mail-preview {
    border:1px solid #ddd;
    border-radius:3px;
    padding:6px;
    background:#fff;
    min-height:200px;
    overflow:auto;
}
/* 保持不變 */
.tpma-mail-tag {
    display:inline-block;
    padding:1px 4px;
    border-radius:3px;
    border:1px solid #ddd;
    margin:1px;
    background:#f1f3f5;
    font-size:11px;
}
/* 保持不變 */
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
/* 保持不變 */
@media (max-width:768px){
    .tpma-mail-modal {
        width:100vw;
        height:100vh;
        border-radius:0;
    }
    .tpma-mail-modal-body {
        /* 在小螢幕下保持原本的 flex-direction:column; */
        flex-direction:column;
    }
    /* 移除在小螢幕下額外添加的下邊距，避免重複 */
    .tpma-mail-modal-col {
        margin-bottom: 0; 
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

                <label class="tpma-mail-label">副本 (逗號分隔)</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-default-cc">

                <label class="tpma-mail-label">密件副本 (逗號分隔)</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-default-bcc">

                <label class="tpma-mail-label">主旨</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-subject">

                <label class="tpma-mail-label">內文 (HTML，支援 {{變數}})</label>
                <textarea class="tpma-mail-textarea" id="tpma-mail-body"></textarea>				
				
                <label class="tpma-mail-label">使用廣告區塊</label>
                <select class="tpma-mail-select" id="tpma-mail-use-ad">
                    <option value="0">不使用</option>
                    <option value="1">使用</option>
                </select>

				<label class="tpma-mail-label">廣告 key</label>
                <input type="text" class="tpma-mail-input" id="tpma-mail-ad-key">
                <label class="tpma-mail-label">廣告內容 (對應上方廣告 key)</label>
                <textarea class="tpma-mail-textarea" id="tpma-mail-ad-html" rows="4"></textarea>

                <label class="tpma-mail-label">共通尾巴 HTML (common_footer_html)</label>
                <textarea class="tpma-mail-textarea" id="tpma-mail-common-footer" rows="4"></textarea>
                <div style="margin-top:6px;">
                    <span class="tpma-mail-label" style="margin-bottom:2px;">可用變數 (context)</span>
                    <div id="tpma-mail-vars"></div>
                </div>
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
	const elAdHtml   = document.getElementById('tpma-mail-ad-html');
	const elFooter   = document.getElementById('tpma-mail-common-footer');	

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

		const ads   = state.config.ads || {};
		const adKey = cfg.ad_key || '';
		const adCfg = ads[adKey] || {};
		elAdHtml.value = adCfg.html || '';

		// 共通尾巴 HTML
		elFooter.value = state.config.common_footer_html || '';		
		
        elSubject.value  = tpl.subject || '';
        elBody.value     = tpl.body_html || '';

        renderVarsHint();
        refreshPreview();
    }

	function renderVarsHint(){
			// 實際變數集合你可以依照模板內容 / context 自行調整，這裡先列出常用
			const keys = [
				'reg_no',
				'created_at',
				'course_id',
				'course_name',
				'class_date',          // 漂亮版：YYYY/MM/DD（週） HH:MM~HH:MM
				'class_date_raw',      // 原始日期：YYYY-MM-DD
				'course_hours',
				'lecturer_name',
				'student_name',
				'job_title',
				'company_name',
				'tax_id',
				'department',
				'phone',
				'mobile',
				'emails',
				'receiver',
				'address',
				'receipt_type',
				'source',
				'note',
				'contact_name',
				'contact_email',
				'remit_paid_at',
				'remit_amount',
				'status',
			];
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
                reg_no        : '2025A12001',
                created_at    : '2025-12-01 10:00:00',
                course_id     : 1,
                course_name   : '示範課程：董事會 vs 經營團隊',
                class_date    : '2025/12/16（二） 13:30~16:30',
                class_date_raw: '2025-12-16',
                course_hours  : 3,
                lecturer_name : '示範講師 教授',
                student_name  : '示範學員',
                job_title     : '部員',
                company_name  : '示範公司',
                tax_id        : '12345678',
                department    : '企劃部',
                phone         : '07-1234567',
                mobile        : '0912-345-678',
                emails        : 'student@example.com',
                receiver      : '收件人示範',
                address       : '高雄市鳳山區博愛路529號12樓',
                receipt_type  : 'paper',
                source        : '官網報名',
                note          : '備註示範文字',
                contact_name  : '示範承辦人',
                contact_email : 'contact@example.com',
                remit_paid_at : '2025-12-05',
                remit_amount  : 3000,
                status        : 'pending',
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
		
		// 廣告內容與開關
		if (!state.config.ads) state.config.ads = {};
		const adKey = elAdKey.value.trim();
		if (adKey) {
			if (!state.config.ads[adKey]) {
				state.config.ads[adKey] = { enabled: true, html: '' };
			}
			state.config.ads[adKey].enabled = (elUseAd.value === '1');
			state.config.ads[adKey].html    = elAdHtml.value;
		}

		// 共通尾巴
		state.config.common_footer_html = elFooter.value;		

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
