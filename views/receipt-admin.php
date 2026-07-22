<?php
/** @var string $hook_suffix */
defined('ABSPATH') || exit;
?>
<div class="wrap tpma-receipt-admin" id="tpma-receipt-admin">
    <h1>TPMA 收據管理</h1>

    <div class="tpma-receipt-filters" role="search">
        <label class="screen-reader-text" for="tpma-receipt-search">搜尋收據、訂單或課程</label>
        <input id="tpma-receipt-search" type="search" placeholder="搜尋收據號、Woo 訂單號或課程名稱">
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

    <div class="tpma-receipt-bulk">
        <label for="tpma-receipt-bulk-action">批次操作</label>
        <select id="tpma-receipt-bulk-action">
            <option value="">選擇操作</option>
            <option value="generate">批次生成</option>
            <option value="regenerate">重新生成</option>
            <option value="print">列印</option>
            <option value="send">寄發</option>
            <option value="merge">合併開立</option>
        </select>
        <button type="button" class="button button-primary" id="tpma-receipt-bulk-run">套用</button>
        <span id="tpma-receipt-selection-count" aria-live="polite">尚未選取</span>
    </div>

    <div class="notice inline" id="tpma-receipt-message" hidden><p></p></div>

    <div class="tpma-receipt-table-wrap">
        <table class="widefat fixed striped tpma-receipt-table">
            <thead>
                <tr>
                    <td class="check-column"><input type="checkbox" id="tpma-receipt-select-all" aria-label="選取本頁全部資料"></td>
                    <th scope="col">收據號／關聯訂單</th>
                    <th scope="col">課程／授課日期</th>
                    <th scope="col">收據抬頭／統編</th>
                    <th scope="col" class="column-amount">金額</th>
                    <th scope="col">方式／狀態</th>
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
</div>
