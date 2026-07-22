# Google Meet 授權檔自動復原 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 將 Tutor Google Meet 的 OAuth credential 與 token 建立站外受保護備份，並在 `uploads/tutor-json` 被清除時自動還原。

**Architecture:** `TPMA_Tutor_Bridge` 是唯一管理授權檔路徑、備份、還原與安全診斷的元件。授權 callback 在 Tutor 寫入新 token 後要求 Bridge 同步兩個檔案；每次建立可用 client 後再同步一次，使 token 更新不會讓還原檔過期。

**Tech Stack:** WordPress filesystem helpers、WordPress options diagnostics、Tutor Pro `GoogleEvent`。

---

### Task 1: 建立失敗測試

**Files:**
- Create then remove: `C:/WEB/.tests/tpma-meet-auth-recovery.php`

- [ ] 以真實暫存資料夾模擬 `uploads/tutor-json` 與受保護備份資料夾；先寫入備份檔、移除原檔，斷言 `restore_google_meet_auth_files()` 尚不存在而失敗。
- [ ] 執行 `php C:/WEB/.tests/tpma-meet-auth-recovery.php`，確認失敗原因為缺少還原方法。

### Task 2: 實作受保護備份與自動還原

**Files:**
- Modify: `includes/class-tpma-tutor-bridge.php`
- Test: `C:/WEB/.tests/tpma-meet-auth-recovery.php`

- [ ] 新增集中式路徑解析、原子複製、備份與還原 helper；預設備份在 `ABSPATH` 上層的 `.tpma-meet-auth`，可由 `TPMA_MEET_AUTH_BACKUP_DIR` 常數覆寫。
- [ ] 在建立 Tutor `GoogleEvent` 前，僅還原缺失的 credential 或 token；不得覆寫仍存在的檔案。
- [ ] 於 client 有效後同步兩檔至備份，並寫入不含路徑或 token 的安全診斷。
- [ ] 執行暫存測試，確認還原後兩檔內容相同且原檔存在時不被覆寫。

### Task 3: 在更新共用授權時強制同步

**Files:**
- Modify: `includes/class-tpma-cr-settings.php`
- Test: `C:/WEB/.tests/tpma-meet-auth-recovery.php`

- [ ] `handle_meet_settings_callback()` 驗證新 token 成功後呼叫 Bridge 的公開同步方法。
- [ ] 若 Google 授權成功但備份寫入失敗，保留共用授權可用狀態並顯示需要處理備份的明確管理員提示。
- [ ] 執行 PHP syntax、靜態搜尋與 `git diff --check`。

### Task 4: 清理與手動驗收

**Files:**
- Delete: `C:/WEB/.tests/tpma-meet-auth-recovery.php`

- [ ] 刪除暫存測試檔。
- [ ] 測試站重新授權一次，確認 `.tpma-meet-auth` 產生兩檔；清除原 `uploads/tutor-json` 的測試副本後儲存場次，確認紀錄顯示自動復原並可建立 Meet。

No Git commit, push, or PR is part of this plan.
