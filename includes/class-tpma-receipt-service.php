<?php
/**
 * Core receipt lifecycle for TPMA course registrations.
 *
 * The default assumes the WordPress site root is the public document root;
 * when the server reports a conflicting public DOCUMENT_ROOT, storage is
 * rejected until a filtered private directory outside that root is supplied.
 * The REST/UI layer deliberately owns authorised file delivery.
 */

defined('ABSPATH') || exit;

class TPMA_CR_Receipt_Service {
    const STATUS_PENDING       = 'pending';
    const STATUS_GENERATED     = 'generated';
    const STATUS_AWAITING_SCAN = 'awaiting_scan';
    const STATUS_SCANNED       = 'scanned';
    const STATUS_SENT          = 'sent';
    const STATUS_VOID          = 'void';

    public static function init(): void {
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'on_order_completed'), 20, 1);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'on_order_closed'), 20, 1);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'on_order_closed'), 20, 1);
    }

    public static function on_order_completed($order_id): void {
        $result = self::generate_for_order((int) $order_id, false);
        // Woo completed is global: ordinary store orders are intentionally ignored.
        if (is_wp_error($result) && $result->get_error_code() !== 'tpma_receipt_not_tpma_order') {
            do_action('tpma_cr_receipt_generation_failed', (int) $order_id, $result);
        }
    }

    public static function on_order_closed($order_id): void {
        self::maybe_void_for_order((int) $order_id);
    }

    public static function mpdf_available() {
        if (!function_exists('tpma_mpdf_is_available') || !function_exists('tpma_mpdf_render_html_to_file')) {
            return new WP_Error('tpma_receipt_mpdf_unavailable', 'TPMA mPDF Service 尚未啟用。');
        }
        if (!tpma_mpdf_is_available()) {
            return new WP_Error('tpma_receipt_mpdf_unavailable', 'TPMA mPDF Service 目前無法使用。');
        }
        return true;
    }

    public static function private_dir() {
        // The default assumes the WordPress site root is public. If the server
        // reports a different public document root that contains this path, we
        // reject it and require tpma_cr_receipt_private_dir outside that root.
        // Never fall back to uploads: a receipt must not become public merely
        // because private storage fails.
        $default_dir = dirname(untrailingslashit(ABSPATH)) . DIRECTORY_SEPARATOR . 'tpma-receipts';
        $dir = apply_filters('tpma_cr_receipt_private_dir', $default_dir);
        if (!is_string($dir) || trim($dir) === '') {
            return new WP_Error('tpma_receipt_storage_invalid', '收據私有目錄設定無效。');
        }
        if (!path_is_absolute($dir)) {
            return new WP_Error('tpma_receipt_storage_not_absolute', '收據私有目錄必須是站點 document root 之外的絕對路徑；請使用 tpma_cr_receipt_private_dir filter 設定。');
        }
        $dir = wp_normalize_path($dir);
        if (self::path_is_within($dir, ABSPATH)) {
            return new WP_Error('tpma_receipt_storage_public', '收據私有目錄不可位於 WordPress document root 內；請使用 tpma_cr_receipt_private_dir filter 指向站點外部絕對路徑。');
        }
        $document_root = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
        if ($document_root !== '' && path_is_absolute($document_root) && is_dir($document_root)) {
            $document_root = wp_normalize_path($document_root);
            if (self::path_is_within($dir, $document_root)) {
                return new WP_Error('tpma_receipt_storage_document_root', '收據私有目錄位於網站公開 document root 內；請使用 tpma_cr_receipt_private_dir filter 指向真正的網站根目錄外絕對路徑。');
            }
        }
        if (!wp_mkdir_p($dir) || !is_writable($dir)) {
            return new WP_Error('tpma_receipt_storage_unavailable', '無法建立或寫入收據私有目錄。');
        }
        $protection = array(
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "Options -Indexes\nDeny from all\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><directoryBrowse enabled=\"false\" /><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        );
        foreach ($protection as $name => $contents) {
            $path = trailingslashit($dir) . $name;
            if (!file_exists($path)) {
                file_put_contents($path, $contents, LOCK_EX);
            }
        }
        return wp_normalize_path($dir);
    }

    public static function get_receipt($receipt_id) {
        global $wpdb;
        $id = (int) $receipt_id;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPMA_CR_DB::table('receipts') . ' WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? self::hydrate_receipt($row) : null;
    }

    public static function get_receipt_for_order($order_id, $include_void = false) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }
        $where = $include_void ? '' : ' AND ro.active_slot=1';
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT r.* FROM ' . TPMA_CR_DB::table('receipts') . ' r INNER JOIN ' . TPMA_CR_DB::table('receipt_orders') . " ro ON ro.receipt_id=r.id WHERE ro.order_id=%d{$where} ORDER BY r.id DESC LIMIT 1",
            $order_id
        ), ARRAY_A);
        return is_array($row) ? self::hydrate_receipt($row) : null;
    }

    public static function get_receipt_orders($receipt_id): array {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT order_id FROM ' . TPMA_CR_DB::table('receipt_orders') . ' WHERE receipt_id=%d ORDER BY id ASC',
            (int) $receipt_id
        ));
        return array_map('intval', $rows ?: array());
    }

    public static function check_order_eligibility($order) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('tpma_receipt_woocommerce_unavailable', 'WooCommerce 尚未啟用。');
        }
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order((int) $order);
        }
        if (!($order instanceof WC_Order)) {
            return new WP_Error('tpma_receipt_order_not_found', '找不到 WooCommerce 訂單。');
        }
        $receipt_type = self::resolve_registration_receipt_type($order);
        if (is_wp_error($receipt_type)) {
            return $receipt_type;
        }
        $eligibility = self::receipt_send_order_eligibility($order);
        if (empty($eligibility['eligible'])) {
            return new WP_Error(
                (string) ($eligibility['reason'] ?? 'tpma_receipt_payment_required'),
                (string) ($eligibility['message'] ?? '訂單目前不可開立收據。')
            );
        }
        return $order;
    }

    /**
     * The same payment/closure rule is used for receipt issuance and manual
     * delivery. A postpay meta flag is only a checkout snapshot; the TPMA
     * registration row is the source of truth.
     */
    public static function receipt_send_order_eligibility($order): array {
        if (!($order instanceof WC_Order)) {
            return array('eligible' => false, 'reason' => 'order_not_found', 'message' => '找不到 WooCommerce 訂單。');
        }
        $status = (string) $order->get_status();
        if (in_array($status, array('cancelled', 'refunded', 'failed', 'trash'), true)) {
            return array('eligible' => false, 'reason' => 'order_closed', 'message' => '訂單已取消、退款、失敗或刪除，不可寄發收據。');
        }
        if ($status === 'completed' || self::has_postpay_registration($order)) {
            return array('eligible' => true, 'reason' => '', 'message' => '');
        }
        return array('eligible' => false, 'reason' => 'payment_required', 'message' => '訂單尚未完成付款，且沒有有效的課後付款報名資料。');
    }

    /** A merged receipt is sendable only when every source order remains eligible. */
    public static function receipt_send_eligibility_for_receipt($receipt_id) {
        $receipt = self::get_receipt($receipt_id);
        if (!is_array($receipt)) {
            return new WP_Error('tpma_receipt_not_found', '找不到收據。');
        }
        foreach ((array) ($receipt['order_ids'] ?? array()) as $order_id) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
            $eligibility = self::receipt_send_order_eligibility($order);
            if (empty($eligibility['eligible'])) {
                return new WP_Error(
                    'tpma_receipt_source_order_not_sendable',
                    '收據來源訂單 #' . (int) $order_id . ' 不可寄發：' . (string) ($eligibility['message'] ?? '訂單不符合資格。'),
                    array('source_order_id' => (int) $order_id, 'reason' => (string) ($eligibility['reason'] ?? 'order_not_sendable'))
                );
            }
        }
        return $receipt;
    }

    public static function has_postpay_registration(WC_Order $order): bool {
        global $wpdb;
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . TPMA_CR_DB::table('regs') . " WHERE woocommerce_order_id=%d AND status='postpay'",
            $order_id
        ));
    }

    public static function generate_for_order($order_id, $regenerate = false) {
        return self::generate_for_orders(array((int) $order_id), $regenerate);
    }

    /** Create a single receipt. All source orders must be eligible and unlinked. */
    public static function generate_for_orders(array $order_ids, $regenerate = false) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        if (count($order_ids) !== 1 || !$regenerate) {
            if (!$order_ids) {
                return new WP_Error('tpma_receipt_orders_required', '請選擇至少一筆訂單。');
            }
        }

        if ($regenerate) {
            if (count($order_ids) !== 1) {
                return new WP_Error('tpma_receipt_regenerate_requires_receipt', '重新生成請使用收據 ID。');
            }
            $existing = self::get_receipt($order_ids[0]);
            if ($existing) {
                return self::regenerate((int) $existing['id']);
            }
        }

        $orders = self::validate_orders_for_new_receipt($order_ids);
        if (is_wp_error($orders)) {
            return $orders;
        }
        $snapshot = self::build_snapshot($orders);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $receipt = self::create_receipt_record($snapshot, $order_ids);
        if (is_wp_error($receipt)) {
            return $receipt;
        }
        $rendered = self::render_current_pdf((int) $receipt['id']);
        return is_wp_error($rendered) ? $rendered : self::get_receipt((int) $receipt['id']);
    }

    /** Explicit merge API used by a future admin controller. */
    public static function merge_orders(array $order_ids) {
        if (count(array_unique(array_filter(array_map('intval', $order_ids)))) < 2) {
            return new WP_Error('tpma_receipt_merge_requires_multiple_orders', '合併收據至少需要兩筆訂單。');
        }
        return self::generate_for_orders($order_ids, false);
    }

    public static function regenerate($receipt_id) {
        $receipt = self::get_receipt($receipt_id);
        if (!$receipt) {
            return new WP_Error('tpma_receipt_not_found', '找不到收據。');
        }
        if ($receipt['status'] === self::STATUS_VOID) {
            return new WP_Error('tpma_receipt_void', '已作廢收據不可重新生成。');
        }
        $orders = self::validate_orders_for_regeneration(self::get_receipt_orders((int) $receipt['id']));
        if (is_wp_error($orders)) {
            return $orders;
        }
        $snapshot = self::build_snapshot($orders, (string) $receipt['serial']);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        global $wpdb;
        $now = current_time('mysql');
        $revisions = TPMA_CR_DB::table('receipt_revisions');
        $wpdb->insert($revisions, array(
            'receipt_id' => (int) $receipt['id'], 'revision' => (int) $receipt['revision'],
            'snapshot' => wp_json_encode($receipt['snapshot'], JSON_UNESCAPED_UNICODE),
            'generated_file' => (string) $receipt['generated_file'], 'created_by' => get_current_user_id(), 'created_at' => $now,
        ), array('%d', '%d', '%s', '%s', '%d', '%s'));

        if ($receipt['receipt_type'] === 'paper' && !empty($receipt['scanned_file'])) {
            self::delete_private_file((string) $receipt['scanned_file']);
        }
        $new_status = $receipt['receipt_type'] === 'paper' ? self::STATUS_AWAITING_SCAN : self::STATUS_PENDING;
        $wpdb->update(TPMA_CR_DB::table('receipts'), array(
            'snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'revision' => (int) $receipt['revision'] + 1, 'generated_file' => null, 'scanned_file' => null,
            'generated_at' => null, 'scanned_at' => null, 'sent_at' => null, 'status' => $new_status, 'updated_by' => get_current_user_id(), 'updated_at' => $now,
        ), array('id' => (int) $receipt['id']), array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'), array('%d'));
        self::project_receipt_status((int) $receipt['id'], $new_status);
        $rendered = self::render_current_pdf((int) $receipt['id']);
        return is_wp_error($rendered) ? $rendered : self::get_receipt((int) $receipt['id']);
    }

    public static function render_current_pdf($receipt_id) {
        $available = self::mpdf_available();
        if (is_wp_error($available)) {
            return $available;
        }
        $receipt = self::get_receipt($receipt_id);
        if (!$receipt || $receipt['status'] === self::STATUS_VOID) {
            return new WP_Error('tpma_receipt_not_renderable', '找不到可產生的收據。');
        }
        $dir = self::private_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $filename = sanitize_file_name($receipt['serial'] . '-r' . max(1, (int) $receipt['revision']) . '.pdf');
        $relative = $filename;
        $config = self::receipt_pdf_config();
        if (is_wp_error($config)) {
            return $config;
        }
        $result = self::render_word_template_pdf(
            $receipt['snapshot'],
            $receipt['receipt_type'],
            trailingslashit($dir) . $relative,
            $config
        );
        if (is_wp_error($result)) {
            return $result;
        }
        global $wpdb;
        $status = $receipt['receipt_type'] === 'paper' ? self::STATUS_AWAITING_SCAN : self::STATUS_GENERATED;
        $wpdb->update(TPMA_CR_DB::table('receipts'), array(
            'generated_file' => $relative, 'generated_at' => current_time('mysql'), 'status' => $status,
            'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql'),
        ), array('id' => (int) $receipt['id']), array('%s', '%s', '%s', '%d', '%s'), array('%d'));
        self::project_receipt_status((int) $receipt['id'], $status);
        return $relative;
    }

    public static function upload_scanned_file($receipt_id, array $file) {
        $receipt = self::get_receipt($receipt_id);
        if (!$receipt || $receipt['receipt_type'] !== 'paper' || $receipt['status'] === self::STATUS_VOID) {
            return new WP_Error('tpma_receipt_scan_not_allowed', '此收據不可上傳紙本掃描檔。');
        }
        if (!empty($file['error'])) {
            return new WP_Error('tpma_receipt_scan_upload_failed', '掃描檔上傳失敗。');
        }
        $source = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($source === '' || !is_readable($source)) {
            return new WP_Error('tpma_receipt_scan_missing', '找不到上傳的掃描檔。');
        }
        $name = sanitize_file_name((string) ($file['name'] ?? 'scan'));
        $type = wp_check_filetype_and_ext($source, $name);
        $ext = strtolower((string) ($type['ext'] ?? pathinfo($name, PATHINFO_EXTENSION)));
        if (!in_array($ext, array('pdf', 'jpg', 'jpeg', 'png'), true)) {
            return new WP_Error('tpma_receipt_scan_type_invalid', '紙本掃描檔只接受 PDF、JPG、PNG。');
        }
        $dir = self::private_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $target = sanitize_file_name($receipt['serial'] . '-scan-r' . max(1, (int) $receipt['revision']) . '-' . wp_generate_password(10, false, false) . '.pdf');
        $target_path = trailingslashit($dir) . $target;
        if ($ext === 'pdf') {
            if (!@copy($source, $target_path)) {
                return new WP_Error('tpma_receipt_scan_store_failed', '無法保存掃描 PDF。');
            }
        } else {
            $available = self::mpdf_available();
            if (is_wp_error($available)) {
                return $available;
            }
            $data = base64_encode((string) file_get_contents($source));
            $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $html = '<html><body style="margin:0;text-align:center"><img style="max-width:100%;max-height:100%" src="data:' . esc_attr($mime) . ';base64,' . $data . '" /></body></html>';
            $rendered = tpma_mpdf_render_html_to_file($html, $target_path, array('format' => 'A5-L', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0));
            if (is_wp_error($rendered)) {
                return $rendered;
            }
        }
        if (!empty($receipt['scanned_file'])) {
            self::delete_private_file((string) $receipt['scanned_file']);
        }
        global $wpdb;
        $wpdb->update(TPMA_CR_DB::table('receipts'), array(
            'scanned_file' => $target, 'scanned_at' => current_time('mysql'), 'sent_at' => null, 'status' => self::STATUS_SCANNED,
            'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql'),
        ), array('id' => (int) $receipt['id']), array('%s', '%s', '%s', '%s', '%d', '%s'), array('%d'));
        self::project_receipt_status((int) $receipt['id'], self::STATUS_SCANNED);
        return self::get_receipt((int) $receipt['id']);
    }

    public static function get_effective_file($receipt_id) {
        $receipt = self::get_receipt($receipt_id);
        if (!$receipt || $receipt['status'] === self::STATUS_VOID) {
            return new WP_Error('tpma_receipt_file_unavailable', '此收據沒有可用檔案。');
        }
        $file = $receipt['receipt_type'] === 'paper' ? $receipt['scanned_file'] : $receipt['generated_file'];
        if (empty($file)) {
            return new WP_Error('tpma_receipt_file_unavailable', '收據檔案尚未準備完成。');
        }
        $dir = self::private_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $path = trailingslashit($dir) . basename((string) $file);
        if (!is_readable($path)) {
            return new WP_Error('tpma_receipt_file_missing', '收據檔案不存在。');
        }
        return wp_normalize_path($path);
    }

    public static function get_attachment_for_order($order_id) {
        $receipt = self::get_receipt_for_order($order_id);
        return $receipt ? self::get_effective_file((int) $receipt['id']) : new WP_Error('tpma_receipt_not_found', '訂單尚未開立收據。');
    }

    public static function mark_sent($receipt_id) {
        $receipt = self::get_receipt($receipt_id);
        if (!$receipt || $receipt['status'] === self::STATUS_VOID) {
            return new WP_Error('tpma_receipt_not_sendable', '此收據不可標記為已寄送。');
        }
        $file = self::get_effective_file((int) $receipt['id']);
        if (is_wp_error($file)) {
            return $file;
        }
        global $wpdb;
        $wpdb->update(TPMA_CR_DB::table('receipts'), array('status' => self::STATUS_SENT, 'sent_at' => current_time('mysql'), 'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql')), array('id' => (int) $receipt['id']), array('%s', '%s', '%d', '%s'), array('%d'));
        self::project_receipt_status((int) $receipt['id'], self::STATUS_SENT);
        return self::get_receipt((int) $receipt['id']);
    }

    public static function get_recipient_emails($receipt_id): array {
        $emails = array();
        foreach (self::get_receipt_orders($receipt_id) as $order_id) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            if (!$order) continue;
            $emails[] = $order->get_billing_email();
            $extra = preg_split('/[;,\s]+/', (string) $order->get_meta('_tpma_contact_emails', true));
            $emails = array_merge($emails, is_array($extra) ? $extra : array());
        }
        return array_values(array_unique(array_filter(array_map('sanitize_email', $emails), 'is_email')));
    }

    public static function maybe_void_for_order($order_id): void {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT receipt_id FROM ' . TPMA_CR_DB::table('receipt_orders') . ' WHERE order_id=%d AND active_slot=1', (int) $order_id));
        foreach (array_unique(array_map('intval', $ids ?: array())) as $receipt_id) {
            $orders = self::get_receipt_orders($receipt_id);
            if (!$orders) continue;
            $all_closed = true;
            foreach ($orders as $source_order_id) {
                $order = function_exists('wc_get_order') ? wc_get_order($source_order_id) : null;
                if (!$order || !in_array($order->get_status(), array('cancelled', 'refunded'), true)) {
                    $all_closed = false;
                    break;
                }
            }
            if (!$all_closed) continue;
            $now = current_time('mysql');
            $wpdb->update(TPMA_CR_DB::table('receipts'), array('status' => self::STATUS_VOID, 'voided_at' => $now, 'updated_by' => get_current_user_id(), 'updated_at' => $now), array('id' => $receipt_id), array('%s', '%s', '%d', '%s'), array('%d'));
            $wpdb->query($wpdb->prepare('UPDATE ' . TPMA_CR_DB::table('receipt_orders') . ' SET active_slot=NULL WHERE receipt_id=%d', $receipt_id));
            self::project_receipt_status($receipt_id, self::STATUS_VOID);
        }
    }

    private static function validate_orders_for_new_receipt(array $order_ids) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('tpma_receipt_woocommerce_unavailable', 'WooCommerce 尚未啟用。');
        }
        $orders = array();
        foreach ($order_ids as $order_id) {
            if (self::get_receipt_for_order($order_id)) {
                return new WP_Error('tpma_receipt_order_already_linked', '訂單已連結至有效收據。');
            }
            $order = self::check_order_eligibility($order_id);
            if (is_wp_error($order)) return $order;
            $orders[] = $order;
        }
        if (count($orders) > 1) {
            $first = self::merge_identity($orders[0]);
            if (is_wp_error($first)) return $first;
            foreach (array_slice($orders, 1) as $order) {
                $identity = self::merge_identity($order);
                if (is_wp_error($identity)) return $identity;
                if ($identity !== $first) {
                    return new WP_Error('tpma_receipt_merge_identity_mismatch', '合併訂單必須有相同付款人、統編與收據方式。');
                }
            }
        }
        return $orders;
    }

    private static function validate_orders_for_regeneration(array $order_ids) {
        $orders = array();
        foreach ($order_ids as $order_id) {
            $order = self::check_order_eligibility($order_id);
            if (is_wp_error($order)) return $order;
            $orders[] = $order;
        }
        return $orders;
    }

    private static function create_receipt_record(array $snapshot, array $order_ids) {
        global $wpdb;
        $lock_name = 'tpma_receipt_serial_' . current_time('Ym');
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock_name));
        if ($locked !== 1) {
            return new WP_Error('tpma_receipt_serial_lock_failed', '收據流水號配置忙碌，請稍後再試。');
        }
        try {
            $wpdb->query('START TRANSACTION');
            foreach ($order_ids as $order_id) {
                $exists = $wpdb->get_var($wpdb->prepare('SELECT receipt_id FROM ' . TPMA_CR_DB::table('receipt_orders') . ' WHERE order_id=%d AND active_slot=1', $order_id));
                if ($exists) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('tpma_receipt_order_already_linked', '訂單已由另一個操作連結至收據。');
                }
            }
            $serial = self::next_serial();
            if (is_wp_error($serial)) {
                $wpdb->query('ROLLBACK');
                return $serial;
            }
            $snapshot['serial'] = $serial;
            $now = current_time('mysql');
            $inserted = $wpdb->insert(TPMA_CR_DB::table('receipts'), array(
                'serial' => $serial, 'receipt_type' => $snapshot['receipt_type'], 'status' => self::STATUS_PENDING,
                'revision' => 1, 'snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
            ), array('%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s'));
            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tpma_receipt_create_failed', '無法建立收據主檔。');
            }
            $receipt_id = (int) $wpdb->insert_id;
            foreach ($order_ids as $order_id) {
                if (!$wpdb->insert(TPMA_CR_DB::table('receipt_orders'), array('receipt_id' => $receipt_id, 'order_id' => $order_id, 'active_slot' => 1, 'created_at' => $now), array('%d', '%d', '%d', '%s'))) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('tpma_receipt_link_failed', '無法連結收據與訂單。');
                }
            }
            $wpdb->query('COMMIT');
            self::project_receipt_status($receipt_id, self::STATUS_PENDING);
            return self::get_receipt($receipt_id);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private static function next_serial() {
        global $wpdb;
        $prefix = 'TPMA' . wp_date('Y') . 'R' . wp_date('m');
        $last = $wpdb->get_var($wpdb->prepare('SELECT serial FROM ' . TPMA_CR_DB::table('receipts') . ' WHERE serial LIKE %s ORDER BY serial DESC LIMIT 1', $prefix . '%'));
        $sequence = 1;
        if ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\d{3})$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }
        if ($sequence > 999) {
            return new WP_Error('tpma_receipt_serial_limit', '本月收據流水號已達 999 張上限。');
        }
        return $prefix . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    private static function build_snapshot(array $orders, $serial = '') {
        if (!$orders) return new WP_Error('tpma_receipt_orders_required', '沒有可建立快照的訂單。');
        $first = $orders[0];
        $amount = 0.0;
        $order_ids = array();
        foreach ($orders as $order) {
            $amount += (float) $order->get_total();
            $order_ids[] = (int) $order->get_id();
        }
        $identity = self::order_payer_identity($first);
        $receipt_type = self::resolve_registration_receipt_type($first);
        if (is_wp_error($receipt_type)) return $receipt_type;
        return array(
            'serial' => $serial, 'receipt_type' => $receipt_type,
            'payer_name' => $identity['payer_name'], 'tax_id' => $identity['tax_id'],
            'company_name' => $identity['company_name'], 'contact_name' => $identity['contact_name'],
            'amount' => round($amount), 'amount_formatted' => number_format(round($amount)), 'amount_chinese' => self::amount_to_chinese(round($amount)),
            'amount_digits' => self::amount_digits_for_receipt(round($amount)),
            'item_name' => '課程費', 'issue_date' => current_time('mysql'), 'issue_date_roc' => self::roc_date(current_time('timestamp')),
            'order_ids' => $order_ids,
        );
    }

    private static function render_template(array $snapshot, $receipt_type): string {
        $seal_svg = '';
        if ($receipt_type === 'electronic') {
            $seal = defined('TPMA_CR_PATH') ? TPMA_CR_PATH . '理事長印.svg' : '';
            if ($seal && is_readable($seal)) {
                // mPDF treats a data-URI SVG <img> as normal-flow content and
                // can move it to a second page. The version-controlled SVG is
                // trusted and is intentionally inlined inside the signature box.
                $seal_svg = (string) file_get_contents($seal);
                $seal_svg = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $seal_svg);
                $seal_svg = preg_replace('/<!DOCTYPE[^>]*(?:\[[\s\S]*?\]\s*)?>/i', '', $seal_svg);
                // The source SVG is percentage-sized; mPDF resolves that against
                // the signature cell instead of the 1 cm wrapper. Set the SVG's
                // own dimensions to the approved electronic-stamp size.
                $seal_svg = preg_replace('/<svg\s+width="[^"]*"\s+height="[^"]*"/i', '<svg width="10mm" height="10mm"', $seal_svg, 1);
            }
        }
        ob_start();
        // Normalise legacy snapshots too: a lone payer_name is never enough to
        // represent a company receipt, so it falls back to the contact display.
        $receipt = self::normalize_snapshot_identity($snapshot);
        include TPMA_CR_PATH . 'views/receipt-pdf.php';
        return (string) ob_get_clean();
    }

    private static function merge_identity(WC_Order $order) {
        $receipt_type = self::resolve_registration_receipt_type($order);
        if (is_wp_error($receipt_type)) return $receipt_type;
        $identity = self::order_payer_identity($order);
        return implode('|', array(
            $identity['identity_key'], $receipt_type,
        ));
    }

    /**
     * A company receipt needs both company name and tax ID. Partial corporate
     * details are intentionally treated as an individual/contact receipt.
     */
    private static function order_payer_identity(WC_Order $order): array {
        global $wpdb;
        $company_name = trim((string) $order->get_billing_company());
        $tax_id = self::get_tax_id($order);
        $contact_name = trim((string) $wpdb->get_var($wpdb->prepare(
            'SELECT contact_name FROM ' . TPMA_CR_DB::table('regs') . ' WHERE woocommerce_order_id=%d AND contact_name<>\'\' ORDER BY id ASC LIMIT 1',
            (int) $order->get_id()
        )));
        if ($contact_name === '') $contact_name = trim((string) $order->get_formatted_billing_full_name());
        if ($contact_name === '') $contact_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        if ($company_name !== '' && $tax_id !== '') {
            return array('payer_name' => $company_name, 'tax_id' => $tax_id, 'company_name' => $company_name, 'contact_name' => $contact_name, 'identity_key' => 'company|' . $company_name . '|' . $tax_id);
        }
        return array('payer_name' => $contact_name, 'tax_id' => '', 'company_name' => '', 'contact_name' => $contact_name, 'identity_key' => 'contact|' . $contact_name);
    }

    /** Apply the current identity rule before rendering a persisted legacy snapshot. */
    private static function normalize_snapshot_identity(array $snapshot): array {
        $company_name = trim((string) ($snapshot['company_name'] ?? ''));
        $contact_name = trim((string) ($snapshot['contact_name'] ?? ''));
        $tax_id = trim((string) ($snapshot['tax_id'] ?? ''));
        if ($contact_name === '') $contact_name = trim((string) ($snapshot['payer_name'] ?? ''));
        if ($company_name !== '' && $tax_id !== '') {
            $snapshot['payer_name'] = $company_name;
            $snapshot['tax_id'] = $tax_id;
            $snapshot['company_name'] = $company_name;
        } else {
            $snapshot['payer_name'] = $contact_name;
            $snapshot['tax_id'] = '';
            $snapshot['company_name'] = '';
        }
        $snapshot['contact_name'] = $contact_name;
        if (empty($snapshot['amount_digits']) || !is_array($snapshot['amount_digits']) || count($snapshot['amount_digits']) !== 6) {
            $snapshot['amount_digits'] = self::amount_digits_for_receipt((int) ($snapshot['amount'] ?? 0));
        }
        return $snapshot;
    }

    /** The Word template has six separate amount boxes: 拾萬、萬、仟、佰、拾、元。 */
    private static function amount_digits_for_receipt($amount): array {
        $amount = max(0, (int) round($amount));
        if ($amount > 999999) {
            return array('×', '×', '×', '×', '×', '×');
        }
        $digits = array('零', '壹', '貳', '參', '肆', '伍', '陸', '柒', '捌', '玖');
        $slots = array();
        foreach (array(100000, 10000, 1000, 100, 10, 1) as $divisor) {
            $digit = intdiv($amount, $divisor) % 10;
            $slots[] = $digit === 0 ? '×' : $digits[$digit];
        }
        return $slots;
    }

    /**
     * The Word source specifies 標楷體 (DFKai-SB). Do not let mPDF substitute
     * another CJK font: a missing configured font is a rendering error.
     */
    private static function receipt_pdf_config() {
        $font_file = apply_filters('tpma_cr_receipt_font_file', '');
        $candidates = array_filter(array(
            is_string($font_file) ? $font_file : '',
            defined('TPMA_CR_PATH') ? TPMA_CR_PATH . 'assets/fonts/kaiu.ttf' : '',
            'C:/Windows/Fonts/kaiu.ttf',
        ));
        $font_path = '';
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $font_path = wp_normalize_path($candidate);
                break;
            }
        }
        if ($font_path === '') {
            return new WP_Error('tpma_receipt_font_unavailable', '找不到 Word 範本指定的標楷體（DFKai-SB）。請以 tpma_cr_receipt_font_file 指定可讀取的 kaiu.ttf，系統不會改用其他字型。');
        }
        return array(
            'format' => 'A5-L',
            // Word template: left/right 15 mm, top/bottom 10 mm.
            'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 10, 'margin_bottom' => 10,
            'default_font' => 'tpma_dikai', 'default_font_size' => 10.5,
            'fontDir' => array(dirname($font_path)),
            'fontdata' => array('tpma_dikai' => array('R' => basename($font_path), 'B' => basename($font_path))),
            'autoScriptToLang' => false, 'autoLangToFont' => false,
        );
    }

    /**
     * Word is the receipt's layout authority. The exported PDF preserves its
     * original table grid, line heights, paragraph spacing and signature row;
     * mPDF only writes the changing merge-field values on top of that page.
     */
    private static function render_word_template_pdf(array $snapshot, string $receipt_type, string $path, array $config) {
        $template = self::receipt_template_pdf_path($receipt_type);
        if (is_wp_error($template)) {
            return $template;
        }
        $mpdf = tpma_mpdf_create($config);
        if (is_wp_error($mpdf)) {
            return $mpdf;
        }
        try {
            $pages = $mpdf->setSourceFile($template);
            if ((int) $pages < 1) {
                return new WP_Error('tpma_receipt_template_invalid', '收據 Word 底稿沒有可匯入的頁面。');
            }
            $page = $mpdf->importPage(1);
            $mpdf->useTemplate($page, 0, 0, 210, 148.2);

            self::overlay_receipt_snapshot($mpdf, self::normalize_snapshot_identity($snapshot));
            $destination = class_exists('\\Mpdf\\Output\\Destination') ? \Mpdf\Output\Destination::FILE : 'F';
            $mpdf->Output($path, $destination);
        } catch (Throwable $e) {
            return new WP_Error('tpma_receipt_template_render_failed', $e->getMessage());
        }
        return is_readable($path) ? true : new WP_Error('tpma_receipt_template_output_missing', '收據 PDF 底稿輸出失敗。');
    }

    private static function receipt_template_pdf_path(string $receipt_type) {
        $type = $receipt_type === 'paper' ? 'paper' : 'electronic';
        $path = TPMA_CR_PATH . 'assets/receipt-templates/receipt-' . $type . '.pdf';
        if (!is_readable($path)) {
            return new WP_Error('tpma_receipt_template_missing', '找不到 Word 收據 PDF 底稿：' . basename($path));
        }
        return $path;
    }

    /** Overlay only values that were Word merge fields; all static design remains the original PDF. */
    private static function overlay_receipt_snapshot($mpdf, array $snapshot): void {
        // WriteFixedPosHTML positions a line from its top, whereas the Word
        // measurements used for this template are text baselines. This offset
        // restores the Word baseline rather than top-aligning every value.
        $html_baseline_offset = 2.6;

        self::pdf_html_text($mpdf, 132, 25.9 + $html_baseline_offset, 61.3, 5.8, '編號：' . (string) ($snapshot['serial'] ?? ''), 12);

        // The Word/PDF base template intentionally leaves all merge-field
        // areas blank. Do not paint white rectangles here: they can cover a
        // table edge and make the imported Word layout visibly different.
        self::pdf_html_text($mpdf, 21, 25.9 + $html_baseline_offset, 75, 6.2, self::receipt_date_for_word($snapshot), 12);

        self::pdf_html_text($mpdf, 54.3, 37.4 + $html_baseline_offset, 74, 9.8, (string) ($snapshot['payer_name'] ?? ''), 14, true);

        self::pdf_html_text($mpdf, 160.3, 37.4 + $html_baseline_offset, 30.5, 9.8, (string) ($snapshot['tax_id'] ?? ''), 14, true);

        $digits = (array) ($snapshot['amount_digits'] ?? array());
        $digit_x = array(75.9, 94.5, 113.1, 131.8, 150.6, 169.4);
        foreach ($digit_x as $index => $x) {
            self::pdf_html_text($mpdf, $x - 1, 65 + $html_baseline_offset, 9, 7.1, (string) ($digits[$index] ?? '×'), 18, true, 'center');
        }
    }

    /**
     * mPDF's low-level Text() path does not shape UTF-8/CJK glyphs reliably.
     * Use its HTML renderer for every dynamic field, while the PDF imported
     * from Word continues to provide the exact original layout and static text.
     */
    private static function pdf_html_text($mpdf, float $x, float $y, float $width, float $height, string $text, float $size, bool $bold = false, string $align = 'left'): void {
        $style = sprintf(
            'margin:0; padding:0; width:100%%; height:100%%; overflow:visible; white-space:nowrap; font-family:tpma_dikai; font-size:%Fpt; line-height:1; text-align:%s;%s',
            $size,
            $align === 'center' ? 'center' : 'left',
            $bold ? ' font-weight:bold;' : ''
        );
        $html = '<div style="' . $style . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
        $mpdf->WriteFixedPosHTML($html, $x, $y, $width, $height, 'visible');
    }

    private static function receipt_date_for_word(array $snapshot): string {
        $value = (string) ($snapshot['issue_date_roc'] ?? '');
        if (preg_match('/^(\d+)年(\d+)月(\d+)日$/u', $value, $matches)) {
            return '中華民國 ' . $matches[1] . ' 年 ' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . ' 月 ' . str_pad($matches[3], 2, '0', STR_PAD_LEFT) . ' 日';
        }
        return '中華民國 ' . $value;
    }

    /**
     * TPMA registrations own the receipt-type decision; Woo meta is only a
     * checkout snapshot and must agree with a non-empty registration value.
     */
    private static function resolve_registration_receipt_type(WC_Order $order) {
        global $wpdb;
        $values = $wpdb->get_col($wpdb->prepare(
            'SELECT receipt_type FROM ' . TPMA_CR_DB::table('regs') . ' WHERE woocommerce_order_id=%d',
            (int) $order->get_id()
        ));
        if (empty($values)) {
            return new WP_Error('tpma_receipt_not_tpma_order', '訂單沒有 TPMA 課程報名資料，不可開立收據。');
        }
        $types = array();
        foreach ($values as $value) {
            $value = sanitize_key((string) $value);
            if ($value !== '') $types[$value] = true;
        }
        if (count($types) > 1) {
            return new WP_Error('tpma_receipt_type_conflict', '同一訂單的 TPMA 報名資料包含不同收據方式，請先統一後再開立。');
        }
        $registration_type = $types ? (string) key($types) : '';
        if ($registration_type !== '' && !in_array($registration_type, array('electronic', 'paper'), true)) {
            return new WP_Error('tpma_receipt_type_invalid', 'TPMA 報名資料的收據方式無效。');
        }
        $order_type = sanitize_key((string) $order->get_meta('_tpma_receipt_type', true));
        if ($order_type !== '' && !in_array($order_type, array('electronic', 'paper'), true)) {
            return new WP_Error('tpma_receipt_type_invalid', 'WooCommerce 訂單的收據方式無效。');
        }
        if ($registration_type !== '' && $order_type !== '' && $registration_type !== $order_type) {
            return new WP_Error('tpma_receipt_type_conflict', 'TPMA 報名資料與 WooCommerce 訂單的收據方式不一致，請先統一後再開立。');
        }
        // Legacy rows can be blank; only then read the order snapshot and use
        // electronic as the final established default.
        return $registration_type !== '' ? $registration_type : ($order_type !== '' ? $order_type : 'electronic');
    }

    private static function get_tax_id(WC_Order $order): string {
        foreach (array('_billing_vat_id', '_opay_tax_id', '_tpma_tax_id', 'billing_tax_id') as $key) {
            $value = trim((string) $order->get_meta($key, true));
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function roc_date($timestamp): string {
        return (int) wp_date('Y', $timestamp) - 1911 . '年' . wp_date('m月d日', $timestamp);
    }

    private static function amount_to_chinese($number): string {
        $number = max(0, (int) round($number));
        if ($number === 0) return '零元整';
        $digits = array('零', '壹', '貳', '參', '肆', '伍', '陸', '柒', '捌', '玖');
        $small = array('', '拾', '佰', '仟');
        $large = array('', '萬', '億', '兆');
        $groups = array();
        while ($number > 0) { $groups[] = $number % 10000; $number = intdiv($number, 10000); }
        $out = ''; $pending_zero = false;
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $group = $groups[$i];
            if ($group === 0) { if ($out !== '') $pending_zero = true; continue; }
            if ($pending_zero || ($out !== '' && $group < 1000)) { $out .= '零'; }
            $pending_zero = false; $part = ''; $zero = false;
            for ($p = 3; $p >= 0; $p--) {
                $d = intdiv($group, (int) pow(10, $p)) % 10;
                if ($d === 0) { if ($part !== '') $zero = true; continue; }
                if ($zero) { $part .= '零'; $zero = false; }
                $part .= $digits[$d] . $small[$p];
            }
            $out .= $part . $large[$i];
        }
        return $out . '元整';
    }

    private static function project_receipt_status($receipt_id, $status): void {
        global $wpdb;
        $orders = self::get_receipt_orders($receipt_id);
        if (!$orders) return;
        $placeholders = implode(',', array_fill(0, count($orders), '%d'));
        $sql = $wpdb->prepare('UPDATE ' . TPMA_CR_DB::table('regs') . " SET receipt_status=%s WHERE woocommerce_order_id IN ({$placeholders})", array_merge(array($status), $orders));
        $wpdb->query($sql);
    }

    private static function delete_private_file($file): void {
        $dir = self::private_dir();
        if (is_wp_error($dir)) return;
        $path = trailingslashit($dir) . basename((string) $file);
        if (is_file($path)) @unlink($path);
    }

    private static function hydrate_receipt(array $receipt): array {
        $snapshot = json_decode((string) ($receipt['snapshot'] ?? ''), true);
        $receipt['snapshot'] = is_array($snapshot) ? $snapshot : array();
        $receipt['id'] = (int) $receipt['id'];
        $receipt['revision'] = (int) $receipt['revision'];
        $receipt['order_ids'] = self::get_receipt_orders($receipt['id']);
        return $receipt;
    }

    /** Compare normalized paths with a separator to avoid C:/site vs C:/site2 false matches. */
    private static function path_is_within($path, $parent): bool {
        $path = strtolower(trailingslashit(wp_normalize_path((string) $path)));
        $parent = strtolower(trailingslashit(wp_normalize_path((string) $parent)));
        return strpos($path, $parent) === 0;
    }
}
