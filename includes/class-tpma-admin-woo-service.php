<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce helpers for admin REST endpoints.
 * 把 Woo 訂單讀取/同步的邏輯集中處理，避免 REST controller 過度耦合。
 */
class TPMA_CR_Admin_Woo_Service
{
    private static function normalize_email_list($raw): array
    {
        if (class_exists('TPMA_CR_Woo_Shared') && method_exists('TPMA_CR_Woo_Shared', 'normalize_email_list')) {
            return TPMA_CR_Woo_Shared::normalize_email_list($raw);
        }

        $text = sanitize_text_field($raw ?? '');
        if ($text === '') {
            return array();
        }
        $text = str_replace(array('，', '；'), array(',', ';'), $text);
        $parts = preg_split('/[\s,;]+/', $text);
        $emails = array();
        foreach ((array) $parts as $part) {
            $email = trim((string) $part);
            if ($email !== '' && is_email($email)) {
                $emails[] = sanitize_email($email);
            }
        }

        return array_values(array_unique($emails));
    }

    private static function build_contact_email_display($primary, $extras_raw = ''): string
    {
        $emails = array();
        if ($primary && is_email($primary)) {
            $emails[] = sanitize_email($primary);
        }
        $emails = array_merge($emails, self::normalize_email_list($extras_raw));
        $emails = array_values(array_unique($emails));
        return implode(', ', $emails);
    }

    private static function is_cancelled_status($status): bool
    {
        $status = strtolower(trim((string) $status));
        return in_array($status, array('cancelled', 'wc-cancelled'), true)
            || strpos($status, '已取消') !== false;
    }

    private static function counts_for_class(array $row): bool
    {
        $values = array(
            $row['status'] ?? '',
            $row['status_label'] ?? '',
            $row['payment_status'] ?? '',
            $row['payment_status_label'] ?? '',
            $row['order_status'] ?? '',
            $row['order_status_label'] ?? '',
        );

        foreach ($values as $value) {
            if (self::is_cancelled_status($value)) {
                return false;
            }
        }

        return true;
    }

    private static function parse_contact_email_payload($raw)
    {
        $text = sanitize_text_field($raw ?? '');
        $text = trim(str_replace(array('，', '；'), array(',', ';'), $text));
        if ($text === '') {
            return array(
                'primary' => '',
                'extras' => array(),
                'extra_raw' => '',
            );
        }

        $parts = preg_split('/[\s,;]+/', $text);
        $valid = array();
        $invalid = array();
        foreach ((array) $parts as $part) {
            $email = trim((string) $part);
            if ($email === '') {
                continue;
            }
            if (is_email($email)) {
                $valid[] = sanitize_email($email);
            } else {
                $invalid[] = $email;
            }
        }

        if (!empty($invalid)) {
            return new WP_Error(
                'invalid_contact_email',
                '承辦人 Email 格式不正確：' . implode(', ', array_unique($invalid)),
                array('status' => 400)
            );
        }

        $valid = array_values(array_unique($valid));
        return array(
            'primary' => $valid[0] ?? '',
            'extras' => array_slice($valid, 1),
            'extra_raw' => implode(', ', array_slice($valid, 1)),
        );
    }

