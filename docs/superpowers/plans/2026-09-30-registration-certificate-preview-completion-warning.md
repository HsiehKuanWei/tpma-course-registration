# 報名管理證書預覽與結訓警告 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在報名管理列表預覽待寄證 PDF，並於單筆與批次手動標記已結訓前，以後端即時資料提示未完成作業。

**Architecture:** `class-tpma-rest-admin.php` 新增可重用的結訓警告彙整器，兩個既有更新端點都在寫入前呼叫它；第一次回傳 `completion_warnings`，帶 `force_completion` 的確認請求才執行更新。前端沿用既有 Blob PDF 預覽模式，並在共用 UI 模組處理警告確認和重新送出。

**Tech Stack:** WordPress REST API、PHP／wpdb、WooCommerce、既有 TPMA 收據／證書服務、原生 JavaScript。

---

## 檔案結構

- `includes/class-tpma-rest-admin.php`：提供以資料庫現況判定的結訓警告、在單筆與批次端點實施二段確認。
- `assets/js/reg-admin/03.reg-admin.api.js`：提供證書 PDF Blob 讀取。
- `assets/js/reg-admin/05.reg-admin.render.js`：渲染待寄證預覽標籤，並共用收據預覽的安全彈窗與 Blob 顯示方式。
- `assets/js/reg-admin/06.reg-admin.ui-events.js`：在批次狀態更新收到警告時顯示確認資訊並以 `force_completion` 重送。

### Task 1: 後端彙整手動結訓警告

**Files:**
- Modify: `includes/class-tpma-rest-admin.php:2028-2372`
- Modify: `includes/class-tpma-rest-admin.php:2374-2434`

- [ ] **Step 1: 在 WordPress 測試站建立四筆有 Woo 訂單的測試報名**

建立下列組合，並在變更前透過既有 `/admin/registration/update` 或 `/admin/registrations/bulk` 將其標記為 `completed`：

| 測試資料 | 預期現況 | 目前預期結果 |
| --- | --- | --- |
| A | 無收據、無證書、空白成績 | 現在會直接更新，作為待修正行為 |
| B | 收據／證書已產製但未寄、及格成績 | 現在會直接更新，作為待修正行為 |
| C | 收據與證書已寄、空白成績 | 現在會直接更新，作為待修正行為 |
| D | 無 Woo 訂單且其他資料未完成 | 必須仍可直接更新，作為舊資料排除基線 |

- [ ] **Step 2: 在 `TPMA_CR_REST_Admin` 新增私有警告彙整器**

在 `admin_update_reg()` 前加入 `private static function completion_warnings_for_ids(array $ids): array`，以一次查詢取得報名、有效 Woo 訂單 ID、收據主檔、證書主檔、測驗成績與課程及格設定。函式只回傳有 `woocommerce_order_id > 0` 的資料，且每筆回傳 `id`、`reg_no`、`student_name` 與 `reasons`。

原因判定採下列固定訊息與資料條件：

```php
if (!$receipt || empty($receipt['generated_file'])) {
    $reasons[] = '收據尚未產製';
} elseif ((string) $receipt['status'] !== 'sent') {
    $reasons[] = '收據尚未寄出';
}
if (!$certificate || empty($certificate['generated_file'])) {
    $reasons[] = '證書尚未產製';
} elseif (empty($certificate['sent_at']) || (string) $certificate['status'] !== 'sent') {
    $reasons[] = '證書尚未寄出';
}
if (trim((string) $row['test_score']) === '') {
    $reasons[] = '尚無測驗成績';
} elseif (empty($row['certificate_passed_at'])) {
    $reasons[] = '測驗成績未達及格標準';
}
```

`certificate_passed_at` 是既有 Tutor bridge 在通過正式及格判定後才寫入的正式通過紀錄；這樣不會在 REST controller 重製私有的 Tutor 各測驗及格邏輯，也不會把僅人工輸入的分數誤判為通過。

- [ ] **Step 3: 在單筆端點寫入前回傳警告而非寫入**

在 `admin_update_reg()` 完成 payload sanitize、但在 `TPMA_CR_Admin_Woo_Service::apply_order_updates()` 之前加入：

```php
if (($tpma_update['status'] ?? '') === 'completed' && empty($d['force_completion'])) {
    $warnings = self::completion_warnings_for_ids(array($id));
    if ($warnings) {
        return rest_ensure_response(array(
            'success' => false,
            'requires_completion_confirmation' => true,
            'completion_warnings' => $warnings,
        ));
    }
}
```

確保 `force_completion` 不進入 `$tpma_fields`，不寫入資料表，也不改變非 `completed` 更新。

- [ ] **Step 4: 在批次端點於 `bulk_update_field()` 前回傳相同警告**

在 `admin_bulk_registrations()` 的 `update_field` 分支中，於 `$field === 'status' && $value === 'completed' && empty($d['force_completion'])` 時呼叫同一彙整器。若有警告，回傳同樣的三個欄位，且不呼叫 `bulk_update_field()`；若無警告或帶 `force_completion`，維持既有批次更新。

