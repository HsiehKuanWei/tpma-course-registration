<?php
if (!defined('ABSPATH')) { exit; }
$api_base   = isset($api_base) ? $api_base : rtrim(rest_url('tpma/v1'), '/');
$assets_base = isset($assets_base) ? $assets_base : TPMA_CR_URL;
?>
<link rel="stylesheet" href="<?php echo esc_url($assets_base . 'assets/css/admin-common.css'); ?>">
<link rel="stylesheet" href="<?php echo esc_url($assets_base . 'assets/css/form-public.css'); ?>">
<div id="tpma-course-registration">
  <div class="tpma-form-wrap">
    <div id="tpma-loading" class="tpma-loading">載入課程資料中...</div>
    <form id="tpma-reg-form" novalidate>
      <!-- 課程名稱 -->
      <div class="tpma-field">
        <div class="tpma-label-row">
          <div class="tpma-label">課程名稱</div>
          <div class="tpma-required">* 必填</div>
        </div>
        <select id="course-select" class="tpma-select">
          <option value="">請選擇課程</option>
        </select>
        <div class="tpma-error-msg" data-error-for="course-select"></div>
      </div>

      <!-- 授課日期 / 場次 -->
      <div class="tpma-field">
        <div class="tpma-label-row">
          <div class="tpma-label">授課日期 / 場次</div>
          <div class="tpma-required">* 必填</div>
        </div>
        <select id="session-select" class="tpma-select" disabled>
          <option value="">請先選擇課程</option>
        </select>
        <div class="tpma-error-msg" data-error-for="session-select"></div>
      </div>

      <!-- 課程資訊展示 -->
      <div class="tpma-field">
        <div class="tpma-label">講師</div>
        <p id="course-lecturer"></p>
      </div>

      <div class="tpma-field">
        <div class="tpma-label">課程名稱</div>
        <p id="course-name-display"></p>
      </div>

      <div class="tpma-field">
        <div class="tpma-label">簡介</div>
        <p id="course-intro"></p>
      </div>

      <div class="tpma-field">
        <div class="tpma-label">大綱</div>
        <div id="course-outline"></div>
      </div>

      <div class="tpma-field">
        <div class="tpma-label">授課時數（小時）</div>
        <p id="course-duration"></p>
      </div>

      <hr>

      <!-- 學員區塊 -->
      <div class="tpma-field">
        <div class="tpma-label-row">
          <div class="tpma-label">報名學員資料</div>
          <div class="tpma-required">* 至少 1 位學員，姓名與 Email 必填</div>
        </div>
      </div>

      <div id="learners-wrapper"></div>

      <div class="tpma-field">
        <button type="button" id="add-learner" class="tpma-btn tpma-btn-secondary">+ 新增一位學員</button>
        <div class="tpma-error-msg" data-error-for="learners"></div>
      </div>

      <!-- 承辦 / 公司 / 收據 / 收件人欄位已移至 Woo 結帳頁，表單不再收集 -->

      <!-- 資訊來源 -->
      <div class="tpma-field">
        <div class="tpma-label-row">
          <div class="tpma-label">資訊來源</div>
        </div>
        <select id="source" class="tpma-select">
          <option value="">請選擇</option>
          <option value="學會官網">學會官網</option>
          <option value="TWSE 官網">TWSE 官網</option>
          <option value="學會 Mail">學會 Mail</option>
          <option value="學會電訪">學會電訪</option>
          <option value="其他">其他</option>
        </select>
      </div>

      <!-- 備註 
      <div class="tpma-field">
        <div class="tpma-label-row">
          <div class="tpma-label">備註</div>
        </div>
        <textarea id="note" class="tpma-textarea" rows="3"></textarea>
      </div>-->

      <button type="button" id="tpma-submit" class="tpma-btn">下一步</button>
      <div id="tpma-message"></div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
window.TPMAPublicConfig = <?php echo wp_json_encode(array(
    'apiBase'   => $api_base,
    'nonce'     => wp_create_nonce( 'wp_rest' ), // Add nonce for consistency
)); ?>;
</script>
<script src="<?php echo esc_url($assets_base . 'assets/js/public/00.tpma-datetime.js'); ?>"></script>
<script src="<?php echo esc_url($assets_base . 'assets/js/public/01.tpma-public.utils.js'); ?>"></script>
<script src="<?php echo esc_url($assets_base . 'assets/js/public/form-public.js'); ?>"></script>