    private static function sync_contact_emails_to_regs($order, $regs_table, string $primary, string $extra_raw): void
    {
        if (!$order || !$regs_table) {
            return;
        }

        global $wpdb;
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$regs_table}
                 SET contact_email = %s, contact_emails = %s
                 WHERE woocommerce_order_id = %d",
                $primary,
                $extra_raw,
                $order_id
            )
        );
    }

    private static function update_contact_emails($order, array $payload)
    {
        if (!$order || !array_key_exists('contact_email', $payload)) {
            return array('has_change' => false);
        }

        $parsed = self::parse_contact_email_payload($payload['contact_email']);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $has_change = false;
        $primary = (string) ($parsed['primary'] ?? '');
        $extra_raw = (string) ($parsed['extra_raw'] ?? '');

        $billing = $order->get_address('billing');
        $current_primary = trim((string) ($billing['email'] ?? ''));
        if ($current_primary !== $primary) {
            $billing['email'] = $primary;
            $order->set_address($billing, 'billing');
            $has_change = true;
        }

        $current_extra = sanitize_text_field($order->get_meta('_tpma_contact_emails', true));
        if ($current_extra !== $extra_raw) {
            if ($extra_raw !== '') {
                $order->update_meta_data('_tpma_contact_emails', $extra_raw);
            } else {
                $order->delete_meta_data('_tpma_contact_emails');
            }
            $has_change = true;
        }

        return array('has_change' => $has_change);
    }

    private static function get_course_snapshot(int $course_id): array
    {
        if ($course_id <= 0 || !class_exists('TPMA_CR_DB')) {
            return array();
        }

        global $wpdb;
        $courses_table   = TPMA_CR_DB::table('courses');
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $course = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $course_id),
            ARRAY_A
        );
        if (!$course) {
            return array();
        }

        $lecturer = '';
        if (!empty($course['lecturer_code'])) {
            $lect_schema = TPMA_CR_DB::get_lecturer_schema();
            $lect = $wpdb->get_row($wpdb->prepare(
                "SELECT {$lect_schema['name']} AS lecturer_name, {$lect_schema['title']} AS lecturer_title
                 FROM {$lecturers_table}
                 WHERE {$lect_schema['code']} = %s",
                $course['lecturer_code']
            ));
            if ($lect && !empty($lect->lecturer_name)) {
                $lecturer = trim($lect->lecturer_name . (!empty($lect->lecturer_title) ? ' ' . $lect->lecturer_title : ''));
            }
        }

        return array(
            'course_name'      => sanitize_text_field($course['course_name'] ?? ''),
            'lecturer'         => $lecturer,
            'duration_minutes' => (int) ($course['duration_minutes'] ?? 0),
        );
    }

    private static function get_session_snapshot(int $course_id, string $class_date, int $session_id = 0): array
    {
        if ($course_id <= 0 || ($class_date === '' && $session_id <= 0) || !class_exists('TPMA_CR_DB')) {
            return array();
        }

        global $wpdb;
        $sessions_table = TPMA_CR_DB::table('sessions');
        $class_date = sanitize_text_field($class_date);

        $session = null;
        if ($session_id > 0) {
            $session = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, session_datetime FROM {$sessions_table} WHERE id = %d AND course_id = %d LIMIT 1",
                    $session_id,
                    $course_id
                ),
                ARRAY_A
            );
        }

        if (!$session && strlen($class_date) > 10 && strpos($class_date, ' ') !== false) {
            $session = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, session_datetime FROM {$sessions_table} WHERE course_id = %d AND session_datetime = %s LIMIT 1",
                    $course_id,
                    $class_date
                ),
                ARRAY_A
            );
        }

        if (!$session) {
            $date_only = substr($class_date, 0, 10);
            $session = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, session_datetime FROM {$sessions_table} WHERE course_id = %d AND DATE(session_datetime) = %s ORDER BY session_datetime ASC LIMIT 1",
                    $course_id,
                    $date_only
                ),
                ARRAY_A
            );
        }

        return $session ? array(
            'session_id'       => (int) $session['id'],
            'session_datetime' => sanitize_text_field($session['session_datetime']),
            'class_date'       => substr((string) $session['session_datetime'], 0, 10),
        ) : array();
    }

    private static function build_learners_from_regs(array $rows): array
    {
        $learners = array();
        foreach ($rows as $row) {
            $name = sanitize_text_field($row['student_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $learners[] = array(
                'student_name' => $name,
                'department'   => sanitize_text_field($row['department'] ?? ''),
                'job_title'    => sanitize_text_field($row['job_title'] ?? ''),
                'mobile'       => sanitize_text_field($row['mobile'] ?? ''),
                'emails'       => implode(', ', self::normalize_email_list($row['emails'] ?? '')),
                'reg_no'       => sanitize_text_field($row['reg_no'] ?? ''),
                'reg_id'       => (int) ($row['id'] ?? 0),
            );
        }

        return $learners;
    }

    /**
     * Keep the TPMA order snapshot aligned after reg-admin edits course/date/student fields.
     */
    public static function sync_registration_snapshot($order, string $regs_table, int $updated_reg_id, string $raw_class_date = '')
    {
        if (!$order || !$regs_table || $updated_reg_id <= 0 || !class_exists('TPMA_CR_DB')) {
            return array('has_change' => false);
        }

        global $wpdb;
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return array('has_change' => false);
        }

        $updated_row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id = %d", $updated_reg_id),
            ARRAY_A
        );
        if (!$updated_row) {
            return array('has_change' => false);
        }

        $course_id = (int) ($updated_row['course_id'] ?? 0);
        if ($course_id <= 0) {
            return array('has_change' => false);
        }

        $date_source = $raw_class_date !== '' ? $raw_class_date : (string) ($updated_row['class_date'] ?? '');
        $session = self::get_session_snapshot($course_id, $date_source, (int) ($updated_row['session_id'] ?? 0));
        if (empty($session)) {
            return array('has_change' => false);
        }

        $course = self::get_course_snapshot($course_id);
        if (empty($course)) {
            return array('has_change' => false);
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$regs_table} WHERE woocommerce_order_id = %d ORDER BY id ASC",
                $order_id
            ),
            ARRAY_A
        );
        if (empty($rows)) {
            return array('has_change' => false);
        }

        $draft_json = (string) $order->get_meta('_tpma_reg_draft_json', true);
        $draft = $draft_json ? json_decode($draft_json, true) : array();
        if (!is_array($draft)) {
            $draft = array();
        }

        $learners = self::build_learners_from_regs($rows);
        if (!empty($learners)) {
            $draft['learners'] = $learners;
            $draft['total_learners'] = count($learners);
        }

        $order->update_meta_data('_tpma_reg_draft_json', wp_json_encode($draft, JSON_UNESCAPED_UNICODE));
        $order->update_meta_data('_tpma_learner_count', count($draft['learners'] ?? array()));

        $order_course_ids = array_values(array_unique(array_map('intval', wp_list_pluck($rows, 'course_id'))));
        $order_session_ids = array_values(array_unique(array_map('intval', wp_list_pluck($rows, 'session_id'))));

        // A Woo order has one legacy course/session snapshot. Do not overwrite it
        // when its learners now belong to multiple sessions.
        if (count($order_course_ids) === 1 && count($order_session_ids) === 1
            && (int) $order_course_ids[0] === $course_id && (int) $order_session_ids[0] === (int) $session['session_id']
            && (int) $order_session_ids[0] > 0) {
            $draft['course_id'] = $course_id;
            $draft['session_id'] = (int) $session['session_id'];
            $draft['course_name'] = $course['course_name'];
            $draft['lecturer'] = $course['lecturer'];
            $draft['duration_minutes'] = (int) $course['duration_minutes'];
            $draft['session_datetime'] = $session['session_datetime'];
            $draft['class_date'] = $session['class_date'];
            $order->update_meta_data('_tpma_reg_draft_json', wp_json_encode($draft, JSON_UNESCAPED_UNICODE));
            $order->update_meta_data('_tpma_course_id', $course_id);
            $order->update_meta_data('_tpma_session_id', (int) $session['session_id']);
            $order->update_meta_data('_tpma_session_datetime', $session['session_datetime']);
        }

        return array('has_change' => true);
    }

    /**
     * Keep registrations and Woo snapshots aligned when a course session time changes.
     */
    public static function sync_session_datetime_snapshot(string $regs_table, int $session_id, string $session_datetime): array
    {
        if ($regs_table === '' || $session_id <= 0 || $session_datetime === '' || !class_exists('TPMA_CR_DB')) {
            return array('registrations' => 0, 'orders' => 0);
        }

        global $wpdb;
        $session_datetime = sanitize_text_field($session_datetime);
        $class_date = substr($session_datetime, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $class_date)) {
            return array('registrations' => 0, 'orders' => 0);
        }

        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT woocommerce_order_id FROM {$regs_table} WHERE session_id = %d AND woocommerce_order_id IS NOT NULL AND woocommerce_order_id > 0",
            $session_id
        ));

        $wpdb->update(
            $regs_table,
            array('class_date' => $class_date),
            array('session_id' => $session_id),
            array('%s'),
            array('%d')
        );
        $updated_regs = (int) $wpdb->rows_affected;

        $updated_orders = 0;
        if (!function_exists('wc_get_order')) {
            return array('registrations' => $updated_regs, 'orders' => 0);
        }

        foreach ((array) $order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!$order) {
                continue;
            }

            $changed = false;
            $order_session_id = (int) $order->get_meta('_tpma_session_id', true);
            $draft_json = (string) $order->get_meta('_tpma_reg_draft_json', true);
            $draft = $draft_json !== '' ? json_decode($draft_json, true) : array();
            if (!is_array($draft)) {
                $draft = array();
            }
            $draft_session_id = (int) ($draft['session_id'] ?? 0);

            if ($order_session_id === $session_id || $draft_session_id === $session_id) {
                $order->update_meta_data('_tpma_session_id', $session_id);
                $order->update_meta_data('_tpma_session_datetime', $session_datetime);

                $draft['session_id'] = $session_id;
                $draft['session_datetime'] = $session_datetime;
                $draft['class_date'] = $class_date;
                $order->update_meta_data('_tpma_reg_draft_json', wp_json_encode($draft, JSON_UNESCAPED_UNICODE));
                $changed = true;
            }

            if ($changed) {
                $order->save();
                $updated_orders++;
            }
        }

        return array('registrations' => $updated_regs, 'orders' => $updated_orders);
    }

    /**
     * 讀取 rows 中涉及的 Woo 訂單，並將 Woo 資訊覆蓋回傳。
     *
     * @param array $rows regs 查詢結果（含 woocommerce_order_id）
     * @return array
     */
    public static function enrich_regs_with_orders(array $rows)
    {
        if (empty($rows) || !function_exists('wc_get_order')) {
            return $rows;
        }

        $orders_map = array();
        $order_ids  = array();
        foreach ($rows as $r) {
            if (!empty($r['woocommerce_order_id'])) {
                $order_ids[] = (int) $r['woocommerce_order_id'];
            }
        }
        $order_ids = array_values(array_unique(array_filter($order_ids)));

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                continue;
            }
            $remit_paid_at = $order->get_meta('_tpma_remit_paid_at', true);
            if ($remit_paid_at === '' || $remit_paid_at === null) {
                $remit_paid_at = $order->get_meta('_tpma_remit_date', true);
            }
            $orders_map[$oid] = array(
                'status'             => $order->get_status(),
                'total'              => $order->get_total(),
                'contact_name'       => $order->get_billing_first_name(),
                'contact_email'      => self::build_contact_email_display(
                    $order->get_billing_email(),
                    $order->get_meta('_tpma_contact_emails', true)
                ),
                'contact_emails'     => sanitize_text_field($order->get_meta('_tpma_contact_emails', true)),
                'company_name'       => $order->get_billing_company(),
                'phone'              => $order->get_billing_phone(),
                'note'               => (string) $order->get_customer_note(),
                'address'            => trim(implode(' ', array_filter([
                    $order->get_billing_postcode(),
                    $order->get_billing_state(),
                    $order->get_billing_city(),
                    $order->get_billing_address_1(),
                    $order->get_billing_address_2(),
                ], function($v){ return $v !== null && $v !== ''; }))),

                // ★ 編輯模式需要拆分地址，避免 address_1 被塞入整串後再被合併顯示造成重複
                'address_postcode'   => $order->get_billing_postcode(),
                'address_state'      => $order->get_billing_state(),
                'address_city'       => $order->get_billing_city(),
                'address_line1'      => $order->get_billing_address_1(),

                'receiver'           => $order->get_shipping_first_name(),
                'receipt_type'       => $order->get_meta('_tpma_receipt_type', true),
                'tax_id'             => self::get_order_tax_id($order),
                'remit_amount_total' => $order->get_meta('_tpma_remit_amount_total', true),
                'remit_paid_at'      => $remit_paid_at,
                'remit_account'      => $order->get_meta('_tpma_remit_account', true),
                'note'              => (string) $order->get_customer_note(),
            );
        }

        foreach ($rows as &$r) {
            $r['contact_emails'] = sanitize_text_field($r['contact_emails'] ?? '');
            $r['contact_email'] = self::build_contact_email_display($r['contact_email'] ?? '', $r['contact_emails']);
            $oid = !empty($r['woocommerce_order_id']) ? (int) $r['woocommerce_order_id'] : 0;
            if ($oid && isset($orders_map[$oid])) {
                $o = $orders_map[$oid];
                $r['payment_status']     = $o['status']; // 用 Woo 狀態
                $r['order_status']       = $o['status'];
                $r['order_total']        = $o['total'];
                $r['contact_name']       = $o['contact_name'];
                $r['contact_email']      = $o['contact_email'];
                $r['company_name']       = $o['company_name'];
                $r['phone']              = $o['phone'];
                $r['address']            = $o['address'];
                $r['address_postcode']   = $o['address_postcode'] ?? '';
                $r['address_state']      = $o['address_state'] ?? '';
                $r['address_city']       = $o['address_city'] ?? '';
                $r['address_line1']      = $o['address_line1'] ?? '';
                $r['receiver']           = $o['receiver'];
                // TPMA 報名表才是未開立收據的來源資料。若無條件以 Woo
                // snapshot 覆蓋，單筆編輯會看似已改成功、實際卻沒有回寫
                // regs.receipt_type，導致後續開立收據時被判定為不一致。
                if (empty($r['receipt_type']) && $o['receipt_type'] !== '') {
                    $r['receipt_type'] = $o['receipt_type'];
                }
                $r['tax_id']             = $o['tax_id'] !== '' ? $o['tax_id'] : $r['tax_id'];
                $r['remit_amount_total'] = $o['remit_amount_total'];
                $r['remit_paid_at']      = $o['remit_paid_at'] ?: $r['remit_paid_at'];
                $r['remit_account']      = $o['remit_account'] ?: $r['remit_account'];
                $r['payment_status_label'] = self::admin_label_for_woo_status($o['status']);
                $r['order_status_label']   = $r['payment_status_label'];
                $r['note']                = $o['note'];
                $r['contact_emails']      = $o['contact_emails'];
            }
            $r['counts_for_class'] = self::counts_for_class($r) ? 1 : 0;
        }
        unset($r);

        return $rows;
    }

    /**
     * 欄位映射表（payload key -> Woo 欄位）
     */
    private static function get_field_map()
    {
        return array(
            'contact_name'     => array('type' => 'billing', 'field' => 'first_name'),
            'company_name'     => array('type' => 'billing', 'field' => 'company'),
            'phone'            => array('type' => 'billing', 'field' => 'phone'),

            // ★ 地址拆欄
            'address_postcode' => array('type' => 'billing', 'field' => 'postcode'),
            'address_state'    => array('type' => 'billing', 'field' => 'state'),
            'address_city'     => array('type' => 'billing', 'field' => 'city'),
            'address_line1'    => array('type' => 'billing', 'field' => 'address_1'),

            // 保留舊 key（若有舊前端仍送 address），只回寫到 address_1
            'address'          => array('type' => 'billing', 'field' => 'address_1'),

            'receiver'         => array('type' => 'shipping', 'field' => 'first_name'),
            'receipt_type'     => array('type' => 'meta',    'field' => '_tpma_receipt_type'),
            'tax_id'           => array('type' => 'meta',    'field' => '_billing_vat_id'),
            'remit_account'    => array('type' => 'meta',    'field' => '_tpma_remit_account'),
            'remit_paid_at'    => array('type' => 'meta',    'field' => '_tpma_remit_paid_at'),
            // ✅ Woo 備註（顧客下單備註 / customer note）
            'note'             => array('type' => 'customer_note', 'field' => 'customer_note'),
        );
    }

    /**
     * 只同步 Woo 欄位，不處理金額。
     *
     * @return array ['has_change'=>bool]
     */
    public static function update_order_fields($order, array $payload)
    {
        if (!$order) {
            return array('has_change' => false);
        }
        $has_change   = false;
        $contact_result = self::update_contact_emails($order, $payload);
        if (is_wp_error($contact_result)) {
            return $contact_result;
        }
        $has_change = $has_change || !empty($contact_result['has_change']);
        $field_map    = self::get_field_map();
        foreach ($field_map as $payload_key => $info) {
            if (!isset($payload[$payload_key])) {
                continue;
            }
            $val = sanitize_text_field($payload[$payload_key]);
            $has_change = true;
            if ($info['type'] === 'billing') {
                $addr = $order->get_address('billing');
                $addr[$info['field']] = $val;
                $order->set_address($addr, 'billing');
            } elseif ($info['type'] === 'shipping') {
                $addr = $order->get_address('shipping');
                $addr[$info['field']] = $val;
                $order->set_address($addr, 'shipping');
            } elseif ($info['type'] === 'meta') {
                if ($info['field'] === '_billing_vat_id') {
                    self::set_order_tax_id($order, $val);
                } else {
                    $order->update_meta_data($info['field'], $val);
                }
            } elseif ($info['type'] === 'customer_note') {
                $current = (string) $order->get_customer_note();
                if ($current !== (string) $val) {
                        $order->set_customer_note((string) $val);
                }
}
        }
        return array('has_change' => $has_change);
    }

    /**
     * 同步 remit_amount 並回寫 Woo 總額。
     *
     * @return array|WP_Error ['has_change'=>bool]
     */
    public static function sync_remit_amount($order, $regs_table, $new_amount)
    {
        if (!$order || !function_exists('wc_get_order')) {
            return array('has_change' => false);
        }
        global $wpdb;

        $order_id = $order->get_id();
        $woo_status = $order->get_status();
        $can_touch_woo_total = in_array($woo_status, array('on-hold','pending','processing'), true);

        if ($order_id <= 0) {
            return new WP_Error('no_order', '找不到對應的 Woo 訂單，無法同步金額', array('status' => 400));
        }
        if (!$can_touch_woo_total) {
            return new WP_Error('order_locked', '訂單狀態不允許改金額（僅 pending / processing 可改）', array('status' => 400));
        }

        // 同步 regs remit_amount
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$regs_table} SET remit_amount = %d WHERE woocommerce_order_id = %d",
                $new_amount,
                $order_id
            )
        );

        // 重新計算總額 = 每人金額 * 人數
        $learner_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$regs_table} WHERE woocommerce_order_id = %d",
                $order_id
            )
        );
        $order_total = $new_amount * max(1, $learner_count);

        foreach ($order->get_items() as $item_id => $item) {
            $item->set_subtotal($order_total);
            $item->set_total($order_total);
            $item->save();
            break; // 只有一個商品
        }

        $order->set_total($order_total);
        $order->calculate_totals(false);

        return array('has_change' => true);
    }

    /**
     * 同步 Woo 訂單欄位與金額。
     *
     * @param WC_Order|null $order
     * @param array         $payload  前端傳入的欄位資料
     * @param string        $regs_table regs 資料表名稱（處理 remit_amount 更新時需要）
     * @return array|WP_Error ['has_change'=>bool]
     */
    public static function apply_order_updates($order, array $payload, $regs_table)
    {
        if (!$order || !function_exists('wc_get_order')) {
            return array('has_change' => false);
        }
        $has_change = false;

        // 欄位同步
        $field_result = self::update_order_fields($order, $payload);
        if (is_wp_error($field_result)) {
            return $field_result;
        }
        $has_change = $has_change || !empty($field_result['has_change']);
        if (array_key_exists('contact_email', $payload)) {
            $parsed_contact_email = self::parse_contact_email_payload($payload['contact_email']);
            if (is_wp_error($parsed_contact_email)) {
                return $parsed_contact_email;
            }
            self::sync_contact_emails_to_regs(
                $order,
                $regs_table,
                (string) ($parsed_contact_email['primary'] ?? ''),
                (string) ($parsed_contact_email['extra_raw'] ?? '')
            );
        }

        // ★ NEW：允許後台更新 Woo 訂單狀態（payload 送 payment_status）
        if (isset($payload['payment_status'])) {
            $new_status = sanitize_key($payload['payment_status']);

            // 驗證是否為 Woo 合法狀態（keys 是 wc-pending / wc-on-hold ...）
            $allowed = array();
            if (function_exists('wc_get_order_statuses')) {
                foreach (array_keys(wc_get_order_statuses()) as $k) {
                    $allowed[] = str_replace('wc-', '', $k);
                }
            }

            if (!empty($allowed) && !in_array($new_status, $allowed, true)) {
                return new WP_Error('invalid_status', '不支援的 Woo 狀態：' . $new_status, array('status' => 400));
            }

            if ($order->get_status() !== $new_status) {
                $order->update_status($new_status, 'TPMA reg-admin 更新訂單狀態', true);
                $has_change = true;
            }
        }

        // remit_amount 特別處理：同步 regs 並重算 Woo 總額
        if (isset($payload['remit_amount'])) {
            $sync = self::sync_remit_amount($order, $regs_table, (int) sanitize_text_field($payload['remit_amount']));
            if (is_wp_error($sync)) {
                return $sync;
            }
            $has_change = $has_change || !empty($sync['has_change']);
        }

        return array('has_change' => $has_change);
    }

    /**
     * 統編讀取：優先舊 TPMA 欄位，缺值時回退 O'Pay。
     */
    private static function get_order_tax_id($order)
    {
        if (!$order instanceof WC_Order) {
            return '';
        }

        $tax_id = trim((string) $order->get_meta('_billing_vat_id', true));
        if ($tax_id === '') {
            $tax_id = trim((string) $order->get_meta('_opay_tax_id', true));
        }
        return $tax_id;
    }

    /**
     * 統編寫入：維持舊 TPMA 欄位，必要時同步 O'Pay 欄位。
     */
    private static function set_order_tax_id($order, $tax_id)
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $tax_id = preg_replace('/\D+/', '', (string) $tax_id);
        $opay_type = (string) $order->get_meta('_opay_invoice_type', true);
        $has_opay_tax = trim((string) $order->get_meta('_opay_tax_id', true)) !== '';

        if ($tax_id === '') {
            $order->delete_meta_data('_billing_vat_id');
            if ($opay_type === 'company' || $has_opay_tax) {
                $order->delete_meta_data('_opay_tax_id');
            }
            return;
        }

        $order->update_meta_data('_billing_vat_id', $tax_id);
        if ($opay_type === 'company' || $has_opay_tax) {
            $order->update_meta_data('_opay_tax_id', $tax_id);
        }
    }

    private static function admin_label_for_woo_status($status)
    {
        $status = (string)$status;

        if ($status === 'on-hold') {
            return '尚未付款';
        }
        if ($status === 'processing') {
            return '待核帳';
        }

        // 其餘維持 Woo 原來的（例如 已取消/已退款...）
        if (function_exists('wc_get_order_status_name')) {
            return wc_get_order_status_name($status);
        }

        return $status;
    }
}