- [ ] **Step 5: 驗證後端二段確認與舊資料例外**

在測試站用 REST nonce 依序檢查：

```text
POST /wp-json/tpma/v1/admin/registration/update { id: A, status: "completed" }
=> success=false, requires_completion_confirmation=true, completion_warnings 含 A 的所有原因，資料庫 status 未改

POST /wp-json/tpma/v1/admin/registration/update { id: A, status: "completed", force_completion: true }
=> success=true，status 改為 completed

POST /wp-json/tpma/v1/admin/registrations/bulk { ids: [A,B], action: "update_field", field: "status", value: "completed" }
=> 相同的警告格式，兩筆皆未寫入

POST /wp-json/tpma/v1/admin/registration/update { id: D, status: "completed" }
=> success=true，沒有 completion_warnings
```

- [ ] **Step 6: 執行 PHP 語法檢查**

Run: `C:\xampp\php\php.exe -l C:\WEB\tpma-course-registration\includes\class-tpma-rest-admin.php`

Expected: `No syntax errors detected`。

### Task 2: 待寄證 PDF 預覽與終態狀態列

**Files:**
- Modify: `assets/js/reg-admin/03.reg-admin.api.js:149-187`
- Modify: `assets/js/reg-admin/05.reg-admin.render.js:235-290`
- Modify: `assets/js/reg-admin/05.reg-admin.render.js:552-590`

- [ ] **Step 1: 在測試站確認目前狀態列的缺口**

以一筆 `cert_ready` 且 `certificate_generated_at` 有值的報名開啟報名管理列表。預期變更前「待寄證」是普通文字，無法預覽 PDF；一筆 `completed` 或 `cancelled` 則仍會顯示付款或收據等額外標籤。

- [ ] **Step 2: 新增證書 Blob API helper**

在 `03.reg-admin.api.js` 加入：

```js
API.certificateBlob = async function certificateBlob(ctx, certificateId){
  const res = await fetch(ctx.apiBase + '/admin/certificates/' + (parseInt(certificateId, 10) || 0) + '/file', {
    method: 'GET', credentials: 'include', headers: { 'X-WP-Nonce': ctx.nonce }
  });
  if (!res.ok) {
    const data = await res.json().catch(() => null);
    throw new Error((data && data.message) ? data.message : ('無法讀取證書檔案（HTTP ' + res.status + '）'));
  }
  return await res.blob();
};
```

- [ ] **Step 3: 新增證書預覽綁定並調整 `buildStatusIconsHtml()`**

在 `05.reg-admin.render.js` 新增 `R.bindCertificatePreviewLink()` 與 `R.openCertificatePreview()`，使用現有 `R.prepareReceiptPreviewWindow()`、`API.certificateBlob()` 與 `API.openPdfBlob()`；錯誤時必須呼叫 `API.closePdfWindow()`。

將主狀態標籤分為一般與預覽版：

```js
const certificatePreviewable = sCode === 'cert_ready'
  && Number(row.certificate_record_id || 0) > 0
  && String(row.certificate_status || '') === 'generated'
  && !!row.certificate_generated_at;
const statusHtml = U.esc(sLabel);
icons.push(certificatePreviewable
  ? '<a href="#" class="tpma-status-pill '+sClass+' tpma-certificate-link" data-certificate-preview="'+U.esc(row.certificate_record_id)+'" title="預覽證書">'+statusHtml+'</a>'
  : '<span class="tpma-status-pill '+sClass+'" title="報名狀態: '+statusHtml+'">'+statusHtml+'</span>');
```

同時把 `completed` 與 `cancelled` 提前處理，直接 `return '<div class="tpma-status-icons">…</div>'`，使兩者只顯示主報名狀態，不渲染付款、收據與測驗標籤。證書 ID 必須由列表 SQL 另行選出 `cert.id AS certificate_record_id`，並在 JS 使用此欄位，不能誤用舊有 `r.certificate_id`（Tutor hash）。

- [ ] **Step 4: 在兩種列表視圖綁定證書連結**

緊接既有兩處 `R.bindReceiptPreviewLink(...)` 後，加入：

```js
R.bindCertificatePreviewLink(ctx, cStatus.querySelector('[data-certificate-preview]'));
```

並在詳情檢視的狀態列也使用同一綁定，確保平面與巢狀卡片視圖都有一致預覽行為。

- [ ] **Step 5: 驗證 UI 行為**

在測試站驗證：

- `cert_ready`、有正式 PDF 的標籤可開啟正確證書；
- `cert_ready` 但檔案缺失時顯示錯誤且不留下空白視窗；
- `completed`、`cancelled` 各只顯示一個主狀態標籤；
- 一般狀態仍保留既有收據連結與測驗標籤；
- popup 被瀏覽器封鎖時顯示既有提示。

- [ ] **Step 6: 執行 JavaScript 語法檢查**

Run: `node --check C:\WEB\tpma-course-registration\assets\js\reg-admin\03.reg-admin.api.js; node --check C:\WEB\tpma-course-registration\assets\js\reg-admin\05.reg-admin.render.js`

