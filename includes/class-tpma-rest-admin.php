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
            {$lecturer_display_sql} AS lecturer,
            r.woocommerce_order_id,
            r.payment_status
        FROM {$regs_table} r
        LEFT JOIN {$courses_table} c
            ON c.id = r.course_id
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
        'class_date',
        'student_name',
        'department',
        'job_title',
        'mobile',
        'emails',
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

        $tpma_update[$f] = sanitize_text_field($d[$f]);
    }

    $has_change = !empty($tpma_update);

    // Woo 欄位更新透過 Service 統一處理
    $woo_result = TPMA_CR_Admin_Woo_Service::apply_order_updates($order, $d, $regs_table);
    if (is_wp_error($woo_result)) {
        return $woo_result;
    }
    $woo_changed = !empty($woo_result['has_change']);
    $has_change = $has_change || $woo_changed;

    if (empty($tpma_update) && !$has_change) {
        return new WP_Error('no_data', '沒有可更新欄位', array('status' => 400));
    }

    if (!empty($tpma_update)) {
        $wpdb->update($regs_table, $tpma_update, array('id' => $id));
    }

    if ($order && $woo_changed) {
        $order->save();
    }

    return rest_ensure_response(array('success' => true));
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
                {$sort_select}
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
                    wp_user_id
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
                SELECT id, course_id, session_datetime, is_active, visibility_override
                FROM {$sessions_table}
                WHERE course_id IN ({$ids_in})
                ORDER BY session_datetime ASC
            ", ARRAY_A);

            foreach ($sessions as $s) {
                $cid = (int)$s['course_id'];
                if (!isset($sessions_map[$cid])) {
                    $sessions_map[$cid] = array();
                }
                $sessions_map[$cid][] = $s;
            }
        }

        foreach ($courses as &$c) {
            $cid = (int)$c['id'];
            $c['sessions'] = isset($sessions_map[$cid]) ? $sessions_map[$cid] : array();
        }

        return rest_ensure_response($courses);
    }

     public static function admin_save_course($request)
    {   
        global $wpdb;
        $courses_table  = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');

        $d  = $request->get_json_params();
        $id = intval($d['id'] ?? 0);

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

        // 組合要寫入的課程資料
        $data = array(
            'course_code'      => $course_code,
            'course_name'      => $course_name,
            'category'         => $category,
            'category_code'    => $category_code ?: null,
            'lecturer_code'    => $lecturer_code ?: null,
            'intro'            => wp_kses_post($d['intro'] ?? ''),
            'outline'          => wp_kses_post($d['outline'] ?? ''),
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

        // 先清掉舊場次
        $wpdb->delete($sessions_table, array('course_id' => $course_id));

        foreach ($sessions as $s) {
            if (empty($s['datetime'])) {
                continue;
            }
            $raw = trim($s['datetime']);
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

            $wpdb->insert($sessions_table, array(
                'course_id'        => $course_id,
                'session_datetime' => $dt,
                'is_active'        => 1,
                'visibility_override' => $visibility_override,
                'created_at'       => current_time('mysql'),
            ));
        }

        // ── Tutor LMS sync (fire-and-forget; doesn't affect the REST response) ──
        if (class_exists('TPMA_Tutor_Bridge')) {
            TPMA_Tutor_Bridge::sync_course($course_id);
        }

        return rest_ensure_response(array(
            'success'          => true,
            'id'               => $course_id,
            'course_code'      => $course_code,
            'is_active'        => $is_active,
            'tutor_course_id'  => class_exists('TPMA_Tutor_Bridge')
                ? TPMA_Tutor_Bridge::get_tutor_course_id($course_id)
                : 0,
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

        $urls = TPMA_Tutor_Bridge::regenerate_magic_urls_for_reg($reg_id);
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


}
