<?php
/** @var string $hook_suffix */
defined('ABSPATH') || exit;
?>
<div class="wrap tpma-receipt-admin" id="tpma-receipt-admin">
    <h1>TPMA 收據管理</h1>

    <div class="tpma-receipt-filters" role="search">
        <label class="screen-reader-text" for="tpma-receipt-search">搜尋收據、訂單、課程或公司</label>
        <input id="tpma-receipt-search" type="search" placeholder="搜尋收據號、Woo 訂單號、課程或公司名稱">
        <select id="tpma-receipt-type-filter" aria-label="收據方式">
            <option value="">全部方式</option>
            <option value="electronic">電子</option>
            <option value="paper">紙本</option>
        </select>
        <select id="tpma-receipt-status-filter" aria-label="收據狀態">
            <option value="">全部狀態</option>
            <option value="pending">待開</option>
            <option value="generated">待寄</option>
            <option value="awaiting_scan">待掃描</option>
            <option value="scanned">待寄</option>
            <option value="sent">已寄</option>
            <option value="void">作廢</option>
        </select>
        <button type="button" class="button" id="tpma-receipt-reset">清除篩選</button>
    </div>

    <details class="tpma-receipt-mobile-filters" id="tpma-receipt-mobile-filters">
        <summary>進階欄位篩選</summary>
        <div class="tpma-receipt-mobile-filters-content">
            <label for="tpma-receipt-mobile-filter-number">收據號或 Woo 訂單號</label><input id="tpma-receipt-mobile-filter-number" type="search">
            <label for="tpma-receipt-mobile-filter-course">課程名稱</label><input id="tpma-receipt-mobile-filter-course" type="search">
            <label for="tpma-receipt-mobile-filter-course-date-from">授課日期起</label><input id="tpma-receipt-mobile-filter-course-date-from" type="date">
            <label for="tpma-receipt-mobile-filter-course-date-to">授課日期訖</label><input id="tpma-receipt-mobile-filter-course-date-to" type="date">
            <label for="tpma-receipt-mobile-filter-heading">公司、承辦人或統編</label><input id="tpma-receipt-mobile-filter-heading" type="search">
            <label for="tpma-receipt-mobile-filter-amount-min">最低金額</label><input id="tpma-receipt-mobile-filter-amount-min" type="number" min="0" step="1">
            <label for="tpma-receipt-mobile-filter-amount-max">最高金額</label><input id="tpma-receipt-mobile-filter-amount-max" type="number" min="0" step="1">
            <label for="tpma-receipt-mobile-filter-type">收據方式</label><select id="tpma-receipt-mobile-filter-type"><option value="">全部方式</option><option value="electronic">電子</option><option value="paper">紙本</option></select>
            <label for="tpma-receipt-mobile-filter-status">收據狀態</label><select id="tpma-receipt-mobile-filter-status"><option value="">全部狀態</option><option value="pending">待開</option><option value="generated">待寄</option><option value="awaiting_scan">待掃描</option><option value="scanned">待寄</option><option value="sent">已寄</option><option value="void">作廢</option></select>
            <div class="tpma-receipt-mobile-filter-actions"><button type="button" class="button button-primary" id="tpma-receipt-mobile-filter-apply">套用篩選</button><button type="button" class="button" id="tpma-receipt-mobile-filter-clear">清除欄位篩選</button></div>
        </div>
    </details>

    <div class="tpma-receipt-bulk">
        <label for="tpma-receipt-bulk-action">批次操作</label>
        <select id="tpma-receipt-bulk-action">
            <option value="">選擇操作</option>
            <option value="generate">批次生成</option>
            <option value="regenerate">重新生成</option>
            <option value="print">列印</option>
            <option value="download">下載</option>
            <option value="send">寄發</option>
            <option value="change_type_electronic">改為電子</option>
            <option value="change_type_paper">改為紙本</option>
            <option value="void">作廢</option>
            <option value="merge">合併開立</option>
        </select>
        <button type="button" class="button button-primary" id="tpma-receipt-bulk-run">套用</button>
        <span id="tpma-receipt-selection-count" aria-live="polite">尚未選取</span>
    </div>

    <div class="tpma-receipt-mobile-sort" aria-label="收據排序">
        <label for="tpma-receipt-mobile-sort-by">排序方式</label>
        <select id="tpma-receipt-mobile-sort-by">
            <option value="receipt_number">收據號／關聯訂單</option>
            <option value="course_date">課程／授課日期</option>
            <option value="heading">收據抬頭／統編</option>
            <option value="amount">金額</option>
            <option value="type_status">方式／狀態</option>
        </select>
        <button type="button" class="button" id="tpma-receipt-mobile-sort-order">升冪</button>
    </div>

    <div class="notice inline" id="tpma-receipt-message" hidden><p></p></div>

    <div class="tpma-receipt-table-wrap">
        <table class="widefat fixed striped tpma-receipt-table">
            <thead>
                <tr>
                    <td class="check-column"><input type="checkbox" id="tpma-receipt-select-all" aria-label="選取本頁全部資料"></td>
                    <th scope="col" class="tpma-receipt-sortable tpma-receipt-filterable" aria-sort="ascending">
                        <div class="tpma-receipt-header-controls">
                            <button type="button" class="tpma-receipt-sort-button" data-sort-by="receipt_number">
                                收據號／關聯訂單 <span class="tpma-receipt-sort-indicator" aria-hidden="true">升冪</span>
                            </button>
                            <button type="button" class="tpma-receipt-filter-toggle" data-filter-menu="tpma-receipt-filter-number" aria-expanded="false" aria-controls="tpma-receipt-filter-number" aria-label="篩選收據號或關聯訂單">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M7 12h10m-7 6h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <div class="tpma-receipt-filter-menu" id="tpma-receipt-filter-number" hidden>
                                <label for="tpma-receipt-filter-number-value">收據號或 Woo 訂單號</label>
                                <input id="tpma-receipt-filter-number-value" type="search" autocomplete="off">
                                <div class="tpma-receipt-filter-menu-actions"><button type="button" class="button button-primary" data-filter-apply>套用</button><button type="button" class="button-link" data-filter-clear="number">清除</button></div>
                            </div>
                        </div>
                    </th>
                    <th scope="col" class="tpma-receipt-sortable tpma-receipt-filterable" aria-sort="none">
                        <div class="tpma-receipt-header-controls">
                            <button type="button" class="tpma-receipt-sort-button" data-sort-by="course_date">
                                課程／授課日期 <span class="tpma-receipt-sort-indicator" aria-hidden="true">未排序</span>
                            </button>
                            <button type="button" class="tpma-receipt-filter-toggle" data-filter-menu="tpma-receipt-filter-course" aria-expanded="false" aria-controls="tpma-receipt-filter-course" aria-label="篩選課程或授課日期">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M7 12h10m-7 6h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <div class="tpma-receipt-filter-menu" id="tpma-receipt-filter-course" hidden>
                                <label for="tpma-receipt-filter-course-value">課程名稱</label>
                                <input id="tpma-receipt-filter-course-value" type="search" autocomplete="off">
                                <div class="tpma-receipt-filter-date-range"><label for="tpma-receipt-filter-course-date-from">授課日期起</label><input id="tpma-receipt-filter-course-date-from" type="date"><label for="tpma-receipt-filter-course-date-to">授課日期訖</label><input id="tpma-receipt-filter-course-date-to" type="date"></div>
                                <div class="tpma-receipt-filter-menu-actions"><button type="button" class="button button-primary" data-filter-apply>套用</button><button type="button" class="button-link" data-filter-clear="course">清除</button></div>
                            </div>
                        </div>
                    </th>
                    <th scope="col" class="tpma-receipt-sortable tpma-receipt-filterable" aria-sort="none">
                        <div class="tpma-receipt-header-controls">
                            <button type="button" class="tpma-receipt-sort-button" data-sort-by="heading">
                                收據抬頭／統編 <span class="tpma-receipt-sort-indicator" aria-hidden="true">未排序</span>
                            </button>
                            <button type="button" class="tpma-receipt-filter-toggle" data-filter-menu="tpma-receipt-filter-heading" aria-expanded="false" aria-controls="tpma-receipt-filter-heading" aria-label="篩選收據抬頭或統編">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M7 12h10m-7 6h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <div class="tpma-receipt-filter-menu" id="tpma-receipt-filter-heading" hidden>
                                <label for="tpma-receipt-filter-heading-value">公司、承辦人或統編</label>
                                <input id="tpma-receipt-filter-heading-value" type="search" autocomplete="off">
                                <div class="tpma-receipt-filter-menu-actions"><button type="button" class="button button-primary" data-filter-apply>套用</button><button type="button" class="button-link" data-filter-clear="heading">清除</button></div>
                            </div>
                        </div>
                    </th>
                    <th scope="col" class="column-amount tpma-receipt-sortable tpma-receipt-filterable" aria-sort="none">
                        <div class="tpma-receipt-header-controls">
                            <button type="button" class="tpma-receipt-sort-button" data-sort-by="amount">
                                金額 <span class="tpma-receipt-sort-indicator" aria-hidden="true">未排序</span>
                            </button>
                            <button type="button" class="tpma-receipt-filter-toggle" data-filter-menu="tpma-receipt-filter-amount" aria-expanded="false" aria-controls="tpma-receipt-filter-amount" aria-label="篩選金額">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M7 12h10m-7 6h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <div class="tpma-receipt-filter-menu tpma-receipt-filter-menu-end" id="tpma-receipt-filter-amount" hidden>
                                <label for="tpma-receipt-filter-amount-min">最低金額</label><input id="tpma-receipt-filter-amount-min" type="number" min="0" step="1">
                                <label for="tpma-receipt-filter-amount-max">最高金額</label><input id="tpma-receipt-filter-amount-max" type="number" min="0" step="1">
                                <div class="tpma-receipt-filter-menu-actions"><button type="button" class="button button-primary" data-filter-apply>套用</button><button type="button" class="button-link" data-filter-clear="amount">清除</button></div>
                            </div>
                        </div>
                    </th>
                    <th scope="col" class="tpma-receipt-sortable tpma-receipt-filterable" aria-sort="none">
                        <div class="tpma-receipt-header-controls">
                            <button type="button" class="tpma-receipt-sort-button" data-sort-by="type_status">
                                方式／狀態 <span class="tpma-receipt-sort-indicator" aria-hidden="true">未排序</span>
                            </button>
                            <button type="button" class="tpma-receipt-filter-toggle" data-filter-menu="tpma-receipt-filter-status" aria-expanded="false" aria-controls="tpma-receipt-filter-status" aria-label="篩選收據方式或狀態">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M7 12h10m-7 6h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <div class="tpma-receipt-filter-menu tpma-receipt-filter-menu-end" id="tpma-receipt-filter-status" hidden>
                                <label for="tpma-receipt-header-type-filter">收據方式</label><select id="tpma-receipt-header-type-filter"><option value="">全部方式</option><option value="electronic">電子</option><option value="paper">紙本</option></select>
                                <label for="tpma-receipt-header-status-filter">收據狀態</label><select id="tpma-receipt-header-status-filter"><option value="">全部狀態</option><option value="pending">待開</option><option value="generated">待寄</option><option value="awaiting_scan">待掃描</option><option value="scanned">待寄</option><option value="sent">已寄</option><option value="void">作廢</option></select>
                                <div class="tpma-receipt-filter-menu-actions"><button type="button" class="button button-primary" data-filter-apply>套用</button><button type="button" class="button-link" data-filter-clear="status">清除</button></div>
                            </div>
                        </div>
                    </th>
                </tr>
            </thead>
            <tbody id="tpma-receipt-list">
                <tr><td colspan="6">載入中…</td></tr>
            </tbody>
        </table>
    </div>

    <div class="tablenav bottom tpma-receipt-pagination">
        <div class="tablenav-pages" id="tpma-receipt-pagination"></div>
    </div>

    <dialog class="tpma-receipt-scan-dialog" id="tpma-receipt-scan-dialog" aria-labelledby="tpma-receipt-scan-title" aria-describedby="tpma-receipt-scan-description">
        <form id="tpma-receipt-scan-form" novalidate>
            <h2 id="tpma-receipt-scan-title">上傳紙本掃描檔</h2>
            <p id="tpma-receipt-scan-description">請選擇已簽章的收據掃描檔。僅接受 PDF、JPG 或 PNG。</p>
            <label for="tpma-receipt-scan-file">掃描檔</label>
            <input id="tpma-receipt-scan-file" name="scan" type="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" aria-describedby="tpma-receipt-scan-description tpma-receipt-scan-error">
            <p class="tpma-receipt-dialog-error" id="tpma-receipt-scan-error" role="alert" hidden></p>
            <div class="tpma-receipt-dialog-actions">
                <button type="button" class="button" id="tpma-receipt-scan-cancel">取消</button>
                <button type="submit" class="button button-primary" id="tpma-receipt-scan-submit">上傳掃描檔</button>
            </div>
        </form>
    </dialog>
</div>
