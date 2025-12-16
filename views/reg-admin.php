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
            <!-- 報名狀態 (整合所有狀態) -->
			<th>
				<div class="tpma-th-inner">
					<span>狀態</span>
					<button class="tpma-th-menu-btn" data-menu-toggle="status">▾</button>
					<div class="tpma-th-menu" data-menu-col="status">
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
window.TPMARegAdminConfig = <?php echo wp_json_encode(array(
    'apiBase' => $apiBase,
    'nonce' => $restNonce,
    'orderEditBase' => admin_url('post.php?post='),
)); ?>;
</script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/00.tpma-datetime.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/01.tpma-public.utils.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/02.tpma-public.api.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/01.reg-admin.utils.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/02.reg-admin.labels.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/03.reg-admin.api.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/04.reg-admin.state.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/05.reg-admin.render.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/06.reg-admin.ui-events.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/07.reg-admin.core.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/08.reg-admin-init.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin-init.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
