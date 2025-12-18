<?php
if (!defined('ABSPATH')) { exit; }
$api_base   = isset($api_base) ? $api_base : rtrim(rest_url('tpma/v1'), '/');
$assets_base = isset($assets_base) ? $assets_base : TPMA_CR_URL;
$form_url   = isset($form_url) ? $form_url : ($assets_base . 'form.html');
?>
<link rel="stylesheet" href="<?php echo esc_url($assets_base . 'assets/css/admin-common.css'); ?>">
<link rel="stylesheet" href="<?php echo esc_url($assets_base . 'assets/css/list-public.css'); ?>">
<div class="tpma-course-list">
<div class="tpma-course-list-wrap tpma-wrap">
  <div class="tpma-course-list-header">
    <h1>課程一覽</h1>
    <div class="tpma-status" id="tpma-status">載入課程中...</div>
  </div>

  <table class="tpma-course-table tpma-table-shared">
    <thead>
      <tr>
        <!-- 授課時間 -->
        <th style="width: 28%;">
          <div class="tpma-th-inner">
            <span class="tpma-th-title">授課時間</span>
            <button type="button"
                    class="tpma-th-menu-btn"
                    data-menu-target="menu-time">
              ▼ 
            </button>
          </div>
          <div class="tpma-th-menu" id="menu-time">
            <label>
              關鍵字篩選（日期、時間）：
              <input type="text" id="filter-time" placeholder="例如 2025/03 或 09:00">
            </label>
            <div class="tpma-th-menu-actions">
              <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="time-asc">時間↑</button>
              <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="time-desc">時間↓</button>
              <button type="button" class="tpma-btn tpma-btn-danger" data-clear="time">清除</button>
            </div>
          </div>
        </th>

        <!-- 課程名稱 -->
        <th style="width: 32%;">
          <div class="tpma-th-inner">
            <span class="tpma-th-title">課程名稱</span>
            <button type="button"
                    class="tpma-th-menu-btn"
                    data-menu-target="menu-name">
              ▼ 
            </button>
          </div>
          <div class="tpma-th-menu" id="menu-name">
            <label>
              關鍵字篩選（課程名稱）：
              <input type="text" id="filter-name" placeholder="輸入課程關鍵字">
            </label>
            <div class="tpma-th-menu-actions">
              <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="name-asc">名稱↑</button>
              <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="name-desc">名稱↓</button>
              <button type="button" class="tpma-btn tpma-btn-danger" data-clear="name">清除</button>
            </div>
          </div>
        </th>

        <!-- 授課講師 -->
        <th style="width: 20%;">
          <div class="tpma-th-inner">
            <span class="tpma-th-title">授課講師</span>
            <button type="button"
                    class="tpma-th-menu-btn"
                    data-menu-target="menu-lecturer">
              ▼ 
            </button>
          </div>
          <div class="tpma-th-menu" id="menu-lecturer">
            <label>
              關鍵字篩選（講師名字）：
              <input type="text" id="filter-lecturer" placeholder="輸入講師姓名">
            </label>
            <div class="tpma-th-menu-actions">
              <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="lecturer-asc">講師↑</button>
              <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="lecturer-desc">講師↓</button>
              <button type="button" class="tpma-btn tpma-btn-danger" data-clear="lecturer">清除</button>
            </div>
          </div>
        </th>

        <!-- 報名網址 -->
        <th style="width: 20%;">報名網址</th>
      </tr>
    </thead>
    <tbody id="tpma-course-tbody">
      <tr>
        <td colspan="4" class="tpma-loading-row">載入課程中...</td>
      </tr>
    </tbody>
  </table>
</div>
</div>

<script>
window.TPMAPublicConfig = <?php echo wp_json_encode(array(
    'apiBase'   => $api_base,
    'formUrl'   => $form_url,
    'nonce'     => wp_create_nonce( 'wp_rest' ), // Add nonce for consistency, even if not strictly needed for public GET
)); ?>;
</script>
<script src="<?php echo esc_url($assets_base . 'assets/js/public/00.tpma-datetime.js'); ?>"></script>
<script src="<?php echo esc_url($assets_base . 'assets/js/public/01.tpma-public.utils.js'); ?>"></script>
<script src="<?php echo esc_url($assets_base . 'assets/js/public/list-public.js'); ?>"></script>
