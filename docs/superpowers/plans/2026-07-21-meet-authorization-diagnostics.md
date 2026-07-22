# Google Meet 共用授權診斷面板 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在 TPMA 設定頁顯示 Google Meet 共用授權的即時有效狀態，並安全保存最近 30 筆診斷紀錄。

**Architecture:** `TPMA_CR_Settings` 擁有非自動載入的目前狀態與診斷 option、保存上限與設定頁呈現。`TPMA_Tutor_Bridge` 擁有對 Tutor Pro `GoogleEvent` 的實際檢查，並在 client 建立成功或失敗時把安全化的結果交給設定類別保存；Calendar 操作發生例外時也寫入相同紀錄。

**Tech Stack:** WordPress options API、PHP 7+、Tutor Pro Google Meet `GoogleEvent`、WordPress Settings API。

---

## File structure

- Modify: `includes/class-tpma-cr-settings.php` — 診斷 option、30 筆保存限制、狀態／紀錄讀取方法與設定頁表格。
- Modify: `includes/class-tpma-tutor-bridge.php` — client 建立結果的安全化診斷、公開即時狀態查詢，以及建立／連結／刪除／更新 Meet 的操作來源。
- Create then remove: `C:/WEB/.tests/tpma-meet-diagnostics.php` — 最小 PHP stub 測試，驗證 30 筆上限與 token 刷新後重建 client；不得留下於最終變更。

### Task 1: 先建立失敗測試

**Files:**
- Create: `C:/WEB/.tests/tpma-meet-diagnostics.php`
- Modify: none

- [ ] **Step 1: 建立 WordPress option stub 與 31 筆寫入的失敗測試**

```php
for ($i = 1; $i <= 31; $i++) {
    TPMA_CR_Settings::record_tutor_meet_diagnostic('建立 Meet', false, 'meet_unauthorized', 'Google token 無法使用');
}
$records = TPMA_CR_Settings::get_tutor_meet_diagnostics();
assert(count($records) === 30);
assert($records[0]['operation'] === '建立 Meet');
assert($records[0]['code'] === 'meet_unauthorized');
```

- [ ] **Step 2: 執行測試並確認因方法尚不存在而失敗**

Run: `& 'C:\xampp\php\php.exe' 'C:\WEB\.tests\tpma-meet-diagnostics.php'`

Expected: non-zero exit，指出 `record_tutor_meet_diagnostic` 未定義。

### Task 2: 實作診斷資料模型

**Files:**
- Modify: `includes/class-tpma-cr-settings.php:419-447`
- Test: `C:/WEB/.tests/tpma-meet-diagnostics.php`

- [ ] **Step 1: 新增 non-autoload option 常數與公開存取方法**

```php
const OPTION_TUTOR_MEET_DIAGNOSTICS = 'tpma_cr_tutor_meet_diagnostics';
const OPTION_TUTOR_MEET_STATUS      = 'tpma_cr_tutor_meet_status';
private const TUTOR_MEET_DIAGNOSTIC_LIMIT = 30;

public static function get_tutor_meet_diagnostics(): array {
    $records = get_option(self::OPTION_TUTOR_MEET_DIAGNOSTICS, array());
    return is_array($records) ? array_slice($records, 0, self::TUTOR_MEET_DIAGNOSTIC_LIMIT) : array();
}

public static function get_tutor_meet_status(): array {
    $status = get_option(self::OPTION_TUTOR_MEET_STATUS, array());
    return is_array($status) ? $status : array();
}
```

- [ ] **Step 2: 實作安全化紀錄寫入與設定頁檢查去重**

```php
public static function record_tutor_meet_diagnostic(string $operation, bool $valid, string $code, string $reason): void {
    $entry = array(
        'checked_at' => current_time('mysql'),
        'operation'  => sanitize_text_field($operation),
        'valid'      => $valid ? 1 : 0,
        'code'       => sanitize_key($code),
        'reason'     => sanitize_text_field($reason),
    );
    update_option(self::OPTION_TUTOR_MEET_STATUS, $entry, false);
    $records = self::get_tutor_meet_diagnostics();
    $latest = $records[0] ?? array();
    if ($operation === '設定頁檢查' && (int) ($latest['valid'] ?? -1) === $entry['valid'] && ($latest['code'] ?? '') === $entry['code']) {
        return;
    }
    array_unshift($records, $entry);
    update_option(self::OPTION_TUTOR_MEET_DIAGNOSTICS, array_slice($records, 0, self::TUTOR_MEET_DIAGNOSTIC_LIMIT), false);
}
```