Expected: 兩個指令均以 exit code 0 結束。

### Task 3: 單筆與批次警告確認視窗

**Files:**
- Modify: `assets/js/reg-admin/05.reg-admin.render.js:1090-1110`
- Modify: `assets/js/reg-admin/06.reg-admin.ui-events.js:407-595`

- [ ] **Step 1: 確認變更前單筆與批次行為**

在 Task 1 的測試資料 A 上，於單筆編輯儲存與批次「報名狀態 → 已結訓」分別操作。預期後端回傳 `requires_completion_confirmation`，目前前端只會把它當一般錯誤而不提供繼續按鈕。

- [ ] **Step 2: 實作共用警告內容與確認 helper**

在 `06.reg-admin.ui-events.js` 提供 `UI.confirmCompletionWarnings(warnings)`，將每筆以 `報名編號／姓名：原因 1、原因 2` 組成可讀文字，並以 `confirm()` 顯示：

```js
UI.confirmCompletionWarnings = function(warnings){
  const rows = Array.isArray(warnings) ? warnings : [];
  const detail = rows.map(function(row){
    return [row.reg_no || ('#' + row.id), row.student_name || ''].filter(Boolean).join('／')
      + '：' + (row.reasons || []).join('、');
  }).join('\n');
  return global.confirm('以下資料尚有未完成作業：\n\n' + detail + '\n\n仍要標記為已結訓嗎？');
};
```

保留既有批次結果視窗作為實際執行結果，不把警告誤作更新失敗。

- [ ] **Step 3: 在批次 status 更新收到警告時重新送出**

將批次送出包成 `submitBulk(payload)`。第一次呼叫若回傳 `requires_completion_confirmation`，呼叫共用 helper；確認後複製 payload、設定 `force_completion: true` 並重送，取消則不寫入也不刷新列表。

```js
let data = await API.bulkRegistrations(ctx, payload);
if (data.requires_completion_confirmation) {
  if (!UI.confirmCompletionWarnings(data.completion_warnings)) return;
  data = await API.bulkRegistrations(ctx, Object.assign({}, payload, { force_completion: true }));
}
```

- [ ] **Step 4: 在單筆儲存收到警告時重新送出**

在 `renderDetailEdit()` 的 `API.updateRegistration(ctx, payload)` 呼叫處採用同一回應判定與確認 helper；確認後才以 `Object.assign({}, payload, { force_completion: true })` 呼叫第二次。所有其他 API 錯誤仍走既有 `catch`，取消時保留編輯表單內容。

- [ ] **Step 5: 驗證互動流程**

在測試站驗證單筆與批次各自的：

- 警告清單包含所有未完成原因；
- 取消後報名狀態未寫入；
- 確定後帶 `force_completion` 寫入，並刷新列表；
- 沒有 Woo 訂單的舊資料沒有警告；
- 非「已結訓」的批次狀態更新不會出現這個確認；
- 原有 postpay、收據類型、寄信與證書批次確認不受影響。

- [ ] **Step 6: 執行 JavaScript 語法檢查**

Run: `node --check C:\WEB\tpma-course-registration\assets\js\reg-admin\06.reg-admin.ui-events.js`

Expected: exit code 0。

### Task 4: 完整靜態驗證與交付前檢查

**Files:**
- Verify: `includes/class-tpma-rest-admin.php`
- Verify: `assets/js/reg-admin/03.reg-admin.api.js`
- Verify: `assets/js/reg-admin/05.reg-admin.render.js`
- Verify: `assets/js/reg-admin/06.reg-admin.ui-events.js`

- [ ] **Step 1: 執行完整語法檢查**

Run:

```powershell
C:\xampp\php\php.exe -l C:\WEB\tpma-course-registration\includes\class-tpma-rest-admin.php
node --check C:\WEB\tpma-course-registration\assets\js\reg-admin\03.reg-admin.api.js
node --check C:\WEB\tpma-course-registration\assets\js\reg-admin\05.reg-admin.render.js
node --check C:\WEB\tpma-course-registration\assets\js\reg-admin\06.reg-admin.ui-events.js
```

Expected: 所有命令 exit code 0。

- [ ] **Step 2: 審閱差異與資料欄位擁有權**

Run: `git -C C:\WEB\tpma-course-registration diff --check; git -C C:\WEB\tpma-course-registration diff -- includes/class-tpma-rest-admin.php assets/js/reg-admin/03.reg-admin.api.js assets/js/reg-admin/05.reg-admin.render.js assets/js/reg-admin/06.reg-admin.ui-events.js`

Expected: `diff --check` 無空白錯誤；確認只讀取收據／證書服務擁有的資料，不直接覆寫其狀態，且沒有將 Tutor certificate hash 當作正式證書資料表 ID。

- [ ] **Step 3: 確認沒有新增外掛內臨時測試檔**

Run: `git -C C:\WEB\tpma-course-registration status --short`

Expected: 只出現設計、計畫與正式外掛檔案變更；不出現外掛目錄內臨時測試或診斷檔。
