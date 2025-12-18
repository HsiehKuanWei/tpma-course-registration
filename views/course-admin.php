<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>
<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/admin-common.css?ver=' . TPMA_CR_VERSION ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/course-admin.css?ver=' . TPMA_CR_VERSION ); ?>">

<div id="tpma-course-admin" class="tpma-wrap">
    <div class="tpma-filter-row">
        <input type="text" id="tpma-filter-q" placeholder="關鍵字：課程代碼 / 課程名稱 / 類別 / 講師">
        <button class="tpma-btn" id="tpma-add-course">新增課程</button>
        <button class="tpma-btn" id="tpma-reset-filter">重置篩選</button>
    </div>

    <div class="tpma-filter-row">
        <span>上課日期篩選：</span>
        <input type="date" id="tpma-filter-date-from">
        <span>到</span>
        <input type="date" id="tpma-filter-date-to">

        <select id="tpma-filter-mode">
            <option value="open_only">只顯示開啟課程</option>
            <option value="with_closed">包含已關閉課程</option>
            <option value="scheduled_future">只顯示未來已排課（且有場次）</option>
        </select>

        <span style="font-size:12px;color:#666;">
            說明：日期篩選會比對各課程的場次；若勾選「只顯示未來已排課」，也會自動排除沒有未來場次的課程。
        </span>
    </div>

    <table class="tpma-course-table tpma-table-shared" id="tpma-course-table">
        <thead>
        <tr>
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">課程代碼</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-course_code">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-course_code">
                    <div class="tpma-menu-section">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="course_code-asc">代碼↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="course_code-desc">代碼↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="course_code">清除</button>
                    </div>
                </div>
            </th>

            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">課程名稱</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-course_name">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-course_name">
                    <label>
                        課程名稱篩選：
                        <select id="tpma-filter-course">
                            <option value="">全部課程名稱</option>
                        </select>
                    </label>
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="course_name-asc">名稱↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="course_name-desc">名稱↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="course_name">清除</button>
                    </div>
                </div>
            </th>

            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">講師</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-lecturer">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-lecturer">
                    <label>
                        講師篩選：
                        <select id="tpma-filter-lecturer">
                            <option value="">全部講師</option>
                        </select>
                    </label>
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="lecturer-asc">講師↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="lecturer-desc">講師↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="lecturer">清除</button>
                    </div>
                </div>
            </th>
            
            <th>
                <div class="tpma-th-inner">
                    <span class="tpma-th-title">課程類別</span>
                    <button type="button"
                            class="tpma-th-menu-btn"
                            data-menu-target="menu-category">
                        ▼ 
                    </button>
                </div>
                <div class="tpma-th-menu" id="menu-category">
                    <label>
                        類別篩選：
                        <select id="tpma-filter-category">
                            <option value="">全部類別</option>
                            <optgroup label="核心課程">
                                <option value="A1">董事的法律義務與責任</option>
                                <option value="A2">董事會的架構與運作</option>
                                <option value="A3">提升董事會績效</option>
                                <option value="A4">財務、會計</option>
                                <option value="A5">永續發展</option>
                            </optgroup>
                            <optgroup label="專業課程">
                                <option value="B1">董事會成員和管理團隊之間的關係與合作</option>
                                <option value="B2">董事與股東會事務</option>
                                <option value="B3">公司所屬產業之業務、商務</option>
                                <option value="B4">風險管理、內部控制、數位治理</option>
                                <option value="B5">其他</option>
                            </optgroup>
                        </select>
                    </label>
                    <div class="tpma-th-menu-actions">
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="category-asc">類別↑</button>
                        <button type="button" class="tpma-btn tpma-btn-secondary" data-sort="category-desc">類別↓</button>
                        <button type="button" class="tpma-btn tpma-btn-danger" data-clear="category">清除</button>
                    </div>
                </div>
            </th>

            <th>操作</th>
        </tr>
        </thead>
        <tbody id="tpma-course-tbody">
        <tr><td colspan="5">載入中...</td></tr>
        </tbody>
    </table>
</div>

<!-- 新增講師 Modal -->
<div id="tpma-lecturer-backdrop" class="tpma-modal-backdrop">
    <div id="tpma-lecturer-modal" class="tpma-modal">
        <div class="tpma-modal-header">
            <h3>新增講師</h3>
            <button type="button" class="tpma-modal-close-btn" id="tpma-lect-cancel-btn">×</button>
        </div>
        <div class="tpma-modal-content">
            <label>講師代碼<span class="tpma-required-label">必填</span></label>
            <input type="text" id="tpma-lect-code" placeholder="例如 HSSA">
            <label>講師姓名<span class="tpma-required-label">必填</span></label>
            <input type="text" id="tpma-lect-name" placeholder="講師姓名">
            <label>講師頭銜</label>
            <input type="text" id="tpma-lect-title" placeholder="例如 講師 / 顧問">
            <label>講師排序（數字越小越前面）</label>
            <input type="number" id="tpma-lect-sort" placeholder="例如 10">

            <div class="tpma-error" id="tpma-lect-error" style="display:none;"></div>
        </div>
        <div class="tpma-modal-footer">
            <button type="button" class="tpma-btn secondary" id="tpma-lect-cancel-btn">取消</button>
            <button type="button" class="tpma-btn" id="tpma-lect-save-btn">儲存講師</button>
        </div>
    </div>
</div>

<script>
window.TPMACourseAdminConfig = <?php echo wp_json_encode(array(
    'apiBase' => $apiBase,
    'nonce'   => $restNonce,
)); ?>;
</script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/00.tpma-datetime.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/01.tpma-public.utils.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/public/02.tpma-public.api.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/01.course-admin.utils.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/02.course-admin.core.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/03.course-admin.api.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/04.course-admin.filters.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/05.course-admin.render.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/06.course-admin.modal.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/07.course-admin.course-save.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/08.course-admin.ui-events.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/09.course-admin.init.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