Only store the safe code and reason supplied by the Bridge. Never store token content, OAuth values, Google API response bodies, complete filesystem paths, or Google account email.

- [ ] **Step 3: 重跑測試，確認保留 30 筆且最新結果在第一筆**

Run: `& 'C:\xampp\php\php.exe' 'C:\WEB\.tests\tpma-meet-diagnostics.php'`

Expected: exit 0 with `PASS: diagnostics retain the newest 30 records.`

### Task 3: 將 Bridge client 結果寫入診斷資料

**Files:**
- Modify: `includes/class-tpma-tutor-bridge.php:151-188,807,837,1148,1279`
- Test: `C:/WEB/.tests/tpma-meet-diagnostics.php`

- [ ] **Step 1: 建立單一安全化紀錄 helper 與公開狀態檢查**

```php
private static function record_google_meet_diagnostic(string $operation, bool $valid, string $code, string $reason): void {
    if (class_exists('TPMA_CR_Settings')) {
        TPMA_CR_Settings::record_tutor_meet_diagnostic($operation, $valid, $code, $reason);
    }
}

public static function get_google_meet_authorization_status(): array {
    $client = self::create_google_meet_client(0, '設定頁檢查');
    if (is_wp_error($client)) {
        return array('valid' => false, 'code' => $client->get_error_code(), 'reason' => $client->get_error_message());
    }
    return array('valid' => true, 'code' => 'authorized', 'reason' => 'Google Meet 共用授權可使用。');
}
```

- [ ] **Step 2: 擴充 client factory 的操作來源並在每個結果分支紀錄**

Change the signature to `create_google_meet_client(int $meet_post_id = 0, string $operation = 'Google Meet 操作')`. Before every `WP_Error` return, call `record_google_meet_diagnostic($operation, false, $code, $safe_reason)`. After the final validity test succeeds, call `record_google_meet_diagnostic($operation, true, 'authorized', 'Google Meet 共用授權可使用。')`.

Use only these safe reasons:

```php
'Tutor Pro Google Meet 模組未啟用'
'尚未完成 Google Meet 共用授權'
'共用授權帳號不存在或已失去管理員權限'
'無法建立 Tutor Google Meet 授權用戶端'
'Google token 無法使用或無法刷新'
```

- [ ] **Step 3: 標示所有 client 使用處的來源操作**

```php
self::create_google_meet_client($meet_post_id, '連結既有 Meet');
self::create_google_meet_client(0, '建立 Meet');
self::create_google_meet_client($meet_id, '刪除 Google Calendar 活動');
self::create_google_meet_client($meet_id, '更新 Meet 時間');
```

- [ ] **Step 4: 在 Calendar API 例外處新增安全化失敗紀錄**

Before returning the existing `WP_Error` in create/link/delete/update exception handlers, call:

```php
self::record_google_meet_diagnostic('建立 Meet', false, 'calendar_create_failed', 'Google Calendar 無法建立 Meet 活動');
```

Use equivalent fixed codes and reasons for `連結既有 Meet`、`刪除 Google Calendar 活動` and `更新 Meet 時間`. Do not save `$e->getMessage()`.

- [ ] **Step 5: 執行 client refresh retry 測試與語法檢查**

Run: `& 'C:\xampp\php\php.exe' 'C:\WEB\.tests\tpma-meet-diagnostics.php'; & 'C:\xampp\php\php.exe' -l 'C:\WEB\tpma-course-registration\includes\class-tpma-tutor-bridge.php'`

Expected: exit 0; test verifies the first failed Tutor refresh rebuilds the client once and records a final authorized status.

### Task 4: 呈現設定頁狀態與可展開紀錄

