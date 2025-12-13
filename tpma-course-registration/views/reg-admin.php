<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>

<?php
// 引入共用 mail modal（路徑依你實際放的位置調整）
// 假設 mail-modal.php 跟 reg-admin.php 一樣在 views 資料夾：
include __DIR__ . '/mail-modal.php';
?>
<style>
	.tpma-wrap { font-size:14px; }

	/* 上方文字搜尋列 */
	.tpma-filter-row {
		display:flex;
		flex-wrap:wrap;
		gap:6px;
		margin-bottom:8px;
		align-items:center;
	}
	.tpma-filter-row input,
	.tpma-filter-row select {
		padding:3px 6px;
		font-size:14px;
	}

	/* 表頭＋下拉選單 */
	.tpma-th-inner {
		position:relative;
		display:flex;
		align-items:center;
		gap:4px;
	}
	.tpma-th-menu-btn {
		border:1px solid #999;
		background:#fff;
		cursor:pointer;
		font-size:14px;
		padding:0 4px;
		border-radius:3px;
		line-height:1.2;
	}
	.tpma-th-menu-btn:hover {
		background:#eee;
	}
	.tpma-th-menu-btn.tpma-filter-active {
		background:#cfe9ff;
		border-color:#4a90e2;
	}
	.tpma-th-menu {
		position:absolute;
		top:100%;
		left:0;
		background:#fff;
		border:1px solid #ccc;
		padding:6px;
		z-index:50;
		min-width:200px;
		display:none;
		box-shadow:0 2px 5px rgba(0,0,0,.15);
	}
	.tpma-th-menu h4 {
		margin:0 0 4px;
		font-size:12px;
	}
	.tpma-th-menu .tpma-menu-section {
		margin-top: 4px;
		padding-top: 4px;
		border-top: 1px solid #eee;
		display: grid;
	}
	.tpma-th-menu label {
		display:block;
		font-size:14px;
		margin:2px 0;
		text-align:left;
	}
	.tpma-th-menu input,
	.tpma-th-menu select {
		box-sizing:border-box;
		font-size:12px;
		padding:2px 3px;
	}
	.tpma-th-menu .tpma-btn {
		font-size:12px;
		padding:2px 6px;
		margin-top:4px;
	}

	.tpma-btn {
		padding:3px 8px;
		font-size:14px;
		cursor:pointer;
		margin:0 4px 4px 0;
		}

	.tpma-th-menu{
		white-space:normal;		
	}	
	
	.tpma-menu-options{
		width:100%;
		border-radius:0;
		padding: 3px 2px;
		margin: 0;
		font-size: 14px;
		text-align: left;
		background: #FFF;
		color: black;
	}
	
	.tpma-menu-options:hover{
		background: #BBB;
		color: black;
		cursor:pointer;
	}
	
	.tpma-menu-options:has(input[type="checkbox"]:checked){
		color: #888;
	}

	.tpma-menu-options input[type=checkbox] {
		display: none;
	}	
	
	
	/* 表格 */
	.tpma-reg-table {
		width:100%;
		border-collapse:collapse;
		font-size:14px;
	}
	.tpma-reg-table th,
	.tpma-reg-table td {
		border:1px solid #ddd;
		padding:4px 6px;
		vertical-align:top;
	}
	.tpma-reg-table th {
		background:#f7f7f7;
		white-space:nowrap;
	}
	.tpma-seq-col {
		text-align:right;
		width:40px;
	}

	/* 單一儲存格：最多兩行，超出隱藏 */
	.tpma-cell-wrap {
		display:-webkit-box;
		-webkit-box-orient:vertical;
		-webkit-line-clamp:2;
		overflow:hidden;
		word-break:break-all;
	}

	/* 狀態 chip */
	.tpma-chip {
		display:inline-block;
		padding:1px 6px;
		border-radius:999px;
		border:1px solid #ccc;
		font-size:14px;
		white-space:nowrap;
	}
	.tpma-chip-status-pending { background:#fff3cd; border-color:#ffeeba; }
	.tpma-chip-status-paid { background:#d4edda; border-color:#c3e6cb; }
	.tpma-chip-status-test_pending,
	.tpma-chip-status-cert_pending { background:#d1ecf1; border-color:#bee5eb; }
	.tpma-chip-status-completed { background:#e2e3e5; border-color:#d6d8db; }
	.tpma-chip-status-cancelled { background:#f8d7da; border-color:#f5c6cb; }

	/* 詳細列：用兩欄 grid */
	.tpma-reg-detail-row {
		background:#fcfcfc;
	}
	.tpma-reg-detail-cell {
		padding:6px 8px;
	}
	.tpma-reg-detail-grid {
		display:grid;
		grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
		gap:8px 16px;
	}
	.tpma-reg-detail-section-title {
		font-weight:bold;
		border-left:3px solid #999;
		padding-left:4px;
		margin-bottom:4px;
	}
	.tpma-reg-detail-section label {
		display:block;
		font-weight:bold;
		margin-top:4px;
		font-size:14px;
	}
	.tpma-reg-detail-section .value {
		margin-top:2px;
		white-space:pre-wrap;
	}
	.tpma-reg-detail-section input,
	.tpma-reg-detail-section select,
	.tpma-reg-detail-section textarea {
		width:100%;
		box-sizing:border-box;
		font-size:14px;
		padding:3px 4px;
	}
	.tpma-reg-detail-section textarea {
		min-height:60px;
	}
	.tpma-reg-detail-actions {
		text-align:right;
		margin-top:6px;
	}

	/* 批次按鈕 / 欄位 disabled 狀態 */
	.tpma-batch-btn:disabled,
	.tpma-batch-select:disabled,
	.tpma-batch-input:disabled {
		opacity:0.5;
		cursor:not-allowed;
	}

	/* 分頁 */
	.tpma-pagination {
		margin-top:8px;
		display:flex;
		align-items:center;
		gap:8px;
		justify-content:flex-end;
		font-size:14px;
	}
	.tpma-pagination-info {
		white-space:nowrap;
	}
	
	/* 狀態圖標 */
	.tpma-status-icons {
		display:flex;
		gap:3px;
		justify-content:center;
		align-items:center;
	}

	.tpma-status-pill {
		display:inline-block;
		padding:0 4px;
		border-radius:999px;
		font-size:10px;
		line-height:14px;
		white-space:nowrap;
		border:1px solid transparent;
	}

	/* 組一：報名狀態 */
	.tpma-status-pill-g1-pending     { background:#fff3cd; border-color:#ffeeba; }
	.tpma-status-pill-g1-verifying   { background:#ffe6b3; border-color:#ffcc80; }
	.tpma-status-pill-g1-paid        { background:#d4edda; border-color:#c3e6cb; }
	.tpma-status-pill-g1-cert        { background:#d1ecf1; border-color:#bee5eb; }
	.tpma-status-pill-g1-completed   { background:#e2e3e5; border-color:#d6d8db; }
	.tpma-status-pill-g1-cancelled   { background:#f8d7da; border-color:#f5c6cb; }

	/* 組二：收據狀態 */
	.tpma-status-pill-g2-pending   { background:#f0f0f0; border-color:#d6d8db; }
	.tpma-status-pill-g2-opened    { background:#e2f0ff; border-color:#b3d4ff; }
	.tpma-status-pill-g2-sent      { background:#d4edda; border-color:#c3e6cb; }

	/* 組三：測驗狀態 */
	.tpma-status-pill-g3-notyet   { background:#fff3cd; border-color:#ffeeba; }
	.tpma-status-pill-g3-done     { background:#d4edda; border-color:#c3e6cb; }	


</style>

<div id="tpma-reg-admin" class="tpma-wrap">

    <!-- 上方關鍵字搜尋列 -->
    <div class="tpma-filter-row">
        <span>關鍵字搜尋：</span>
        <input type="text" id="tpma-filter-q"
               placeholder="報名編號 / 學員 / 承辦 / 公司（模糊）">
        <button class="tpma-btn" id="tpma-btn-apply-q">搜尋</button>
        <button class="tpma-btn" id="tpma-btn-clear-all">清除全部篩選</button>
    </div>
	
	<button type="button" class="tpma-btn" id="tpma-btn-mail-templates">
    信件模板設定
</button>

    <table class="tpma-reg-table">
        <thead>
        <tr>
            <th style="width:26px;">
                <input type="checkbox" id="tpma-select-all-head">
            </th>
            <th class="tpma-seq-col">序</th>

            <!-- 報名時間 -->
            <th>
                <div class="tpma-th-inner">
                    <span>報名時間</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="created_at">▾</button>
                    <div class="tpma-th-menu" data-menu-col="created_at">
						<div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="created_at" data-sort-dir="asc">升冪排列</label>
                            <label class="tpma-menu-options" data-sort-field="created_at" data-sort-dir="desc">降冪排列</label>
                        </div>						
						<div class="tpma-menu-section">
							<div>
								<label class="tpma-menu-options">
									<input type="checkbox" id="tpma-filter-created-range" style="display: none;">
									<span>範圍搜索</span>
								</label>
							</div>
							
							<!-- 單日模式 -->
							<div id="tpma-created-single">
								<input type="date" id="tpma-filter-created-single" list="tpma-created-date-list">
								<datalist id="tpma-created-date-list"></datalist>
							</div>

							<!-- 範圍模式 -->
							<div id="tpma-created-range" style="display:none;">
								<input type="date" id="tpma-filter-created-from" placeholder="起日">
								<input type="date" id="tpma-filter-created-to" placeholder="訖日">
							</div>
							<div class="tpma-menu-section">
								<label class="tpma-menu-options" id="tpma-btn-clear-created">清除篩選</label>
							</div>
						</div>
                    </div>
                </div>
            </th>

            <!-- 課程名稱（hover 顯示講師） -->
            <th>
                <div class="tpma-th-inner">
                    <span>課程名稱</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="course">▾</button>
                    <div class="tpma-th-menu" data-menu-col="course">
						<div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="course_name" data-sort-dir="asc">升冪排列</label>
                            <label class="tpma-menu-options" data-sort-field="course_name" data-sort-dir="desc">降冪排列</label>
                        </div>						
						<div class="tpma-menu-section">
							<select id="tpma-filter-course">
								<option value="">全部課程</option>
							</select>
							<label class="tpma-menu-options" id="tpma-btn-clear-course">清除篩選</label>
						</div>

                    </div>
                </div>
            </th>

            <!-- 授課日期時間（顯示日期＋起迄時間） -->
            <th>
                <div class="tpma-th-inner">
                    <span>授課日期</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="class_date">▾</button>
                    <div class="tpma-th-menu" data-menu-col="class_date">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="class_date" data-sort-dir="asc">升冪排列</label>
                            <label class="tpma-menu-options" data-sort-field="class_date" data-sort-dir="desc">降冪排列</label>
                        </div>						
						<div class="tpma-menu-section">
							<label class="tpma-menu-options">
								<input type="checkbox" id="tpma-filter-class-range">
								<span>範圍搜索</span>	
							</label>
							<!-- 單日模式 -->
							<div id="tpma-class-single">
								<input type="date" id="tpma-filter-class-single" list="tpma-class-date-list">
								<datalist id="tpma-class-date-list"></datalist>
							</div>
							<!-- 範圍模式 -->
							<div id="tpma-class-range" style="display:none;">
								<input type="date" id="tpma-filter-class-from" placeholder="起日">
								<input type="date" id="tpma-filter-class-to" placeholder="訖日">
							</div>

							<label class="tpma-menu-options" id="tpma-btn-clear-class-date">清除篩選</label>
						</div>

                    </div>
                </div>
            </th>

            <!-- 匯款日期 -->
            <th>
                <div class="tpma-th-inner">
                    <span>匯款日期</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="remit_paid_at">▾</button>
                    <div class="tpma-th-menu" data-menu-col="remit_paid_at">
						<div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="remit_paid_at" data-sort-dir="asc">升冪排列</label>
                            <label class="tpma-menu-options" data-sort-field="remit_paid_at" data-sort-dir="desc">降冪排列</label>
                        </div>						
						<div class="tpma-menu-section">
							<label class="tpma-menu-options">
								<input type="checkbox" id="tpma-filter-remit-range">
								<span>範圍搜索</span>
							</label>

							<!-- 單日模式 -->
							<div id="tpma-remit-single">
								<input type="date" id="tpma-filter-remit-single" list="tpma-remit-date-list">
								<datalist id="tpma-remit-date-list"></datalist>
							</div>

							<!-- 範圍模式 -->
							<div id="tpma-remit-range" style="display:none;">
								<input type="date" id="tpma-filter-remit-from" placeholder="起日">
								<input type="date" id="tpma-filter-remit-to" placeholder="訖日">
							</div>

							<label class="tpma-menu-options" class="tpma-btn" id="tpma-btn-clear-remit">清除篩選</label>
						</div>

                        <div class="tpma-menu-section">
                            <label>批次修改匯款日期</label>
                            <input type="date" id="tpma-batch-remit-date" class="tpma-batch-input">
                            <button class="tpma-btn tpma-batch-btn" data-batch-field="remit_paid_at">套用批次設定</button>
                        </div>
                    </div>
                </div>
            </th>

            <!-- 學員姓名 -->
            <th>
                <div class="tpma-th-inner">
                    <span>學員姓名</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="student_name">▾</button>
                    <div class="tpma-th-menu" data-menu-col="student_name">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="student_name" data-sort-dir="asc">升冪排列</label>
                            <label class="tpma-menu-options" data-sort-field="student_name" data-sort-dir="desc">降冪排列</label>
                        </div>
                    </div>
                </div>
            </th>

            <!-- 公司抬頭 -->
            <th>
                <div class="tpma-th-inner">
                    <span>公司抬頭</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="company_name">▾</button>
                    <div class="tpma-th-menu" data-menu-col="company_name">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="company_name" data-sort-dir="asc">升冪排列</label>
                            <label class="tpma-menu-options" data-sort-field="company_name" data-sort-dir="desc">降冪排列</label>
                        </div>
                    </div>
                </div>
            </th>

            <!-- 付款狀態 (WooCommerce) -->
            <th>
                <div class="tpma-th-inner">
                    <span>付款狀態 (WC)</span>
                    <button class="tpma-th-menu-btn" data-menu-toggle="payment_status_wc">▾</button>
                    <div class="tpma-th-menu" data-menu-col="payment_status_wc">
                        <h4>付款狀態篩選</h4>
                        <div class="tpma-menu-section">
                            <select id="tpma-filter-payment-status">
                                <option value="">全部</option>
                                <option value="pending">待付款 (WC)</option>
                                <option value="processing">處理中 (WC)</option>
                                <option value="on-hold">保留中 (WC)</option>
                                <option value="completed">已完成 (WC)</option>
                                <option value="cancelled">已取消 (WC)</option>
                                <option value="refunded">已退款 (WC)</option>
                                <option value="failed">失敗 (WC)</option>
                                <option value="checkout-draft">草稿 (WC)</option>
                            </select>
                        </div>
                        <div class="tpma-menu-section">
                            <button class="tpma-btn" id="tpma-btn-clear-payment-status">清除篩選</button>
                        </div>
                    </div>
                </div>
            </th>

            <!-- 報名狀態 -->
			<!-- 狀態（報名 + 收據 + 測驗 圖標 + 多層級篩選 / 批次） -->
			<th>
				<div class="tpma-th-inner">
					<span>報名狀態</span>
					<button class="tpma-th-menu-btn" data-menu-toggle="status">▾</button>
					<div class="tpma-th-menu" data-menu-col="status">
						<h4>報名狀態篩選</h4>

						<div class="tpma-menu-section">
							<label><strong>報名狀態</strong></label>
							<select id="tpma-filter-status">
								<option value="">全部</option>
								<option value="pending">待付款</option>
								<option value="verifying">待核帳</option>
								<option value="paid">已付款</option>
								<option value="cert_pending">待發證</option>
								<option value="completed">已結訓</option>
								<option value="cancelled">已取消</option>
							</select>
						</div>

						<div class="tpma-menu-section">
							<label><strong>收據狀態</strong></label>
							<select id="tpma-filter-receipt-status">
								<option value="">全部</option>
								<option value="pending">待開立</option>
								<option value="auto">已開立待寄（自動）</option>
								<option value="manual">已開立待寄（手動）</option>
								<option value="sent">已寄出</option>
							</select>
						</div>

						<div class="tpma-menu-section">
							<label><strong>測驗狀態</strong></label>
							<select id="tpma-filter-test">
								<option value="">全部</option>
								<option value="notyet">待測驗</option>
								<option value="done">已測驗</option>
							</select>
						</div>

						<div class="tpma-menu-section">
							<button class="tpma-btn" id="tpma-btn-clear-status-all">清除狀態篩選</button>
						</div>

						<!-- 第二層：批次修改 -->
						<div class="tpma-menu-section">
							<div style="font-weight:bold; margin-bottom:2px;">批次修改（第二層）</div>

							<label>批次修改報名狀態</label>
							<select id="tpma-batch-status" class="tpma-batch-select">
								<option value="">請選擇狀態</option>
								<option value="pending">待付款</option>
								<option value="verifying">待核帳</option>
								<option value="paid">已付款</option>
								<option value="cert_pending">待發證</option>
								<option value="completed">已結訓</option>
								<option value="cancelled">已取消</option>
							</select>
							<button class="tpma-btn tpma-batch-btn" data-batch-field="status">批次設定報名狀態</button>

							<label style="margin-top:4px;">批次修改收據狀態</label>
							<select id="tpma-batch-receipt-status" class="tpma-batch-select">
								<option value="">請選擇狀態</option>
								<option value="pending">待開立</option>
								<option value="auto">已開立待寄（自動）</option>
								<option value="manual">已開立待寄（手動）</option>
								<option value="sent">已寄出</option>
							</select>
							<button class="tpma-btn tpma-batch-btn" data-batch-field="receipt_status">批次設定收據狀態</button>

							<label style="margin-top:4px;">批次修改收據方式</label>
							<select id="tpma-batch-receipt-type" class="tpma-batch-select">
								<option value="">請選擇方式</option>
								<option value="electronic">電子</option>
								<option value="paper">紙本</option>
							</select>
							<button class="tpma-btn tpma-batch-btn" data-batch-field="receipt_type">批次設定收據方式</button>
						</div>
					</div>
				</div>
			</th>


            <!-- 操作 -->
            <th>操作</th>
        </tr>
        </thead>
        <tbody id="tpma-reg-tbody">
        <tr><td colspan="10">載入中...</td></tr>
        </tbody>
    </table>

    <!-- 分頁列 -->
    <div class="tpma-pagination">
        <button class="tpma-btn" id="tpma-page-prev">上一頁</button>
        <span class="tpma-pagination-info" id="tpma-page-info"></span>
        <button class="tpma-btn" id="tpma-page-next">下一頁</button>
    </div>
</div>

<script>
// 信件模板設定按鈕
const btnMailTpl = document.getElementById('tpma-btn-mail-templates');
if (btnMailTpl && window.TPMA_MailModal) {
    btnMailTpl.addEventListener('click', function(){
        TPMA_MailModal.open('registration_notice'); // 預設打開報名通知模板
    });
}	
	
(function(){
    const apiBase    = '<?php echo $apiBase; ?>';
    const wpRestNonce = '<?php echo $restNonce; ?>';

    const STATUS_LABELS = {
        pending:      '待付款',
        verifying:    '待核帳',
        paid:         '已付款',
        cert_pending: '待發證',
        completed:    '已結訓',
        cancelled:    '已取消'
    };

    const PAYMENT_STATUS_LABELS = {
        'pending':     '待付款 (WC)',
        'processing':  '處理中 (WC)',
        'on-hold':     '保留中 (WC)',
        'completed':   '已完成 (WC)',
        'cancelled':   '已取消 (WC)',
        'refunded':    '已退款 (WC)',
        'failed':      '失敗 (WC)',
        'checkout-draft': '草稿 (WC)'
    };

    const RECEIPT_TYPE_LABELS = {
        electronic: '電子',
        paper: '紙本'
    };

    const RECEIPT_STATUS_LABELS = {
        pending: '待開立',
        auto:    '已開立待寄（自動）',
        manual:  '已開立待寄（手動）',
        sent:    '已寄出'
    };

    const pageSize = 50;
    let currentPage = 1;

    let allCourses = [];
    let allRegs    = [];
    let currentRegs = [];

    const tbody        = document.getElementById('tpma-reg-tbody');
    const $selectAllHead = document.getElementById('tpma-select-all-head');
    const $pagePrev    = document.getElementById('tpma-page-prev');
    const $pageNext    = document.getElementById('tpma-page-next');
    const $pageInfo    = document.getElementById('tpma-page-info');

    // 篩選狀態
    const filterState = {
		q: '',
		course_id: '',
		class_date: '',          // 單日模式
		class_date_from: '',     // 範圍模式 起日
		class_date_to: '',       // 範圍模式 訖日
		status: '',
		receipt_status: '',
		receipt_type: '',
		created_from: '',
		created_to: '',
		remit_from: '',
		remit_to: '',
		test_state: '',
        payment_status: '' // New: WooCommerce payment status filter
    };

    // 排序狀態
    const sortState = {
        field: 'created_at',
        dir: 'desc'
    };

    let openMenuCol = null;

    function esc(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function(m){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }
    function display(v) { return v == null ? '' : String(v); }

    function statusLabel(code){ return STATUS_LABELS[code] || code || ''; }
    function receiptTypeLabel(code){ return RECEIPT_TYPE_LABELS[code] || code || ''; }
    function receiptStatusLabel(code){ return RECEIPT_STATUS_LABELS[code] || code || ''; }
	
	    function getTestState(row){
        const score = row.test_score;
        if (score == null || String(score).trim() === '') {
            return 'notyet'; // 待測驗
        }
        return 'done'; // 已測驗
    }

    function buildPaymentStatusHtml(row) {
        const pCode = row.payment_status || '';
        const pLabel = PAYMENT_STATUS_LABELS[pCode] || pCode || '';
        if (!pLabel) return '';

        let pClass = '';
        switch (pCode) {
            case 'pending':      pClass = 'tpma-status-pill-g1-pending'; break;
            case 'processing':   pClass = 'tpma-status-pill-g1-verifying'; break; // Using verifying color for processing
            case 'on-hold':      pClass = 'tpma-status-pill-g1-verifying'; break; // Using verifying color for on-hold
            case 'completed':    pClass = 'tpma-status-pill-g1-paid'; break;
            case 'cancelled':    pClass = 'tpma-status-pill-g1-cancelled'; break;
            case 'refunded':     pClass = 'tpma-status-pill-g1-cancelled'; break; // Using cancelled color for refunded
            case 'failed':       pClass = 'tpma-status-pill-g1-cancelled'; break; // Using cancelled color for failed
            case 'checkout-draft': pClass = 'tpma-status-pill-g1-completed'; break; // Using completed color for draft
        }
        return '<span class="tpma-status-pill ' + pClass + '" title="' + esc(pLabel) + '">' + esc(pLabel) + '</span>';
    }

    function buildStatusIconsHtml(row){
        const sCode = row.status || 'pending';
        const rCode = row.receipt_status || '';
        const testState = getTestState(row);

        const sLabel = STATUS_LABELS[sCode] || sCode || '';
        let rLabel;
        if (rCode === 'sent') {
            rLabel = '已寄出';
        } else if (rCode === 'pending') {
            rLabel = '待開立';
        } else if (rCode === 'auto' || rCode === 'manual') {
            rLabel = '已開立待寄';
        } else {
            rLabel = '';
        }

        const tLabel = (testState === 'done') ? '已測驗' : '待測驗';

        const icons = [];

        // 圖標組一：報名狀態
        let g1Class = '';
        switch (sCode) {
            case 'pending':      g1Class = 'tpma-status-pill-g1-pending'; break;
            case 'verifying':    g1Class = 'tpma-status-pill-g1-verifying'; break;
            case 'paid':         g1Class = 'tpma-status-pill-g1-paid'; break;
            case 'cert_pending': g1Class = 'tpma-status-pill-g1-cert'; break;
            case 'completed':    g1Class = 'tpma-status-pill-g1-completed'; break;
            case 'cancelled':    g1Class = 'tpma-status-pill-g1-cancelled'; break;
        }
        if (sLabel) {
            icons.push(
                '<span class="tpma-status-pill ' + g1Class + '" title="' + esc(sLabel) + '">' +
                esc(sLabel) +
                '</span>'
            );
        }

        // 判斷哪些情況要隱藏組二 / 組三
        const hideG2 = (sCode === 'cancelled' || sCode === 'completed');
        const hideG3 = (sCode === 'cancelled' || sCode === 'completed' || sCode === 'cert_pending');

        // 圖標組二：收據狀態
        if (!hideG2 && rLabel) {
            let g2Class = 'tpma-status-pill-g2-pending';
            if (rCode === 'sent') {
                g2Class = 'tpma-status-pill-g2-sent';
            } else if (rCode === 'auto' || rCode === 'manual') {
                g2Class = 'tpma-status-pill-g2-opened';
            }
            icons.push(
                '<span class="tpma-status-pill ' + g2Class + '" title="' + esc(rLabel) + '">' +
                esc(rLabel) +
                '</span>'
            );
        }

        // 圖標組三：測驗狀態
        if (!hideG3) {
            const g3Class = (testState === 'done') ? 'tpma-status-pill-g3-done' : 'tpma-status-pill-g3-notyet';
            icons.push(
                '<span class="tpma-status-pill ' + g3Class + '" title="' + esc(tLabel) + '">' +
                esc(tLabel) +
                '</span>'
            );
        }

        return '<div class="tpma-status-icons">' + icons.join('') + '</div>';
    }

    function statusChipClass(code){ return 'tpma-chip-status-' + (code || 'pending'); }

	function trimToMinute(datetimeStr){
		if (!datetimeStr) return '';
		const s = String(datetimeStr);
		return s.length >= 16 ? s.substring(0,16) : s;
	}
	
    // 取得這筆課程的時數（小時）
    function getCourseHoursForRow(row){
        const tryParse = (v)=> {
            const n = parseFloat(v);
            return isNaN(n) ? 0 : n;
        };

        if (row.class_hours)  { const n = tryParse(row.class_hours);  if (n>0) return n; }
        if (row.course_hours) { const n = tryParse(row.course_hours); if (n>0) return n; }
        if (row.hours)        { const n = tryParse(row.hours);        if (n>0) return n; }

        if (allCourses && allCourses.length && row.course_id) {
            const c = allCourses.find(x => String(x.id) === String(row.course_id));
            if (c) {
                if (c.class_hours)  { const n = tryParse(c.class_hours);  if (n>0) return n; }
                if (c.course_hours) { const n = tryParse(c.course_hours); if (n>0) return n; }
                if (c.hours)        { const n = tryParse(c.hours);        if (n>0) return n; }
            }
        }
        return 3; // 找不到就先當成 3 小時
    }
	
    // 依課程 sessions，幫這筆找到實際上課 datetime（如果 class_date 只有日期）
    function findSessionDatetimeForRow(row){
        if (!allCourses || !allCourses.length) return null;
        if (!row.course_id || !row.class_date) return null;

        const course = allCourses.find(c => String(c.id) === String(row.course_id));
        if (!course || !Array.isArray(course.sessions) || !course.sessions.length) {
            return null;
        }

        const dateOnly = String(row.class_date).substring(0,10);
        const sameDay = course.sessions.find(
            s => s.session_datetime && String(s.session_datetime).substring(0,10) === dateOnly
        );
        return sameDay ? sameDay.session_datetime : null;
    }
	

    // 授課日期顯示：YYYY-MM-DD（週）HH:MM~HH:MM
    function buildClassDateRangeHtml(row){
        let dtStr = row.class_date;
        if (!dtStr) return '';

        let s = String(dtStr);

        // 如果 class_date 只有日期，試著從 sessions 找同一天的實際上課 datetime
        if (s.length <= 10 || s.indexOf(' ') === -1) {
            const sessionDt = findSessionDatetimeForRow(row);
            if (sessionDt) {
                s = String(sessionDt);
            }
        }

        const dayNames = ['日','一','二','三','四','五','六'];

        // 如果最後還是只有日期，就顯示 YYYY-MM-DD（週）
        if (s.length < 16) {
            const datePart = s.substring(0,10);
            let week = '';
            try {
                const d = new Date(datePart + 'T00:00:00');
                if (!isNaN(d.getTime())) {
                    const w = d.getDay();
                    week = dayNames[w] || '';
                }
            } catch(e){}
            const text = datePart + (week ? '（' + week + '）' : '');
            return esc(text);
        }

        const datePart = s.substring(0,10);
        const timePart = s.substring(11,16); // HH:MM

        let week = '';
        let endTimeStr = '';

        try {
            const base = new Date(s.replace(' ', 'T'));
            if (!isNaN(base.getTime())) {
                // 星期
                const w = base.getDay();
                week = dayNames[w] || '';

                // 結束時間 = 開始時間 + 課程時數
                const hours = getCourseHoursForRow(row);
                if (hours > 0) {
                    const end = new Date(base.getTime() + hours*60*60*1000);
                    const pad = n => (n < 10 ? '0' + n : '' + n);
                    endTimeStr = pad(end.getHours()) + ':' + pad(end.getMinutes());
                }
            }
        } catch(e){}

        const range = endTimeStr ? (timePart + '~' + endTimeStr) : timePart;
        const full  = datePart + (week ? '（' + week + '） ' : ' ') + range;

        return esc(full);
    }

	//計算下拉式選單的時間<br>
	function formatSessionDisplay(sessionDatetime, durationMinutes) {
		// sessionDatetime = "2025-12-10 09:00:00"
		// durationMinutes = 180

		if (!sessionDatetime) return '';

		const d = sessionDatetime.substring(0,10);   // yyyy-mm-dd
		const t = sessionDatetime.substring(11,16);  // hh:mm

		const [HH,MM] = t.split(':');
		const start = new Date(d+'T'+t+':00');
		const end = new Date(start.getTime() + (durationMinutes || 0)*60000);

		const endHH = String(end.getHours()).padStart(2,'0');
		const endMM = String(end.getMinutes()).padStart(2,'0');

		// 星期
		const wd = '日一二三四五六'[start.getDay()];

		return `${d}（${wd}） ${t}~${endHH}:${endMM}`;
	}

	

    function formatAmount(val){
        if (val == null || val === '') return '';
        const n = parseFloat(String(val).replace(/,/g,'')); 
        if (isNaN(n)) return String(val);
        return String(Math.round(n));
    }

    async function fetchJson(url, options){
        const res = await fetch(url, options || {});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    }

    async function loadCourses(){
        try{
            const list = await fetchJson(apiBase + '/admin/courses', {
                credentials:'include',
                headers:{ 'X-WP-Nonce': wpRestNonce }
            });
            allCourses = Array.isArray(list) ? list : [];
        }catch(e){
            console.error(e);
            allCourses = [];
        }
    }

    async function refreshFromServer(){
        tbody.innerHTML = '<tr><td colspan="11">載入中...</td></tr>';
        try{
            const list = await fetchJson(apiBase + '/admin/registrations', {
                credentials:'include',
                headers:{ 'X-WP-Nonce': wpRestNonce }
            });
            allRegs = Array.isArray(list) ? list : [];
            currentPage = 1;
            applyFiltersAndRender();
        }catch(e){
            console.error(e);
            tbody.innerHTML = '<tr><td colspan="11">載入失敗</td></tr>';
        }
    }

    function applyFiltersAndRender(){
        let list = allRegs.slice();

        // 關鍵字：reg_no / student_name / contact_name / company_name
        if (filterState.q) {
            const q = filterState.q.toLowerCase();
            list = list.filter(r=>{
                const fields = [
                    r.reg_no,
                    r.student_name,
                    r.contact_name,
                    r.company_name
                ];
                return fields.some(v => v && String(v).toLowerCase().includes(q));
            });
        }

        if (filterState.course_id) {
            list = list.filter(r => String(r.course_id) === String(filterState.course_id));
        }
		// 授課日期：單日模式（class_date），比對日期部分
		if (filterState.class_date) {
			const target = filterState.class_date;
			list = list.filter(r=>{
				const d = (r.class_date || '').substring(0,10);
				return d === target;
			});
		}

		// 授課日期：範圍模式（class_date_from / class_date_to）
		if (filterState.class_date_from || filterState.class_date_to) {
			list = list.filter(r=>{
				const d = (r.class_date || '').substring(0,10);
				if (!d) return false;
				if (filterState.class_date_from && d < filterState.class_date_from) return false;
				if (filterState.class_date_to   && d > filterState.class_date_to)   return false;
				return true;
			});
		}
        if (filterState.status) {
            list = list.filter(r => String(r.status) === String(filterState.status));
        }
        if (filterState.receipt_status) {
            list = list.filter(r => String(r.receipt_status) === String(filterState.receipt_status));
        }
        if (filterState.receipt_type) {
            list = list.filter(r => String(r.receipt_type) === String(filterState.receipt_type));
        }
		if (filterState.test_state) {
			list = list.filter(r=>{
				const ts = getTestState(r); // 前面 helper
				return ts === filterState.test_state;
			});
		}
		

        // 報名時間篩選（created_at）
        if (filterState.created_from || filterState.created_to) {
            list = list.filter(r=>{
                const v = r.created_at || '';
                if (!v) return false;
                const d = v.substring(0,10);
                if (filterState.created_from && d < filterState.created_from) return false;
                if (filterState.created_to && d > filterState.created_to) return false;
                return true;
            });
        }

        // 匯款日期篩選（remit_paid_at，DATE）
        if (filterState.remit_from || filterState.remit_to) {
            list = list.filter(r=>{
                const d = (r.remit_paid_at || '').substring(0,10);
                if (!d) return false;
                if (filterState.remit_from && d < filterState.remit_from) return false;
                if (filterState.remit_to && d > filterState.remit_to) return false;
                return true;
            });
        }

        // New: Payment status filter
        if (filterState.payment_status) {
            list = list.filter(r => String(r.payment_status) === String(filterState.payment_status));
        }

        // 排序
        const field = sortState.field;
        const dir   = sortState.dir;
        if (field) {
            list.sort((a,b)=>{
                const va = (a[field] == null ? '' : String(a[field]));
                const vb = (b[field] == null ? '' : String(b[field]));
                if (va < vb) return dir === 'asc' ? -1 : 1;
                if (va > vb) return dir === 'asc' ? 1 : -1;
                return 0;
            });
        }

        currentRegs = list;
        // 每次重新篩選後回到第一頁
        currentPage = 1;

        renderTable(currentRegs);
        buildHeaderFilterOptions();
        updateFilterButtonStates();
    }

	function buildHeaderFilterOptions(){
		// 課程選單（原本的邏輯保留）
		const courseSelect = document.getElementById('tpma-filter-course');
		if (courseSelect) {
			const map = {};
			allRegs.forEach(r=>{
				if (!r.course_id) return;
				const key = String(r.course_id);
				if (!map[key]) {
					map[key] = r.course_name || ('課程ID ' + key);
				}
			});
			courseSelect.innerHTML = '<option value="">全部課程</option>';
			Object.keys(map).sort((a,b)=> {
				const va = map[a] || '';
				const vb = map[b] || '';
				return va.localeCompare(vb, 'zh-Hant');
			}).forEach(id=>{
				const opt = document.createElement('option');
				opt.value = id;
				opt.textContent = map[id];
				if (filterState.course_id && String(filterState.course_id) === id) {
					opt.selected = true;
				}
				courseSelect.appendChild(opt);
			});
		}

		// 授課日期 datalist（依有報名資料建立，且受課程篩選影響）
		const classDateList = document.getElementById('tpma-class-date-list');
		if (classDateList) {
			const dateSet = new Set();
			allRegs.forEach(r=>{
				if (!r.class_date) return;
				if (filterState.course_id && String(r.course_id) !== String(filterState.course_id)) return;
				const d = String(r.class_date).substring(0,10);
				dateSet.add(d);
			});
			classDateList.innerHTML = '';
			Array.from(dateSet).sort().forEach(d=>{
				const opt = document.createElement('option');
				opt.value = d;
				classDateList.appendChild(opt);
			});
		}

		// 下面順便把報名時間 / 匯款日期 datalist 也一起建好（下一段會用到）
		const createdList = document.getElementById('tpma-created-date-list');
		if (createdList) {
			const set = new Set();
			allRegs.forEach(r=>{
				if (!r.created_at) return;
				const d = String(r.created_at).substring(0,10);
				set.add(d);
			});
			createdList.innerHTML = '';
			Array.from(set).sort().forEach(d=>{
				const opt = document.createElement('option');
				opt.value = d;
				createdList.appendChild(opt);
			});
		}

		const remitList = document.getElementById('tpma-remit-date-list');
		if (remitList) {
			const set = new Set();
			allRegs.forEach(r=>{
				if (!r.remit_paid_at) return;
				const d = String(r.remit_paid_at).substring(0,10);
				set.add(d);
			});
			remitList.innerHTML = '';
			Array.from(set).sort().forEach(d=>{
				const opt = document.createElement('option');
				opt.value = d;
				remitList.appendChild(opt);
			});
		}

		updateFilterButtonStates();
	}

    function anyRowSelected(){
        return !!document.querySelector('.tpma-reg-select:checked');
    }

    function updateBatchButtonsEnabled(){
        const hasSel = anyRowSelected();
        document.querySelectorAll('.tpma-batch-btn').forEach(btn=>{
            btn.disabled = !hasSel;
            btn.title = hasSel ? '' : '請先勾選資料';
        });
        document.querySelectorAll('.tpma-batch-select, .tpma-batch-input').forEach(el=>{
            el.disabled = !hasSel;
            el.title = hasSel ? '' : '請先勾選資料';
        });
    }

    function updatePaginationControls(){
        const total = currentRegs.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        const start = total === 0 ? 0 : (currentPage - 1) * pageSize + 1;
        const end   = total === 0 ? 0 : Math.min(currentPage * pageSize, total);

        $pageInfo.textContent = '第 ' + currentPage + ' / ' + totalPages + ' 頁，顯示 ' + start + '–' + end + ' 筆，共 ' + total + ' 筆';

        $pagePrev.disabled = (currentPage <= 1);
        $pageNext.disabled = (currentPage >= totalPages);
    }

    function renderTable(list){
        tbody.innerHTML = '';
        $selectAllHead.checked = false;

        const total = list.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;

        if (!list || !list.length) {
            tbody.innerHTML = '<tr><td colspan="12">查無符合條件的報名資料。</td></tr>';
            updateBatchButtonsEnabled();
            updatePaginationControls();
            return;
        }

        const startIndex = (currentPage - 1) * pageSize;
        const endIndex   = Math.min(startIndex + pageSize, total);
        const pageItems  = list.slice(startIndex, endIndex);

        pageItems.forEach((row, idx)=>{
            const tr = document.createElement('tr');
            tr.dataset.id = row.id || '';

            // checkbox
            const tdSel = document.createElement('td');
            tdSel.innerHTML = '<input type="checkbox" class="tpma-reg-select">';
            tr.appendChild(tdSel);

            // 序號（依目前頁面顯示順序，1 開始）
            const tdSeq = document.createElement('td');
            tdSeq.className = 'tpma-seq-col';
            tdSeq.textContent = idx + 1;
            tr.appendChild(tdSeq);

            // 報名時間（去秒）
            const tdCreated = document.createElement('td');
			const createdText = trimToMinute(row.created_at); // 例如 2025-12-10 09:00

			let createdHtml = '';
			if (createdText && createdText.length >= 16) {
				const datePart = createdText.substring(0, 10);   // YYYY-MM-DD
				const timePart = createdText.substring(11, 16);  // HH:MM
				createdHtml = esc(datePart) + '<br>' + esc(timePart);
			} else {
				createdHtml = esc(createdText);
			}
			tdCreated.innerHTML = '<div class="tpma-cell-wrap">' + createdHtml + '</div>';
			tr.appendChild(tdCreated);

            // 課程名稱（hover 顯示講師）
            const tdCourse = document.createElement('td');
            const cname = display(row.course_name);
            const lect  = display(row.lecturer);
            const titleAttr = lect ? ' title="講師：' + esc(lect) + '"' : '';
            tdCourse.innerHTML = '<div class="tpma-cell-wrap"><span'+titleAttr+'>' + esc(cname) + '</span></div>';
            tr.appendChild(tdCourse);

            // 授課日期 + 時間（兩行，含結束時間）
            const tdDate = document.createElement('td');
			const classText = buildClassDateRangeHtml(row); // 例如 2025-12-10（三） 09:00~12:00

			let classHtml = '';
			if (classText) {
				// 找第一個空白，假設格式固定為「日期（週） 空白 時間範圍」
				const idx = classText.indexOf(' ');
				if (idx > 0) {
					const datePart = classText.substring(0, idx);       // 2025-12-10（三）
					const timePart = classText.substring(idx + 1).trim(); // 09:00~12:00
					classHtml = esc(datePart) + '<br>' + esc(timePart);
				} else {
					classHtml = esc(classText);
				}
			}
			tdDate.innerHTML = '<div class="tpma-cell-wrap">' + classHtml + '</div>';
			tr.appendChild(tdDate);

            // 匯款日期（只顯示日期）
            const tdRemit = document.createElement('td');
            tdRemit.innerHTML = '<div class="tpma-cell-wrap">' + esc((row.remit_paid_at || '').substring(0,10)) + '</div>';
            tr.appendChild(tdRemit);

            // 學員姓名
            const tdStu = document.createElement('td');
            tdStu.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.student_name)) + '</div>';
            tr.appendChild(tdStu);

            // 公司抬頭
            const tdComp = document.createElement('td');
            tdComp.innerHTML = '<div class="tpma-cell-wrap">' + esc(display(row.company_name)) + '</div>';
            tr.appendChild(tdComp);

            // 付款狀態 (WooCommerce)
            const tdPaymentWC = document.createElement('td');
            tdPaymentWC.innerHTML = '<div class="tpma-status-icons">' + buildPaymentStatusHtml(row) + '</div>';
            tr.appendChild(tdPaymentWC);

			// 合併狀態欄（報名 + 收據 + 測驗 圖標）
			const tdStatus = document.createElement('td');
			tdStatus.innerHTML = buildStatusIconsHtml(row);
			tr.appendChild(tdStatus);

            // 操作
            const tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="tpma-btn tpma-detail-btn">詳細</button>';
            tr.appendChild(tdAct);

            tbody.appendChild(tr);

            // 詳細列
            const trDetail = document.createElement('tr');
            trDetail.className = 'tpma-reg-detail-row';
            trDetail.style.display = 'none';
            trDetail.dataset.id = row.id || '';
            const tdDetail = document.createElement('td');
            tdDetail.className = 'tpma-reg-detail-cell';
            tdDetail.colSpan = 11; // Increased colspan to account for new payment status column
            trDetail.appendChild(tdDetail);
            tbody.appendChild(trDetail);

            renderDetailView(tdDetail, row);

            tdAct.querySelector('.tpma-detail-btn').addEventListener('click', function(){
                if (trDetail.style.display === 'none') {
                    trDetail.style.display = '';
                    this.textContent = '收合';
                } else {
                    trDetail.style.display = 'none';
                    this.textContent = '詳細';
                }
            });

            const cb = tdSel.querySelector('.tpma-reg-select');
            cb.addEventListener('change', updateBatchButtonsEnabled);
        });

        updateBatchButtonsEnabled();
        updatePaginationControls();
    }

    function appendFieldView(section, labelText, val, asHtml){
        const label = document.createElement('label');
        label.textContent = labelText;
        section.appendChild(label);

        const div = document.createElement('div');
        div.className = 'value';
        if (asHtml) {
            // 直接當 HTML 用，給已經組好的 <br> 等標籤
            div.innerHTML = val || '';
        } else {
            // 原本行為：當純文字顯示
            div.innerHTML = esc(display(val));
        }
        section.appendChild(div);
    }
	
    function renderDetailView(container, row){
        container.innerHTML = '';

        const grid = document.createElement('div');
        grid.className = 'tpma-reg-detail-grid';

        // 基本資訊
        const basic = document.createElement('div');
        basic.className = 'tpma-reg-detail-section';
        basic.innerHTML = '<div class="tpma-reg-detail-section-title">基本資訊（部分唯讀）</div>';
        appendFieldView(basic, '報名編號', row.reg_no);
        appendFieldView(basic, '報名時間', trimToMinute(row.created_at));
        appendFieldView(basic, '課程名稱', row.course_name);
        appendFieldView(basic, '授課講師', row.lecturer);
        appendFieldView(basic, '授課日期時間', buildClassDateRangeHtml(row), true);
        
        // New: WooCommerce Order ID and Link
        if (row.woocommerce_order_id) {
            const wcOrderLink = `<?php echo admin_url('post.php?post='); ?>${row.woocommerce_order_id}&action=edit`;
            appendFieldView(basic, 'WooCommerce 訂單 ID', `<a href="${wcOrderLink}" target="_blank">${row.woocommerce_order_id}</a>`, true);
        }
        appendFieldView(basic, '付款狀態 (WC)', PAYMENT_STATUS_LABELS[row.payment_status] || row.payment_status);

        grid.appendChild(basic);

        // 學員資訊
        const stu = document.createElement('div');
        stu.className = 'tpma-reg-detail-section';
        stu.innerHTML = '<div class="tpma-reg-detail-section-title">學員資訊</div>';
        appendFieldView(stu, '學員姓名', row.student_name);
        appendFieldView(stu, '部門', row.department);
        appendFieldView(stu, '職稱', row.job_title);
        appendFieldView(stu, '手機', row.mobile);
        appendFieldView(stu, '電話', row.phone);
        appendFieldView(stu, 'Email（多筆）', row.emails);
        grid.appendChild(stu);

        // 公司與聯絡資訊
        const company = document.createElement('div');
        company.className = 'tpma-reg-detail-section';
        company.innerHTML = '<div class="tpma-reg-detail-section-title">公司與聯絡資訊</div>';
        appendFieldView(company, '公司抬頭', row.company_name);
        appendFieldView(company, '統一編號', row.tax_id);
        appendFieldView(company, '承辦人姓名', row.contact_name);
        appendFieldView(company, '承辦人Email', row.contact_email);
        appendFieldView(company, '收件人', row.receiver);
        appendFieldView(company, '地址', row.address);
        appendFieldView(company, '資訊來源', row.source);
        grid.appendChild(company);

        // 收據與付款
        const receipt = document.createElement('div');
        receipt.className = 'tpma-reg-detail-section';
        receipt.innerHTML = '<div class="tpma-reg-detail-section-title">收據與付款</div>';
        appendFieldView(receipt, '收據方式', receiptTypeLabel(row.receipt_type));
        appendFieldView(receipt, '收據狀態', receiptStatusLabel(row.receipt_status));
        appendFieldView(receipt, '匯款金額（元）', formatAmount(row.remit_amount));
        appendFieldView(receipt, '匯款日期', row.remit_paid_at);
        grid.appendChild(receipt);

        // 其他
        const other = document.createElement('div');
        other.className = 'tpma-reg-detail-section';
        other.innerHTML = '<div class="tpma-reg-detail-section-title">其他資訊</div>';
        appendFieldView(other, '報名狀態', statusLabel(row.status));
        appendFieldView(other, '測驗成績', row.test_score);
        appendFieldView(other, '證書編號', row.certificate_id);
        appendFieldView(other, '備註', row.note);
        grid.appendChild(other);

        container.appendChild(grid);

        const actions = document.createElement('div');
        actions.className = 'tpma-reg-detail-actions';
        actions.innerHTML = '<button class="tpma-btn tpma-edit-btn">編輯</button>';
        container.appendChild(actions);

        actions.querySelector('.tpma-edit-btn').addEventListener('click', function(){
            renderDetailEdit(container, row);
        });
    }

	function populateEditCourseAndDate(row){
		const cid = 'tpma-edit-course-' + row.id;
		const did = 'tpma-edit-class-date-' + row.id;
		const courseSel = document.getElementById(cid);
		const dateSel   = document.getElementById(did);
		if (!courseSel || !dateSel) return;

		// 課程下拉
		courseSel.innerHTML = '<option value="">請選擇課程</option>';
		allCourses.forEach(c=>{
			const opt = document.createElement('option');
			opt.value = c.id || '';
			opt.textContent = c.course_name || '';
			if (String(c.id) === String(row.course_id || '')) {
				opt.selected = true;
			}
			courseSel.appendChild(opt);
		});

		// 授課日期時間下拉
		function rebuildDates(selectedCourseId){
			dateSel.innerHTML = '<option value="">請選擇授課日期時間</option>';

			const course = allCourses.find(c => String(c.id) === String(selectedCourseId));
			let has = false;

			// 先決定這筆資料「應該對照的真正 datetime」
			// - 如果 class_date 只有日期或沒有時間，就用 findSessionDatetimeForRow 幫它找 session_datetime
			// - 另外多一層 substring(0,16) 比對，避免秒數差異（HH:MM vs HH:MM:SS）比不到
			let compareValue = String(row.class_date || '');
			if (compareValue && (compareValue.length <= 10 || compareValue.indexOf(' ') === -1)) {
				const resolved = findSessionDatetimeForRow(Object.assign({}, row, { course_id: selectedCourseId || row.course_id }));
				if (resolved) {
					compareValue = String(resolved);
				}
			}

			if (course && Array.isArray(course.sessions)) {
				// 算出這堂課的時數（小時），轉成分鐘給 formatSessionDisplay 用
				const hours = getCourseHoursForRow(Object.assign({}, row, { course_id: selectedCourseId || row.course_id }));
				const durationMinutes = hours > 0 ? hours * 60 : 0;

				course.sessions.forEach(s=>{
					if (!s.session_datetime) return;

					const sessionValue = String(s.session_datetime);

					const opt = document.createElement('option');
					opt.value = sessionValue;   // 實際寫回資料庫的值：保持原本 session_datetime

					// 顯示用格式：YYYY-MM-DD（週）HH:MM~HH:MM
					const label = durationMinutes
						? formatSessionDisplay(sessionValue, durationMinutes)
						: sessionValue;
					opt.textContent = label;

					// 比對目前這筆報名原本的 class_date
					if (
						sessionValue === compareValue ||
						sessionValue.substring(0,16) === compareValue.substring(0,16)
					) {
						opt.selected = true;
					}

					dateSel.appendChild(opt);
					has = true;
				});
			}

			// 若課程沒 session，但原本 class_date 有值，就保留原資料
			if (!has && row.class_date) {
				const baseRow = Object.assign({}, row, { course_id: selectedCourseId || row.course_id });
				const hours = getCourseHoursForRow(baseRow);
				const durationMinutes = hours > 0 ? hours * 60 : 0;

				const opt = document.createElement('option');
				opt.value = row.class_date;

				const label = durationMinutes
					? formatSessionDisplay(row.class_date, durationMinutes)
					: row.class_date;

				opt.textContent = label + '（原資料）';
				opt.selected = true;
				dateSel.appendChild(opt);
			}
		}

		const initCourseId = courseSel.value || row.course_id || '';
		rebuildDates(initCourseId);

		courseSel.addEventListener('change', function(){
			rebuildDates(this.value);
		});
	}


    function renderDetailEdit(container, row){
        container.innerHTML = '';

        const grid = document.createElement('div');
        grid.className = 'tpma-reg-detail-grid';

        // 基本資訊：課程 / 授課日期可編輯
        const basic = document.createElement('div');
        basic.className = 'tpma-reg-detail-section';
        basic.innerHTML = ''
            + '<div class="tpma-reg-detail-section-title">基本資訊</div>'
            + '<label>報名編號（唯讀）</label>'
            + '<div class="value">' + esc(display(row.reg_no)) + '</div>'
            + '<label>報名時間（唯讀）</label>'
            + '<div class="value">' + esc(trimToMinute(row.created_at)) + '</div>'
            + '<label>課程名稱</label>'
            + '<select data-field="course_id" id="tpma-edit-course-' + row.id + '"></select>'
            + '<label>授課日期時間</label>'
            + '<select data-field="class_date" id="tpma-edit-class-date-' + row.id + '"></select>'
            // New: WooCommerce Order ID and Payment Status (read-only in edit view)
            + '<label>WooCommerce 訂單 ID（唯讀）</label>'
            + '<div class="value">' + esc(display(row.woocommerce_order_id)) + '</div>'
            + '<label>付款狀態 (WC)（唯讀）</label>'
            + '<div class="value">' + esc(PAYMENT_STATUS_LABELS[row.payment_status] || row.payment_status) + '</div>';
        grid.appendChild(basic);

        // 學員資訊
        const stu = document.createElement('div');
        stu.className = 'tpma-reg-detail-section';
        stu.innerHTML = '<div class="tpma-reg-detail-section-title">學員資訊</div>'
            + '<label>學員姓名</label>'
            + '<input type="text" data-field="student_name" value="'+esc(display(row.student_name))+'">'
            + '<label>部門</label>'
            + '<input type="text" data-field="department" value="'+esc(display(row.department))+'">'
            + '<label>職稱</label>'
            + '<input type="text" data-field="job_title" value="'+esc(display(row.job_title))+'">'
            + '<label>手機</label>'
            + '<input type="text" data-field="mobile" value="'+esc(display(row.mobile))+'">'
            + '<label>電話</label>'
            + '<input type="text" data-field="phone" value="'+esc(display(row.phone))+'">'
            + '<label>Email（多筆）</label>'
            + '<input type="text" data-field="emails" value="'+esc(display(row.emails))+'">';
        grid.appendChild(stu);

        // 公司與聯絡資訊
        const company = document.createElement('div');
        company.className = 'tpma-reg-detail-section';
        company.innerHTML = '<div class="tpma-reg-detail-section-title">公司與聯絡資訊</div>'
            + '<label>公司抬頭</label>'
            + '<input type="text" data-field="company_name" value="'+esc(display(row.company_name))+'">'
            + '<label>統一編號</label>'
            + '<input type="text" data-field="tax_id" value="'+esc(display(row.tax_id))+'">'
            + '<label>承辦人姓名</label>'
            + '<input type="text" data-field="contact_name" value="'+esc(display(row.contact_name))+'">'
            + '<label>承辦人Email</label>'
            + '<input type="text" data-field="contact_email" value="'+esc(display(row.contact_email))+'">'
            + '<label>收件人</label>'
            + '<input type="text" data-field="receiver" value="'+esc(display(row.receiver))+'">'
            + '<label>地址</label>'
            + '<input type="text" data-field="address" value="'+esc(display(row.address))+'">';
        grid.appendChild(company);

        // 收據與付款（匯款金額用整數）
        const receipt = document.createElement('div');
        receipt.className = 'tpma-reg-detail-section';
        receipt.innerHTML = '<div class="tpma-reg-detail-section-title">收據與付款</div>'
            + '<label>收據方式</label>'
            + '<select data-field="receipt_type">'
            + '  <option value="">請選擇</option>'
            + '  <option value="electronic"'+(row.receipt_type==='electronic'?' selected':'')+'>電子</option>'
            + '  <option value="paper"'+(row.receipt_type==='paper'?' selected':'')+'>紙本</option>'
            + '</select>'
			+ '<label>收據狀態</label>'
			+ '<select data-field="receipt_status">'
			+ '  <option value="">請選擇</option>'
			+ '  <option value="pending"'+(row.receipt_status==='pending'?' selected':'')+'>待開立</option>'
			+ '  <option value="auto"'+(row.receipt_status==='auto'?' selected':'')+'>已開立待寄（自動）</option>'
			+ '  <option value="manual"'+(row.receipt_status==='manual'?' selected':'')+'>已開立待寄（手動）</option>'
			+ '  <option value="sent"'+(row.receipt_status==='sent'?' selected':'')+'>已寄出</option>'
			+ '</select>'
            + '<label>匯款金額（元）</label>'
            + '<input type="text" data-field="remit_amount" value="'+esc(formatAmount(row.remit_amount))+'">'
            + '<label>匯款日期</label>'
            + '<input type="date" data-field="remit_paid_at" value="'+esc(display(row.remit_paid_at))+'">';
        grid.appendChild(receipt);

        // 其他資訊
        const other = document.createElement('div');
        other.className = 'tpma-reg-detail-section';
        other.innerHTML = '<div class="tpma-reg-detail-section-title">其他資訊</div>'
			+ '<label>報名狀態</label>'
			+ '<select data-field="status">'
			+ '  <option value="pending"'+(row.status==='pending'?' selected':'')+'>待付款</option>'
			+ '  <option value="verifying"'+(row.status==='verifying'?' selected':'')+'>待核帳</option>'
			+ '  <option value="paid"'+(row.status==='paid'?' selected':'')+'>已付款</option>'
			+ '  <option value="cert_pending"'+(row.status==='cert_pending'?' selected':'')+'>待發證</option>'
			+ '  <option value="completed"'+(row.status==='completed'?' selected':'')+'>已結訓</option>'
			+ '  <option value="cancelled"'+(row.status==='cancelled'?' selected':'')+'>已取消</option>'
			+ '</select>'
            + '</select>'
            + '<label>測驗成績</label>'
            + '<input type="text" data-field="test_score" value="'+esc(display(row.test_score))+'">'
            + '<label>證書編號</label>'
            + '<input type="text" data-field="certificate_id" value="'+esc(display(row.certificate_id))+'">'
            + '<label>備註</label>'
            + '<textarea data-field="note">'+esc(display(row.note))+'</textarea>';
        grid.appendChild(other);

        container.appendChild(grid);

        const actions = document.createElement('div');
        actions.className = 'tpma-reg-detail-actions';
        actions.innerHTML = ''
            + '<button class="tpma-btn tpma-save-btn">儲存</button>'
            + '<button class="tpma-btn tpma-cancel-btn">取消</button>';
        container.appendChild(actions);

        populateEditCourseAndDate(row);

        actions.querySelector('.tpma-save-btn').addEventListener('click', async function(){
            await saveDetail(container, row.id);
        });
        actions.querySelector('.tpma-cancel-btn').addEventListener('click', function(){
            renderDetailView(container, row);
        });
    }

    async function saveDetail(container, id){
        const inputs = container.querySelectorAll('[data-field]');
        const payload = { id: parseInt(id,10) || 0 };
        inputs.forEach(el=>{
            const f = el.dataset.field;
            if (!f) return;
            let v = el.value;
            if (v == null) v = '';
            v = v.trim();
            if (f === 'remit_amount' && v !== '') {
                v = String(parseInt(v.replace(/,/g,''), 10) || 0);
            }
            payload[f] = v;
        });
        if (!payload.id) {
            alert('找不到這筆資料的 ID');
            return;
        }
        try{
            const res = await fetch(apiBase + '/admin/registration/update', {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-WP-Nonce': wpRestNonce
                },
                credentials:'include',
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!res.ok || !data || !data.success) {
                throw new Error(data && data.message ? data.message : '更新失敗');
            }
            await refreshFromServer();
        }catch(e){
            console.error(e);
            alert('儲存失敗：' + e.message);
        }
    }

    // 標記哪些欄位有啟用篩選（讓按鈕變色）
    function updateFilterButtonStates(){
        const btnCreated = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="created_at"]');
        const btnCourse  = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="course"]');
        const btnClass   = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="class_date"]');
        const btnRemit   = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="remit_paid_at"]');
        const btnStatus    = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="status"]');
        const btnReceipt   = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="receipt_status"]');
        const btnPaymentWC = document.querySelector('.tpma-th-menu-btn[data-menu-toggle="payment_status_wc"]'); // New: Payment status filter button

        const hasCreated   = !!(filterState.created_from || filterState.created_to);
        const hasCourse    = !!filterState.course_id;
        const hasClass     = !!filterState.class_date;
        const hasRemit     = !!(filterState.remit_from || filterState.remit_to);
        const hasStatus    = !!filterState.status;
        const hasReceipt   = !!(filterState.receipt_status || filterState.receipt_type);
        const hasPaymentWC = !!filterState.payment_status; // New: Check if payment status filter is active

        if (btnCreated)   btnCreated.classList.toggle('tpma-filter-active', hasCreated);
        if (btnCourse)    btnCourse.classList.toggle('tpma-filter-active', hasCourse);
        if (btnClass)     btnClass.classList.toggle('tpma-filter-active', hasClass);
        if (btnRemit)     btnRemit.classList.toggle('tpma-filter-active', hasRemit);
        if (btnStatus)    btnStatus.classList.toggle('tpma-filter-active', hasStatus);
        if (btnReceipt)   btnReceipt.classList.toggle('tpma-filter-active', hasReceipt);
        if (btnPaymentWC) btnPaymentWC.classList.toggle('tpma-filter-active', hasPaymentWC); // New: Toggle class for payment status filter button
    }

    // ─── 表頭下拉選單控制 ──────────────────────────
    function closeAllMenus(){
        document.querySelectorAll('.tpma-th-menu').forEach(m => m.style.display = 'none');
        openMenuCol = null;
    }

    document.querySelectorAll('.tpma-th-menu-btn').forEach(btn=>{
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            const col = this.getAttribute('data-menu-toggle');
            if (!col) return;
            const menu = document.querySelector('.tpma-th-menu[data-menu-col="'+col+'"]');
            if (!menu) return;
            if (openMenuCol === col) {
                menu.style.display = 'none';
                openMenuCol = null;
            } else {
                closeAllMenus();
                menu.style.display = 'block';
                openMenuCol = col;
            }
        });
    });

    document.addEventListener('click', function(e){
        if (!e.target.closest('.tpma-th-menu') && !e.target.closest('.tpma-th-menu-btn')) {
            closeAllMenus();
        }
    });

    // 排序按鈕（共用）
    document.querySelectorAll('.tpma-th-menu [data-sort-field]').forEach(btn=>{
        btn.addEventListener('click', function(){
            const f = this.getAttribute('data-sort-field');
            const d = this.getAttribute('data-sort-dir');
            sortState.field = f;
            sortState.dir   = d;
            applyFiltersAndRender();
        });
    });

	// 報名時間（created_at）篩選：單日 / 範圍
	const $createdSingle     = document.getElementById('tpma-filter-created-single');
	const $createdFrom       = document.getElementById('tpma-filter-created-from');
	const $createdTo         = document.getElementById('tpma-filter-created-to');
	const $createdRangeCheck = document.getElementById('tpma-filter-created-range');
	const $createdSingleWrap = document.getElementById('tpma-created-single');
	const $createdRangeWrap  = document.getElementById('tpma-created-range');

	function updateCreatedFilter() {
		const range = $createdRangeCheck && $createdRangeCheck.checked;

		if (range) {
			const fromVal = $createdFrom ? ($createdFrom.value || '') : '';
			const toVal   = $createdTo   ? ($createdTo.value   || '') : '';
			// 範圍模式：from / to 正常使用
			filterState.created_from = fromVal;
			filterState.created_to   = toVal;
		} else {
			const v = $createdSingle ? ($createdSingle.value || '') : '';
			// 單日模式：from / to 同一天
			filterState.created_from = v;
			filterState.created_to   = v;
		}
		applyFiltersAndRender();
	}

	if ($createdSingle) {
		$createdSingle.addEventListener('change', updateCreatedFilter);
	}
	if ($createdFrom) {
		$createdFrom.addEventListener('change', updateCreatedFilter);
	}
	if ($createdTo) {
		$createdTo.addEventListener('change', updateCreatedFilter);
	}
	if ($createdRangeCheck) {
		$createdRangeCheck.addEventListener('change', function(){
			const range = this.checked;
			if ($createdSingleWrap) $createdSingleWrap.style.display = range ? 'none' : '';
			if ($createdRangeWrap)  $createdRangeWrap.style.display  = range ? '' : 'none';
			updateCreatedFilter();
		});
	}

	document.getElementById('tpma-btn-clear-created').addEventListener('click', function(){
		filterState.created_from = '';
		filterState.created_to   = '';
		if ($createdSingle)     $createdSingle.value = '';
		if ($createdFrom)       $createdFrom.value   = '';
		if ($createdTo)         $createdTo.value     = '';
		if ($createdRangeCheck) $createdRangeCheck.checked = false;
		if ($createdSingleWrap) $createdSingleWrap.style.display = '';
		if ($createdRangeWrap)  $createdRangeWrap.style.display  = 'none';
		applyFiltersAndRender();
	});

	// 匯款日期（remit_paid_at）篩選：單日 / 範圍
	const $remitSingle     = document.getElementById('tpma-filter-remit-single');
	const $remitFrom       = document.getElementById('tpma-filter-remit-from');
	const $remitTo         = document.getElementById('tpma-filter-remit-to');
	const $remitRangeCheck = document.getElementById('tpma-filter-remit-range');
	const $remitSingleWrap = document.getElementById('tpma-remit-single');
	const $remitRangeWrap  = document.getElementById('tpma-remit-range');

	function updateRemitFilter(){
		const range = $remitRangeCheck && $remitRangeCheck.checked;

		if (range) {
			const fromVal = $remitFrom ? ($remitFrom.value || '') : '';
			const toVal   = $remitTo   ? ($remitTo.value   || '') : '';
			filterState.remit_from = fromVal;
			filterState.remit_to   = toVal;
		} else {
			const v = $remitSingle ? ($remitSingle.value || '') : '';
			filterState.remit_from = v;
			filterState.remit_to   = v;
		}
		applyFiltersAndRender();
	}

	if ($remitSingle) {
		$remitSingle.addEventListener('change', updateRemitFilter);
	}
	if ($remitFrom) {
		$remitFrom.addEventListener('change', updateRemitFilter);
	}
	if ($remitTo) {
		$remitTo.addEventListener('change', updateRemitFilter);
	}
	if ($remitRangeCheck) {
		$remitRangeCheck.addEventListener('change', function(){
			const range = this.checked;
			if ($remitSingleWrap) $remitSingleWrap.style.display = range ? 'none' : '';
			if ($remitRangeWrap)  $remitRangeWrap.style.display  = range ? '' : 'none';
			updateRemitFilter();
		});
	}

	document.getElementById('tpma-btn-clear-remit').addEventListener('click', function(){
		filterState.remit_from = '';
		filterState.remit_to   = '';
		if ($remitSingle)     $remitSingle.value = '';
		if ($remitFrom)       $remitFrom.value   = '';
		if ($remitTo)         $remitTo.value     = '';
		if ($remitRangeCheck) $remitRangeCheck.checked = false;
		if ($remitSingleWrap) $remitSingleWrap.style.display = '';
		if ($remitRangeWrap)  $remitRangeWrap.style.display  = 'none';
		applyFiltersAndRender();
	});



    // 課程篩選
	const $course = document.getElementById('tpma-filter-course');
	if ($course) {
		$course.addEventListener('change', function(){
			filterState.course_id = $course.value || '';
			applyFiltersAndRender();
		});
	}
	document.getElementById('tpma-btn-clear-course').addEventListener('click', function(){
		filterState.course_id = '';
		if ($course) $course.value = '';
		applyFiltersAndRender();
	});

	// 授課日期（class_date）篩選：單日 / 範圍
	const $classSingle     = document.getElementById('tpma-filter-class-single');
	const $classFrom       = document.getElementById('tpma-filter-class-from');
	const $classTo         = document.getElementById('tpma-filter-class-to');
	const $classRangeCheck = document.getElementById('tpma-filter-class-range');
	const $classSingleWrap = document.getElementById('tpma-class-single');
	const $classRangeWrap  = document.getElementById('tpma-class-range');

	function updateClassDateFilter() {
		const range = $classRangeCheck && $classRangeCheck.checked;

		if (range) {
			const fromVal = $classFrom ? ($classFrom.value || '') : '';
			const toVal   = $classTo   ? ($classTo.value   || '') : '';
			filterState.class_date      = '';
			filterState.class_date_from = fromVal;
			filterState.class_date_to   = toVal;
		} else {
			const v = $classSingle ? ($classSingle.value || '') : '';
			filterState.class_date      = v;
			filterState.class_date_from = '';
			filterState.class_date_to   = '';
		}
		applyFiltersAndRender();
	}

	if ($classSingle) {
		$classSingle.addEventListener('change', updateClassDateFilter);
	}
	if ($classFrom) {
		$classFrom.addEventListener('change', updateClassDateFilter);
	}
	if ($classTo) {
		$classTo.addEventListener('change', updateClassDateFilter);
	}
	if ($classRangeCheck) {
		$classRangeCheck.addEventListener('change', function(){
			const range = this.checked;
			if ($classSingleWrap) $classSingleWrap.style.display = range ? 'none' : '';
			if ($classRangeWrap)  $classRangeWrap.style.display  = range ? '' : 'none';
			updateClassDateFilter();
		});
	}

	document.getElementById('tpma-btn-clear-class-date').addEventListener('click', function(){
		filterState.class_date      = '';
		filterState.class_date_from = '';
		filterState.class_date_to   = '';
		if ($classSingle)     $classSingle.value = '';
		if ($classFrom)       $classFrom.value   = '';
		if ($classTo)         $classTo.value     = '';
		if ($classRangeCheck) $classRangeCheck.checked = false;
		if ($classSingleWrap) $classSingleWrap.style.display = '';
		if ($classRangeWrap)  $classRangeWrap.style.display  = 'none';
		applyFiltersAndRender();
	});
    // 關鍵字篩選（上方列）
    const $q = document.getElementById('tpma-filter-q');
    document.getElementById('tpma-btn-apply-q').addEventListener('click', function(){
        filterState.q = ($q.value || '').trim();
        applyFiltersAndRender();
    });

        // 清除全部篩選
        document.getElementById('tpma-btn-clear-all').addEventListener('click', function(){
            // reset state
            filterState.q             = '';
            filterState.course_id     = '';
            filterState.class_date    = '';
            filterState.class_date_from = '';
            filterState.class_date_to = '';
            filterState.status        = '';
            filterState.receipt_status= '';
            filterState.receipt_type  = '';
            filterState.created_from  = '';
            filterState.created_to    = '';
            filterState.remit_from    = '';
            filterState.remit_to      = '';
            filterState.test_state    = '';
            filterState.payment_status= ''; // New: Reset payment status filter

            // reset UI
            $q.value = '';
            const $status          = document.getElementById('tpma-filter-status');
            const $receiptStat     = document.getElementById('tpma-filter-receipt-status');
            const $receiptType     = document.getElementById('tpma-filter-receipt-type');
            const $paymentStatusEl = document.getElementById('tpma-filter-payment-status'); // New: Payment status select
            const $createdFromEl   = document.getElementById('tpma-filter-created-from');
            const $createdToEl     = document.getElementById('tpma-filter-created-to');
            const $remitFromEl     = document.getElementById('tpma-filter-remit-from');
            const $remitToEl       = document.getElementById('tpma-filter-remit-to');
            const $testFilter      = document.getElementById('tpma-filter-test');
            const $courseFilter    = document.getElementById('tpma-filter-course');
            const $classSingle     = document.getElementById('tpma-filter-class-single');
            const $classFrom       = document.getElementById('tpma-filter-class-from');
            const $classTo         = document.getElementById('tpma-filter-class-to');
            const $classRangeCheck = document.getElementById('tpma-filter-class-range');
            const $createdSingle   = document.getElementById('tpma-filter-created-single');
            const $createdRangeCheck = document.getElementById('tpma-filter-created-range');
            const $remitSingle     = document.getElementById('tpma-filter-remit-single');
            const $remitRangeCheck = document.getElementById('tpma-filter-remit-range');


            if ($status)      $status.value = '';
            if ($receiptStat) $receiptStat.value = '';
            if ($receiptType) $receiptType.value = '';
            if ($paymentStatusEl) $paymentStatusEl.value = ''; // New: Reset payment status select
            if ($createdFromEl) $createdFromEl.value = '';
            if ($createdToEl)   $createdToEl.value = '';
            if ($remitFromEl)   $remitFromEl.value = '';
            if ($remitToEl)     $remitToEl.value = '';
            if ($testFilter)    $testFilter.value = '';
            if ($courseFilter)  $courseFilter.value = '';
            if ($classSingle)   $classSingle.value = '';
            if ($classFrom)     $classFrom.value = '';
            if ($classTo)       $classTo.value = '';
            if ($classRangeCheck) $classRangeCheck.checked = false;
            if ($createdSingle) $createdSingle.value = '';
            if ($createdRangeCheck) $createdRangeCheck.checked = false;
            if ($remitSingle) $remitSingle.value = '';
            if ($remitRangeCheck) $remitRangeCheck.checked = false;

            // Re-apply display logic for date ranges
            if (document.getElementById('tpma-created-single')) document.getElementById('tpma-created-single').style.display = '';
            if (document.getElementById('tpma-created-range'))  document.getElementById('tpma-created-range').style.display  = 'none';
            if (document.getElementById('tpma-remit-single'))   document.getElementById('tpma-remit-single').style.display   = '';
            if (document.getElementById('tpma-remit-range'))    document.getElementById('tpma-remit-range').style.display    = 'none';
            if (document.getElementById('tpma-class-single'))   document.getElementById('tpma-class-single').style.display   = '';
            if (document.getElementById('tpma-class-range'))    document.getElementById('tpma-class-range').style.display    = 'none';


            // 排序回預設（報名時間新到舊）
            sortState.field = 'created_at';
            sortState.dir   = 'desc';

            applyFiltersAndRender();
        });

        // 狀態 / 收據 / 付款狀態篩選
        const $status          = document.getElementById('tpma-filter-status');
        const $receiptStat     = document.getElementById('tpma-filter-receipt-status');
        const $receiptType     = document.getElementById('tpma-filter-receipt-type');
        const $paymentStatusEl = document.getElementById('tpma-filter-payment-status'); // New: Payment status select

        if ($status) {
            $status.addEventListener('change', function(){
                filterState.status = $status.value || '';
                applyFiltersAndRender();
            });
        }

        if ($receiptStat) {
            $receiptStat.addEventListener('change', function(){
                filterState.receipt_status = $receiptStat.value || '';
                applyFiltersAndRender();
            });
        }

        if ($receiptType) {
            $receiptType.addEventListener('change', function(){
                filterState.receipt_type = $receiptType.value || '';
                applyFiltersAndRender();
            });
        }

        // New: Payment status filter event listener
        if ($paymentStatusEl) {
            $paymentStatusEl.addEventListener('change', function(){
                filterState.payment_status = $paymentStatusEl.value || '';
                applyFiltersAndRender();
            });
        }

    // 全選
    $selectAllHead.addEventListener('change', function(){
        const checked = this.checked;
        document.querySelectorAll('.tpma-reg-select').forEach(cb=>{
            cb.checked = checked;
        });
        updateBatchButtonsEnabled();
    });

    // 分頁按鈕
    $pagePrev.addEventListener('click', function(){
        if (currentPage > 1) {
            currentPage--;
            renderTable(currentRegs);
        }
    });
    $pageNext.addEventListener('click', function(){
        const total = currentRegs.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage < totalPages) {
            currentPage++;
            renderTable(currentRegs);
        }
    });

    // 批次處理（共用）
    async function applyBatch(field, value){
        if (!field) return;
        const checked = Array.from(document.querySelectorAll('.tpma-reg-select:checked'));
        if (!checked.length) return;
        if (value == null || value === '') return;

        if (!confirm('確定要批次修改 ' + checked.length + ' 筆資料？')) return;

        try{
            for (const cb of checked) {
                const tr = cb.closest('tr');
                if (!tr) continue;
                const id = parseInt(tr.dataset.id, 10) || 0;
                if (!id) continue;

                const payload = { id: id };
                payload[field] = value;

                const res = await fetch(apiBase + '/admin/registration/update', {
                    method:'POST',
                    headers:{
                        'Content-Type':'application/json',
                        'X-WP-Nonce': wpRestNonce
                    },
                    credentials:'include',
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!res.ok || !data || !data.success) {
                    console.error('批次更新失敗 id=' + id, data);
                }
            }
            await refreshFromServer();
        }catch(e){
            console.error(e);
            alert('批次變更發生錯誤：' + e.message);
        }
    }

    // 綁定批次按鈕
    document.querySelectorAll('.tpma-batch-btn').forEach(btn=>{
        btn.addEventListener('click', async function(){
            if (!anyRowSelected()) return;
            const field = this.getAttribute('data-batch-field');
            let value = '';
            if (field === 'status') {
                value = document.getElementById('tpma-batch-status').value || '';
            } else if (field === 'receipt_status') {
                value = document.getElementById('tpma-batch-receipt-status').value || '';
            } else if (field === 'receipt_type') {
                value = document.getElementById('tpma-batch-receipt-type').value || '';
            } else if (field === 'remit_paid_at') {
                value = document.getElementById('tpma-batch-remit-date').value || '';
            }
            if (!value) {
                alert('請先選擇要套用的值');
                return;
            }
            await applyBatch(field, value);
        });
    });

    // 初始化
    (async function init(){
        await loadCourses();
        await refreshFromServer();
    })();
})();
</script>
