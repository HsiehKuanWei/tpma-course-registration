# Receipt reg-admin Source of Truth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `tpma_cr_regs` the authoritative source for TPMA receipt identity data, synchronize reg-admin edits to WooCommerce, and restore all reg-admin receipt batch actions.

**Architecture:** The REST update endpoint persists receipt identity fields to every registration belonging to the same Woo order, then passes the same authoritative values to the existing Woo synchronization service. The receipt service resolves identity and merge comparisons from registration rows rather than Woo billing/meta fields. The browser batch toolbar maps the receipt primary action to its existing receipt-operation selector so it reaches unchanged protected REST endpoints.

**Tech Stack:** WordPress REST API, `$wpdb`, WooCommerce `WC_Order`, PHP 7.4-compatible PHP, browser JavaScript.

---

## File structure

- Modify `includes/class-tpma-rest-admin.php`: persist `contact_name` and `tax_id` in `tpma_cr_regs`; use the resulting registration values to synchronize Woo.
- Modify `includes/class-tpma-admin-woo-service.php`: provide one focused public method that projects receipt identity from a registration row to Woo billing/meta fields without Woo-to-reg fallback.
- Modify `includes/class-tpma-receipt-service.php`: resolve receipt payer identity and merged-receipt comparison keys from `tpma_cr_regs` only.
- Modify `assets/js/reg-admin/06.reg-admin.ui-events.js`: map the `receipt` primary batch action to `tpma-bulk-receipt-action`.
- Modify `docs/superpowers/specs/2026-10-15-receipt-reg-admin-source-design.md`: record the final manual verification result after implementation.

### Task 1: Persist reg-admin identity data and project it to Woo

**Files:**

- Modify: `includes/class-tpma-rest-admin.php:2113-2130,2260-2317`
- Modify: `includes/class-tpma-admin-woo-service.php:290-377`

- [ ] **Step 1: Establish the pre-fix failure with a targeted static check**

Run:

```powershell
rg -n "'contact_name'|'tax_id'|sync_registration_snapshot" includes/class-tpma-rest-admin.php includes/class-tpma-admin-woo-service.php
```

Expected before change: `contact_name` and `tax_id` are absent from `$tpma_fields`; `sync_registration_snapshot()` updates learner/course draft data but not receipt identity billing/meta values.

- [ ] **Step 2: Add registration-owned identity fields to the REST persistence allowlist**

In `admin_update_reg()`, extend the `$tpma_fields` array with the two database columns and leave existing sanitization intact:

```php
'contact_name',
'company_name',
'tax_id',
```

This makes the submitted reg-admin values persist to `tpma_cr_regs` before synchronization.

- [ ] **Step 3: Add a registration-to-Woo identity projection method**

Add `TPMA_CR_Admin_Woo_Service::sync_registration_receipt_identity($order, array $registration): array`. It must build this payload solely from the provided registration row, then call `update_order_fields()`:

```php
$payload = array(
    'contact_name' => sanitize_text_field((string) ($registration['contact_name'] ?? '')),
    'company_name' => sanitize_text_field((string) ($registration['company_name'] ?? '')),
    'tax_id'       => sanitize_text_field((string) ($registration['tax_id'] ?? '')),
    'receipt_type' => sanitize_key((string) ($registration['receipt_type'] ?? '')),
);

return self::update_order_fields($order, $payload);
```

Do not read Woo fields in this method and do not change order totals or status.

- [ ] **Step 4: Synchronize after the registration row has been updated**

After `$wpdb->update($regs_table, $tpma_update, array('id' => $id));`, when the record has a Woo order and the update includes `contact_name`, `company_name`, or `tax_id`, update those three submitted values across all rows with that `woocommerce_order_id`. Reload the edited row and invoke the new projection method. Combine its `has_change` result with `$woo_changed`; retain the existing final `$order->save()` block so billing/meta data is saved once.

```php
$updated_registration = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id = %d", $id),
    ARRAY_A
);
$identity_sync = TPMA_CR_Admin_Woo_Service::sync_registration_receipt_identity($order, (array) $updated_registration);
if (is_wp_error($identity_sync)) {
    return $identity_sync;
}
$woo_changed = $woo_changed || !empty($identity_sync['has_change']);
```

Use an explicit `$identity_update` allowlist made from `array_intersect_key($tpma_update, array_flip(array('contact_name', 'company_name', 'tax_id')))`; do not propagate learner-specific fields.

- [ ] **Step 5: Verify the source direction statically and with PHP syntax checks**

Run:

```powershell
rg -n "contact_name|tax_id|sync_registration_receipt_identity" includes/class-tpma-rest-admin.php includes/class-tpma-admin-woo-service.php
& 'D:\xampp\php\php.exe' -l includes/class-tpma-rest-admin.php
& 'D:\xampp\php\php.exe' -l includes/class-tpma-admin-woo-service.php
```

Expected: both identity columns are persisted, the new projection only receives the post-update registration row, and both files report `No syntax errors detected`.

### Task 2: Build receipt identity from registration rows

**Files:**

- Modify: `includes/class-tpma-receipt-service.php:715-735,793-821`

