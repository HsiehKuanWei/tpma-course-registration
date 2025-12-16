<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>
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

    <table class="tpma-course-table" id="tpma-course-table">
        <thead>
        <tr>
            <th>
                <div class="tpma-th-inner">
                    <span>課程代碼</span>
                    <button class="tpma-th-menu-btn" type="button" data-menu-toggle="course_code">⋯</button>
                    <div class="tpma-th-menu" data-menu-col="course_code">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="course_code" data-sort-dir="asc">升冪排序</label>
                            <label class="tpma-menu-options" data-sort-field="course_code" data-sort-dir="desc">降冪排序</label>
                            <label class="tpma-menu-options" data-sort-field="" data-sort-dir="">清除排序</label>
                        </div>
                    </div>
                </div>
            </th>

            <th>
                <div class="tpma-th-inner">
                    <span>課程類別</span>
                    <button class="tpma-th-menu-btn" type="button" data-menu-toggle="category">⋯</button>
                    <div class="tpma-th-menu" data-menu-col="category">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="category_code" data-sort-dir="asc">升冪排序</label>
                            <label class="tpma-menu-options" data-sort-field="category_code" data-sort-dir="desc">降冪排序</label>
                        </div>
                        <div class="tpma-menu-section">
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
                            <label class="tpma-menu-options" id="tpma-btn-clear-category">清除篩選</label>
                        </div>
                    </div>
                </div>
            </th>

            <th>
                <div class="tpma-th-inner">
                    <span>課程名稱</span>
                    <button class="tpma-th-menu-btn" type="button" data-menu-toggle="course_name">⋯</button>
                    <div class="tpma-th-menu" data-menu-col="course_name">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="course_name" data-sort-dir="asc">升冪排序</label>
                            <label class="tpma-menu-options" data-sort-field="course_name" data-sort-dir="desc">降冪排序</label>
                        </div>
                        <div class="tpma-menu-section">
                            <select id="tpma-filter-course">
                                <option value="">全部課程名稱</option>
                            </select>
                            <label class="tpma-menu-options" id="tpma-btn-clear-course">清除篩選</label>
                        </div>
                    </div>
                </div>
            </th>

            <th>
                <div class="tpma-th-inner">
                    <span>講師</span>
                    <button class="tpma-th-menu-btn" type="button" data-menu-toggle="lecturer">⋯</button>
                    <div class="tpma-th-menu" data-menu-col="lecturer">
                        <div class="tpma-menu-section">
                            <label class="tpma-menu-options" data-sort-field="lecturer_code" data-sort-dir="asc">升冪排序</label>
                            <label class="tpma-menu-options" data-sort-field="lecturer_code" data-sort-dir="desc">降冪排序</label>
                        </div>
                        <div class="tpma-menu-section">
                            <select id="tpma-filter-lecturer">
                                <option value="">全部講師</option>
                            </select>
                            <label class="tpma-menu-options" id="tpma-btn-clear-lecturer">清除篩選</label>
                        </div>
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
<div id="tpma-lecturer-backdrop" class="tpma-modal-backdrop"></div>
<div id="tpma-lecturer-modal" class="tpma-modal">
    <h3>新增講師</h3>
    <label>講師代碼<span class="tpma-required-label">必填</span></label>
    <input type="text" id="tpma-lect-code" placeholder="例如 HSSA">
    <label>講師姓名<span class="tpma-required-label">必填</span></label>
    <input type="text" id="tpma-lect-name" placeholder="講師姓名">
    <label>講師頭銜</label>
    <input type="text" id="tpma-lect-title" placeholder="例如 講師 / 顧問">
    <label>講師排序（數字越小越前面）</label>
    <input type="number" id="tpma-lect-sort" placeholder="例如 10">

    <div class="tpma-error" id="tpma-lect-error" style="display:none;"></div>

    <div class="tpma-modal-actions">
        <button type="button" class="tpma-btn" id="tpma-lect-cancel-btn">取消</button>
        <button type="button" class="tpma-btn" id="tpma-lect-save-btn">儲存講師</button>
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
