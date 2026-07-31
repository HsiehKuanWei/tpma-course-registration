<?php
/**
 * Protected administrator endpoints and WooCommerce order screen controls for
 * TPMA course receipts. Receipt files are only streamed after capability and
 * nonce checks; their private storage paths are never returned to clients.
 */

defined('ABSPATH') || exit;

class TPMA_CR_Receipt_Admin {
    const ACTION = 'tpma_cr_receipt_order';
    const STREAM_ACTION = 'tpma_cr_receipt_stream';

    /** @var array<string,array<string,int|string|bool>> Forms rendered after the Woo order editor form. */
    private static $footer_forms = array();
    private static $footer_forms_hooked = false;

    public static function init(): void {
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('admin_menu', array(__CLASS__, 'add_receipt_admin_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_receipt_admin_assets'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_order_action'));
        add_action('admin_post_' . self::STREAM_ACTION, array(__CLASS__, 'handle_stream'));
        add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
    }

    public static function add_receipt_admin_page(): void {
        add_menu_page(
            'TPMA 收據管理',
            'TPMA 收據管理',
            'manage_options',
            'tpma-cr-receipts',
            array(__CLASS__, 'render_receipt_admin_page'),
            'dashicons-media-spreadsheet',
            58
        );
    }

    public static function enqueue_receipt_admin_assets(string $hook): void {
        if ($hook !== 'toplevel_page_tpma-cr-receipts' || !self::can_manage()) {
            return;
        }
        $version = defined('TPMA_CR_VERSION') ? TPMA_CR_VERSION : null;
        $asset_version = $version . '-' . max(
            (int) @filemtime(TPMA_CR_PATH . 'assets/css/receipt-admin.css'),
            (int) @filemtime(TPMA_CR_PATH . 'assets/js/receipt-admin.js')
        );
        wp_enqueue_style('tpma-cr-admin-common', TPMA_CR_URL . 'assets/css/admin-common.css', array(), $version);
        wp_enqueue_style('tpma-cr-receipt-admin', TPMA_CR_URL . 'assets/css/receipt-admin.css', array('tpma-cr-admin-common'), $asset_version);
        wp_enqueue_script('tpma-cr-receipt-admin', TPMA_CR_URL . 'assets/js/receipt-admin.js', array(), $asset_version, true);
        wp_add_inline_script('tpma-cr-receipt-admin', 'window.TPMAReceiptAdminConfig = ' . wp_json_encode(array(
            'apiBase' => untrailingslashit(rest_url('tpma/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => array(
                'selectRows' => '請先勾選至少一筆資料。',
                'pendingOnly' => '此操作只能處理待開立訂單；已略過不適用資料。',
                'receiptOnly' => '此操作只能處理已開立收據；已略過不適用資料。',
                'mergeConfirm' => '確定合併所選訂單嗎？未寄的既有收據會先作廢，再開立一張新的合併收據。',
            ),
        ), JSON_UNESCAPED_UNICODE) . ';', 'before');
    }

    public static function render_receipt_admin_page(): void {
        if (!self::can_manage()) {
            wp_die('權限不足。', 'TPMA 收據管理', array('response' => 403));
        }
        include TPMA_CR_PATH . 'views/receipt-admin.php';
    }

    public static function register_routes(): void {
        $namespace = 'tpma/v1';
        $permission = array(__CLASS__, 'can_manage');

        register_rest_route($namespace, '/admin/receipts/order/(?P<order_id>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'get_order_receipt'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/list', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'list_receipts'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'get_receipt'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/generate', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'generate_receipt'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/merge', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'merge_receipts'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)/regenerate', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'regenerate_receipt'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)/type', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'change_receipt_type'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)/void', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'void_receipt'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)/scan', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'upload_scan'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)/send', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'send_receipt'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/(?P<receipt_id>\d+)/file', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'stream_rest_file'), 'permission_callback' => $permission,
        ));
        register_rest_route($namespace, '/admin/receipts/bulk', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'bulk_action'), 'permission_callback' => $permission,
        ));
    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    public static function get_order_receipt(WP_REST_Request $request) {
        $order_id = absint($request['order_id']);
        if ($order_id <= 0 || !self::get_order($order_id)) {
            return new WP_Error('tpma_receipt_order_not_found', '找不到 WooCommerce 訂單。', array('status' => 404));
        }
        return rest_ensure_response(array('receipt' => self::receipt_payload(TPMA_CR_Receipt_Service::get_receipt_for_order($order_id, true))));
    }

    public static function get_receipt(WP_REST_Request $request) {
        $receipt = TPMA_CR_Receipt_Service::get_receipt(absint($request['receipt_id']));
        if (!$receipt) {
            return new WP_Error('tpma_receipt_not_found', '找不到收據。', array('status' => 404));
        }
        return rest_ensure_response(array('receipt' => self::receipt_payload($receipt)));
    }

    /**
     * Receipt-centric administrative list. A pending order is deliberately
     * represented as a separate row type, so batch actions cannot mistake it
     * for an already-issued receipt.
     */
    public static function list_receipts(WP_REST_Request $request) {
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(10, absint($request->get_param('per_page') ?: 20)));
        $query = sanitize_text_field((string) $request->get_param('q'));
        $filters = self::receipt_list_filters($request);
        if (is_wp_error($filters)) {
            return $filters;
        }
        $receipt_type = sanitize_key((string) $request->get_param('receipt_type'));
        $status = sanitize_key((string) $request->get_param('status'));
        $sort_by = sanitize_key((string) ($request->get_param('sort_by') ?: 'receipt_number'));
        $sort_order = strtolower((string) ($request->get_param('sort_order') ?: 'asc'));
        if ($receipt_type !== '' && !in_array($receipt_type, array('electronic', 'paper'), true)) {
            return new WP_Error('tpma_receipt_type_invalid', '收據方式篩選值無效。', array('status' => 400));
        }
        if ($status !== '' && !in_array($status, array('pending', 'generated', 'awaiting_scan', 'scanned', 'sent', 'void'), true)) {
            return new WP_Error('tpma_receipt_status_invalid', '收據狀態篩選值無效。', array('status' => 400));
        }
        if (!array_key_exists($sort_by, self::receipt_sort_fields())) {
            return new WP_Error('tpma_receipt_sort_invalid', '收據排序欄位無效。', array('status' => 400));
        }
        if (!in_array($sort_order, array('asc', 'desc'), true)) {
            return new WP_Error('tpma_receipt_sort_order_invalid', '收據排序方向無效。', array('status' => 400));
        }

        $filters['query'] = $query;
        $receipt_total = self::receipt_list_count($filters, $receipt_type, $status);
        $pending_total = self::pending_list_count($filters, $receipt_type, $status);
        $total = $receipt_total + $pending_total;
        $offset = ($page - 1) * $per_page;
        $items = array();
        if ($offset < $receipt_total) {
            $receipt_limit = min($per_page, $receipt_total - $offset);
            $items = self::receipt_list_page($filters, $receipt_type, $status, $sort_by, $sort_order, $receipt_limit, $offset);
            if (count($items) < $per_page) {
                $items = array_merge($items, self::pending_list_page($filters, $receipt_type, $status, $sort_by, $sort_order, $per_page - count($items), 0));
            }
        } else {
            $items = self::pending_list_page($filters, $receipt_type, $status, $sort_by, $sort_order, $per_page, $offset - $receipt_total);
        }
        return rest_ensure_response(array(
            'items' => array_values($items),
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $per_page)),
            ),
        ));
    }

    private static function receipt_list_filters(WP_REST_Request $request) {
        $filters = array();
        foreach (array('number', 'course', 'course_date_from', 'course_date_to', 'heading') as $key) {
            $filters[$key] = sanitize_text_field((string) $request->get_param($key));
        }
        foreach (array('course_date_from', 'course_date_to') as $key) {
            if ($filters[$key] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                return new WP_Error('tpma_receipt_date_invalid', '課程日期篩選值無效。', array('status' => 400));
            }
        }
        foreach (array('amount_min', 'amount_max') as $key) {
            $value = trim((string) $request->get_param($key));
            if ($value !== '' && !is_numeric($value)) {
                return new WP_Error('tpma_receipt_amount_invalid', '金額篩選值無效。', array('status' => 400));
            }
            $filters[$key] = $value === '' ? null : (float) $value;
        }
        if ($filters['amount_min'] !== null && $filters['amount_max'] !== null && $filters['amount_min'] > $filters['amount_max']) {
            return new WP_Error('tpma_receipt_amount_range_invalid', '最低金額不可高於最高金額。', array('status' => 400));
        }
        return $filters;
    }

    private static function receipt_list_count(array $filters, string $receipt_type, string $status): int {
        global $wpdb;
        list($where, $params) = self::receipt_list_where($filters, $receipt_type, $status);
        $sql = 'SELECT COUNT(*) FROM ' . TPMA_CR_DB::table('receipts') . ' r WHERE ' . implode(' AND ', $where);
        return (int) $wpdb->get_var($params ? $wpdb->prepare($sql, $params) : $sql);
    }

    private static function receipt_list_page(array $filters, string $receipt_type, string $status, string $sort_by, string $sort_order, int $limit, int $offset): array {
        if ($limit <= 0) {
            return array();
        }
        global $wpdb;
        list($where, $params) = self::receipt_list_where($filters, $receipt_type, $status);
        $sql = 'SELECT r.*, ' . self::receipt_sort_select() . ' FROM ' . TPMA_CR_DB::table('receipts') . ' r WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . self::receipt_list_order_by($sort_by, $sort_order) . ' LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        $receipts = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!$receipts) {
            return array();
        }
        $receipt_ids = array_map(static function ($row) { return (int) $row['id']; }, $receipts);
        $links = self::receipt_links_by_receipt($receipt_ids);
        $order_ids = array();
        foreach ($links as $ids) {
            $order_ids = array_merge($order_ids, $ids);
        }
        $context = self::list_context($order_ids);
        $items = array();
        foreach ($receipts as $receipt) {
            $receipt['id'] = (int) $receipt['id'];
            $receipt['order_ids'] = $links[(int) $receipt['id']] ?? array();
            $receipt['snapshot'] = json_decode((string) ($receipt['snapshot'] ?? ''), true);
            if (!is_array($receipt['snapshot'])) {
                $receipt['snapshot'] = array();
            }
            $items[] = self::receipt_list_item($receipt, $context);
        }
        return $items;
    }

    private static function receipt_list_where(array $filters, string $receipt_type, string $status): array {
        global $wpdb;
        $links = TPMA_CR_DB::table('receipt_orders');
        $regs = TPMA_CR_DB::table('regs');
        $courses = TPMA_CR_DB::table('courses');
        $sessions = TPMA_CR_DB::table('sessions');
        $snapshot_company = "JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.company_name'))";
        $snapshot_tax_id = "JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.tax_id'))";
        $snapshot_contact = "JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.contact_name'))";
        $snapshot_amount = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.amount')), ''), '0') AS DECIMAL(20,4))";
        $where = array('1=1');
        $params = array();
        if ($receipt_type !== '') {
            $where[] = 'r.receipt_type=%s';
            $params[] = $receipt_type;
        }
        if ($status !== '') {
            $where[] = 'r.status=%s';
            $params[] = $status;
        }
        if (!empty($filters['query'])) {
            $like = '%' . $wpdb->esc_like($filters['query']) . '%';
            $where[] = '(r.serial LIKE %s OR ' . $snapshot_company . ' LIKE %s OR EXISTS (SELECT 1 FROM ' . $links . ' roq WHERE roq.receipt_id=r.id AND CAST(roq.order_id AS CHAR) LIKE %s) OR EXISTS (SELECT 1 FROM ' . $links . ' roq INNER JOIN ' . $regs . ' rq ON rq.woocommerce_order_id=roq.order_id INNER JOIN ' . $courses . ' cq ON cq.id=rq.course_id WHERE roq.receipt_id=r.id AND (cq.course_name LIKE %s OR rq.company_name LIKE %s)))';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($filters['number'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['number']) . '%';
            $where[] = '(r.serial LIKE %s OR EXISTS (SELECT 1 FROM ' . $links . ' ron WHERE ron.receipt_id=r.id AND CAST(ron.order_id AS CHAR) LIKE %s))';
            array_push($params, $like, $like);
        }
        if ($filters['course'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['course']) . '%';
            $where[] = 'EXISTS (SELECT 1 FROM ' . $links . ' roc INNER JOIN ' . $regs . ' rc ON rc.woocommerce_order_id=roc.order_id INNER JOIN ' . $courses . ' cc ON cc.id=rc.course_id WHERE roc.receipt_id=r.id AND cc.course_name LIKE %s)';
            $params[] = $like;
        }
        foreach (array('course_date_from' => '>=', 'course_date_to' => '<=') as $key => $operator) {
            if ($filters[$key] !== '') {
                $where[] = 'EXISTS (SELECT 1 FROM ' . $links . ' rod INNER JOIN ' . $regs . ' rd ON rd.woocommerce_order_id=rod.order_id LEFT JOIN ' . $sessions . ' sd ON sd.id=rd.session_id WHERE rod.receipt_id=r.id AND DATE(COALESCE(sd.session_datetime, rd.class_date)) ' . $operator . ' %s)';
                $params[] = $filters[$key];
            }
        }
        if ($filters['heading'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['heading']) . '%';
            $where[] = '(' . $snapshot_company . ' LIKE %s OR ' . $snapshot_tax_id . ' LIKE %s OR ' . $snapshot_contact . ' LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if ($filters['amount_min'] !== null) {
            $where[] = $snapshot_amount . ' >= %f';
            $params[] = $filters['amount_min'];
        }
        if ($filters['amount_max'] !== null) {
            $where[] = $snapshot_amount . ' <= %f';
            $params[] = $filters['amount_max'];
        }
        return array($where, $params);
    }

    private static function receipt_links_by_receipt(array $receipt_ids): array {
        if (!$receipt_ids) {
            return array();
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($receipt_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT receipt_id, order_id FROM ' . TPMA_CR_DB::table('receipt_orders') . ' WHERE receipt_id IN (' . $placeholders . ') ORDER BY id ASC', $receipt_ids), ARRAY_A);
        $links = array();
        foreach ((array) $rows as $row) {
            $links[(int) $row['receipt_id']][] = (int) $row['order_id'];
        }
        return $links;
    }

    private static function pending_list_count(array $filters, string $receipt_type, string $status): int {
        if ($status !== '' && $status !== 'pending') {
            return 0;
        }
        global $wpdb;
        list($sql, $params) = self::pending_list_sql($filters, $receipt_type);
        $count_sql = 'SELECT COUNT(*) FROM (' . $sql . ') pending_rows';
        return (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);
    }

    private static function pending_list_page(array $filters, string $receipt_type, string $status, string $sort_by, string $sort_order, int $limit, int $offset): array {
        if ($limit <= 0 || ($status !== '' && $status !== 'pending')) {
            return array();
        }
        global $wpdb;
        list($sql, $params) = self::pending_list_sql($filters, $receipt_type);
        $params[] = $limit;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY ' . self::pending_list_order_by($sort_by, $sort_order) . ' LIMIT %d OFFSET %d', $params), ARRAY_A);
        if (!$rows) {
            return array();
        }
        $order_ids = array_map(static function ($row) { return (int) $row['order_id']; }, $rows);
        $context = self::list_context($order_ids);
        $items = array();
        foreach ($rows as $row) {
            $order_id = (int) $row['order_id'];
            $order = $context['orders'][$order_id] ?? null;
            if (!$order instanceof WC_Order) {
                continue;
            }
            $items[] = self::pending_list_item($order_id, (string) ($row['receipt_type'] ?? 'electronic'), $order, $context);
        }
        return $items;
    }

    private static function pending_list_sql(array $filters, string $receipt_type): array {
        global $wpdb;
        $order_table = self::order_status_table();
        $regs = TPMA_CR_DB::table('regs');
        $links = TPMA_CR_DB::table('receipt_orders');
        $sessions = TPMA_CR_DB::table('sessions');
        $where = array(
            'r.woocommerce_order_id IS NOT NULL', 'r.woocommerce_order_id>0',
            'o.' . $order_table['status'] . " NOT IN ('wc-cancelled','wc-refunded','wc-failed','trash')",
            "(o.{$order_table['status']}='wc-completed' OR r.status='postpay')",
            "NOT EXISTS (SELECT 1 FROM {$links} ro WHERE ro.order_id=r.woocommerce_order_id AND ro.active_slot=1)",
        );
        if (empty($order_table['hpos'])) {
            $where[] = "o.post_type='shop_order'";
        }
        $params = array();
        $amount = !empty($order_table['hpos'])
            ? 'o.total_amount'
            : '(SELECT pm.meta_value FROM ' . $wpdb->postmeta . " pm WHERE pm.post_id=r.woocommerce_order_id AND pm.meta_key='_order_total' LIMIT 1)";
        $billing = self::order_billing_sql($order_table, 'r.woocommerce_order_id');
        if (!empty($filters['query'])) {
            $like = '%' . $wpdb->esc_like($filters['query']) . '%';
            $where[] = '(CAST(r.woocommerce_order_id AS CHAR) LIKE %s OR r.company_name LIKE %s OR ' . $billing['company'] . ' LIKE %s OR EXISTS (SELECT 1 FROM ' . TPMA_CR_DB::table('courses') . ' cq WHERE cq.id=r.course_id AND cq.course_name LIKE %s))';
            array_push($params, $like, $like, $like, $like);
        }
        if ($filters['number'] !== '') {
            $where[] = 'CAST(r.woocommerce_order_id AS CHAR) LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['number']) . '%';
        }
        if ($filters['course'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM ' . TPMA_CR_DB::table('courses') . ' cc WHERE cc.id=r.course_id AND cc.course_name LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($filters['course']) . '%';
        }
        foreach (array('course_date_from' => '>=', 'course_date_to' => '<=') as $key => $operator) {
            if ($filters[$key] !== '') {
                $where[] = 'DATE(COALESCE(s.session_datetime, r.class_date)) ' . $operator . ' %s';
                $params[] = $filters[$key];
            }
        }
        if ($filters['heading'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['heading']) . '%';
            $where[] = '(r.company_name LIKE %s OR r.tax_id LIKE %s OR r.contact_name LIKE %s OR ' . $billing['company'] . ' LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($filters['amount_min'] !== null) {
            $where[] = "CAST(COALESCE(NULLIF({$amount}, ''), '0') AS DECIMAL(20,4)) >= %f";
            $params[] = $filters['amount_min'];
        }
        if ($filters['amount_max'] !== null) {
            $where[] = "CAST(COALESCE(NULLIF({$amount}, ''), '0') AS DECIMAL(20,4)) <= %f";
            $params[] = $filters['amount_max'];
        }
        $having = "COUNT(DISTINCT CASE WHEN r.receipt_type IN ('electronic','paper') THEN r.receipt_type END)<=1";
        if ($receipt_type !== '') {
            $having .= ' AND MAX(CASE WHEN r.receipt_type IN (\'electronic\',\'paper\') THEN r.receipt_type ELSE \'electronic\' END)=%s';
            $params[] = $receipt_type;
        }
        $profile_contact = '(SELECT NULLIF(TRIM(rc.contact_name), \'\') FROM ' . $regs . ' rc WHERE rc.woocommerce_order_id=r.woocommerce_order_id AND NULLIF(TRIM(rc.contact_name), \'\') IS NOT NULL ORDER BY rc.id ASC LIMIT 1)';
        // Keep the DB sort key aligned with order_identity_from_profile(): a
        // registration profile never turns an order into a company identity.
        $heading = 'MAX(CASE WHEN ' . $billing['company'] . ' IS NOT NULL AND ' . $billing['has_tax_id'] . ' THEN ' . $billing['company'] . ' ELSE COALESCE(' . $profile_contact . ', ' . $billing['full_name'] . ", '') END)";
        $sql = 'SELECT r.woocommerce_order_id AS order_id, '
            . 'MAX(CASE WHEN r.receipt_type IN (\'electronic\',\'paper\') THEN r.receipt_type ELSE \'electronic\' END) AS receipt_type, '
            . 'MIN(COALESCE(DATE(s.session_datetime), r.class_date)) AS sort_course_date, '
            . $heading . ' AS sort_heading, '
            . 'MAX(CAST(COALESCE(NULLIF(' . $amount . ", ''), '0') AS DECIMAL(20,4))) AS sort_amount, "
            . "CONCAT(MAX(CASE WHEN r.receipt_type IN ('electronic','paper') THEN r.receipt_type ELSE 'electronic' END), ':pending') AS sort_type_status "
            . 'FROM ' . $regs . ' r INNER JOIN ' . $order_table['table'] . ' o ON o.' . $order_table['id'] . '=r.woocommerce_order_id '
            . 'LEFT JOIN ' . $sessions . ' s ON s.id=r.session_id WHERE ' . implode(' AND ', $where)
            . ' GROUP BY r.woocommerce_order_id HAVING ' . $having;
        return array($sql, $params);
    }

    /**
     * All list ORDER BY clauses are assembled from these fixed SQL expressions.
     * The receipt and pending queries each expose matching sort aliases.
     */
    private static function receipt_sort_fields(): array {
        return array(
            'receipt_number' => 'r.serial',
            'course_date' => 'sort_course_date',
            'heading' => 'sort_heading',
            'amount' => 'sort_amount',
            'type_status' => 'sort_type_status',
        );
    }

    private static function receipt_sort_select(): string {
        $order_table = self::order_status_table();
        $links = TPMA_CR_DB::table('receipt_orders');
        $regs = TPMA_CR_DB::table('regs');
        $sessions = TPMA_CR_DB::table('sessions');
        $first_order_id = '(SELECT roi.order_id FROM ' . $links . ' roi WHERE roi.receipt_id=r.id ORDER BY roi.id ASC LIMIT 1)';
        $billing = self::order_billing_sql($order_table, $first_order_id);
        $legacy_contact = '(SELECT NULLIF(TRIM(rr.contact_name), \'\') FROM ' . $regs . ' rr WHERE rr.woocommerce_order_id=' . $first_order_id . ' AND NULLIF(TRIM(rr.contact_name), \'\') IS NOT NULL ORDER BY rr.id ASC LIMIT 1)';
        $snapshot_company = "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.company_name'))), '')";
        $snapshot_tax_id = "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.tax_id'))), '')";
        $snapshot_contact = "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.contact_name'))), '')";
        // Receipt company/tax remain immutable snapshot data. Only legacy
        // snapshots without contact_name may use the linked order for a
        // contact-name display fallback, mirroring receipt_identity().
        $heading = 'CASE WHEN ' . $snapshot_company . ' IS NOT NULL AND ' . $snapshot_tax_id . ' IS NOT NULL THEN ' . $snapshot_company
            . ' WHEN ' . $snapshot_contact . ' IS NOT NULL THEN ' . $snapshot_contact
            . " WHEN COALESCE(JSON_CONTAINS_PATH(r.snapshot, 'one', '$.contact_name'), 0)=0 THEN COALESCE(" . $legacy_contact . ', ' . $billing['full_name'] . ", '') ELSE '' END";
        return '(SELECT MIN(COALESCE(DATE(rs.session_datetime), rr.class_date)) FROM ' . $links . ' ros '
            . 'INNER JOIN ' . $regs . ' rr ON rr.woocommerce_order_id=ros.order_id '
            . 'LEFT JOIN ' . $sessions . ' rs ON rs.id=rr.session_id WHERE ros.receipt_id=r.id) AS sort_course_date, '
            . $heading . ' AS sort_heading, '
            . "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.amount')), ''), '0') AS DECIMAL(20,4)) AS sort_amount, "
            . "CONCAT(r.receipt_type, ':', r.status) AS sort_type_status";
    }

    /**
     * SQL fragments for the billing values read by WC_Order.  Each fragment
     * is scalar, so order-meta rows cannot multiply a grouped pending order.
     */
    private static function order_billing_sql(array $order_table, string $order_id): array {
        global $wpdb;
        $tax_keys = "'_billing_vat_id','_opay_tax_id','_tpma_tax_id','billing_tax_id'";
        if (!empty($order_table['hpos'])) {
            $addresses = $wpdb->prefix . 'wc_order_addresses';
            $meta = $wpdb->prefix . 'wc_orders_meta';
            $meta_order_id = 'order_id';
            $company = "(SELECT NULLIF(TRIM(oa.company), '') FROM {$addresses} oa WHERE oa.order_id={$order_id} AND oa.address_type='billing' LIMIT 1)";
            $full_name = "(SELECT NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(oa.first_name), ''), NULLIF(TRIM(oa.last_name), ''))), '') FROM {$addresses} oa WHERE oa.order_id={$order_id} AND oa.address_type='billing' LIMIT 1)";
        } else {
            $meta = $wpdb->postmeta;
            $meta_order_id = 'post_id';
            $company = "(SELECT NULLIF(TRIM(om_company.meta_value), '') FROM {$meta} om_company WHERE om_company.post_id={$order_id} AND om_company.meta_key='_billing_company' LIMIT 1)";
            $full_name = "(SELECT NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM((SELECT om_first.meta_value FROM {$meta} om_first WHERE om_first.post_id={$order_id} AND om_first.meta_key='_billing_first_name' LIMIT 1)), ''), NULLIF(TRIM((SELECT om_last.meta_value FROM {$meta} om_last WHERE om_last.post_id={$order_id} AND om_last.meta_key='_billing_last_name' LIMIT 1)), ''))), ''))";
        }
        return array(
            'company' => $company,
            'full_name' => $full_name,
            'has_tax_id' => "EXISTS (SELECT 1 FROM {$meta} om_tax WHERE om_tax.{$meta_order_id}={$order_id} AND om_tax.meta_key IN ({$tax_keys}) AND NULLIF(TRIM(om_tax.meta_value), '') IS NOT NULL)",
        );
    }

    private static function receipt_list_order_by(string $sort_by, string $sort_order): string {
        $fields = self::receipt_sort_fields();
        return $fields[$sort_by] . ' ' . $sort_order . ', r.id ' . $sort_order;
    }

    private static function pending_list_order_by(string $sort_by, string $sort_order): string {
        $fields = array(
            'receipt_number' => 'order_id',
            'course_date' => 'sort_course_date',
            'heading' => 'sort_heading',
            'amount' => 'sort_amount',
            'type_status' => 'sort_type_status',
        );
        return $fields[$sort_by] . ' ' . $sort_order . ', order_id ' . $sort_order;
    }

    private static function order_status_table(): array {
        global $wpdb;
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return array('table' => $wpdb->prefix . 'wc_orders', 'id' => 'id', 'status' => 'status', 'hpos' => true);
        }
        return array('table' => $wpdb->posts, 'id' => 'ID', 'status' => 'post_status', 'hpos' => false);
    }

    public static function generate_receipt(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        $order_ids = self::normalise_ids($payload['order_ids'] ?? ($payload['order_id'] ?? array()));
        if (count($order_ids) !== 1) {
            return new WP_Error('tpma_receipt_order_required', '一次生成收據請指定一筆訂單。', array('status' => 400));
        }
        $result = TPMA_CR_Receipt_Service::generate_for_order($order_ids[0]);
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($result)));
    }

    public static function merge_receipts(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        $result = TPMA_CR_Receipt_Service::merge_orders(self::normalise_ids($payload['order_ids'] ?? array()));
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($result)));
    }

    public static function regenerate_receipt(WP_REST_Request $request) {
        $result = TPMA_CR_Receipt_Service::regenerate(absint($request['receipt_id']));
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($result)));
    }

    public static function change_receipt_type(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        $result = TPMA_CR_Receipt_Service::change_receipt_type(absint($request['receipt_id']), $payload['receipt_type'] ?? '');
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($result)));
    }

    public static function void_receipt(WP_REST_Request $request) {
        $result = TPMA_CR_Receipt_Service::void_receipt(absint($request['receipt_id']));
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($result)));
    }

    public static function upload_scan(WP_REST_Request $request) {
        $files = $request->get_file_params();
        $file = $files['scan'] ?? ($files['receipt_scan'] ?? null);
        if (!is_array($file)) {
            return new WP_Error('tpma_receipt_scan_required', '請選擇紙本掃描檔（PDF、JPG 或 PNG）。', array('status' => 400));
        }
        $result = TPMA_CR_Receipt_Service::upload_scanned_file(absint($request['receipt_id']), $file);
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($result)));
    }

    public static function send_receipt(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        $result = self::send_receipt_by_id(absint($request['receipt_id']), rest_sanitize_boolean($payload['force'] ?? false));
        if (is_wp_error($result)) {
            return self::rest_service_error($result);
        }
        return rest_ensure_response(array(
            'success' => true,
            'receipt' => self::receipt_payload($result['receipt']),
            'mail_result' => $result['mail_result'],
        ));
    }

    public static function stream_rest_file(WP_REST_Request $request) {
        $receipt_id = absint($request['receipt_id']);
        $download = rest_sanitize_boolean($request->get_param('download'));
        return self::stream_receipt($receipt_id, $download);
    }

    public static function bulk_action(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        $action = sanitize_key((string) ($payload['action'] ?? ''));

        if ($action === 'generate') {
            return self::bulk_generate(self::normalise_ids($payload['order_ids'] ?? array()));
        }
        if ($action === 'regenerate') {
            return self::bulk_regenerate(self::normalise_ids($payload['receipt_ids'] ?? array()));
        }
        if ($action === 'change_type') {
            return self::bulk_change_type(self::normalise_ids($payload['receipt_ids'] ?? array()), $payload['receipt_type'] ?? '');
        }
        if ($action === 'void') {
            return self::bulk_void(self::normalise_ids($payload['receipt_ids'] ?? array()));
        }
        if ($action === 'merge') {
            return self::bulk_merge(
                self::normalise_ids($payload['order_ids'] ?? array()),
                self::normalise_ids($payload['receipt_ids'] ?? array())
            );
        }
        if ($action === 'send') {
            return self::bulk_send(self::normalise_ids($payload['receipt_ids'] ?? array()));
        }
        if ($action === 'print') {
            return self::stream_batch_pdf(self::normalise_ids($payload['receipt_ids'] ?? array()), false);
        }
        if ($action === 'download') {
            return self::stream_batch_pdf(self::normalise_ids($payload['receipt_ids'] ?? array()), true);
        }

        return new WP_Error('tpma_receipt_bulk_action_invalid', '不支援的收據批次動作。', array('status' => 400));
    }

    public static function add_meta_box(): void {
        foreach (self::get_order_screen_ids() as $screen_id) {
            add_meta_box('tpma_cr_receipt', 'TPMA 收據', array(__CLASS__, 'render_meta_box'), $screen_id, 'side', 'default');
        }
    }

    /** @param WP_Post|WC_Order $post_or_order */
    public static function render_meta_box($post_or_order): void {
        $order = self::order_from_context($post_or_order);
        if (!$order instanceof WC_Order) {
            echo '<p>找不到訂單。</p>';
            return;
        }
        if (!self::can_manage()) {
            echo '<p>權限不足。</p>';
            return;
        }

        $order_id = (int) $order->get_id();
        $receipt = TPMA_CR_Receipt_Service::get_receipt_for_order($order_id, true);
        echo '<div class="tpma-cr-receipt-meta">';
        if (!$receipt) {
            echo '<p>尚未開立收據。</p>';
            self::render_action_form($order_id, 'generate', '生成收據', 'button button-primary');
            echo '</div>';
            return;
        }

        echo '<p><strong>' . esc_html((string) $receipt['serial']) . '</strong></p>';
        echo '<p>方式：' . esc_html(self::receipt_type_label((string) $receipt['receipt_type'])) . '<br>狀態：' . esc_html(self::receipt_status_label((string) $receipt['status'])) . '</p>';
        echo '<p>來源訂單：' . esc_html(implode('、', array_map(static function ($id) { return '#' . (int) $id; }, $receipt['order_ids']))) . '</p>';

        $file = TPMA_CR_Receipt_Service::get_preview_file((int) $receipt['id']);
        if (!is_wp_error($file)) {
            echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url(self::stream_url((int) $receipt['id'], false)) . '">預覽</a> ';
            echo '<a class="button" href="' . esc_url(self::stream_url((int) $receipt['id'], true)) . '">下載</a></p>';
        } elseif ($receipt['receipt_type'] === 'paper') {
            echo '<p><em>紙本收據尚待上傳加蓋掃描檔。</em></p>';
        }

        if ($receipt['status'] !== TPMA_CR_Receipt_Service::STATUS_VOID) {
            self::render_action_form($order_id, 'regenerate', '重新生成', 'button', (int) $receipt['id']);
            if ($receipt['receipt_type'] === 'paper') {
                self::render_scan_form($order_id, (int) $receipt['id']);
            }
            self::render_action_form($order_id, 'send', '寄發收據', 'button', (int) $receipt['id']);
        }
        echo '</div>';
    }

    public static function handle_order_action(): void {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ($order_id <= 0) {
            wp_die('缺少訂單 ID。', '收據操作錯誤', array('response' => 400));
        }
        check_admin_referer(self::nonce_action($order_id));
        if (!self::can_manage() || !self::get_order($order_id)) {
            wp_die('權限不足或訂單不存在。', '收據操作錯誤', array('response' => 403));
        }

        $operation = sanitize_key((string) ($_POST['receipt_operation'] ?? ''));
        $receipt_id = isset($_POST['receipt_id']) ? absint($_POST['receipt_id']) : 0;
        $result = null;
        if ($operation === 'generate') {
            $result = TPMA_CR_Receipt_Service::generate_for_order($order_id);
        } elseif ($operation === 'regenerate') {
            $receipt = self::receipt_for_order_or_error($receipt_id, $order_id);
            $result = is_wp_error($receipt) ? $receipt : TPMA_CR_Receipt_Service::regenerate((int) $receipt['id']);
        } elseif ($operation === 'upload_scan') {
            $receipt = self::receipt_for_order_or_error($receipt_id, $order_id);
            $file = $_FILES['receipt_scan'] ?? null;
            $result = is_wp_error($receipt) ? $receipt : (is_array($file)
                ? TPMA_CR_Receipt_Service::upload_scanned_file((int) $receipt['id'], $file)
                : new WP_Error('tpma_receipt_scan_required', '請選擇掃描檔。'));
        } elseif ($operation === 'send') {
            $receipt = self::receipt_for_order_or_error($receipt_id, $order_id);
            if (!is_wp_error($receipt)) {
                $sent = self::send_receipt_by_id((int) $receipt['id']);
                $result = is_wp_error($sent) ? $sent : $sent['receipt'];
            } else {
                $result = $receipt;
            }
        } else {
            $result = new WP_Error('tpma_receipt_operation_invalid', '不支援的收據操作。');
        }

        if (is_wp_error($result)) {
            self::set_notice('error', $result->get_error_message());
        } else {
            self::set_notice('success', '收據操作完成：' . (string) ($result['serial'] ?? ''));
        }
        wp_safe_redirect(self::back_url($order_id));
        exit;
    }

    public static function handle_stream(): void {
        $receipt_id = isset($_GET['receipt_id']) ? absint($_GET['receipt_id']) : 0;
        if ($receipt_id <= 0) {
            wp_die('缺少收據 ID。', '收據檔案錯誤', array('response' => 400));
        }
        check_admin_referer(self::stream_nonce_action($receipt_id));
        if (!self::can_manage()) {
            wp_die('權限不足。', '收據檔案錯誤', array('response' => 403));
        }
        self::stream_receipt($receipt_id, !empty($_GET['download']));
    }

    public static function render_admin_notice(): void {
        if (!self::can_manage()) {
            return;
        }
        $key = self::notice_key();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return;
        }
        delete_transient($key);
        $class = ($notice['type'] ?? '') === 'success' ? 'notice notice-success is-dismissible' : 'notice notice-error';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html((string) ($notice['message'] ?? '收據操作完成。')) . '</p></div>';
    }

    /**
     * Sends a receipt once, regardless of how many source orders it contains.
     * The dispatcher performs the final attachment, type/status, and recipient checks.
     */
    private static function send_receipt_by_id(int $receipt_id, bool $force = false) {
        if (!class_exists('TPMA_CR_Mail_Dispatcher')) {
            return new WP_Error('tpma_receipt_mailer_unavailable', '收據寄件模組尚未載入。');
        }
        $receipt = TPMA_CR_Receipt_Service::get_receipt($receipt_id);
        if (!is_array($receipt)) {
            return new WP_Error('tpma_receipt_not_found', '找不到收據。');
        }
        if (($receipt['status'] ?? '') === TPMA_CR_Receipt_Service::STATUS_VOID) {
            return new WP_Error('tpma_receipt_void', '已作廢收據不可寄發。');
        }
        $eligibility = TPMA_CR_Receipt_Service::receipt_send_eligibility_for_receipt($receipt_id);
        if (is_wp_error($eligibility)) {
            return $eligibility;
        }
        foreach ((array) ($receipt['order_ids'] ?? array()) as $order_id) {
            $order = self::get_order((int) $order_id);
            if ($order) {
                $mail_result = TPMA_CR_Mail_Dispatcher::send_receipt_notice($order, array('force' => $force));
                if (!empty($mail_result['sent'])) {
                    return array(
                        'receipt' => TPMA_CR_Receipt_Service::get_receipt($receipt_id),
                        'mail_result' => $mail_result,
                    );
                }
                return self::mail_result_error($mail_result);
            }
        }
        return new WP_Error('tpma_receipt_source_order_missing', '收據沒有可用的來源 WooCommerce 訂單，無法寄發。');
    }

    private static function mail_result_error(array $mail_result): WP_Error {
        foreach ((array) ($mail_result['failed'] ?? array()) as $item) {
            return new WP_Error(
                sanitize_key((string) ($item['reason'] ?? 'tpma_receipt_send_failed')),
                (string) ($item['message'] ?? '收據寄發失敗，未更新寄發狀態。')
            );
        }
        foreach ((array) ($mail_result['skipped'] ?? array()) as $item) {
            return new WP_Error(
                sanitize_key((string) ($item['reason'] ?? 'tpma_receipt_not_sendable')),
                (string) ($item['message'] ?? '此收據目前不符合寄發條件。')
            );
        }
        return new WP_Error('tpma_receipt_send_failed', '收據寄發失敗，未更新寄發狀態。');
    }

    private static function bulk_generate(array $order_ids) {
        if (!$order_ids) {
            return new WP_Error('tpma_receipt_orders_required', '請選擇至少一筆訂單。', array('status' => 400));
        }
        $result = array('success' => true, 'processed' => count($order_ids), 'generated' => array(), 'failed' => array(), 'skipped' => array());
        foreach ($order_ids as $order_id) {
            $receipt = TPMA_CR_Receipt_Service::get_receipt_for_order($order_id);
            if ($receipt) {
                $result['skipped'][] = array('order_id' => $order_id, 'reason' => 'already_issued', 'message' => '訂單已有有效收據，不可再次生成。');
                continue;
            }
            $created = TPMA_CR_Receipt_Service::generate_for_order($order_id);
            if (is_wp_error($created)) {
                $result['failed'][] = self::error_payload($order_id, $created);
                continue;
            }
            $result['generated'][] = array('order_id' => $order_id, 'receipt' => self::receipt_payload($created), 'existing' => false);
        }
        $result['success'] = empty($result['failed']);
        return rest_ensure_response($result);
    }

    private static function bulk_regenerate(array $receipt_ids) {
        if (!$receipt_ids) {
            return new WP_Error('tpma_receipt_ids_required', '請選擇至少一張收據。', array('status' => 400));
        }
        $result = array('success' => true, 'processed' => count($receipt_ids), 'regenerated' => array(), 'failed' => array(), 'skipped' => array());
        foreach ($receipt_ids as $receipt_id) {
            $existing = TPMA_CR_Receipt_Service::get_receipt($receipt_id);
            if (!$existing) {
                $result['skipped'][] = array('receipt_id' => $receipt_id, 'reason' => 'not_found', 'message' => '找不到收據。');
                continue;
            }
            if (($existing['status'] ?? '') === TPMA_CR_Receipt_Service::STATUS_VOID) {
                $result['skipped'][] = array('receipt_id' => $receipt_id, 'reason' => 'void', 'message' => '已作廢收據不可重新生成。');
                continue;
            }
            $receipt = TPMA_CR_Receipt_Service::regenerate($receipt_id);
            if (is_wp_error($receipt)) {
                $result['failed'][] = self::error_payload($receipt_id, $receipt, 'receipt_id');
                continue;
            }
            $result['regenerated'][] = self::receipt_payload($receipt);
        }
        $result['success'] = empty($result['failed']);
        return rest_ensure_response($result);
    }

    private static function bulk_change_type(array $receipt_ids, $receipt_type) {
        $receipt_type = sanitize_key((string) $receipt_type);
        if (!$receipt_ids) {
            return new WP_Error('tpma_receipt_ids_required', '請選擇至少一張收據。', array('status' => 400));
        }
        if (!in_array($receipt_type, array('electronic', 'paper'), true)) {
            return new WP_Error('tpma_receipt_type_invalid', '請選擇電子或紙本收據。', array('status' => 400));
        }
        $result = array('success' => true, 'processed' => count($receipt_ids), 'changed' => array(), 'failed' => array(), 'skipped' => array());
        foreach ($receipt_ids as $receipt_id) {
            $receipt = TPMA_CR_Receipt_Service::change_receipt_type($receipt_id, $receipt_type);
            if (is_wp_error($receipt)) {
                $result['failed'][] = self::error_payload($receipt_id, $receipt, 'receipt_id');
                continue;
            }
            $result['changed'][] = self::receipt_payload($receipt);
        }
        $result['success'] = empty($result['failed']);
        return rest_ensure_response($result);
    }

    private static function bulk_void(array $receipt_ids) {
        if (!$receipt_ids) {
            return new WP_Error('tpma_receipt_ids_required', '請選擇至少一張收據。', array('status' => 400));
        }
        $result = array('success' => true, 'processed' => count($receipt_ids), 'voided' => array(), 'failed' => array(), 'skipped' => array());
        foreach ($receipt_ids as $receipt_id) {
            $receipt = TPMA_CR_Receipt_Service::void_receipt($receipt_id);
            if (is_wp_error($receipt)) {
                $result['failed'][] = self::error_payload($receipt_id, $receipt, 'receipt_id');
                continue;
            }
            $result['voided'][] = self::receipt_payload($receipt);
        }
        $result['success'] = empty($result['failed']);
        return rest_ensure_response($result);
    }

    private static function bulk_merge(array $order_ids, array $receipt_ids = array()) {
        foreach ($receipt_ids as $receipt_id) {
            $receipt = TPMA_CR_Receipt_Service::get_receipt($receipt_id);
            if (!$receipt) {
                continue;
            }
            $order_ids = array_merge($order_ids, TPMA_CR_Receipt_Service::get_receipt_orders((int) $receipt['id']));
        }
        $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        if (count($order_ids) < 2) {
            return new WP_Error('tpma_receipt_merge_requires_multiple_orders', '合併開立至少需選擇兩筆不同的訂單。', array('status' => 400));
        }
        $receipt = TPMA_CR_Receipt_Service::merge_orders($order_ids);
        if (is_wp_error($receipt)) {
            return self::rest_service_error($receipt);
        }
        return rest_ensure_response(array('success' => true, 'receipt' => self::receipt_payload($receipt), 'skipped' => array()));
    }

    private static function bulk_send(array $receipt_ids) {
        if (!$receipt_ids) {
            return new WP_Error('tpma_receipt_ids_required', '請選擇至少一張收據。', array('status' => 400));
        }
        $result = array('success' => true, 'processed' => count($receipt_ids), 'sent' => array(), 'failed' => array(), 'skipped' => array());
        foreach ($receipt_ids as $receipt_id) {
            $sent = self::send_receipt_by_id($receipt_id);
            if (is_wp_error($sent)) {
                $result['failed'][] = self::error_payload($receipt_id, $sent, 'receipt_id');
                continue;
            }
            $result['sent'][] = self::receipt_payload($sent['receipt']);
        }
        $result['success'] = empty($result['failed']);
        return rest_ensure_response($result);
    }

    /**
     * Streams a merged batch of receipt PDFs. Management printing and download
     * may use the generated, data-filled paper PDF before a scan is uploaded.
     * Mail attachment eligibility remains stricter in the receipt service.
     */
    private static function stream_batch_pdf(array $receipt_ids, bool $download) {
        if (!$receipt_ids) {
            return new WP_Error('tpma_receipt_ids_required', $download ? '沒有可下載的收據。' : '沒有可列印的收據。', array('status' => 400));
        }
        if (!function_exists('tpma_mpdf_create')) {
            return new WP_Error('tpma_receipt_mpdf_unavailable', 'TPMA mPDF Service 尚未啟用。', array('status' => 503));
        }
        $mpdf = tpma_mpdf_create(array('format' => 'A5-L', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0));
        if (is_wp_error($mpdf)) {
            return self::rest_service_error($mpdf, 503);
        }
        $included = 0;
        $skipped = 0;
        foreach ($receipt_ids as $receipt_id) {
            $file = TPMA_CR_Receipt_Service::get_preview_file($receipt_id);
            if (is_wp_error($file)) {
                $skipped++;
                continue;
            }
            try {
                $pages = (int) $mpdf->SetSourceFile($file);
                for ($page = 1; $page <= $pages; $page++) {
                    $mpdf->AddPage('L');
                    $template = $mpdf->ImportPage($page);
                    $mpdf->UseTemplate($template, 0, 0, $mpdf->w, $mpdf->h);
                    $included++;
                }
            } catch (Throwable $e) {
                return new WP_Error('tpma_receipt_batch_print_failed', ($download ? '合併下載失敗：' : '合併列印失敗：') . $e->getMessage(), array('status' => 500));
            }
        }
        if ($included === 0) {
            return new WP_Error('tpma_receipt_file_unavailable', $download ? '所選收據尚無可下載的檔案。' : '所選收據尚無可列印的有效檔案。', array('status' => 400));
        }
        try {
            $destination = class_exists('\\Mpdf\\Output\\Destination') ? \Mpdf\Output\Destination::STRING_RETURN : 'S';
            $pdf = $mpdf->Output('', $destination);
        } catch (Throwable $e) {
            return new WP_Error('tpma_receipt_batch_print_failed', ($download ? '輸出合併下載檔案失敗：' : '輸出合併收據失敗：') . $e->getMessage(), array('status' => 500));
        }
        header('X-TPMA-Receipt-Skipped: ' . $skipped);
        self::stream_pdf((string) $pdf, 'tpma-receipts-' . wp_date('Ymd-His') . '.pdf', $download);
    }

    private static function stream_receipt(int $receipt_id, bool $download) {
        $receipt = TPMA_CR_Receipt_Service::get_receipt($receipt_id);
        if (!$receipt) {
            return new WP_Error('tpma_receipt_not_found', '找不到收據。', array('status' => 404));
        }
        $file = TPMA_CR_Receipt_Service::get_preview_file($receipt_id);
        if (is_wp_error($file)) {
            return self::rest_service_error($file);
        }
        $contents = file_get_contents($file);
        if (!is_string($contents) || $contents === '') {
            return new WP_Error('tpma_receipt_file_missing', '無法讀取收據檔案。', array('status' => 404));
        }
        self::stream_pdf($contents, sanitize_file_name((string) $receipt['serial'] . '.pdf'), $download);
    }

    private static function stream_pdf(string $pdf, string $filename, bool $download): void {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function receipt_payload($receipt): ?array {
        if (!is_array($receipt)) {
            return null;
        }
        $id = (int) ($receipt['id'] ?? 0);
        $payload = array(
            'id' => $id,
            'serial' => (string) ($receipt['serial'] ?? ''),
            'receipt_type' => (string) ($receipt['receipt_type'] ?? ''),
            'status' => (string) ($receipt['status'] ?? ''),
            'revision' => (int) ($receipt['revision'] ?? 0),
            'order_ids' => array_values(array_map('intval', (array) ($receipt['order_ids'] ?? array()))),
            'created_at' => (string) ($receipt['created_at'] ?? ''),
            'generated_at' => (string) ($receipt['generated_at'] ?? ''),
            'scanned_at' => (string) ($receipt['scanned_at'] ?? ''),
            'sent_at' => (string) ($receipt['sent_at'] ?? ''),
            'voided_at' => (string) ($receipt['voided_at'] ?? ''),
            'snapshot' => is_array($receipt['snapshot'] ?? null) ? $receipt['snapshot'] : array(),
            'preview_url' => self::rest_file_url($id, false),
            'download_url' => self::rest_file_url($id, true),
        );
        $payload['source_orders'] = self::source_orders($payload['order_ids']);
        return $payload;
    }

    private static function source_orders(array $order_ids): array {
        $orders = array();
        foreach ($order_ids as $order_id) {
            $order = self::get_order($order_id);
            $orders[] = array(
                'id' => (int) $order_id,
                'number' => $order ? (string) $order->get_order_number() : (string) $order_id,
                'status' => $order ? (string) $order->get_status() : 'missing',
            );
        }
        return $orders;
    }

    private static function receipt_list_item(array $receipt, array $context): array {
        $order_ids = array_values(array_map('intval', (array) ($receipt['order_ids'] ?? array())));
        $snapshot = is_array($receipt['snapshot'] ?? null) ? $receipt['snapshot'] : array();
        $identity = self::receipt_identity($snapshot, $order_ids, $context);
        $file = TPMA_CR_Receipt_Service::get_preview_file((int) $receipt['id']);
        return array(
            'kind' => 'receipt',
            'id' => (int) $receipt['id'],
            'receipt_id' => (int) $receipt['id'],
            'serial' => (string) ($receipt['serial'] ?? ''),
            'preview_url' => is_wp_error($file) ? '' : self::rest_file_url((int) $receipt['id'], false),
            'download_url' => is_wp_error($file) ? '' : self::rest_file_url((int) $receipt['id'], true),
            'order_ids' => $order_ids,
            'orders' => self::list_orders($order_ids, $context['orders']),
            'courses' => self::courses_from_context($order_ids, $context['courses']),
            'heading' => $identity,
            'amount' => (int) round((float) ($snapshot['amount'] ?? 0)),
            'amount_formatted' => number_format((int) round((float) ($snapshot['amount'] ?? 0))),
            'receipt_type' => (string) ($receipt['receipt_type'] ?? 'electronic'),
            'status' => (string) ($receipt['status'] ?? 'pending'),
            'display_status' => self::compact_status_label((string) ($receipt['status'] ?? 'pending')),
            'display_type' => self::receipt_type_label((string) ($receipt['receipt_type'] ?? 'electronic')),
            'updated_at' => (string) ($receipt['updated_at'] ?? ''),
        );
    }

    private static function pending_list_item(int $order_id, string $receipt_type, $order, array $context): array {
        $identity = self::order_identity_from_profile($context['profiles'][$order_id] ?? array(), $order);
        return array(
            'kind' => 'pending',
            'id' => 'order-' . $order_id,
            'order_id' => $order_id,
            'serial' => '尚未開立',
            'preview_url' => '',
            'order_ids' => array($order_id),
            'orders' => self::list_orders(array($order_id), $context['orders']),
            'courses' => self::courses_from_context(array($order_id), $context['courses']),
            'heading' => $identity,
            'amount' => (int) round((float) $order->get_total()),
            'amount_formatted' => number_format((int) round((float) $order->get_total())),
            'receipt_type' => $receipt_type,
            'status' => 'pending',
            'display_status' => self::compact_status_label('pending'),
            'display_type' => self::receipt_type_label($receipt_type),
            'updated_at' => ($modified = $order->get_date_modified()) ? $modified->date('Y-m-d H:i:s') : '',
        );
    }

    private static function list_context(array $order_ids): array {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        return array(
            'orders' => self::orders_by_id($order_ids),
            'profiles' => self::registration_profiles_by_order($order_ids),
            'courses' => self::courses_by_order($order_ids),
        );
    }

    private static function orders_by_id(array $order_ids): array {
        $orders = array();
        if ($order_ids && function_exists('wc_get_orders')) {
            $loaded = wc_get_orders(array('include' => $order_ids, 'limit' => count($order_ids), 'return' => 'objects'));
            foreach ((array) $loaded as $order) {
                if ($order instanceof WC_Order) {
                    $orders[(int) $order->get_id()] = $order;
                }
            }
        }
        foreach ($order_ids as $order_id) {
            if (isset($orders[$order_id])) {
                continue;
            }
            $order = self::get_order($order_id);
            if ($order) {
                $orders[$order_id] = $order;
            }
        }
        return $orders;
    }

    private static function registration_profiles_by_order(array $order_ids): array {
        if (!$order_ids) {
            return array();
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, woocommerce_order_id, company_name, tax_id, contact_name FROM ' . TPMA_CR_DB::table('regs') . ' WHERE woocommerce_order_id IN (' . $placeholders . ') ORDER BY woocommerce_order_id ASC, id ASC', $order_ids), ARRAY_A);
        $profiles = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row['woocommerce_order_id'];
            if (!isset($profiles[$id])) {
                $profiles[$id] = $row;
            }
            if (empty($profiles[$id]['contact_name']) && !empty($row['contact_name'])) {
                $profiles[$id]['contact_name'] = $row['contact_name'];
            }
            if (empty($profiles[$id]['company_name']) && !empty($row['company_name'])) {
                $profiles[$id]['company_name'] = $row['company_name'];
            }
            if (empty($profiles[$id]['tax_id']) && !empty($row['tax_id'])) {
                $profiles[$id]['tax_id'] = $row['tax_id'];
            }
        }
        return $profiles;
    }

    private static function courses_by_order(array $order_ids): array {
        $courses = array();
        foreach (self::courses_for_orders($order_ids) as $course) {
            $courses[(int) $course['order_id']][] = $course;
        }
        return $courses;
    }

    private static function courses_from_context(array $order_ids, array $courses): array {
        $items = array();
        foreach ($order_ids as $order_id) {
            foreach ((array) ($courses[(int) $order_id] ?? array()) as $course) {
                $items[] = $course;
            }
        }
        return $items ?: array(array('order_id' => (int) reset($order_ids), 'name' => '—', 'date' => '—'));
    }

    private static function list_orders(array $order_ids, array $order_map = array()): array {
        $items = array();
        foreach ($order_ids as $order_id) {
            $order = $order_map[(int) $order_id] ?? self::get_order((int) $order_id);
            $items[] = array(
                'id' => (int) $order_id,
                'number' => $order ? (string) $order->get_order_number() : (string) $order_id,
                'edit_url' => self::order_edit_url((int) $order_id),
            );
        }
        return $items;
    }

    /** @return array<int,array{order_id:int,name:string,date:string}> */
    private static function courses_for_orders(array $order_ids): array {
        if (!$order_ids) {
            return array();
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $sql = 'SELECT r.woocommerce_order_id AS order_id, c.course_name, r.class_date, s.session_datetime
            FROM ' . TPMA_CR_DB::table('regs') . ' r
            LEFT JOIN ' . TPMA_CR_DB::table('courses') . ' c ON c.id=r.course_id
            LEFT JOIN ' . TPMA_CR_DB::table('sessions') . ' s ON s.id=r.session_id
            WHERE r.woocommerce_order_id IN (' . $placeholders . ')
            ORDER BY r.woocommerce_order_id ASC, r.id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_values($order_ids)), ARRAY_A);
        $items = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            $date = !empty($row['session_datetime']) ? substr((string) $row['session_datetime'], 0, 10) : (string) ($row['class_date'] ?? '');
            $name = trim((string) ($row['course_name'] ?? ''));
            $key = (int) $row['order_id'] . '|' . $name . '|' . $date;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = array('order_id' => (int) $row['order_id'], 'name' => $name !== '' ? $name : '—', 'date' => $date !== '' ? $date : '—');
        }
        return $items ?: array(array('order_id' => (int) reset($order_ids), 'name' => '—', 'date' => '—'));
    }

    /** Never treat the personal payer name as a company title. */
    private static function receipt_identity(array $snapshot, array $order_ids, array $context = array()): array {
        $company = trim((string) ($snapshot['company_name'] ?? ''));
        $tax_id = trim((string) ($snapshot['tax_id'] ?? ''));
        $contact = trim((string) ($snapshot['contact_name'] ?? ''));
        if ($company !== '' && $tax_id !== '') {
            return array('kind' => 'company', 'company_name' => $company, 'tax_id' => $tax_id, 'contact_name' => '');
        }
        if ($contact !== '') {
            return array('kind' => 'contact', 'company_name' => '', 'tax_id' => '', 'contact_name' => $contact);
        }
        // Receipt identity must remain an issued-record snapshot. Old rows did
        // not persist a contact_name, so only that one display value may fall
        // back to the current order; company and tax data never do.
        if (!array_key_exists('contact_name', $snapshot)) {
            $first_id = $order_ids ? (int) $order_ids[0] : 0;
            $profile = $context['profiles'][$first_id] ?? array();
            $order = $context['orders'][$first_id] ?? self::get_order($first_id);
            return array('kind' => 'contact', 'company_name' => '', 'tax_id' => '', 'contact_name' => self::current_order_contact_name($first_id, $order, $profile));
        }
        return array('kind' => 'contact', 'company_name' => '', 'tax_id' => '', 'contact_name' => '—');
    }

    private static function current_order_contact_name(int $order_id, $order, array $profile = array()): string {
        global $wpdb;
        $contact = trim((string) ($profile['contact_name'] ?? ''));
        if ($contact === '' && !$profile) {
            $contact = trim((string) $wpdb->get_var($wpdb->prepare(
                'SELECT contact_name FROM ' . TPMA_CR_DB::table('regs') . ' WHERE woocommerce_order_id=%d AND contact_name<>\'\' ORDER BY id ASC LIMIT 1',
                $order_id
            )));
        }
        if ($contact === '' && $order instanceof WC_Order) {
            $contact = trim((string) $order->get_formatted_billing_full_name());
        }
        return $contact !== '' ? $contact : '—';
    }

    private static function order_identity_from_profile(array $profile, $order): array {
        $company = '';
        $tax_id = '';
        if ($order instanceof WC_Order) {
            $company = trim((string) $order->get_billing_company());
            foreach (array('_billing_vat_id', '_opay_tax_id', '_tpma_tax_id', 'billing_tax_id') as $key) {
                $tax_id = trim((string) $order->get_meta($key, true));
                if ($tax_id !== '') {
                    break;
                }
            }
        }
        if ($company !== '' && $tax_id !== '') {
            return array('kind' => 'company', 'company_name' => $company, 'tax_id' => $tax_id, 'contact_name' => '');
        }
        return array('kind' => 'contact', 'company_name' => '', 'tax_id' => '', 'contact_name' => self::current_order_contact_name(0, $order, $profile));
    }

    private static function order_edit_url(int $order_id): string {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        }
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private static function compact_status_label(string $status): string {
        $labels = array('pending' => '待開', 'generated' => '待寄', 'awaiting_scan' => '待掃描', 'scanned' => '待寄', 'sent' => '已寄', 'void' => '作廢');
        return $labels[$status] ?? $status;
    }

    private static function request_payload(WP_REST_Request $request): array {
        $json = $request->get_json_params();
        return is_array($json) ? $json : $request->get_params();
    }

    private static function normalise_ids($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw);
        }
        if (!is_array($raw)) {
            $raw = array($raw);
        }
        return array_values(array_unique(array_filter(array_map('absint', $raw))));
    }

    private static function error_payload(int $id, WP_Error $error, string $id_key = 'order_id'): array {
        return array($id_key => $id, 'code' => $error->get_error_code(), 'message' => $error->get_error_message());
    }

    private static function rest_service_error(WP_Error $error, int $default_status = 400): WP_Error {
        $data = $error->get_error_data();
        if (!is_array($data)) {
            $data = array();
        }
        if (empty($data['status'])) {
            $data['status'] = $default_status;
        }
        return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }

    private static function render_action_form(int $order_id, string $operation, string $label, string $class = 'button', int $receipt_id = 0): void {
        $form_id = self::queue_footer_form($order_id, $operation, $receipt_id);
        echo '<button type="submit" form="' . esc_attr($form_id) . '" class="' . esc_attr($class) . '" style="margin:0 4px 6px 0;">' . esc_html($label) . '</button>';
    }

    private static function render_scan_form(int $order_id, int $receipt_id): void {
        $form_id = self::queue_footer_form($order_id, 'upload_scan', $receipt_id, true);
        echo '<div style="margin-top:6px;">';
        echo '<input type="file" form="' . esc_attr($form_id) . '" name="receipt_scan" accept="application/pdf,image/jpeg,image/png" required style="max-width:100%;margin-bottom:4px;">';
        echo '<button type="submit" form="' . esc_attr($form_id) . '" class="button">上傳紙本掃描檔</button>';
        echo '</div>';
    }

    /**
     * HPOS wraps the order editor and its meta boxes in WooCommerce's own form.
     * Forms emitted inside a meta box become invalid nested forms, causing the
     * browser to submit the receipt action instead of the order update. Queue
     * the actual admin-post form for admin_footer and associate controls by id.
     */
    private static function queue_footer_form(int $order_id, string $operation, int $receipt_id = 0, bool $multipart = false): string {
        $form_id = 'tpma-cr-receipt-form-' . $order_id . '-' . sanitize_key($operation) . ($receipt_id > 0 ? '-' . $receipt_id : '');
        if (!isset(self::$footer_forms[$form_id])) {
            self::$footer_forms[$form_id] = array(
                'order_id' => $order_id,
                'operation' => $operation,
                'receipt_id' => $receipt_id,
                'multipart' => $multipart,
            );
        }
        if (!self::$footer_forms_hooked) {
            self::$footer_forms_hooked = true;
            add_action('admin_footer', array(__CLASS__, 'render_footer_forms'));
        }
        return $form_id;
    }

    /** Render queued admin-post forms outside the WooCommerce order edit form. */
    public static function render_footer_forms(): void {
        foreach (self::$footer_forms as $form_id => $form) {
            $order_id = (int) $form['order_id'];
            $receipt_id = (int) $form['receipt_id'];
            echo '<form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . (!empty($form['multipart']) ? ' enctype="multipart/form-data"' : '') . ' style="display:none;">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '">';
            echo '<input type="hidden" name="receipt_operation" value="' . esc_attr((string) $form['operation']) . '">';
            if ($receipt_id > 0) {
                echo '<input type="hidden" name="receipt_id" value="' . esc_attr((string) $receipt_id) . '">';
            }
            wp_nonce_field(self::nonce_action($order_id));
            echo '</form>';
        }
    }

    private static function order_from_context($post_or_order) {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }
        $order_id = is_object($post_or_order) && isset($post_or_order->ID) ? absint($post_or_order->ID) : 0;
        if ($order_id <= 0) {
            $order_id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        }
        return self::get_order($order_id);
    }

    private static function get_order(int $order_id) {
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order($order_id);
        return $order instanceof WC_Order ? $order : null;
    }

    private static function receipt_for_order_or_error(int $receipt_id, int $order_id) {
        $receipt = TPMA_CR_Receipt_Service::get_receipt($receipt_id);
        if (!$receipt || !in_array($order_id, (array) $receipt['order_ids'], true)) {
            return new WP_Error('tpma_receipt_order_mismatch', '收據與訂單不相符。');
        }
        return $receipt;
    }

    private static function get_order_screen_ids(): array {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        return array_values(array_unique(array_filter($screens)));
    }

    private static function rest_file_url(int $receipt_id, bool $download): string {
        return add_query_arg(array(
            'download' => $download ? '1' : '0',
            '_wpnonce' => wp_create_nonce('wp_rest'),
        ), rest_url('tpma/v1/admin/receipts/' . $receipt_id . '/file'));
    }

    private static function stream_url(int $receipt_id, bool $download): string {
        return wp_nonce_url(add_query_arg(array(
            'action' => self::STREAM_ACTION,
            'receipt_id' => $receipt_id,
            'download' => $download ? '1' : '0',
        ), admin_url('admin-post.php')), self::stream_nonce_action($receipt_id));
    }

    private static function nonce_action(int $order_id): string {
        return self::ACTION . '_' . $order_id;
    }

    private static function stream_nonce_action(int $receipt_id): string {
        return self::STREAM_ACTION . '_' . $receipt_id;
    }

    private static function set_notice(string $type, string $message): void {
        set_transient(self::notice_key(), array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private static function notice_key(): string {
        return 'tpma_cr_receipt_notice_' . get_current_user_id();
    }

    private static function back_url(int $order_id): string {
        $referer = wp_get_referer();
        return $referer ? $referer : admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private static function receipt_type_label(string $type): string {
        return $type === 'paper' ? '紙本' : '電子';
    }

    private static function receipt_status_label(string $status): string {
        return self::compact_status_label($status);
    }
}