- [ ] **Step 1: Establish the pre-fix failure with a targeted static check**

Run:

```powershell
rg -n "order_payer_identity|build_snapshot|merge_identity|get_billing_company|get_tax_id" includes/class-tpma-receipt-service.php
```

Expected before change: `build_snapshot()` and `merge_identity()` use `order_payer_identity()`, which reads `get_billing_company()` and order tax meta.

- [ ] **Step 2: Replace Woo-based identity resolution with registration-based resolution**

Replace `order_payer_identity(WC_Order $order)` with `registration_payer_identity(WC_Order $order)`. Query all active registration rows for that order and validate that their normalized `contact_name`, `company_name`, and `tax_id` form one identity. Build the return shape used by snapshots:

```php
return array(
    'payer_name'  => $company_name !== '' && $tax_id !== '' ? $company_name : $contact_name,
    'tax_id'      => $company_name !== '' && $tax_id !== '' ? $tax_id : '',
    'company_name'=> $company_name !== '' && $tax_id !== '' ? $company_name : '',
    'contact_name'=> $contact_name,
    'identity_key'=> $company_name !== '' && $tax_id !== ''
        ? 'company|' . $company_name . '|' . $tax_id
        : 'contact|' . $contact_name,
);
```

If no registration exists or the selected order has conflicting identity values, return `WP_Error`; do not fall back to Woo.

- [ ] **Step 3: Update snapshot and merge callers to propagate validation errors**

In `build_snapshot()` and `merge_identity()`, call `registration_payer_identity()`, return its `WP_Error` unchanged, and otherwise use the existing identity fields. This makes generate, regenerate, receipt-type changes, and merge eligibility all use the same `tpma_cr_regs` source.

- [ ] **Step 4: Verify no receipt identity path reads Woo billing/meta**

Run:

```powershell
rg -n "get_billing_company|get_tax_id|order_payer_identity|registration_payer_identity" includes/class-tpma-receipt-service.php
& 'D:\xampp\php\php.exe' -l includes/class-tpma-receipt-service.php
```

Expected: receipt identity callers only use `registration_payer_identity`; syntax check passes.

### Task 3: Restore reg-admin receipt batch routing

**Files:**

- Modify: `assets/js/reg-admin/06.reg-admin.ui-events.js:114-123`

- [ ] **Step 1: Establish the pre-fix failure with a targeted static check**

Run:

```powershell
rg -n "getBulkTargetElement|receipt:|tpma-bulk-receipt-action" assets/js/reg-admin/06.reg-admin.ui-events.js views/reg-admin.php
```

Expected before change: the view contains `tpma-bulk-receipt-action`, but `getBulkTargetElement()` has no `receipt` key.

- [ ] **Step 2: Add the missing receipt selector mapping**

Add this entry alongside the existing primary action mappings:

```javascript
receipt: 'tpma-bulk-receipt-action',
```

The existing `applyBulk()` branches will then receive `receipt_generate`, `receipt_regenerate`, `receipt_print`, `receipt_download`, `receipt_void`, or `receipt_merge` and call the existing REST endpoints.

- [ ] **Step 3: Verify all receipt actions remain reachable**

Run:

```powershell
rg -n "receipt: 'tpma-bulk-receipt-action'|receipt_generate|receipt_regenerate|receipt_print|receipt_download|receipt_void|receipt_merge" assets/js/reg-admin/06.reg-admin.ui-events.js
```

Expected: one mapping and all six existing action branches are present.

### Task 4: Review and WordPress manual acceptance

**Files:**

- Modify: `docs/superpowers/specs/2026-10-15-receipt-reg-admin-source-design.md`

- [ ] **Step 1: Review the narrow diff and ensure unrelated user work is untouched**

Run:

```powershell
git -c safe.directory='C:/WEB/tpma-course-registration' diff -- includes/class-tpma-rest-admin.php includes/class-tpma-admin-woo-service.php includes/class-tpma-receipt-service.php assets/js/reg-admin/06.reg-admin.ui-events.js docs/superpowers/specs/2026-10-15-receipt-reg-admin-source-design.md
```

Expected: only the four defined behavior changes and the acceptance note are shown; the pre-existing `includes/class-tpma-course-access.php` change remains untouched.

- [ ] **Step 2: Perform WP acceptance after deployment**

1. In reg-admin, edit a not-yet-sent receipt’s company, tax ID, and contact; save and refresh. Confirm the Woo order displays the identical billing company/tax/contact values.
2. Regenerate that receipt. Confirm receipt management and PDF use the revised registration values.
3. Mark a receipt as sent, edit its registration identity, and confirm it cannot be directly regenerated; void it, issue a new receipt, and confirm the new PDF uses the changed data.
4. From reg-admin select eligible records and run each action: generate, regenerate, print, download, void, and merge. Confirm each action produces a result modal or file request rather than silently doing nothing.

- [ ] **Step 3: Record verification outcome in the design spec**

Append a dated verification section with the static/PHP results and the four WP acceptance outcomes. Do not create local test files or commit, because the TPMA plugin workflow requires runtime deployment for browser verification and no Git operation has been authorized.
