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
    }

    public static function can_manage()
    {
        return current_user_can('manage_options');
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
        ob_start();
        include TPMA_CR_PATH . 'views/course-admin.php';
        return ob_get_clean();
    }

    /* ---------- 報名管理 ---------- */

    public static function admin_get_regs($request)
    {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        $reg_no       = $request->get_param('reg_no');
        $course_name  = $request->get_param('course_name');
        $student_name = $request->get_param('student_name');
        $status       = $request->get_param('status');

        $where  = array('1=1');
        $params = array();

        if ($reg_no) {
            $where[]  = "reg_no LIKE %s";
            $params[] = '%' . $wpdb->esc_like($reg_no) . '%';
        }
        if ($course_name) {
            $where[]  = "course_name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($course_name) . '%';
        }
        if ($student_name) {
            $where[]  = "student_name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($student_name) . '%';
        }
        if ($status) {
            $where[]  = "status = %s";
            $params[] = $status;
        }

        $sql = "SELECT *
                FROM {$regs_table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql);
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

        $fields = array(
            'course_name', 'lecturer', 'class_date',
            'student_name', 'company_name', 'tax_id', 'department',
            'job_title', 'phone', 'emails', 'receiver', 'address',
            'source', 'note'
        );

        $update = array();
        foreach ($fields as $f) {
            if (isset($d[$f])) {
                $update[$f] = sanitize_text_field($d[$f]);
            }
        }

        if (empty($update)) {
            return new WP_Error('no_data', '沒有可更新欄位', array('status' => 400));
        }

        $wpdb->update($regs_table, $update, array('id' => $id));
        return rest_ensure_response(array('success' => true));
    }

    /* ---------- 講師 ---------- */

    public static function admin_get_lecturers($request)
    {
        global $wpdb;
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $rows = $wpdb->get_results("
            SELECT id, code, name, title, sort_order
            FROM {$lecturers_table}
            ORDER BY sort_order ASC, name ASC
        ", ARRAY_A);

        return rest_ensure_response($rows);
    }

    public static function admin_save_lecturer($request)
    {
        global $wpdb;
        $lecturers_table = TPMA_CR_DB::table('lecturers');
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

        // 檢查代碼唯一
        if ($id > 0) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$lecturers_table} WHERE code = %s AND id != %d",
                    $code,
                    $id
                )
            );
        } else {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$lecturers_table} WHERE code = %s",
                    $code
                )
            );
        }
        if ($exists) {
            return new WP_Error('duplicate', '講師代碼已存在', array('status' => 400));
        }

        // sort_order
        if ($sort === null) {
            $max = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$lecturers_table}");
            $sort = $max + 10;
        }

        // shift_sort：將 >= sort 的講師序往後移
        if ($shift && $sort !== null) {
            if ($id > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$lecturers_table}
                         SET sort_order = sort_order + 1
                         WHERE sort_order >= %d AND id != %d",
                        $sort,
                        $id
                    )
                );
            } else {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$lecturers_table}
                         SET sort_order = sort_order + 1
                         WHERE sort_order >= %d",
                        $sort
                    )
                );
            }
        }

        $data = array(
            'code'       => $code,
            'name'       => $name,
            'title'      => $title,
            'sort_order' => $sort,
        );

        if ($id > 0) {
            $wpdb->update($lecturers_table, $data, array('id' => $id));
        } else {
            $wpdb->insert($lecturers_table, $data);
            $id = $wpdb->insert_id;
        }

        $lect = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, code, name, title, sort_order
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
        $courses_table  = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');

        $courses = $wpdb->get_results("
            SELECT *
            FROM {$courses_table}
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
                SELECT id, course_id, session_datetime, is_active
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

        $d = $request->get_json_params();
        $id = intval($d['id'] ?? 0);

        $course_name   = sanitize_text_field($d['course_name'] ?? '');
        $category_code = sanitize_text_field($d['category_code'] ?? '');
        $lecturer_code = sanitize_text_field($d['lecturer_code'] ?? '');
        $category      = sanitize_text_field($d['category'] ?? '');
        $lecturer      = sanitize_text_field($d['lecturer'] ?? '');
        $course_code   = sanitize_text_field($d['course_code'] ?? '');
        $is_active_in  = isset($d['is_active']) ? intval($d['is_active']) : 1;
        $is_active     = $is_active_in === 0 ? 0 : 1;
        $duration      = isset($d['duration_minutes']) ? intval($d['duration_minutes']) : 180;
        if ($duration <= 0) $duration = 180;

        if ($course_name === '' || $category_code === '' || $lecturer_code === '') {
            return new WP_Error('invalid', '課程名稱、課程類別、講師為必填', array('status' => 400));
        }

        // 自動產生課程編號（如空）
        if ($course_code === '' && $lecturer_code !== '' && $category_code !== '') {
            $prefix = $lecturer_code . $category_code;
            $last = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT course_code FROM {$courses_table}
                     WHERE course_code LIKE %s
                     ORDER BY course_code DESC
                     LIMIT 1",
                    $prefix . '%'
                )
            );
            $seq = 1;
            if ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $last, $m)) {
                $seq = intval($m[1]) + 1;
            }
            $course_code = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
        }

        if ($course_code === '') {
            return new WP_Error('invalid_code', '課程編號無法產生，請確認講師代碼與課程類別。', array('status' => 400));
        }

        // 檢查課程編號唯一
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
            return new WP_Error('duplicate_code', '課程編號已存在，請更換或清空讓系統自動產生。', array('status' => 400));
        }

        $data = array(
            'course_code'    => $course_code,
            'course_name'    => $course_name,
            'category'       => $category,
            'category_code'  => $category_code ?: null,
            'lecturer'       => $lecturer,
            'lecturer_code'  => $lecturer_code ?: null,
            'intro'          => wp_kses_post($d['intro'] ?? ''),
            'outline'        => wp_kses_post($d['outline'] ?? ''),
            'updated_at'     => current_time('mysql'),
            'is_active'      => $is_active,
            'duration_minutes' => $duration,
        );

        if ($id > 0) {
            $wpdb->update($courses_table, $data, array('id' => $id));
            $course_id = $id;
        } else {
            $wpdb->insert($courses_table, $data);
            $course_id = $wpdb->insert_id;
            if (!$course_id) {
                return new WP_Error('db_error', '無法新增課程', array('status' => 500));
            }
        }

        // 場次
        $sessions = array();
        if (!empty($d['sessions']) && is_array($d['sessions'])) {
            $sessions = $d['sessions'];
        }

        $wpdb->delete($sessions_table, array('course_id' => $course_id));

        foreach ($sessions as $s) {
            if (empty($s['datetime'])) {
                continue;
            }
            $dt = sanitize_text_field($s['datetime']);
            $dt = str_replace('T', ' ', $dt);
            if (strlen($dt) === 16) {
                $dt .= ':00';
            }

            $wpdb->insert($sessions_table, array(
                'course_id'        => $course_id,
                'session_datetime' => $dt,
                'is_active'        => 1,
                'created_at'       => current_time('mysql'),
            ));
        }

        return rest_ensure_response(array(
            'success'     => true,
            'id'          => $course_id,
            'course_code' => $course_code,
            'is_active'   => $is_active,
        ));
    }
}
