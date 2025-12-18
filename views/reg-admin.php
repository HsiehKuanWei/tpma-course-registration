<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );

if (!function_exists('tpma_cr_asset_ver')) {
    function tpma_cr_asset_ver($relativePath) {
        $relativePath = ltrim((string)$relativePath, '/\\');
        $fullPath = (defined('TPMA_CR_PATH') ? TPMA_CR_PATH : '') . $relativePath;
        $mtime = (is_string($fullPath) && $fullPath !== '' && file_exists($fullPath)) ? (string)filemtime($fullPath) : (defined('TPMA_CR_VERSION') ? (string)TPMA_CR_VERSION : '1');
        return (defined('TPMA_CR_VERSION') ? (string)TPMA_CR_VERSION : '1') . '.' . $mtime;
    }
}
?>

<?php
// 引入共用 mail modal（路徑依你實際放的位置調整）
// 假設 mail-modal.php 跟 reg-admin.php 一樣在 views 資料夾：
include __DIR__ . '/mail-modal.php';
?>
<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/admin-common.css?ver=' . TPMA_CR_VERSION ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/reg-admin.css?ver=' . TPMA_CR_VERSION ); ?>">

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

    <table class="tpma-course-table tpma-reg-table tpma-table-shared">
        <thead>
        <tr>
            <th style="width:35px;">
                <input type="checkbox" id="tpma-select-all-head">
            </th>
            <th class="tpma-seq-col">序</th>

            <!-- 報名時間 -->
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">報名時間</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-created_at">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-created_at">
                    <label>
                        範圍篩選：
                        <input type="checkbox" id="tpma-filter-created-range">
                    </label>
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
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="created_at-asc">時間↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="created_at-desc">時間↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="created_at">清除</button>
                    </div>
                </div>
            </th>

            <!-- 課程名稱（hover 顯示講師） -->
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">課程名稱</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-course">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-course">
                    <label>
                        課程篩選：
                        <select id="tpma-filter-course">
                            <option value="">全部課程</option>
                        </select>
                    </label>
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="course_name-asc">名稱↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="course_name-desc">名稱↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="course">清除</button>
                    </div>
                </div>
            </th>

            <!-- 授課日期時間（顯示日期＋起迄時間） -->
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">授課日期</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-class_date">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-class_date">
                    <label>
                        範圍篩選：
                        <input type="checkbox" id="tpma-filter-class-range">
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
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="class_date-asc">日期↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="class_date-desc">日期↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="class_date">清除</button>
                    </div>
                </div>
            </th>

            <!-- 匯款日期 
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">匯款日期</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-remit_paid_at">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-remit_paid_at">
                    <label>
                        範圍篩選：
                        <input type="checkbox" id="tpma-filter-remit-range">
                    </label>
                    <-- 單日模式 --
                    <div id="tpma-remit-single">
                        <input type="date" id="tpma-filter-remit-single" list="tpma-remit-date-list">
                        <datalist id="tpma-remit-date-list"></datalist>
                    </div>
                    <-- 範圍模式 --
                    <div id="tpma-remit-range" style="display:none;">
                        <input type="date" id="tpma-filter-remit-from" placeholder="起日">
                        <input type="date" id="tpma-filter-remit-to" placeholder="訖日">
                    </div>
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="remit_paid_at-asc">日期↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="remit_paid_at-desc">日期↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="remit_paid_at">清除</button>
                    </div>
                    <div class="tpma-menu-section">
                        <label>批次修改匯款日期</label>
                        <input type="date" id="tpma-batch-remit-date" class="tpma-batch-input">
                        <button class="tpma-btn tpma-batch-btn" data-batch-field="remit_paid_at">套用批次設定</button>
                    </div>
                </div>
            </th>
        -->

            <!-- 學員姓名 -->
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">學員姓名</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-student_name">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-student_name">
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="student_name-asc">姓名↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="student_name-desc">姓名↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="student_name">清除</button>
                    </div>
                </div>
            </th>

            <!-- 公司抬頭 -->
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">公司抬頭</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-company_name">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-company_name">
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="company_name-asc">公司↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="company_name-desc">公司↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="company_name">清除</button>
                    </div>
                </div>
            </th>

            <!-- 付款狀態 (WooCommerce) -->
            <!-- 報名狀態 (整合所有狀態) -->
			<th>
				<div class="tpma-th-inner">
					<span class="tpma-th-title">狀態</span>
					<button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-status">
                        ▼ 
                    </button>
				</div>
					<div class="tpma-th-menu" id="menu-status">
						<h4>狀態篩選</h4>

                        <div class="tpma-menu-section">
							<label><strong>付款狀態 (WC)</strong></label>
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
                            <button class="tpma-btn" id="tpma-btn-clear-payment-status">清除付款篩選</button>
                        </div>

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
			</th>


            <!-- 操作 -->
            <th>操作</th>
        </tr>
        </thead>
        <tbody id="tpma-reg-tbody">
        <tr><td colspan="9">載入中...</td></tr>
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
window.TPMARegAdminConfig = <?php echo wp_json_encode(array(
    'apiBase' => $apiBase,
    'nonce' => $restNonce,
    'orderEditBase' => admin_url('post.php?post='),
)); ?>;
</script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/00.tpma-datetime.js?ver=' . tpma_cr_asset_ver('assets/js/public/00.tpma-datetime.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/01.tpma-public.utils.js?ver=' . tpma_cr_asset_ver('assets/js/public/01.tpma-public.utils.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/02.tpma-public.api.js?ver=' . tpma_cr_asset_ver('assets/js/public/02.tpma-public.api.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/01.reg-admin.utils.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/01.reg-admin.utils.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/02.reg-admin.labels.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/02.reg-admin.labels.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/03.reg-admin.api.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/03.reg-admin.api.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/04.reg-admin.state.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/04.reg-admin.state.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/05.reg-admin.render.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/05.reg-admin.render.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/06.reg-admin.ui-events.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/06.reg-admin.ui-events.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/07.reg-admin.core.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/07.reg-admin.core.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/08.reg-admin-init.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/08.reg-admin-init.js') ); ?>"></script>
