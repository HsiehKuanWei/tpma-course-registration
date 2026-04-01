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

<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/admin-common.css?ver=' . TPMA_CR_VERSION ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/reg-admin.css?ver=' . TPMA_CR_VERSION ); ?>">

<?php
// === TPMA：統一選項來源（只改這裡就好）======================

/**
 * 唯一權威 ENUM（真值）
 * - 任何狀態文字/增刪，只改這裡
 * - 篩選 / 批次 / 編輯：全部由這份 ENUM 衍生，避免分散與不一致
 */
$TPMA_ENUM = [
  // 報名狀態
  'regStatus' => [
    'cert_pending' => '待發證',
    'completed'    => '已結訓',
    'hold'         => '保留中',
    'hold_refunded' => '待退款',
    'cancelled'     => '已取消'

  ],

  // Woo 訂單狀態（付款狀態）
  'wcStatus' => [
    'on-hold'        => '未付款 (WC)',  // Woo on-hold → 尚未付款
    'processing'     => '待核帳 (WC)',    // Woo processing → 待核帳
    'completed'      => '已付款 (WC)',
    'cancelled'      => '已取消 (WC)',
    'refunded'       => '已退款 (WC)',
    'failed'         => '失敗 (WC)',
    'checkout-draft' => '草稿 (WC)',
  ],

  // 收據狀態
  'receiptStatus' => [
    'pending' => '待開立',
    'auto'    => '已開立待寄（自動）',
    'manual'  => '已開立待寄（手動）',
    'sent'    => '已寄出',
  ],

  // 收據方式
  'receiptType' => [
    'electronic' => '電子',
    'paper'      => '紙本',
  ],
];

// enum -> [{value,label}, ...]
if ( ! function_exists( 'tpma_enum_to_options' ) ) {
    function tpma_enum_to_options(array $enum){
      $out = [];
      foreach ($enum as $value => $label) {
        $out[] = ['value' => (string)$value, 'label' => (string)$label];
      }
      return $out;
    }
}

// === 篩選用選項（含「全部」）— 仍由 ENUM 組出來 ====================
$TPMA_WC_STATUS_OPTIONS = ['' => '全部'] + $TPMA_ENUM['wcStatus'];
$TPMA_REG_STATUS_FILTER_OPTIONS = ['' => '全部'] + $TPMA_ENUM['regStatus'];
$TPMA_RECEIPT_STATUS_FILTER_OPTIONS = ['' => '全部'] + $TPMA_ENUM['receiptStatus'];
$TPMA_RECEIPT_TYPE_FILTER_OPTIONS   = ['' => '全部'] + $TPMA_ENUM['receiptType'];

// === 給 JS 用（編輯模式的 select 也走同一份）========================
// 注意：JS 端的下拉「提示 option」(例如：請選擇) 由前端自行加上；這裡只提供真值列表
$TPMA_OPTIONS_FOR_JS = [
  'wcStatus'      => tpma_enum_to_options($TPMA_ENUM['wcStatus']),
  'regStatus'     => tpma_enum_to_options($TPMA_ENUM['regStatus']),
  'receiptStatus' => tpma_enum_to_options($TPMA_ENUM['receiptStatus']),
  'receiptType'   => tpma_enum_to_options($TPMA_ENUM['receiptType']),
];

?>

<div id="tpma-reg-admin" class="tpma-wrap">

    <div class="tpma-filter-row">
        <span>關鍵字搜尋：</span>
        <input type="text" id="tpma-filter-q"
               placeholder="報名編號 / 學員 / 承辦 / 公司（模糊）">
        <button class="tpma-btn" id="tpma-btn-apply-q">搜尋</button>
        <button class="tpma-btn" id="tpma-btn-clear-all">清除全部篩選</button>
        <button class="tpma-btn" id="tpma-btn-export">匯出 Excel</button>
    </div>
	
  <div class="tpma-toolbar-row">
    <div class="tpma-toolbar-left">
      <a class="tpma-btn tpma-btn-ghost"
         id="tpma-btn-mail-templates"
         href="https://tw-pma.org.tw/%e4%bf%a1%e4%bb%b6%e6%a8%a1%e6%9d%bf/"
         target="_blank" rel="noopener">
        信件模板
      </a>
    </div>
    <div class="tpma-toolbar-right">
      <div class="tpma-view-mode-row" role="group" aria-label="清單顯示模式">
        <button type="button" class="tpma-btn tpma-btn-secondary tpma-view-mode-btn" id="tpma-view-mode-nested" aria-pressed="false">巢狀模式</button>
        <button type="button" class="tpma-btn tpma-btn-secondary tpma-view-mode-btn" id="tpma-view-mode-flat" aria-pressed="false">平鋪模式</button>
      </div>
    </div>
  </div>


