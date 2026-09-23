<?php
/** Protected management UI and REST operations for formal course certificates. */
defined('ABSPATH') || exit;

class TPMA_CR_Certificate_Admin {
    const PAGE = 'tpma-cr-certificates';

    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'add_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function add_page(): void {
        add_submenu_page(TPMA_CR_Admin_Pages::PARENT_SLUG, 'TPMA 證書管理', 'TPMA 證書管理', 'manage_options', self::PAGE, array(__CLASS__, 'render_page'));
    }

    public static function can_manage(): bool { return current_user_can('manage_options'); }

    public static function enqueue_assets(string $hook): void {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE || !self::can_manage()) return;
        $version = (defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : '1') . '-' . (int) @filemtime(TPMA_CR_PATH . 'assets/js/certificate-admin.js');
        wp_enqueue_style('tpma-cr-admin-common', TPMA_CR_URL . 'assets/css/admin-common.css', array(), defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : '1');
        wp_enqueue_style('tpma-cr-receipt-admin', TPMA_CR_URL . 'assets/css/receipt-admin.css', array('tpma-cr-admin-common'), defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : '1');
        wp_enqueue_script('tpma-cr-certificate-admin', TPMA_CR_URL . 'assets/js/certificate-admin.js', array(), $version, true);
        wp_add_inline_script('tpma-cr-certificate-admin', 'window.TPMACertificateAdminConfig=' . wp_json_encode(array('apiBase' => untrailingslashit(rest_url('tpma/v1')), 'nonce' => wp_create_nonce('wp_rest')), JSON_UNESCAPED_UNICODE) . ';', 'before');
    }

    public static function render_page(): void {
        if (!self::can_manage()) wp_die('權限不足。', 'TPMA 證書管理', array('response' => 403));
        include TPMA_CR_PATH . 'views/certificate-admin.php';
    }

    public static function register_routes(): void {
        $permission = array(__CLASS__, 'can_manage');
        register_rest_route('tpma/v1', '/admin/certificates/list', array('methods' => 'GET', 'callback' => array(__CLASS__, 'list_certificates'), 'permission_callback' => $permission));
        register_rest_route('tpma/v1', '/admin/certificates/registration/(?P<registration_id>\d+)/allocate', array('methods' => 'POST', 'callback' => array(__CLASS__, 'allocate_certificate'), 'permission_callback' => $permission));
        register_rest_route('tpma/v1', '/admin/certificates/(?P<certificate_id>\d+)/render', array('methods' => 'POST', 'callback' => array(__CLASS__, 'render_certificate'), 'permission_callback' => $permission));
        register_rest_route('tpma/v1', '/admin/certificates/(?P<certificate_id>\d+)/send', array('methods' => 'POST', 'callback' => array(__CLASS__, 'send_certificate'), 'permission_callback' => $permission));
        register_rest_route('tpma/v1', '/admin/certificates/(?P<certificate_id>\d+)/file', array('methods' => 'GET', 'callback' => array(__CLASS__, 'stream_file'), 'permission_callback' => $permission));
        register_rest_route('tpma/v1', '/admin/certificates/bulk', array('methods' => 'POST', 'callback' => array(__CLASS__, 'bulk_action'), 'permission_callback' => $permission));
    }

    public static function list_certificates(WP_REST_Request $request) {
        global $wpdb;
        $certs = TPMA_CR_DB::table('certificates'); $regs = TPMA_CR_DB::table('regs'); $courses = TPMA_CR_DB::table('courses'); $sessions = TPMA_CR_DB::table('sessions');
        $page = max(1, absint($request->get_param('page'))); $per_page = min(100, max(10, absint($request->get_param('per_page')) ?: 20));
        $where = array('1=1'); $args = array();
        $q = sanitize_text_field((string) $request->get_param('q'));
        if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $where[] = '(cert.serial LIKE %s OR r.reg_no LIKE %s OR r.student_name LIKE %s OR r.company_name LIKE %s OR c.course_name LIKE %s)'; array_push($args, $like, $like, $like, $like, $like); }
        $status = sanitize_key((string) $request->get_param('status'));
        if ($status === 'candidate') {
            // One-time-compatible recovery for attempts that predate the
            // certificate_passed_at column; this does not allocate a serial.
            TPMA_CR_Certificate_Service::backfill_legacy_passes();
            return self::list_candidates($request, $q, $page, $per_page);
        }
        if (in_array($status, array('pending', 'generated', 'sent'), true)) { $where[] = 'cert.status=%s'; $args[] = $status; }
        foreach (array('course_date_from' => '>=', 'course_date_to' => '<=', 'passed_from' => '>=', 'passed_to' => '<=') as $key => $operator) {
            $value = sanitize_text_field((string) $request->get_param($key)); if (!$value) continue;
            $field = strpos($key, 'passed') === 0 ? 'cert.passed_at' : 'COALESCE(s.session_datetime, r.class_date, c.class_date)';
            $where[] = $field . ' ' . $operator . ' %s'; $args[] = $value . (strpos($key, '_to') !== false ? ' 23:59:59' : '');
        }
        $where_sql = implode(' AND ', $where);
        $from = " FROM {$certs} cert INNER JOIN {$regs} r ON r.id=cert.registration_id LEFT JOIN {$courses} c ON c.id=r.course_id LEFT JOIN {$sessions} s ON s.id=r.session_id WHERE {$where_sql}";
        $count_sql = 'SELECT COUNT(*)' . $from; if ($args) $count_sql = $wpdb->prepare($count_sql, $args);
        $total = (int) $wpdb->get_var($count_sql);
        $sorts = array('serial' => 'cert.serial', 'course_date' => 'COALESCE(s.session_datetime, r.class_date, c.class_date)', 'student' => 'r.student_name', 'passed_at' => 'cert.passed_at', 'status' => 'cert.status', 'sent_at' => 'cert.sent_at');
        $sort = sanitize_key((string) $request->get_param('sort_by')); $sort = $sorts[$sort] ?? $sorts['serial'];
        $order = strtolower((string) $request->get_param('sort_order')) === 'asc' ? 'ASC' : 'DESC';
        $data_sql = 'SELECT cert.*, r.reg_no, r.student_name, r.company_name, r.emails, r.woocommerce_order_id, c.course_name, COALESCE(s.session_datetime, r.class_date, c.class_date) AS course_date' . $from . " ORDER BY {$sort} {$order}, cert.id DESC LIMIT %d OFFSET %d";
        $query_args = array_merge($args, array($per_page, ($page - 1) * $per_page)); $rows = $wpdb->get_results($wpdb->prepare($data_sql, $query_args), ARRAY_A);
        return rest_ensure_response(array('items' => array_map(array(__CLASS__, 'item_payload'), (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))));
    }

    /** Passed registrations without a formal certificate: recovery/manual-numbering queue. */
    private static function list_candidates(WP_REST_Request $request, string $q, int $page, int $per_page) {
        global $wpdb;
        $certs = TPMA_CR_DB::table('certificates'); $regs = TPMA_CR_DB::table('regs'); $courses = TPMA_CR_DB::table('courses'); $sessions = TPMA_CR_DB::table('sessions');
        // The registration table is the queue source.  A certificate row only
        // excludes it after a formal serial has actually been allocated; older
        // failed runs may have left an unnumbered placeholder row behind.
        $where = array("(cert.serial IS NULL OR cert.serial='')", "(r.certificate_passed_at IS NOT NULL AND r.certificate_passed_at <> '' OR COALESCE(r.test_score, '')<>'')", "r.status NOT IN ('cancelled', 'refunded')"); $args = array();
        if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $where[] = '(r.reg_no LIKE %s OR r.student_name LIKE %s OR r.company_name LIKE %s OR c.course_name LIKE %s)'; array_push($args, $like, $like, $like, $like); }
        foreach (array('course_date_from' => '>=', 'course_date_to' => '<=', 'passed_from' => '>=', 'passed_to' => '<=') as $key => $operator) {
            $value = sanitize_text_field((string) $request->get_param($key)); if ($value === '') continue;
            $field = strpos($key, 'passed') === 0 ? 'r.certificate_passed_at' : 'COALESCE(s.session_datetime, r.class_date, c.class_date)';
            $where[] = $field . ' ' . $operator . ' %s'; $args[] = $value . (strpos($key, '_to') !== false ? ' 23:59:59' : '');
        }
        $from = " FROM {$regs} r LEFT JOIN {$certs} cert ON cert.registration_id=r.id LEFT JOIN {$courses} c ON c.id=r.course_id LEFT JOIN {$sessions} s ON s.id=r.session_id WHERE " . implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*)' . $from; if ($args) $count_sql = $wpdb->prepare($count_sql, $args);
        $total = (int) $wpdb->get_var($count_sql);
        $sorts = array('serial' => 'r.certificate_passed_at', 'course_date' => 'COALESCE(s.session_datetime, r.class_date, c.class_date)', 'student' => 'r.student_name', 'passed_at' => 'r.certificate_passed_at', 'status' => 'r.certificate_passed_at', 'sent_at' => 'r.certificate_passed_at');
        $sort = $sorts[sanitize_key((string) $request->get_param('sort_by'))] ?? $sorts['serial'];
        $order = strtolower((string) $request->get_param('sort_order')) === 'asc' ? 'ASC' : 'DESC';
        $data_sql = "SELECT r.id AS registration_id, r.reg_no, r.student_name, r.company_name, r.emails, r.woocommerce_order_id, r.certificate_passed_at AS passed_at, c.course_name, COALESCE(s.session_datetime, r.class_date, c.class_date) AS course_date{$from} ORDER BY {$sort} {$order}, r.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($args, array($per_page, ($page - 1) * $per_page))), ARRAY_A);
        foreach ((array) $rows as &$row) { $row['is_candidate'] = true; $row['status'] = 'candidate'; }
        unset($row);
        return rest_ensure_response(array('items' => array_map(array(__CLASS__, 'item_payload'), (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))));
    }

    public static function render_certificate(WP_REST_Request $request) { return self::render_one(absint($request['certificate_id'])); }
    public static function send_certificate(WP_REST_Request $request) { return self::send_one(absint($request['certificate_id']), (bool) $request->get_param('force')); }
    public static function allocate_certificate(WP_REST_Request $request) { return self::allocate_one(absint($request['registration_id'])); }

    public static function bulk_action(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $action = sanitize_key((string) ($payload['action'] ?? '')); $out = array('processed' => 0, 'success' => 0, 'skipped' => 0, 'errors' => array());
        if ($action === 'allocate') {
            $registration_ids = array_values(array_filter(array_map('absint', (array) ($payload['registration_ids'] ?? array()))));
            if (!$registration_ids) return new WP_Error('tpma_certificate_registration_ids_required', '請先選取至少一筆準證書。', array('status' => 400));
            foreach ($registration_ids as $id) { $out['processed']++; $result = self::allocate_one($id); if (is_wp_error($result)) $out['errors'][] = array('id' => $id, 'message' => $result->get_error_message()); else $out['success']++; }
            $out['skipped'] = count($out['errors']); return rest_ensure_response($out);
        }
        $ids = array_values(array_filter(array_map('absint', (array) ($payload['certificate_ids'] ?? array()))));
        if (!$ids) return new WP_Error('tpma_certificate_ids_required', '請先選取至少一筆證書。', array('status' => 400));
        if (in_array($action, array('print', 'download'), true)) return self::stream_batch($ids, $action === 'download');
        foreach ($ids as $id) { $out['processed']++; $result = $action === 'render' ? self::render_one($id) : ($action === 'send' ? self::send_one($id, !empty($payload['force'])) : new WP_Error('tpma_certificate_action_invalid', '不支援的證書操作。'));
            if (is_wp_error($result)) { $out['errors'][] = array('id' => $id, 'message' => $result->get_error_message()); } else { $out['success']++; } }
        $out['skipped'] = count($out['errors']); return rest_ensure_response($out);
    }

    public static function stream_file(WP_REST_Request $request) {
        $file = TPMA_CR_Certificate_Service::get_effective_file(absint($request['certificate_id'])); if (is_wp_error($file)) return $file;
        $streamed = self::stream_pdf_file($file, !empty($request['download']));
        if (is_wp_error($streamed)) return $streamed;
        return null;
    }

    private static function render_one(int $id) {
        $certificate = TPMA_CR_Certificate_Service::get($id); if (!$certificate) return new WP_Error('tpma_certificate_not_found', '找不到證書。', array('status' => 404));
        $file = TPMA_CR_Certificate_Service::render_current_pdf($id); if (is_wp_error($file)) return $file;
        return rest_ensure_response(array('certificate' => self::item_payload($certificate)));
    }

    private static function allocate_one(int $registration_id) {
        $certificate = TPMA_CR_Certificate_Service::allocate_for_registration($registration_id);
        if (is_wp_error($certificate)) return $certificate;
        return rest_ensure_response(array('certificate' => self::item_payload($certificate)));
    }

    private static function send_one(int $id, bool $force) {
        $certificate = TPMA_CR_Certificate_Service::get($id); if (!$certificate) return new WP_Error('tpma_certificate_not_found', '找不到證書。', array('status' => 404));
        $reg = TPMA_CR_Certificate_Service::get_registration_for_certificate((int) $certificate['registration_id']); if (!$reg) return new WP_Error('tpma_certificate_registration_missing', '找不到對應報名資料。');
        $result = TPMA_CR_Mail_Dispatcher::send_certificate_for_registration($reg, array('force' => $force));
        if (empty($result['sent'])) { $message = (string) (($result['failed'][0]['message'] ?? $result['skipped'][0]['message'] ?? '證書未寄出。')); return new WP_Error('tpma_certificate_send_failed', $message); }
        return rest_ensure_response(array('certificate' => self::item_payload(TPMA_CR_Certificate_Service::get($id))));
    }

    private static function stream_batch(array $ids, bool $download) {
        if (!function_exists('tpma_mpdf_create')) return new WP_Error('tpma_certificate_mpdf_unavailable', 'TPMA mPDF Service 尚未啟用。', array('status' => 503));
        $mpdf = tpma_mpdf_create(array('format' => 'A4', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0)); if (is_wp_error($mpdf)) return $mpdf;
        $included = 0; foreach ($ids as $id) { $file = TPMA_CR_Certificate_Service::get_effective_file($id); if (is_wp_error($file)) continue; try { $pages = (int) $mpdf->SetSourceFile($file); for ($page = 1; $page <= $pages; $page++) { $mpdf->AddPage(); $mpdf->UseTemplate($mpdf->ImportPage($page), 0, 0, $mpdf->w, $mpdf->h); $included++; } } catch (Throwable $e) { return new WP_Error('tpma_certificate_batch_failed', '合併證書失敗：' . $e->getMessage(), array('status' => 500)); } }
        if (!$included) return new WP_Error('tpma_certificate_batch_empty', '所選證書尚無可用 PDF。', array('status' => 400));
        $destination = class_exists('\\Mpdf\\Output\\Destination') ? \Mpdf\Output\Destination::STRING_RETURN : 'S'; self::stream_pdf((string) $mpdf->Output('', $destination), 'tpma-certificates-' . wp_date('Ymd-His') . '.pdf', $download);
    }

    private static function stream_pdf_file(string $file, bool $download) { $contents = file_get_contents($file); if (!is_string($contents) || $contents === '') return new WP_Error('tpma_certificate_file_missing', '無法讀取證書檔案。', array('status' => 404)); self::stream_pdf($contents, basename($file), $download); return null; }
    private static function stream_pdf(string $pdf, string $filename, bool $download): void { if (function_exists('nocache_headers')) nocache_headers(); header('Content-Type: application/pdf'); header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . sanitize_file_name($filename) . '"'); header('Content-Length: ' . strlen($pdf)); echo $pdf; exit; }
    private static function item_payload(array $row): array {
        $candidate = !empty($row['is_candidate']); $status = $candidate ? 'candidate' : (string) ($row['status'] ?? 'pending');
        $registration = array('woocommerce_order_id' => (int) ($row['woocommerce_order_id'] ?? 0));
        $has_order = !empty($registration['woocommerce_order_id']);
        $payment_completed = $has_order ? TPMA_CR_Certificate_Service::registration_order_is_completed($registration) : null;
        $id = $candidate ? (int) ($row['registration_id'] ?? 0) : (int) ($row['id'] ?? 0);
        return array(
            'id' => $id, 'registration_id' => $candidate ? $id : (int) ($row['registration_id'] ?? 0), 'is_candidate' => $candidate,
            'serial' => (string) ($row['serial'] ?? ''), 'reg_no' => (string) ($row['reg_no'] ?? ''),
            'student_name' => (string) ($row['student_name'] ?? ''), 'company_name' => (string) ($row['company_name'] ?? ''),
            'course_name' => (string) ($row['course_name'] ?? ($row['snapshot']['course_name'] ?? '')), 'course_date' => (string) ($row['course_date'] ?? ($row['snapshot']['course_date'] ?? '')),
            'passed_at' => (string) ($row['passed_at'] ?? ''), 'generated_at' => (string) ($row['generated_at'] ?? ''), 'sent_at' => (string) ($row['sent_at'] ?? ''),
            'status' => $status, 'status_label' => $status === 'candidate' ? '準證書' : ($status === 'sent' ? '已結訓' : ($status === 'generated' ? '待發證' : '待產製')),
            'payment_completed' => $payment_completed,
            'preview_url' => rest_url('tpma/v1/admin/certificates/' . $id . '/file'), 'download_url' => add_query_arg('download', '1', rest_url('tpma/v1/admin/certificates/' . $id . '/file')),
        );
    }
}
