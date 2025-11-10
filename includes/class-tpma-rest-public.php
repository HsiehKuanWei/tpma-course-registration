<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_REST_Public
{
    public static function register_routes()
    {
        $ns = 'tpma/v1';

        register_rest_route($ns, '/courses', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'get_courses'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/register', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'register'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/registration/search', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'search_registration'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/payment', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'submit_payment'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * GET /courses
     * 僅回傳：開課中 + 未來場次
     */
    public static function get_courses($request)
    {
        global $wpdb;
        $courses_table  = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');
        $now = current_time('mysql');

        $sql = $wpdb->prepare("
            SELECT
                c.id            AS course_id,
                c.course_code,
                c.course_name,
                c.category,
                c.category_code,
                c.lecturer,
                c.lecturer_code,
                c.intro,
                c.outline,
                c.duration_minutes,
                s.id            AS session_id,
                s.session_datetime
            FROM {$courses_table} c
            INNER JOIN {$sessions_table} s
                ON c.id = s.course_id
            WHERE
                c.is_active = 1
                AND s.is_active = 1
                AND s.session_datetime >= %s
            ORDER BY s.session_datetime ASC
        ", $now);

        $rows = $wpdb->get_results($sql);
        return rest_ensure_response($rows);
    }

    /**
     * POST /register
     */
    public static function register($request)
    {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        $d = $request->get_json_params();

        if (empty($d['course_id']) || empty($d['student_name'])) {
            return new WP_Error('invalid_data', '缺少必要欄位', array('status' => 400));
        }

        $reg_no = 'R' . date('YmdHis') . wp_rand(100, 999);

        $insert = array(
            'reg_no'       => $reg_no,
            'created_at'   => current_time('mysql'),
            'course_id'    => intval($d['course_id']),
            'course_name'  => sanitize_text_field($d['course_name'] ?? ''),
            'lecturer'     => sanitize_text_field($d['lecturer'] ?? ''),
            'class_date'   => !empty($d['class_date']) ? sanitize_text_field($d['class_date']) : null,

            'student_name' => sanitize_text_field($d['student_name']),
            'company_name' => sanitize_text_field($d['company_name'] ?? ''),
            'tax_id'       => sanitize_text_field($d['tax_id'] ?? ''),
            'department'   => sanitize_text_field($d['department'] ?? ''),
            'job_title'    => sanitize_text_field($d['job_title'] ?? ''),
            'phone'        => sanitize_text_field($d['phone'] ?? ''),
            'emails'       => sanitize_text_field($d['emails'] ?? ''),
            'receiver'     => sanitize_text_field($d['receiver'] ?? ''),
            'address'      => sanitize_text_field($d['address'] ?? ''),
            'source'       => sanitize_text_field($d['source'] ?? ''),
            'note'         => sanitize_textarea_field($d['note'] ?? ''),
            'status'       => 'pending',
        );

        $wpdb->insert($regs_table, $insert);
        if (!$wpdb->insert_id) {
            return new WP_Error('db_error', '無法寫入報名資料', array('status' => 500));
        }

        return rest_ensure_response(array('success' => true, 'reg_no' => $reg_no));
    }

    /**
     * POST /registration/search
     */
    public static function search_registration($request)
    {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');
        $p = $request->get_json_params();

        $where  = array();
        $params = array();

        $like_fields = array(
            'reg_no'       => 'reg_no',
            'student_name' => 'student_name',
            'company_name' => 'company_name',
            'tax_id'       => 'tax_id',
            'phone'        => 'phone',
        );

        foreach ($like_fields as $key => $col) {
            if (!empty($p[$key])) {
                $where[]  = "$col LIKE %s";
                $params[] = '%' . $wpdb->esc_like($p[$key]) . '%';
            }
        }

        if (!empty($p['email'])) {
            $where[]  = "emails LIKE %s";
            $params[] = '%' . $wpdb->esc_like($p['email']) . '%';
        }

        if (empty($where)) {
            return new WP_Error('no_criteria', '缺少查詢條件', array('status' => 400));
        }

        $sql = "SELECT id, reg_no, course_name, lecturer, class_date,
                       student_name, company_name, tax_id, status
                FROM {$regs_table}
                WHERE " . implode(' AND ', $where);

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql);
        return rest_ensure_response($rows);
    }

    /**
     * POST /payment
     */
    public static function submit_payment($request)
    {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');
        $p = $request->get_json_params();

        $id = intval($p['id'] ?? 0);
        if (!$id) {
            return new WP_Error('invalid', '缺少 id', array('status' => 400));
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$regs_table} WHERE id = %d", $id)
        );
        if (!$row) {
            return new WP_Error('not_found', '查無報名資料', array('status' => 404));
        }

        if (in_array($row->status, array('submitted', 'paid', 'cancelled'), true)) {
            return new WP_Error('not_allowed', '此報名狀態不可更新繳費資訊', array('status' => 400));
        }

        $wpdb->update(
            $regs_table,
            array(
                'remit_account' => sanitize_text_field($p['remit_account'] ?? ''),
                'remit_date'    => sanitize_text_field($p['remit_date'] ?? ''),
                'remit_amount'  => floatval($p['remit_amount'] ?? 0),
                'status'        => 'submitted',
            ),
            array('id' => $id)
        );

        return rest_ensure_response(array('success' => true));
    }
}