<div class="tpma-reg-grid tpma-table-shared">

  <div class="tpma-reg-grid-header tpma-reg-grid-layout">

  <div class="tpma-reg-grid-th" style="width:35px;">
    <input type="checkbox" id="tpma-select-all-head">
  </div>

  <div class="tpma-reg-grid-th tpma-seq-col">序</div>

  <div class="tpma-reg-grid-th">
    <div class="tpma-th-inner">
      <span class="tpma-th-title">報名時間</span>
      <button type="button" class="tpma-th-menu-btn" data-menu-target="menu-created_at">▼</button>
    </div>
    <div class="tpma-th-menu" id="menu-created_at">
      <label>
        範圍篩選：
        <input type="checkbox" id="tpma-filter-created-range">
      </label>
      <div id="tpma-created-single">
        <input type="date" id="tpma-filter-created-single" list="tpma-created-date-list">
        <datalist id="tpma-created-date-list"></datalist>
      </div>
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
  </div>

  <div class="tpma-reg-grid-th">
    <div class="tpma-th-inner">
      <span class="tpma-th-title">課程名稱</span>
      <button type="button" class="tpma-th-menu-btn" data-menu-target="menu-course">▼</button>
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
  </div>

  <div class="tpma-reg-grid-th">
    <div class="tpma-th-inner">
      <span class="tpma-th-title">授課日期</span>
      <button type="button" class="tpma-th-menu-btn" data-menu-target="menu-class_date">▼</button>
    </div>
    <div class="tpma-th-menu" id="menu-class_date">
      <label>
        範圍篩選：
        <input type="checkbox" id="tpma-filter-class-range">
      </label>
      <div id="tpma-class-single">
        <input type="date" id="tpma-filter-class-single" list="tpma-class-date-list">
        <datalist id="tpma-class-date-list"></datalist>
      </div>
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
  </div>

  <div class="tpma-reg-grid-th">
    <div class="tpma-th-inner">
      <span class="tpma-th-title">學員姓名</span>
      <button type="button" class="tpma-th-menu-btn" data-menu-target="menu-student_name">▼</button>
    </div>
    <div class="tpma-th-menu" id="menu-student_name">
      <div class="tpma-th-menu-actions">
        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="student_name-asc">姓名↑</button>
        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="student_name-desc">姓名↓</button>
        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="student_name">清除</button>
      </div>
    </div>
  </div>

  <div class="tpma-reg-grid-th">
    <div class="tpma-th-inner">
      <span class="tpma-th-title">公司抬頭</span>
      <button type="button" class="tpma-th-menu-btn" data-menu-target="menu-company_name">▼</button>
    </div>
    <div class="tpma-th-menu" id="menu-company_name">
      <div class="tpma-th-menu-actions">
        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="company_name-asc">公司↑</button>
        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="company_name-desc">公司↓</button>
        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="company_name">清除</button>
      </div>
    </div>
  </div>

  <div class="tpma-reg-grid-th">
    <div class="tpma-th-inner">
      <span class="tpma-th-title">狀態</span>
      <button type="button" class="tpma-th-menu-btn" data-menu-target="menu-status">▼</button>
    </div>
    <div class="tpma-th-menu" id="menu-status">
      <h4>狀態篩選</h4>

      <div class="tpma-menu-section">
        <label><strong>付款狀態 (WC)</strong></label>
          <select id="tpma-filter-payment-status">
            <?php foreach ($TPMA_WC_STATUS_OPTIONS as $v => $label): ?>
              <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
      </div>
      <div class="tpma-menu-section">
        <button class="tpma-btn" id="tpma-btn-clear-payment-status">清除付款篩選</button>
      </div>

      <div class="tpma-menu-section">
        <label><strong>報名狀態</strong></label>
          <select id="tpma-filter-status">
            <?php foreach ($TPMA_REG_STATUS_FILTER_OPTIONS as $v => $label): ?>
              <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
      </div>

      <div class="tpma-menu-section">
        <label><strong>收據狀態</strong></label>
        <select id="tpma-filter-receipt-status">
          <?php foreach ($TPMA_RECEIPT_STATUS_FILTER_OPTIONS as $v => $label): ?>
            <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tpma-menu-section">
        <label><strong>收據方式</strong></label>
        <select id="tpma-filter-receipt-type">
          <?php foreach ($TPMA_RECEIPT_TYPE_FILTER_OPTIONS as $v => $label): ?>
            <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tpma-menu-section">
        <button class="tpma-btn" id="tpma-btn-clear-receipt-filter">清除收據篩選</button>
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

      <div class="tpma-menu-section">
        <div style="font-weight:bold; margin-bottom:2px;">批次修改（第二層）</div>

        <label>批次修改報名狀態</label>
        <select id="tpma-batch-status" class="tpma-batch-select">
          <option value="">請選擇狀態</option>
          <?php foreach ($TPMA_ENUM['regStatus'] as $v => $label): ?>
            <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <button class="tpma-btn tpma-batch-btn" data-batch-field="status">批次設定報名狀態</button>

        <label style="margin-top:4px;">批次修改收據狀態</label>
        <select id="tpma-batch-receipt-status" class="tpma-batch-select">
          <option value="">請選擇狀態</option>
          <?php foreach ($TPMA_ENUM['receiptStatus'] as $v => $label): ?>
            <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <button class="tpma-btn tpma-batch-btn" data-batch-field="receipt_status">批次設定收據狀態</button>

        <label style="margin-top:4px;">批次修改收據方式</label>
        <select id="tpma-batch-receipt-type" class="tpma-batch-select">
          <option value="">請選擇方式</option>
          <?php foreach ($TPMA_ENUM['receiptType'] as $v => $label): ?>
            <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <button class="tpma-btn tpma-batch-btn" data-batch-field="receipt_type">批次設定收據方式</button>
      </div>
    </div>
  </div>

  <div class="tpma-reg-grid-th">操作</div>