**Files:**
- Modify: `includes/class-tpma-cr-settings.php:509-516`
- Test: `C:/WEB/.tests/tpma-meet-diagnostics.php`

- [ ] **Step 1: 取得即時狀態與共用帳號顯示名稱**

```php
$meet_status = class_exists('TPMA_Tutor_Bridge')
    ? TPMA_Tutor_Bridge::get_google_meet_authorization_status()
    : array('valid' => false, 'code' => 'bridge_unavailable', 'reason' => 'Tutor 整合未啟用');
$shared_user = get_user_by('id', self::get_tutor_meet_shared_user());
$diagnostics = self::get_tutor_meet_diagnostics();
```

- [ ] **Step 2: 加入有效／失效狀態、最近檢查時間與授權帳號**

```php
$status_color = !empty($meet_status['valid']) ? '#0a7a2f' : '#b32d2e';
$status_text = !empty($meet_status['valid']) ? '目前有效' : '目前失效';
echo '<p style="margin:0 0 8px;color:' . esc_attr($status_color) . ';font-weight:600;">' . esc_html($status_text) . '</p>';
echo '<p class="description">' . esc_html((string) $meet_status['reason']) . '</p>';
```

Show the WordPress display name only when a shared user exists. Show `尚未設定` otherwise. Use `get_tutor_meet_status()['checked_at']` for the latest check time.

- [ ] **Step 3: 加入原生 `<details>` 紀錄面板與安全跳脫欄位**

```php
echo '<details style="margin-top:12px;"><summary>查看診斷紀錄（最近 ' . esc_html((string) count($diagnostics)) . ' 筆）</summary>';
echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>時間</th><th>操作</th><th>結果</th><th>原因</th></tr></thead><tbody>';
foreach ($diagnostics as $record) {
    echo '<tr><td>' . esc_html((string) ($record['checked_at'] ?? '')) . '</td><td>' . esc_html((string) ($record['operation'] ?? '')) . '</td><td>' . esc_html(!empty($record['valid']) ? '有效' : '失效') . '</td><td>' . esc_html((string) ($record['reason'] ?? '')) . '</td></tr>';
}
echo '</tbody></table></details>';
```

Retain the existing authorization button unchanged. An empty record list must render `尚無診斷紀錄` rather than an empty table.

- [ ] **Step 4: 執行 syntax、靜態檢查與 diff 檢查**

Run: `& 'C:\xampp\php\php.exe' -l 'C:\WEB\tpma-course-registration\includes\class-tpma-cr-settings.php'; & 'C:\xampp\php\php.exe' -l 'C:\WEB\tpma-course-registration\includes\class-tpma-tutor-bridge.php'; rg -n "OPTION_TUTOR_MEET_DIAGNOSTICS|TUTOR_MEET_DIAGNOSTIC_LIMIT|get_google_meet_authorization_status|查看診斷紀錄" 'C:\WEB\tpma-course-registration\includes'; git -C 'C:\WEB\tpma-course-registration' diff --check`

Expected: both PHP syntax checks report no errors; static search finds all four symbols; diff check returns exit 0.

### Task 5: 清理與 WordPress 手動驗收

**Files:**
- Delete: `C:/WEB/.tests/tpma-meet-diagnostics.php`

- [ ] **Step 1: 刪除暫時測試檔**

Run: `Test-Path -LiteralPath 'C:\WEB\.tests\tpma-meet-diagnostics.php'`

Expected after deletion: `False`.

- [ ] **Step 2: WordPress 手動驗收**

1. 以網站管理員開啟「設定 → TPMA Course Registration IDs」：確認狀態顯示「目前有效」、授權帳號、最近檢查時間與紀錄面板。
2. 以另一位管理員建立或修改場次：確認 Meet 成功，面板新增該操作的有效紀錄。
3. 在測試環境暫時使共用 token 不可讀，再開啟設定頁：確認顯示「目前失效」、安全化原因與重新授權按鈕；不可顯示 token 或 Google email。
4. 完成重新授權後重開設定頁：確認狀態恢復「目前有效」，紀錄仍只保留最近 30 筆。

No Git commit, push, or PR is part of this plan; those operations require separate current-turn authorization.
