# 收據以 reg-admin 為唯一資料來源

## 目標

修正 TPMA 課程收據在重製後仍採用 WooCommerce 舊抬頭／統編的問題，並恢復 reg-admin 的全部收據批次操作。`tpma_cr_regs`（reg-admin）是收據資料的唯一業務來源：未寄出的收據重製後必須反映該表目前資料。

## 資料規則

- 收據建立、重製、變更收據類型與合併資格比對，都由 `tpma_cr_regs` 取得付款人識別資料，不以 Woo billing 欄位或 order meta 作為收據內容來源。
- 單一訂單若有多筆報名資料，收據使用既有的第一筆有效報名列作為付款人／抬頭資料來源；資料不一致時採用明確驗證錯誤，不靜默挑選不一致的值。
- 課程名稱繼續由報名與課程資料表取得；訂單總額仍由 WooCommerce 訂單取得。
- 收據快照是開立當時的內容。未寄收據可重新生成，並建立修訂歷程；已寄收據不可直接改寫，必須先作廢再新開。
- reg-admin 修改付款人、公司抬頭、統編、收據方式或其他已映射的訂單欄位時，必須由既有 Woo 同步服務回寫同一筆 Woo 訂單。同步方向只允許 `tpma_cr_regs` → Woo；Woo 的舊值不可回寫或覆蓋 reg-admin。

## 批次操作

- reg-admin 的「收據」主操作必須正確取得 `tpma-bulk-receipt-action` 的選擇值。
- 批次生成、重生、列印、下載、作廢與合併開立會送往既有收據 REST 端點；不改變既有權限、nonce、付款資格或合併限制。

## 驗證

- 靜態檢查確認收據付款人快照不再呼叫 Woo billing／tax meta 作為來源。
- 靜態檢查確認 reg-admin 修改付款人／抬頭／統編時，會以更新後的報名列同步 Woo 訂單，且不會由 Woo 反向覆寫報名列。
- 靜態檢查確認收據批次工具列含有正確的 receipt target mapping，並可落到既有 REST 呼叫分支。
- 執行變更 PHP 檔案的語法檢查與 diff review。
- WP 手動驗收：修改未寄收據對應 reg-admin 的抬頭／統編後重製，管理頁與 PDF 均一致；已寄收據仍拒絕直接重製內容；六種批次操作皆可送出且顯示結果。

## 2026-10-15 靜態驗證紀錄

- `class-tpma-rest-admin.php` 已將承辦人與統編存入 `tpma_cr_regs`；同一 Woo 訂單的報名列會同步該三個付款人欄位，再從更新後的報名列單向同步 Woo。
- `class-tpma-admin-woo-service.php` 不再於 reg-admin 列表載入時以 Woo 覆寫承辦人、公司抬頭或統編。
- `class-tpma-receipt-service.php` 的建立、重製與合併付款人識別均改用 `tpma_cr_regs`；已寄收據鎖定規則未變。
- `class-tpma-rest-admin.php`、`class-tpma-admin-woo-service.php` 與 `class-tpma-receipt-service.php` 的 PHP 語法檢查通過。
- 仍須在部署後完成本規格「驗證」段落所列的四項 WordPress 瀏覽器驗收。