</div>

  <div id="tpma-reg-tbody" class="tpma-reg-grid-body">
    <div class="tpma-loading-row">載入中.</div>
  </div>

</div>

    <div class="tpma-pagination">
        <button class="tpma-btn" id="tpma-page-prev">上一頁</button>
        <span class="tpma-pagination-info" id="tpma-page-info"></span>
        <button class="tpma-btn" id="tpma-page-next">下一頁</button>
    </div>

  <!-- ===== Excel 匯出 Modal ===== -->
  <div id="tpma-export-modal" class="tpma-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="tpma-export-modal-title">
    <div class="tpma-modal-dialog">
      <div class="tpma-modal-header">
        <h3 id="tpma-export-modal-title">匯出 Excel</h3>
        <button type="button" class="tpma-modal-close-btn" id="tpma-export-modal-close" aria-label="關閉">✕</button>
      </div>
      <div class="tpma-modal-body">
        <div class="tpma-export-option">
          <label>
            <input type="radio" name="tpma-export-type" value="students" checked>
            <span>
              <span class="tpma-export-option-title">課程學員資料</span>
              <span class="tpma-export-option-desc">匯出目前篩選結果（共 <span id="tpma-export-student-count">0</span> 筆），含完整欄位</span>
            </span>
          </label>
        </div>
        <div class="tpma-export-option">
          <label>
            <input type="radio" name="tpma-export-type" value="statistics">
            <span>
              <span class="tpma-export-option-title">統計報表</span>
              <span class="tpma-export-option-desc">依授課日期範圍，按公司分組列出學員數與收入，底部含總計</span>
              <span class="tpma-export-stats-options" id="tpma-export-stats-options" style="display:none;">
                <label>授課日期起：<input type="date" id="tpma-export-stats-from"></label>
                <label>授課日期訖：<input type="date" id="tpma-export-stats-to"></label>
              </span>
            </span>
          </label>
        </div>
      </div>
      <div class="tpma-modal-footer">
        <button type="button" class="tpma-btn tpma-btn-secondary" id="tpma-export-cancel">取消</button>
        <button type="button" class="tpma-btn" id="tpma-export-confirm">確認匯出</button>
      </div>
    </div>
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
<script>
window.TPMARegAdmin = window.TPMARegAdmin || {};
window.TPMARegAdmin.enums = <?php echo wp_json_encode($TPMA_ENUM, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/02.reg-admin.labels.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/02.reg-admin.labels.js') ); ?>"></script>

<script>
window.TPMARegAdmin = window.TPMARegAdmin || {};
window.TPMARegAdmin.options = window.TPMARegAdmin.options || {};

window.TPMARegAdmin.options.wcStatus      = <?php echo wp_json_encode($TPMA_OPTIONS_FOR_JS['wcStatus'], JSON_UNESCAPED_UNICODE); ?>;
window.TPMARegAdmin.options.regStatus     = <?php echo wp_json_encode($TPMA_OPTIONS_FOR_JS['regStatus'], JSON_UNESCAPED_UNICODE); ?>;
window.TPMARegAdmin.options.receiptStatus = <?php echo wp_json_encode($TPMA_OPTIONS_FOR_JS['receiptStatus'], JSON_UNESCAPED_UNICODE); ?>;
window.TPMARegAdmin.options.receiptType   = <?php echo wp_json_encode($TPMA_OPTIONS_FOR_JS['receiptType'], JSON_UNESCAPED_UNICODE); ?>;
</script>

<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/03.reg-admin.api.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/03.reg-admin.api.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/04.reg-admin.state.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/04.reg-admin.state.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/05.reg-admin.render.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/05.reg-admin.render.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/06.reg-admin.ui-events.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/06.reg-admin.ui-events.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/07.reg-admin.core.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/07.reg-admin.core.js') ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/08.reg-admin-init.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/08.reg-admin-init.js') ); ?>"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/reg-admin/09.reg-admin.export.js?ver=' . tpma_cr_asset_ver('assets/js/reg-admin/09.reg-admin.export.js') ); ?>"></script>

<script>
(function(){
  const L = window.TPMARegAdmin && window.TPMARegAdmin.labels;
  if (!L) return;

  const old = L.paymentStatusLabel;
  L.paymentStatusLabel = function(code){
    if (code === 'on-hold') return '尚未付款';
    if (code === 'processing') return '待核帳';
    return old ? old(code) : code;
  };
})();
</script>
