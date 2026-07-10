<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_REST_Admin
{
    public static function register_routes()
    {
        $ns = 'tpma/v1';

        // 報名管理
        register_rest_route($ns, '/admin/registrations', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'admin_get_regs'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/registration/update', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_update_reg'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/registrations/bulk', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_bulk_registrations'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        // 課程 / 場次
        register_rest_route($ns, '/admin/courses', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'admin_get_courses'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/course/save', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_save_course'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/course/remove', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_remove_course'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/course/restore', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_restore_course'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/course/merge', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_merge_course'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        // 講師
        register_rest_route($ns, '/admin/lecturers', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'admin_get_lecturers'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/lecturer/save', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_save_lecturer'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/lecturer/remove', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_remove_lecturer'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/lecturer/restore', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_restore_lecturer'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        // 信件模板與設定：限管理員使用
        register_rest_route($ns, '/mail/templates', array(
            'methods'  => 'GET',
            'callback' => is_callable(array('TPMA_Mailer_Admin_API', 'get_mail_templates'))
                ? array('TPMA_Mailer_Admin_API', 'get_mail_templates')
                : array('TPMA_CR_Mail_Dispatcher', 'get_mail_templates'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/mail/templates', array(
            'methods'  => 'POST',
            'callback' => is_callable(array('TPMA_Mailer_Admin_API', 'save_mail_templates'))
                ? array('TPMA_Mailer_Admin_API', 'save_mail_templates')
                : array('TPMA_CR_Mail_Dispatcher', 'save_mail_templates'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/mail/preview', array(
            'methods'  => 'POST',
            'callback' => is_callable(array('TPMA_Mailer_Admin_API', 'preview_mail_template'))
                ? array('TPMA_Mailer_Admin_API', 'preview_mail_template')
                : array('TPMA_CR_Mail_Dispatcher', 'preview_mail_template'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/mail/send-test', array(
            'methods'  => 'POST',
            'callback' => is_callable(array('TPMA_Mailer_Admin_API', 'send_test_mail'))
                ? array('TPMA_Mailer_Admin_API', 'send_test_mail')
                : array('TPMA_CR_Mail_Dispatcher', 'send_test_mail'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        // Tutor LMS integration endpoints
        register_rest_route($ns, '/admin/magic-links', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'admin_get_magic_links'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/magic-links/regenerate', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_regenerate_magic_links'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/tutor/sync-course', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'admin_sync_tutor_course'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route($ns, '/admin/tutor/session/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'admin_tutor_session_status'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route($ns, '/admin/tutor/session/prepare', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'admin_tutor_session_prepare'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route($ns, '/admin/tutor/session/meet', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'admin_tutor_session_meet'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
    }

    public static function can_manage()
    {
        return current_user_can('manage_options');
    }

    /**
     * Normalize currency/amount input to integer (rounding floats) or null when empty.
     */
    private static function normalize_amount($value)
    {
        if ($value === null) {
            return null;
        }
        $raw = is_string($value) ? trim($value) : $value;
        if ($raw === '') {
            return null;
        }
        $clean = preg_replace('/[^\d\.\-]/', '', (string)$raw);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        return (int) round((float)$clean);
    }

    /* ---------- Shortcodes ---------- */

    public static function shortcode_reg_admin()
    {
        if (!self::can_manage()) {
            return '<p>請先登入管理帳號。</p>';
        }
        ob_start();
        include TPMA_CR_PATH . 'views/reg-admin.php';
        return ob_get_clean();
    }

    public static function shortcode_course_admin()
    {
        if (!self::can_manage()) {
            return '<p>請先登入管理帳號。</p>';
        }
        $form_url = function_exists('tpma_cr_get_registration_form_url')
            ? tpma_cr_get_registration_form_url()
            : esc_url_raw(TPMA_CR_URL . 'form.html');
        ob_start();
        include TPMA_CR_PATH . 'views/course-admin.php';
        return ob_get_clean();
    }

    /* ---------- 報名管理 ---------- */

public static function admin_get_regs($request)
{
    global $wpdb;

    $regs_table      = TPMA_CR_DB::table('regs');
    $courses_table   = TPMA_CR_DB::table('courses');
    $lecturers_table = TPMA_CR_DB::table('lecturers');
    $lecturer_display_sql = TPMA_CR_DB::sql_lecturer_display('l');
    $lecturer_join_sql    = TPMA_CR_DB::sql_lecturer_join_on_course('l', 'c');

    // 新增的綜合文字搜尋
    $q = $request->get_param('q');

    // 保留原本的個別欄位（相容舊呼叫）
    $reg_no       = $request->get_param('reg_no');
    $course_name  = $request->get_param('course_name');
    $student_name = $request->get_param('student_name');

    // 新增選單篩選
    $course_id      = intval($request->get_param('course_id'));
    $class_date     = $request->get_param('class_date');
    $receipt_type   = $request->get_param('receipt_type');
    $status         = $request->get_param('status');
    $receipt_status = $request->get_param('receipt_status');
    $payment_status = $request->get_param('payment_status'); // New: payment status filter

    // 日期篩選
    $date_field = $request->get_param('date_field'); // 'created' or 'paid'
    $date_from  = $request->get_param('date_from');
    $date_to    = $request->get_param('date_to');

    $where  = array('1=1');
    $params = array();

    // 文字模糊搜尋：reg_no / student_name / contact_name / company_name
    if ($q) {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where[] = "(r.reg_no LIKE %s OR r.student_name LIKE %s OR r.contact_name LIKE %s OR r.company_name LIKE %s)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // 延續舊參數（若你在別處仍有用到）
    if ($reg_no) {
        $where[]  = "r.reg_no LIKE %s";
        $params[] = '%' . $wpdb->esc_like($reg_no) . '%';
    }

    if ($course_name) {
        $where[]  = "c.course_name LIKE %s";
        $params[] = '%' . $wpdb->esc_like($course_name) . '%';
    }

    if ($student_name) {
        $where[]  = "r.student_name LIKE %s";
        $params[] = '%' . $wpdb->esc_like($student_name) . '%';
    }

    if ($course_id) {
        $where[]  = "r.course_id = %d";
        $params[] = $course_id;
    }

    if ($class_date) {
        $where[]  = "r.class_date = %s";
        $params[] = $class_date;
    }

    if ($receipt_type) {
        $where[]  = "r.receipt_type = %s";
        $params[] = $receipt_type;
    }

    if ($status) {
        $where[]  = "r.status = %s";
        $params[] = $status;
    }

    if ($receipt_status) {
        $where[]  = "r.receipt_status = %s";
        $params[] = $receipt_status;
    }
    
    // New: payment status filter
    if ($payment_status) {
        $where[]  = "r.payment_status = %s";
        $params[] = $payment_status;
    }

    // 日期篩選：預設用 created_at
    $field = ($date_field === 'paid') ? 'r.remit_paid_at' : 'r.created_at';

    if ($date_from) {
        if ($field === 'r.created_at' && strlen($date_from) === 10) {
            // date_from 只有日期 → 自動補 00:00:00
            $where[]  = "{$field} >= %s";
            $params[] = $date_from . ' 00:00:00';
        } else {
            $where[]  = "{$field} >= %s";
            $params[] = $date_from;
        }
    }

    if ($date_to) {
        if ($field === 'r.created_at' && strlen($date_to) === 10) {
            // date_to 只有日期 → 自動補 23:59:59
            $where[]  = "{$field} <= %s";
            $params[] = $date_to . ' 23:59:59';
        } else {
            $where[]  = "{$field} <= %s";
            $params[] = $date_to;
        }
    }

    $sql = "
        SELECT
            r.*,
            c.course_name,
            s.delivery_mode,
            {$lecturer_display_sql} AS lecturer,
            r.woocommerce_order_id,
            r.payment_status
        FROM {$regs_table} r
        LEFT JOIN {$courses_table} c
            ON c.id = r.course_id
        LEFT JOIN " . TPMA_CR_DB::table('sessions') . " s
            ON s.id = r.session_id
        LEFT JOIN {$lecturers_table} l
            ON {$lecturer_join_sql}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.created_at DESC
    ";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, ...$params);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    // Woo 訂單資料由獨立 service 同步，避免 controller 過重
    $rows = TPMA_CR_Admin_Woo_Service::enrich_regs_with_orders($rows);

    return rest_ensure_response($rows);
}



public static function admin_update_reg($request)
{
    global $wpdb;

    $regs_table = TPMA_CR_DB::table('regs');
    $d = $request->get_json_params();

    $id = intval($d['id'] ?? 0);
    if (!$id) {
        return new WP_Error('invalid', '缺少 id', array('status' => 400));
    }

    // 先抓出原始資料，取得 woocommerce_order_id
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id = %d", $id),
        ARRAY_A
    );
    if (!$row) {
        return new WP_Error('not_found', '找不到資料', array('status' => 404));
    }

    $order_id = !empty($row['woocommerce_order_id']) ? (int) $row['woocommerce_order_id'] : 0;
    $order = $order_id ? wc_get_order($order_id) : null;

    // Detect remit_amount change; skip Woo sync if unchanged to avoid locked-order errors.
    if (array_key_exists('remit_amount', $d)) {
        $raw_remit_amount     = is_string($d['remit_amount']) ? trim($d['remit_amount']) : $d['remit_amount'];
        $incoming_remit_amount = self::normalize_amount($raw_remit_amount);
        $current_remit_amount  = ($row['remit_amount'] === null || $row['remit_amount'] === '') ? null : (int) $row['remit_amount'];

        $is_same_amount =
            ($incoming_remit_amount === null && $current_remit_amount === null) ||
            ($incoming_remit_amount !== null && $current_remit_amount !== null && $incoming_remit_amount === $current_remit_amount);

        if ($is_same_amount || $raw_remit_amount === '' || $raw_remit_amount === null) {
            unset($d['remit_amount']);
        } else {
            $d['remit_amount'] = $incoming_remit_amount;
        }
    }

    // TPMA-only 欄位（不含 Woo 專責欄位、金額另行處理）
    $tpma_fields = array(
        'course_id',
        'session_id',
        'class_date',
        'student_name',
        'department',
        'job_title',
        'mobile',
        'emails',
        'access_mode',
        'status',          // 報名狀態
        'receipt_status',
        'test_score',
        'certificate_id',
    );

    $tpma_update = array();
    foreach ($tpma_fields as $f) {
        if (!array_key_exists($f, $d)) {
            continue;
        }

        if ($f === 'course_id') {
            $raw_course_id = $d[$f];
            if ($raw_course_id === 'adjusting' || $raw_course_id === '' || $raw_course_id === null) {
                $tpma_update[$f] = 0;
            } else {
                $course_id = intval($raw_course_id);
                if ($course_id >= 0) {
                    $tpma_update[$f] = $course_id;
                }
            }
            continue;
        }

        if ($f === 'session_id') {
            $session_id = absint($d[$f]);
            if ($session_id <= 0) {
                $tpma_update[$f] = null;
            } else {
                $expected_course = isset($d['course_id']) ? absint($d['course_id']) : (int) $row['course_id'];
                $session = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, course_id, session_datetime FROM " . TPMA_CR_DB::table('sessions') . " WHERE id = %d",
                    $session_id
                ), ARRAY_A);
                if (!$session || (int) $session['course_id'] !== $expected_course) {
                    return new WP_Error('invalid_session', '指定場次不屬於所選課程', array('status' => 400));
                }
                $tpma_update[$f] = $session_id;
                $tpma_update['class_date'] = substr((string) $session['session_datetime'], 0, 10);
            }
            continue;
        }

        if ($f === 'class_date') {
            $class_date = sanitize_text_field($d[$f]);
            if ($class_date === '' || $class_date === 'adjusting') {
                $tpma_update[$f] = null;
            } else {
                $class_date = substr($class_date, 0, 10);
                $tpma_update[$f] = $class_date;
            }
            continue;
        }

        if ($f === 'access_mode') {
            $access_mode = sanitize_key((string)$d[$f]);
            $session_id = isset($d['session_id']) ? absint($d['session_id']) : (int)$row['session_id'];
            $delivery_mode = $session_id > 0 ? (string)$wpdb->get_var($wpdb->prepare(
                "SELECT delivery_mode FROM " . TPMA_CR_DB::table('sessions') . " WHERE id=%d", $session_id
            )) : '';
            $allowed_modes = $delivery_mode === 'hybrid' ? array('live','recorded') : array($delivery_mode ?: 'live');
            if (!in_array($access_mode, $allowed_modes, true)) {
                return new WP_Error('invalid_access_mode', '此場次不提供所選課程型態', array('status' => 400));
            }
            $tpma_update[$f] = $access_mode;
            continue;
        }

        $tpma_update[$f] = sanitize_text_field($d[$f]);
    }

    $has_change = !empty($tpma_update);

    $postpay_order_changed = false;
    if ($order && array_key_exists('status', $tpma_update)) {
        $new_reg_status = (string)$tpma_update['status'];
        $was_postpay = $order->get_meta('_tpma_post_course_payment', true) === 'yes';
        if ($new_reg_status === 'postpay') {
            $postpay_order_changed = true;
            $order->update_meta_data('_tpma_post_course_payment', 'yes');
            if ($order->get_status() !== 'on-hold') $order->set_status('on-hold', 'TPMA 後台標記為課後付款');
            $wpdb->update($regs_table, array('status'=>'postpay','payment_status'=>'on-hold'), array('woocommerce_order_id'=>$order_id), array('%s','%s'), array('%d'));
            $tpma_update['payment_status'] = 'on-hold';
        } elseif ($was_postpay) {
            $postpay_order_changed = true;
            $order->delete_meta_data('_tpma_post_course_payment');
            $wpdb->update($regs_table, array('status'=>$new_reg_status), array('woocommerce_order_id'=>$order_id,'status'=>'postpay'), array('%s'), array('%d','%s'));
        }
        if (class_exists('TPMA_Course_Access')) {
            TPMA_Course_Access::get_or_create_portal_url($order_id, true);
        }
    }

    // Woo 欄位更新透過 Service 統一處理
    $woo_result = TPMA_CR_Admin_Woo_Service::apply_order_updates($order, $d, $regs_table);
    if (is_wp_error($woo_result)) {
        return $woo_result;
    }
    $woo_changed = !empty($woo_result['has_change']) || $postpay_order_changed;
    $has_change = $has_change || $woo_changed;

    if (empty($tpma_update) && !$has_change) {
        return new WP_Error('no_data', '沒有可更新欄位', array('status' => 400));
    }

    if (!empty($tpma_update)) {
        $wpdb->update($regs_table, $tpma_update, array('id' => $id));
        if (class_exists('TPMA_Tutor_Bridge')) {
            if (($tpma_update['status'] ?? '') === 'cancelled') {
                TPMA_Tutor_Bridge::expire_tokens_for_registration($id);
            } elseif (array_key_exists('session_id', $tpma_update)) {
                TPMA_Tutor_Bridge::regenerate_magic_urls_for_reg($id);
            }
        }
    }

    if ($order && !empty($tpma_update)) {
        $raw_class_date = '';
        if (array_key_exists('class_date', $d)) {
            $raw_class_date = is_scalar($d['class_date']) ? sanitize_text_field((string) $d['class_date']) : '';
        }
        $snapshot_result = TPMA_CR_Admin_Woo_Service::sync_registration_snapshot($order, $regs_table, $id, $raw_class_date);
        $woo_changed = $woo_changed || !empty($snapshot_result['has_change']);
    }

    if ($order && $woo_changed) {
        $order->save();
        if ($postpay_order_changed && class_exists('TPMA_Course_Access')) {
            TPMA_Course_Access::maybe_send_access_event_for_order($order_id);
        }
    }

    if ($order && class_exists('TPMA_CR_Mail_Dispatcher')) {
        $reset_fields = array('session_id', 'access_mode', 'status', 'payment_status');
        if (array_intersect($reset_fields, array_keys($tpma_update))) {
            $old_session_id = (int)($row['session_id'] ?? 0);
            $new_session_id = array_key_exists('session_id', $tpma_update) ? (int)$tpma_update['session_id'] : $old_session_id;
            TPMA_CR_Mail_Dispatcher::reset_access_event_meta_for_order($order, $old_session_id);
            if ($new_session_id > 0 && $new_session_id !== $old_session_id) {
                TPMA_CR_Mail_Dispatcher::reset_access_event_meta_for_order($order, $new_session_id);
            }
        }
    }

    return rest_ensure_response(array('success' => true));
}

public static function admin_bulk_registrations($request)
{
    global $wpdb;

    $d = $request->get_json_params();
    if (!is_array($d)) {
        $d = array();
    }

    $ids = array_values(array_unique(array_filter(array_map('absint', (array)($d['ids'] ?? array())))));
    $action = sanitize_key((string)($d['action'] ?? ''));
    $field = sanitize_key((string)($d['field'] ?? ''));
    $event_key = sanitize_key((string)($d['event_key'] ?? ''));
    $value = $d['value'] ?? '';
    $force = !empty($d['force']);

    if (empty($ids)) {
        return new WP_Error('invalid_ids', '請先選擇學員', array('status' => 400));
    }

    $result = self::empty_bulk_result();
    if ($action === 'update_field') {
        $allowed_fields = array('status', 'access_mode', 'receipt_status', 'receipt_type', 'remit_paid_at');
        if (!in_array($field, $allowed_fields, true)) {
            return new WP_Error('invalid_field', '不支援的批次欄位', array('status' => 400));
        }
        if ($value === '' || $value === null) {
            return new WP_Error('missing_value', '缺少批次套用值', array('status' => 400));
        }
        $result = self::bulk_update_field($ids, $field, $value);
    } elseif ($action === 'send_course_mail') {
        $allowed_events = array('course_access', 'pre_class_reminder', 'recorded_course_opened', 'certificate_ready', 'receipt_notice');
        if (!in_array($event_key, $allowed_events, true)) {
            return new WP_Error('invalid_event', '不支援的寄件事件', array('status' => 400));
        }
        $result = self::bulk_send_mail($ids, $event_key, $force);
    } elseif ($action === 'reset_course_mail_meta') {
        $result = self::bulk_reset_course_mail_meta($ids, $event_key);
    } else {
        return new WP_Error('invalid_action', '不支援的批次動作', array('status' => 400));
    }

    $result['success'] = empty($result['failed']);
    return rest_ensure_response($result);
}

private static function empty_bulk_result(): array
{
    return array(
        'success'   => true,
        'processed' => 0,
        'updated'   => 0,
        'sent'      => 0,
        'skipped'   => array(),
        'failed'    => array(),
    );
}

private static function bulk_add_skip(array $result, $id, string $reason, string $message = ''): array
{
    $result['skipped'][] = array(
        'id' => $id,
        'reason' => $reason,
        'message' => $message !== '' ? $message : $reason,
    );
    return $result;
}

private static function bulk_add_fail(array $result, $id, string $reason, string $message = ''): array
{
    $result['failed'][] = array(
        'id' => $id,
        'reason' => $reason,
        'message' => $message !== '' ? $message : $reason,
    );
    return $result;
}

private static function merge_bulk_result(array $base, array $part): array
{
    foreach (array('processed', 'updated', 'sent') as $key) {
        $base[$key] += (int)($part[$key] ?? 0);
    }
    foreach (array('skipped', 'failed') as $key) {
        if (!empty($part[$key]) && is_array($part[$key])) {
            $base[$key] = array_merge($base[$key], $part[$key]);
        }
    }
    return $base;
}

private static function get_registration_rows(array $ids): array
{
    global $wpdb;
    if (empty($ids)) {
        return array();
    }
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $sql = $wpdb->prepare(
        "SELECT * FROM " . TPMA_CR_DB::table('regs') . " WHERE id IN ({$placeholders})",
        ...$ids
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    $by_id = array();
    foreach ((array)$rows as $row) {
        $by_id[(int)$row['id']] = $row;
    }
    return $by_id;
}

private static function bulk_update_field(array $ids, string $field, $value): array
{
    global $wpdb;
    $regs_table = TPMA_CR_DB::table('regs');
    $rows = self::get_registration_rows($ids);
    $result = self::empty_bulk_result();
    $result['processed'] = count($ids);
    $orders_to_reset = array();

    foreach ($ids as $id) {
        $row = $rows[$id] ?? null;
        if (!$row) {
            $result = self::bulk_add_skip($result, $id, 'registration_not_found');
            continue;
        }

        $order_id = (int)($row['woocommerce_order_id'] ?? 0);
        $order = $order_id > 0 && function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        try {
            if ($field === 'access_mode') {
                $access_mode = sanitize_key((string)$value);
                $session_id = (int)($row['session_id'] ?? 0);
                $delivery_mode = $session_id > 0 ? (string)$wpdb->get_var($wpdb->prepare(
                    "SELECT delivery_mode FROM " . TPMA_CR_DB::table('sessions') . " WHERE id=%d",
                    $session_id
                )) : '';
                $allowed_modes = $delivery_mode === 'hybrid' ? array('live', 'recorded') : array($delivery_mode ?: 'live');
                if (!in_array($access_mode, $allowed_modes, true)) {
                    $result = self::bulk_add_skip($result, $id, 'invalid_access_mode', '此場次不提供所選課程型態');
                    continue;
                }
                $wpdb->update($regs_table, array('access_mode' => $access_mode), array('id' => $id), array('%s'), array('%d'));
                $result['updated']++;
                if ($order) $orders_to_reset[$order_id][(int)$row['session_id']] = true;
                continue;
            }

            if ($field === 'status') {
                $status = sanitize_key((string)$value);
                if ($status === 'postpay' && $order) {
                    $order->update_meta_data('_tpma_post_course_payment', 'yes');
                    if ($order->get_status() !== 'on-hold') {
                        $order->set_status('on-hold', 'TPMA 後台批次標記為課後付款');
                    }
                    $order->save();
                    $wpdb->update($regs_table, array('status' => 'postpay', 'payment_status' => 'on-hold'), array('woocommerce_order_id' => $order_id), array('%s', '%s'), array('%d'));
                } else {
                    if ($order && $order->get_meta('_tpma_post_course_payment', true) === 'yes' && $status !== 'postpay') {
                        $order->delete_meta_data('_tpma_post_course_payment');
                        $order->save();
                        $wpdb->update($regs_table, array('status' => $status), array('woocommerce_order_id' => $order_id, 'status' => 'postpay'), array('%s'), array('%d', '%s'));
                    } else {
                        $wpdb->update($regs_table, array('status' => $status), array('id' => $id), array('%s'), array('%d'));
                    }
                }
                $result['updated']++;
                if ($order) $orders_to_reset[$order_id][(int)$row['session_id']] = true;
                continue;
            }

            if ($field === 'receipt_status') {
                $wpdb->update($regs_table, array('receipt_status' => sanitize_key((string)$value)), array('id' => $id), array('%s'), array('%d'));
                $result['updated']++;
                continue;
            }

            if ($field === 'receipt_type' || $field === 'remit_paid_at') {
                if (!$order) {
                    $result = self::bulk_add_skip($result, $id, 'order_not_found');
                    continue;
                }
                $payload = array($field => sanitize_text_field((string)$value));
                $woo_result = TPMA_CR_Admin_Woo_Service::apply_order_updates($order, $payload, $regs_table);
                if (is_wp_error($woo_result)) {
                    $result = self::bulk_add_fail($result, $id, $woo_result->get_error_code(), $woo_result->get_error_message());
                    continue;
                }
                $order->save();
                $wpdb->update($regs_table, array($field => sanitize_text_field((string)$value)), array('id' => $id), array('%s'), array('%d'));
                $result['updated']++;
                continue;
            }
        } catch (Throwable $e) {
            $result = self::bulk_add_fail($result, $id, 'exception', $e->getMessage());
        }
    }

    if (class_exists('TPMA_CR_Mail_Dispatcher') && !empty($orders_to_reset) && function_exists('wc_get_order')) {
        foreach ($orders_to_reset as $order_id => $sessions) {
            $order = wc_get_order((int)$order_id);
            if (!$order) {
                continue;
            }
            foreach (array_keys($sessions) as $session_id) {
                TPMA_CR_Mail_Dispatcher::reset_access_event_meta_for_order($order, (int)$session_id);
            }
        }
    }

    return $result;
}

private static function bulk_send_mail(array $ids, string $event_key, bool $force): array
{
    if (!class_exists('TPMA_CR_Mail_Dispatcher') || !function_exists('wc_get_order')) {
        return self::bulk_add_fail(self::empty_bulk_result(), 0, 'mailer_unavailable', '寄件模組未載入');
    }

    $rows = self::get_registration_rows($ids);
    $result = self::empty_bulk_result();
    $orders = array();
    $course_groups = array();

    foreach ($ids as $id) {
        $row = $rows[$id] ?? null;
        if (!$row) {
            $result['processed']++;
            $result = self::bulk_add_skip($result, $id, 'registration_not_found');
            continue;
        }
        $order_id = (int)($row['woocommerce_order_id'] ?? 0);
        if ($order_id <= 0) {
            $result['processed']++;
            $result = self::bulk_add_skip($result, $id, 'order_not_found');
            continue;
        }

        if ($event_key === 'receipt_notice') {
            $orders[$order_id] = true;
            continue;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $result['processed']++;
            $result = self::bulk_add_skip($result, $id, 'order_not_found');
            continue;
        }

        if ($event_key === 'certificate_ready') {
            $part = TPMA_CR_Mail_Dispatcher::send_certificate_email($order, $row, array('force' => $force));
            $result = self::merge_bulk_result($result, $part);
            continue;
        }

        $group_key = $order_id . ':' . (int)($row['session_id'] ?? 0);
        if (in_array($event_key, array('course_access', 'pre_class_reminder', 'recorded_course_opened'), true)) {
            $group_key .= ':' . sanitize_key((string)($row['access_mode'] ?? 'live'));
        }
        if (!isset($course_groups[$group_key])) {
            $course_groups[$group_key] = array('order_id' => $order_id, 'regs' => array());
        }
        $course_groups[$group_key]['regs'][] = $row;
    }

    if ($event_key === 'receipt_notice') {
        foreach (array_keys($orders) as $order_id) {
            $order = wc_get_order((int)$order_id);
            if (!$order) {
                $result = self::bulk_add_skip($result, $order_id, 'order_not_found');
                continue;
            }
            $part = TPMA_CR_Mail_Dispatcher::send_receipt_notice($order, array('force' => $force));
            $result = self::merge_bulk_result($result, $part);
        }
        return $result;
    }

    foreach ($course_groups as $group) {
        $order = wc_get_order((int)$group['order_id']);
        if (!$order) {
            foreach ($group['regs'] as $row) {
                $result['processed']++;
                $result = self::bulk_add_skip($result, (int)$row['id'], 'order_not_found');
            }
            continue;
        }
        $part = TPMA_CR_Mail_Dispatcher::send_course_access_event_for_regs($event_key, $order, $group['regs'], array('force' => $force, 'manual' => true));
        $result = self::merge_bulk_result($result, $part);
    }

    return $result;
}

private static function bulk_reset_course_mail_meta(array $ids, string $event_key = ''): array
{
    $rows = self::get_registration_rows($ids);
    $result = self::empty_bulk_result();
    $result['processed'] = count($ids);
    if (!class_exists('TPMA_CR_Mail_Dispatcher') || !function_exists('wc_get_order')) {
        return self::bulk_add_fail($result, 0, 'dispatcher_unavailable', '寄件模組未載入');
    }

    $seen = array();
    foreach ($ids as $id) {
        $row = $rows[$id] ?? null;
        if (!$row) {
            $result = self::bulk_add_skip($result, $id, 'registration_not_found');
            continue;
        }
        $order_id = (int)($row['woocommerce_order_id'] ?? 0);
        $session_id = (int)($row['session_id'] ?? 0);
        $key = $order_id . ':' . $session_id . ':' . $event_key;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $order = $order_id > 0 ? wc_get_order($order_id) : null;
        if (!$order) {
            $result = self::bulk_add_skip($result, $id, 'order_not_found');
            continue;
        }
        $result['updated'] += TPMA_CR_Mail_Dispatcher::reset_access_event_meta_for_order($order, $session_id, $event_key);
    }

    return $result;
}


    /* ---------- 講師 ---------- */

    public static function admin_get_lecturers($request)
    {
        global $wpdb;

        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $schema = TPMA_CR_DB::get_lecturer_schema();
        $sort_select = $schema['sort_order'] !== ''
            ? $schema['sort_order'] . ' AS sort_order'
            : '0 AS sort_order';
        $sort_order_by = $schema['sort_order'] !== ''
            ? $schema['sort_order'] . ' ASC, '
            : '';

        $rows = $wpdb->get_results("
            SELECT
                id,
                {$schema['code']} AS code,
                {$schema['name']} AS name,
                {$schema['title']} AS title,
                {$sort_select},
                is_active
            FROM {$lecturers_table}
            ORDER BY {$sort_order_by}{$schema['name']} ASC
        ", ARRAY_A);

        return rest_ensure_response($rows);
    }

    public static function admin_save_lecturer($request)
    {
        global $wpdb;

        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $schema = TPMA_CR_DB::get_lecturer_schema();
        $p = $request->get_json_params();

        $id    = intval($p['id'] ?? 0);
        $code  = sanitize_text_field($p['code'] ?? '');
        $name  = sanitize_text_field($p['name'] ?? '');
        $title = sanitize_text_field($p['title'] ?? '');
        $sort  = isset($p['sort_order']) && $p['sort_order'] !== '' ? intval($p['sort_order']) : null;
        $shift = !empty($p['shift_sort']);

        if ($code === '' || $name === '') {
            return new WP_Error('invalid', '講師代碼與姓名為必填', array('status' => 400));
        }

        // 檢查代碼唯一（用 lecturers_code）
        if ($id > 0) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$lecturers_table}
                     WHERE {$schema['code']} = %s AND id != %d",
                    $code,
                    $id
                )
            );
        } else {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$lecturers_table}
                     WHERE {$schema['code']} = %s",
                    $code
                )
            );
        }

        if ($exists) {
            return new WP_Error('duplicate', '講師代碼已存在', array('status' => 400));
        }

        // sort_order 欄位可能不存在於舊 schema
        if ($sort === null) {
            $max = $schema['sort_order'] !== ''
                ? (int) $wpdb->get_var("SELECT MAX({$schema['sort_order']}) FROM {$lecturers_table}")
                : 0;
            $sort = $max + 10;
        }

        // shift_sort：將 >= sort 的講師序往後移
        if ($shift && $sort !== null && $schema['sort_order'] !== '') {
            if ($id > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$lecturers_table}
                         SET {$schema['sort_order']} = {$schema['sort_order']} + 1
                         WHERE {$schema['sort_order']} >= %d AND id != %d",
                        $sort,
                        $id
                    )
                );
            } else {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$lecturers_table}
                         SET {$schema['sort_order']} = {$schema['sort_order']} + 1
                         WHERE {$schema['sort_order']} >= %d",
                        $sort
                    )
                );
            }
        }

        // 寫入資料：欄位用新的，值用前端傳進來的 code/name/title/sort_order
        $data = array(
            $schema['code']  => $code,
            $schema['name']  => $name,
            $schema['title'] => $title,
        );
        if ($schema['sort_order'] !== '') {
            $data[$schema['sort_order']] = $sort;
        }
        // wp_user_id binding (optional — for Tutor instructor mapping)
        if (isset($p['wp_user_id'])) {
            $wp_uid = $p['wp_user_id'] !== '' && $p['wp_user_id'] !== null
                ? absint($p['wp_user_id'])
                : null;
            $data['wp_user_id'] = ($wp_uid && $wp_uid > 0) ? $wp_uid : null;
        }
        if ($id > 0) {
            $wpdb->update($lecturers_table, $data, array('id' => $id));
        } else {
            $wpdb->insert($lecturers_table, $data);
            $id = $wpdb->insert_id;
        }

        // 重新查一次，回傳給前端（同樣用 alias 回 code/name/title/sort_order）
        $lect = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    id,
                    {$schema['code']} AS code,
                    {$schema['name']} AS name,
                    {$schema['title']} AS title,
                    " . ($schema['sort_order'] !== '' ? "{$schema['sort_order']} AS sort_order" : "0 AS sort_order") . ",
                    wp_user_id,
                    is_active
                 FROM {$lecturers_table}
                 WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return rest_ensure_response(array(
            'success'  => true,
            'lecturer' => $lect,
        ));
    }

    public static function admin_remove_lecturer($request)
    {
        global $wpdb;

        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $p = $request->get_json_params();
        $id = intval($p['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('invalid', '缺少講師 id', array('status' => 400));
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(1) FROM {$lecturers_table} WHERE id = %d", $id)
        );
        if (!$exists) {
            return new WP_Error('not_found', '找不到講師', array('status' => 404));
        }

        $ok = $wpdb->update($lecturers_table, array('is_active' => 0), array('id' => $id), array('%d'), array('%d'));
        if ($ok === false) {
            return new WP_Error('db_error', '無法停用講師', array('status' => 500));
        }

        return rest_ensure_response(array('success' => true, 'id' => $id));
    }

    public static function admin_restore_lecturer($request)
    {
        global $wpdb;

        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $p = $request->get_json_params();
        $id = intval($p['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('invalid', '缺少講師 id', array('status' => 400));
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(1) FROM {$lecturers_table} WHERE id = %d", $id)
        );
        if (!$exists) {
            return new WP_Error('not_found', '找不到講師', array('status' => 404));
        }

        $ok = $wpdb->update($lecturers_table, array('is_active' => 1), array('id' => $id), array('%d'), array('%d'));
        if ($ok === false) {
            return new WP_Error('db_error', '無法恢復講師', array('status' => 500));
        }

        return rest_ensure_response(array('success' => true, 'id' => $id));
    }


    /* ---------- 課程 / 場次 ---------- */

    public static function admin_get_courses($request)
    {
        global $wpdb;
        $courses_table   = TPMA_CR_DB::table('courses');
        $sessions_table  = TPMA_CR_DB::table('sessions');
        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $lecturer_display_sql = TPMA_CR_DB::sql_lecturer_display('l');
        $lecturer_join_sql    = TPMA_CR_DB::sql_lecturer_join_on_course('l', 'c');

        $courses = $wpdb->get_results("
            SELECT *
            FROM (
                SELECT
                    c.*,
                    {$lecturer_display_sql} AS lecturer
                FROM {$courses_table} c
                LEFT JOIN {$lecturers_table} l
                    ON {$lecturer_join_sql}
            ) courses_with_lecturer
            ORDER BY id DESC
        ", ARRAY_A);

        if (!$courses) {
            return rest_ensure_response(array());
        }

        $ids = wp_list_pluck($courses, 'id');
        $ids_in = implode(',', array_map('intval', $ids));

        $sessions_map = array();
        if ($ids_in) {
            $sessions = $wpdb->get_results("
                SELECT id, course_id, session_datetime, is_active, visibility_override,
                       tutor_topic_id, tutor_meet_post_id,
                       delivery_mode,
                       recording_available_from, recording_available_until
                FROM {$sessions_table}
                WHERE course_id IN ({$ids_in})
                ORDER BY session_datetime ASC
            ", ARRAY_A);

            foreach ($sessions as $s) {
                $cid = (int)$s['course_id'];
                $topic_course_id = !empty($s['tutor_topic_id']) ? (int) get_post_field('post_parent', (int) $s['tutor_topic_id']) : 0;
                $s['tutor_topic_edit_url'] = $topic_course_id > 0
                    ? admin_url('post.php?post=' . $topic_course_id . '&action=edit')
                    : '';
                if (!isset($sessions_map[$cid])) {
                    $sessions_map[$cid] = array();
                }
                $sessions_map[$cid][] = $s;
            }
        }

        foreach ($courses as &$c) {
            $cid = (int)$c['id'];
            $c['sessions'] = isset($sessions_map[$cid]) ? $sessions_map[$cid] : array();
            // Admin previews render these values as HTML. Sanitize them again at
            // the response boundary so legacy database content is safe to inject.
            $c['intro_rendered'] = wp_kses_post((string) ($c['intro'] ?? ''));
            $c['outline_rendered'] = wp_kses_post((string) ($c['outline'] ?? ''));
            $tutor_id = (int) ($c['tutor_course_id'] ?? 0);
            $c['tutor_enabled'] = class_exists('TPMA_Tutor_Bridge') && TPMA_Tutor_Bridge::is_active();
            $c['tutor_edit_url'] = $tutor_id > 0 ? admin_url('post.php?post=' . $tutor_id . '&action=edit') : '';
            $c['tutor_topic_resources'] = $tutor_id > 0 && class_exists('TPMA_Tutor_Bridge')
                ? TPMA_Tutor_Bridge::get_course_topic_resources($tutor_id)
                : array();
            $c['tutor_content_sync_error'] = $tutor_id > 0
                ? (string) get_post_meta($tutor_id, '_tpma_content_sync_error', true)
                : '';
        }

        return rest_ensure_response($courses);
    }

     public static function admin_save_course($request)
    {   
        global $wpdb;
        $courses_table  = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');
        $regs_table     = TPMA_CR_DB::table('regs');

        $d  = $request->get_json_params();
        $id = intval($d['id'] ?? 0);
        $old_duration = $id > 0 ? (int) $wpdb->get_var($wpdb->prepare("SELECT duration_minutes FROM {$courses_table} WHERE id = %d", $id)) : 0;

        $course_name   = sanitize_text_field($d['course_name'] ?? '');
        $category_code = sanitize_text_field($d['category_code'] ?? '');
        $lecturer_code = sanitize_text_field($d['lecturer_code'] ?? '');
        $category      = sanitize_text_field($d['category'] ?? '');
        $course_code   = sanitize_text_field($d['course_code'] ?? '');
        $is_active_in  = isset($d['is_active']) ? intval($d['is_active']) : 1;
        $is_active     = $is_active_in === 0 ? 0 : 1;
        $duration      = isset($d['duration_minutes']) ? intval($d['duration_minutes']) : 180;
        if ($duration <= 0) {
            $duration = 180;
        }

        // 必填檢查
        if ($course_name === '' || $category_code === '' || $lecturer_code === '') {
            return new WP_Error('invalid', '課程名稱、課程類別、講師為必填', array('status' => 400));
        }
        if (!empty($d['sessions']) && is_array($d['sessions'])) {
            foreach ($d['sessions'] as $incoming_session) {
                $incoming_id = absint($incoming_session['id'] ?? 0);
                if ($id > 0 && $incoming_id > 0 && !(int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(1) FROM {$sessions_table} WHERE id = %d AND course_id = %d",
                    $incoming_id,
                    $id
                ))) {
                    return new WP_Error('invalid_session', '場次不屬於目前課程', array('status' => 400));
                }
                $incoming_from = trim(str_replace('T', ' ', (string) ($incoming_session['recording_available_from'] ?? '')));
                $incoming_until = trim(str_replace('T', ' ', (string) ($incoming_session['recording_available_until'] ?? '')));
                $incoming_mode = sanitize_key((string)($incoming_session['delivery_mode'] ?? 'live'));
                if (!in_array($incoming_mode, array('live','recorded','hybrid'), true)) {
                    return new WP_Error('invalid_delivery_mode', '場次型態必須為直播、錄播或混合', array('status' => 400));
                }
                if (in_array($incoming_mode, array('recorded','hybrid'), true) && ($incoming_from === '' || $incoming_until === '')) {
                    return new WP_Error('recording_window_required', '錄播或混合場次必須設定完整開放起訖', array('status' => 400));
                }
                if ($incoming_from !== '' && $incoming_until !== '' && strtotime($incoming_until) <= strtotime($incoming_from)) {
                    return new WP_Error('invalid_recording_window', '錄播截止時間必須晚於開始時間', array('status' => 400));
                }
            }
        }

        // === 課程編號處理邏輯 ===
        // 新增課程 & 沒有手動填課程編號 → 自動產生「講師碼 + 類別碼 + 2 碼流水號」
        if ($id === 0 && $course_code === '' && $lecturer_code !== '' && $category_code !== '') {
            $prefix = $lecturer_code . $category_code;

            // 找出同 prefix 的既有課程編號
            $like = $wpdb->esc_like($prefix) . '%';
            $existing_codes = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT course_code FROM {$courses_table} WHERE course_code LIKE %s",
                    $like
                )
            );

            $max = 0;
            if ($existing_codes) {
                foreach ($existing_codes as $code) {
                    if (strpos($code, $prefix) !== 0) {
                        continue;
                    }
                    $suffix = substr($code, strlen($prefix));
                    if ($suffix === '' || !ctype_digit($suffix)) {
                        continue;
                    }
                    $n = intval($suffix, 10);
                    if ($n > $max) {
                        $max = $n;
                    }
                }
            }

            $next = $max + 1;

            // 預設使用 2 碼流水號；超過 99 則自然展開成 3 碼以上以避免撞碼
            if ($next <= 99) {
                $course_code = $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT);
            } else {
                $course_code = $prefix . (string)$next;
            }
        }

        // 編輯課程時如果 course_code 沒填，就保留舊的 course_code（避免被重新編號）
        if ($id > 0 && $course_code === '') {
            $old_code = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT course_code FROM {$courses_table} WHERE id = %d",
                    $id
                )
            );
            if ($old_code !== null && $old_code !== '') {
                $course_code = $old_code;
            }
        }

        // 最終還是沒有課程編號就擋掉
        if ($course_code === '') {
            return new WP_Error('invalid_code', '課程編號無法自動產生，請手動輸入課程編號。', array('status' => 400));
        }

        // 課程編號唯一性檢查
        if ($id > 0) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$courses_table}
                     WHERE course_code = %s AND id != %d",
                    $course_code,
                    $id
                )
            );
        } else {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$courses_table}
                     WHERE course_code = %s",
                    $course_code
                )
            );
        }

        if ($exists) {
            return new WP_Error('duplicate_code', '課程編號已存在，請調整後再儲存。', array('status' => 400));
        }

        $intro = wp_kses_post($d['intro'] ?? '');
        $outline = wp_kses_post($d['outline'] ?? '');
        if ($id > 0 && class_exists('TPMA_Tutor_Bridge') && TPMA_Tutor_Bridge::is_active()) {
            $existing_content = $wpdb->get_row($wpdb->prepare(
                "SELECT intro, outline FROM {$courses_table} WHERE id = %d",
                $id
            ), ARRAY_A);
            if ($existing_content) {
                $intro = (string) $existing_content['intro'];
                $outline = (string) $existing_content['outline'];
            }
        }

        // 組合要寫入的課程資料
        $data = array(
            'course_code'      => $course_code,
            'course_name'      => $course_name,
            'category'         => $category,
            'category_code'    => $category_code ?: null,
            'lecturer_code'    => $lecturer_code ?: null,
            'intro'            => $intro,
            'outline'          => $outline,
            'updated_at'       => current_time('mysql'),
            'is_active'        => $is_active,
            'duration_minutes' => $duration,
        );

        // 新增 / 更新課程
        if ($id > 0) {
            $course_id = $id;
            $r = $wpdb->update($courses_table, $data, array('id' => $course_id));
            if ($r === false) {
                return new WP_Error('db_error', '無法更新課程', array('status' => 500));
            }
        } else {
            $r = $wpdb->insert($courses_table, $data);
            if ($r === false) {
                return new WP_Error('db_error', '無法新增課程', array('status' => 500));
            }
            $course_id = intval($wpdb->insert_id);
        }

        // === 處理場次 ===
        $sessions = array();
        if (!empty($d['sessions']) && is_array($d['sessions'])) {
            $sessions = $d['sessions'];
        }

        $existing_sessions = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$sessions_table} WHERE course_id = %d", $course_id),
            OBJECT_K
        );
        $kept_session_ids = array();
        $synced_meet_times = array();
        $seen_new_session_datetimes = array();

        foreach ($sessions as $s) {
            if (empty($s['datetime'])) {
                continue;
            }
            $raw = trim($s['datetime']);
            $session_id = absint($s['id'] ?? 0);
            $visibility_override = sanitize_key($s['visibility_override'] ?? '');
            if (!in_array($visibility_override, array('', 'force_show', 'force_hide'), true)) {
                $visibility_override = '';
            }

            // 從 <input type="datetime-local"> 傳來的格式：YYYY-MM-DDTHH:MM
            $dt = str_replace('T', ' ', $raw);

            // 若沒有秒數，補上 :00
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt)) {
                $dt .= ':00';
            }

            // 非預期格式就跳過，以免寫入壞資料
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dt)) {
                continue;
            }

            if ($session_id <= 0) {
                if (isset($seen_new_session_datetimes[$dt])) {
                    continue;
                }
                $seen_new_session_datetimes[$dt] = true;
                foreach ((array) $existing_sessions as $existing_id => $existing_session) {
                    if ((string) $existing_session->session_datetime === $dt && !in_array((int) $existing_id, $kept_session_ids, true)) {
                        $session_id = (int) $existing_id;
                        break;
                    }
                }
            }

            $normalize_optional_datetime = static function ($value) {
                $value = trim(str_replace('T', ' ', (string) $value));
                if ($value === '') return null;
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) $value .= ':00';
                return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
            };
            $recording_from  = $normalize_optional_datetime($s['recording_available_from'] ?? '');
            $recording_until = $normalize_optional_datetime($s['recording_available_until'] ?? '');
            $delivery_mode = sanitize_key((string)($s['delivery_mode'] ?? 'live'));
            if (!in_array($delivery_mode, array('live','recorded','hybrid'), true)) $delivery_mode = 'live';
            if ($recording_from && $recording_until && strtotime($recording_until) <= strtotime($recording_from)) {
                return new WP_Error('invalid_recording_window', '錄播截止時間必須晚於開始時間', array('status' => 400));
            }

            $session_data = array(
                'session_datetime'         => $dt,
                'is_active'                => isset($s['is_active']) && (int) $s['is_active'] === 0 ? 0 : 1,
                'visibility_override'      => $visibility_override,
                'delivery_mode'            => $delivery_mode,
                'recording_available_from' => $recording_from,
                'recording_available_until'=> $recording_until,
            );

            if ($session_id > 0 && !isset($existing_sessions[$session_id])) {
                return new WP_Error('invalid_session', '場次不屬於目前課程', array('status' => 400));
            }

            if ($session_id > 0 && isset($existing_sessions[$session_id])) {
                $old = $existing_sessions[$session_id];
                $switching_to_recorded = $delivery_mode === 'recorded' && !empty($old->tutor_meet_post_id);
                if (!$switching_to_recorded && ((string) $old->session_datetime !== $dt || ($old_duration > 0 && $old_duration !== $duration)) && class_exists('TPMA_Tutor_Bridge')) {
                    $sync = TPMA_Tutor_Bridge::sync_session_meet_time($session_id, $dt, $duration);
                    if (is_wp_error($sync)) {
                        foreach (array_reverse($synced_meet_times) as $rollback) {
                            TPMA_Tutor_Bridge::sync_session_meet_time((int) $rollback['id'], (string) $rollback['datetime'], $old_duration > 0 ? $old_duration : $duration);
                        }
                        if ($old_duration > 0 && $old_duration !== $duration) {
                            $wpdb->update($courses_table, array('duration_minutes' => $old_duration), array('id' => $course_id), array('%d'), array('%d'));
                        }
                        return $sync;
                    }
                    $synced_meet_times[] = array('id' => $session_id, 'datetime' => (string) $old->session_datetime);
                }
                $session_datetime_changed = (string) $old->session_datetime !== $dt;
                $wpdb->update($sessions_table, $session_data, array('id' => $session_id), array('%s','%d','%s','%s','%s','%s'), array('%d'));
                if ($session_datetime_changed && class_exists('TPMA_CR_Admin_Woo_Service')) {
                    TPMA_CR_Admin_Woo_Service::sync_session_datetime_snapshot($regs_table, $session_id, $dt);
                }
                $kept_session_ids[] = $session_id;
            } else {
                $session_data['course_id'] = $course_id;
                $session_data['created_at'] = current_time('mysql');
                $wpdb->insert($sessions_table, $session_data, array('%s','%d','%s','%s','%s','%s','%d','%s'));
                if ($wpdb->insert_id) $kept_session_ids[] = (int) $wpdb->insert_id;
            }
        }

        foreach ((array) $existing_sessions as $existing_id => $existing) {
            $existing_id = (int) $existing_id;
            if (in_array($existing_id, $kept_session_ids, true)) continue;
            if (class_exists('TPMA_Tutor_Bridge')) {
                $cleanup = TPMA_Tutor_Bridge::cleanup_session_resources($existing_id);
                if (is_wp_error($cleanup)) return $cleanup;
            }
            $has_regs = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM " . TPMA_CR_DB::table('regs') . " WHERE session_id = %d",
                $existing_id
            ));
            if ($has_regs > 0) {
                $wpdb->update($sessions_table, array('is_active' => 0), array('id' => $existing_id), array('%d'), array('%d'));
            } else {
                $wpdb->delete($sessions_table, array('id' => $existing_id), array('%d'));
            }
        }

        // ── Tutor LMS course + session resource reconciliation ──
        $sync_warnings = array();
        if (class_exists('TPMA_Tutor_Bridge')) {
            $tutor_course_id = (int) TPMA_Tutor_Bridge::sync_course($course_id);
            if ($tutor_course_id > 0 && !empty($d['tutor_topic_resources']) && is_array($d['tutor_topic_resources'])) {
                TPMA_Tutor_Bridge::save_course_topic_resources($tutor_course_id, $d['tutor_topic_resources']);
            }
            foreach ($kept_session_ids as $kept_session_id) {
                $resource_sync = TPMA_Tutor_Bridge::sync_session_resources($kept_session_id);
                if (is_wp_error($resource_sync)) {
                    $sync_warnings[] = array(
                        'session_id' => (int) $kept_session_id,
                        'message'    => $resource_sync->get_error_message(),
                    );
                }
            }
        }

        return rest_ensure_response(array(
            'success'          => true,
            'id'               => $course_id,
            'course_code'      => $course_code,
            'is_active'        => $is_active,
            'partial_success'  => !empty($sync_warnings),
            'sync_warnings'    => $sync_warnings,
            'tutor_course_id'  => class_exists('TPMA_Tutor_Bridge')
                ? TPMA_Tutor_Bridge::get_tutor_course_id($course_id)
                : 0,
        ));
    }

    public static function admin_remove_course($request)
    {
        global $wpdb;

        $courses_table = TPMA_CR_DB::table('courses');
        $p = $request->get_json_params();
        $id = intval($p['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('invalid', '缺少課程 id', array('status' => 400));
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(1) FROM {$courses_table} WHERE id = %d", $id)
        );
        if (!$exists) {
            return new WP_Error('not_found', '找不到課程', array('status' => 404));
        }

        $ok = $wpdb->update(
            $courses_table,
            array('is_active' => 0, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );
        if ($ok === false) {
            return new WP_Error('db_error', '無法移除課程', array('status' => 500));
        }

        return rest_ensure_response(array('success' => true, 'id' => $id));
    }

    public static function admin_restore_course($request)
    {
        global $wpdb;

        $courses_table = TPMA_CR_DB::table('courses');
        $p = $request->get_json_params();
        $id = intval($p['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('invalid', '缺少課程 id', array('status' => 400));
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(1) FROM {$courses_table} WHERE id = %d", $id)
        );
        if (!$exists) {
            return new WP_Error('not_found', '找不到課程', array('status' => 404));
        }

        $ok = $wpdb->update(
            $courses_table,
            array('is_active' => 1, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );
        if ($ok === false) {
            return new WP_Error('db_error', '無法恢復課程', array('status' => 500));
        }

        if (class_exists('TPMA_Tutor_Bridge')) {
            TPMA_Tutor_Bridge::sync_course($id);
        }

        return rest_ensure_response(array('success' => true, 'id' => $id));
    }

    public static function admin_merge_course($request)
    {
        global $wpdb;

        $courses_table  = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');
        $regs_table     = TPMA_CR_DB::table('regs');

        $p = $request->get_json_params();
        $source_id = intval($p['source_id'] ?? 0);
        $target_id = intval($p['target_id'] ?? 0);

        if ($source_id <= 0 || $target_id <= 0 || $source_id === $target_id) {
            return new WP_Error('invalid', '來源課程與目標課程必須不同', array('status' => 400));
        }

        $source = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $source_id),
            ARRAY_A
        );
        $target = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $target_id),
            ARRAY_A
        );
        if (!$source || !$target) {
            return new WP_Error('not_found', '找不到來源或目標課程', array('status' => 404));
        }

        $source_tutor_id = (int)($source['tutor_course_id'] ?? 0);
        $target_tutor_id = class_exists('TPMA_Tutor_Bridge')
            ? (int) TPMA_Tutor_Bridge::sync_course($target_id)
            : 0;

        $moved_sessions = 0;
        $moved_regs = 0;
        $updated_orders = 0;
        $reenrolled = 0;

        $source_sessions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, session_datetime, is_active, visibility_override,
                        delivery_mode,
                        tutor_topic_id, tutor_meet_post_id,
                        recording_available_from, recording_available_until
                 FROM {$sessions_table}
                 WHERE course_id = %d
                 ORDER BY session_datetime ASC",
                $source_id
            ),
            ARRAY_A
        );

        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT woocommerce_order_id
                 FROM {$regs_table}
                 WHERE course_id = %d AND woocommerce_order_id IS NOT NULL AND woocommerce_order_id > 0",
                $source_id
            )
        );

        $regs_for_tutor = $target_tutor_id ? $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, wp_user_id, class_date
                 FROM {$regs_table}
                 WHERE course_id = %d",
                $source_id
            ),
            ARRAY_A
        ) : array();

        $wpdb->query('START TRANSACTION');
        $session_id_map = array();
        try {
            foreach ((array)$source_sessions as $session) {
                $target_session_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id
                         FROM {$sessions_table}
                         WHERE course_id = %d AND session_datetime = %s",
                        $target_id,
                        $session['session_datetime']
                    )
                );
                if (!$target_session_id) {
                    $inserted = $wpdb->insert(
                        $sessions_table,
                        array(
                            'course_id'                  => $target_id,
                            'session_datetime'           => $session['session_datetime'],
                            'is_active'                  => isset($session['is_active']) ? (int)$session['is_active'] : 1,
                            'visibility_override'        => sanitize_key($session['visibility_override'] ?? ''),
                            'delivery_mode'              => sanitize_key($session['delivery_mode'] ?? 'live'),
                            'tutor_topic_id'             => !empty($session['tutor_topic_id']) ? (int) $session['tutor_topic_id'] : null,
                            'tutor_meet_post_id'         => !empty($session['tutor_meet_post_id']) ? (int) $session['tutor_meet_post_id'] : null,
                            'recording_available_from'   => $session['recording_available_from'] ?: null,
                            'recording_available_until'  => $session['recording_available_until'] ?: null,
                            'created_at'                 => current_time('mysql'),
                        )
                    );
                    if ($inserted === false) throw new RuntimeException('無法搬移來源課程場次');
                    $target_session_id = (int) $wpdb->insert_id;
                    if (!empty($session['tutor_topic_id']) && $target_tutor_id) {
                        wp_update_post(array('ID' => (int) $session['tutor_topic_id'], 'post_parent' => $target_tutor_id));
                        update_post_meta((int) $session['tutor_topic_id'], '_tpma_session_id', $target_session_id);
                    }
                    if (!empty($session['tutor_meet_post_id'])) update_post_meta((int) $session['tutor_meet_post_id'], '_tpma_session_id', $target_session_id);
                    $moved_sessions++;
                } elseif (!empty($session['tutor_topic_id']) && $target_tutor_id > 0) {
                    $target_session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d", $target_session_id), ARRAY_A);
                    $target_topic_id = (int) ($target_session['tutor_topic_id'] ?? 0);
                    if ($target_topic_id <= 0) {
                        $target_topic_id = (int) $session['tutor_topic_id'];
                        wp_update_post(array('ID' => $target_topic_id, 'post_parent' => $target_tutor_id));
                        $wpdb->update(
                            $sessions_table,
                            array('tutor_topic_id' => $target_topic_id, 'tutor_meet_post_id' => !empty($session['tutor_meet_post_id']) ? (int) $session['tutor_meet_post_id'] : null),
                            array('id' => $target_session_id)
                        );
                    } else {
                        $children = get_posts(array('post_type' => 'any', 'post_parent' => (int) $session['tutor_topic_id'], 'post_status' => array('publish','future','draft'), 'numberposts' => -1));
                        foreach ((array) $children as $child) wp_update_post(array('ID' => $child->ID, 'post_parent' => $target_topic_id));
                    }
                    update_post_meta($target_topic_id, '_tpma_session_id', $target_session_id);
                    if (!empty($session['tutor_meet_post_id'])) update_post_meta((int) $session['tutor_meet_post_id'], '_tpma_session_id', $target_session_id);
                }
                $session_id_map[(int) $session['id']] = $target_session_id;
            }

            foreach ($session_id_map as $old_session_id => $new_session_id) {
                $wpdb->update($regs_table, array('session_id' => $new_session_id), array('course_id' => $source_id, 'session_id' => $old_session_id), array('%d'), array('%d','%d'));
            }

            $updated_regs = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$regs_table}
                     SET course_id = %d
                     WHERE course_id = %d",
                    $target_id,
                    $source_id
                )
            );
            if ($updated_regs === false) {
                throw new RuntimeException('無法搬移報名資料');
            }
            $moved_regs = (int) $updated_regs;

            $deleted_sessions = $wpdb->delete($sessions_table, array('course_id' => $source_id), array('%d'));
            if ($deleted_sessions === false) {
                throw new RuntimeException('無法刪除來源課程場次');
            }

            $deleted_course = $wpdb->delete($courses_table, array('id' => $source_id), array('%d'));
            if ($deleted_course === false || (int)$deleted_course !== 1) {
                throw new RuntimeException('無法刪除來源課程');
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('merge_failed', $e->getMessage(), array('status' => 500));
        }

        foreach ((array)$order_ids as $order_id) {
            $order_id = (int)$order_id;
            if ($order_id <= 0 || !function_exists('wc_get_order')) {
                continue;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            if ((int)$order->get_meta('_tpma_course_id', true) === $source_id) {
                $order->update_meta_data('_tpma_course_id', $target_id);
                $old_order_session_id = (int) $order->get_meta('_tpma_session_id', true);
                if (isset($session_id_map[$old_order_session_id])) {
                    $order->update_meta_data('_tpma_session_id', (int) $session_id_map[$old_order_session_id]);
                }
                $order->save();
                $updated_orders++;
            }
        }

        if ($target_tutor_id && class_exists('TPMA_Tutor_Bridge')) {
            foreach ((array)$regs_for_tutor as $reg) {
                $reg_id = (int)($reg['id'] ?? 0);
                $wp_user_id = (int)($reg['wp_user_id'] ?? 0);
                if ($reg_id <= 0 || $wp_user_id <= 0) {
                    continue;
                }
                TPMA_Tutor_Bridge::enroll_learner($wp_user_id, $target_tutor_id, $reg_id);
                TPMA_Tutor_Bridge::generate_all_tokens_for_registration(
                    $reg_id,
                    $wp_user_id,
                    $target_tutor_id,
                    (string)($reg['class_date'] ?? '')
                );
                $reenrolled++;
            }
        }

        if ($source_tutor_id > 0 && $source_tutor_id !== $target_tutor_id && function_exists('wp_trash_post')) {
            wp_trash_post($source_tutor_id);
        }

        return rest_ensure_response(array(
            'success'        => true,
            'source_id'      => $source_id,
            'target_id'      => $target_id,
            'moved_sessions' => $moved_sessions,
            'moved_regs'     => $moved_regs,
            'updated_orders' => $updated_orders,
            'reenrolled'     => $reenrolled,
            'trashed_tutor_course_id' => $source_tutor_id,
        ));
    }

    /* ---------- Tutor Magic Link endpoints ---------- */

    /**
     * GET /admin/magic-links?reg_id=INT
     * Returns existing token metadata (and regenerated URLs) for a registration.
     */
    public static function admin_get_magic_links($request) {
        if (!class_exists('TPMA_Tutor_Bridge') || !TPMA_Tutor_Bridge::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        }

        $reg_id = intval($request->get_param('reg_id'));
        if ($reg_id <= 0) {
            return new WP_Error('invalid', 'reg_id 必填', array('status' => 400));
        }

        $token_info = TPMA_Tutor_Bridge::get_token_info_for_reg($reg_id);
        return rest_ensure_response(array(
            'success'     => true,
            'reg_id'      => $reg_id,
            'token_info'  => $token_info,
        ));
    }

    /**
     * POST /admin/magic-links/regenerate  { reg_id: INT }
     * Regenerates magic tokens for a registration and returns the new URLs.
     */
    public static function admin_regenerate_magic_links($request) {
        if (!class_exists('TPMA_Tutor_Bridge') || !TPMA_Tutor_Bridge::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        }

        $params = $request->get_json_params();
        $reg_id = intval($params['reg_id'] ?? 0);
        if ($reg_id <= 0) {
            return new WP_Error('invalid', 'reg_id 必填', array('status' => 400));
        }

        global $wpdb;
        $order_id = (int)$wpdb->get_var($wpdb->prepare("SELECT woocommerce_order_id FROM " . TPMA_CR_DB::table('regs') . " WHERE id=%d", $reg_id));
        if ($order_id > 0 && class_exists('TPMA_Course_Access')) {
            $regenerate = !empty($params['regenerate']);
            $portal = TPMA_Course_Access::get_or_create_portal_url($order_id, $regenerate);
            $urls = array('portal'=>$portal,'course'=>$portal,'quiz'=>$portal,'certificate'=>$portal,'meet'=>$portal);
        } else {
            $urls = TPMA_Tutor_Bridge::regenerate_magic_urls_for_reg($reg_id);
        }
        if (empty($urls)) {
            return new WP_Error('not_found', '找不到該報名記錄，或尚未連結 Tutor 課程', array('status' => 404));
        }

        return rest_ensure_response(array(
            'success' => true,
            'reg_id'  => $reg_id,
            'urls'    => $urls,
        ));
    }

    /**
     * POST /admin/tutor/sync-course  { course_id: INT }
     * Manually trigger Tutor course sync for one TPMA course.
     */
    public static function admin_sync_tutor_course($request) {
        if (!class_exists('TPMA_Tutor_Bridge') || !TPMA_Tutor_Bridge::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        }

        $params          = $request->get_json_params();
        $tpma_course_id  = intval($params['course_id'] ?? 0);
        if ($tpma_course_id <= 0) {
            return new WP_Error('invalid', 'course_id 必填', array('status' => 400));
        }

        $tutor_course_id = TPMA_Tutor_Bridge::sync_course($tpma_course_id);
        if (!$tutor_course_id) {
            return new WP_Error('sync_failed', '同步失敗，請確認課程資料與 Tutor 設定', array('status' => 500));
        }

        return rest_ensure_response(array(
            'success'         => true,
            'tpma_course_id'  => $tpma_course_id,
            'tutor_course_id' => $tutor_course_id,
            'tutor_edit_url'  => admin_url('post.php?post=' . $tutor_course_id . '&action=edit'),
        ));
    }

    public static function admin_tutor_session_status($request) {
        if (!class_exists('TPMA_Tutor_Bridge') || !TPMA_Tutor_Bridge::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        }
        $course_id = absint($request->get_param('course_id'));
        if ($course_id <= 0) return new WP_Error('invalid', 'course_id 必填', array('status' => 400));
        return rest_ensure_response(array('sessions' => TPMA_Tutor_Bridge::get_session_status($course_id)));
    }

    public static function admin_tutor_session_prepare($request) {
        if (!class_exists('TPMA_Tutor_Bridge') || !TPMA_Tutor_Bridge::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        }
        $data = (array) $request->get_json_params();
        $session_id = absint($data['session_id'] ?? 0);
        $topic_id = TPMA_Tutor_Bridge::prepare_session_topic($session_id);
        if (!$topic_id) return new WP_Error('topic_failed', '無法建立 Tutor 場次 Topic', array('status' => 500));
        $tutor_course_id = (int) get_post_field('post_parent', $topic_id);
        return rest_ensure_response(array('success' => true, 'session_id' => $session_id, 'topic_id' => $topic_id, 'edit_url' => admin_url('post.php?post=' . $tutor_course_id . '&action=edit')));
    }

    public static function admin_tutor_session_meet($request) {
        if (!class_exists('TPMA_Tutor_Bridge') || !TPMA_Tutor_Bridge::is_active()) {
            return new WP_Error('tutor_inactive', 'Tutor 整合未啟用', array('status' => 503));
        }
        $data = (array) $request->get_json_params();
        $result = TPMA_Tutor_Bridge::create_or_link_session_meet(absint($data['session_id'] ?? 0), absint($data['meet_post_id'] ?? 0));
        if (is_wp_error($result)) return $result;
        return rest_ensure_response(array_merge(array('success' => true), $result));
    }


}
