# Google Meet 共用授權診斷面板

## 目的

讓網站管理員能在「設定 → TPMA Course Registration IDs」立即確認 Google Meet 共用授權是否可用，並查看最近 30 次診斷與 Meet 同步失敗紀錄，無須從前台儲存課程時才發現問題。

## 使用介面

設定頁的「Google Meet 共用授權」列新增：

- 目前狀態：有效（綠色）或失效（紅色）。
- 狀態原因：未授權、授權帳號無效、Tutor Pro 模組不可用、credential/token 檔案缺失，或 Google token 無法刷新。
- 最近檢查時間與共用授權的 WordPress 帳號顯示名稱。
- 「查看診斷紀錄」按鈕，展開最近 30 筆記錄；「授權／更新共用 Meet」仍保留。

此區塊僅對具 `manage_options` 的使用者顯示。頁面不顯示 token、OAuth code、Google email、完整檔案路徑或原始 Google 回應。

## 資料與流程

- 以一個非自動載入的 WordPress option 保存最多 30 筆診斷記錄。
- 每筆記錄包含：時間、結果（有效／失效）、來源操作（設定頁檢查、建立、連結、更新、刪除 Meet）、安全化原因。
- 設定頁載入時，使用目前的共用帳號建立 Tutor Google Meet client，取得即時狀態，並加入一筆「設定頁檢查」紀錄。
- Bridge 的 Google Meet client 建立流程，無論成功或失敗都寫入記錄。既有的 token 刷新後重建 client 邏輯保留。
- 只在結果或原因改變時記錄設定頁的即時檢查，避免每次開頁都擠掉較有價值的操作失敗紀錄；Meet 操作則每次失敗都記錄。

## 錯誤處理

失效時維持既有的可行動錯誤訊息，並在設定頁提供重新授權按鈕。診斷面板僅增加可追查性，不會自動替換授權帳號、移除 token，或修改既有 Google Calendar 活動。

## 驗證

- PHP syntax check：設定類別與 Tutor bridge。
- 靜態檢查：診斷 option 為非自動載入、最多 30 筆、設定頁狀態區塊及展開控制存在。
- WordPress 手動驗收：重新授權後顯示有效；暫時使 token 不可用時顯示失效與安全化原因；建立或修改 Meet 失敗後紀錄新增。
