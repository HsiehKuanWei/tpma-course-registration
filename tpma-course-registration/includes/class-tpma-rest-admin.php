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

    $regs_table      = TPMA_CR_DB::table('regs');
    $courses_table   = TPMA_CR_DB::table('courses');
    $lecturers_table = TPMA_CR_DB::table('lecturers');

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
            CONCAT(
                l.lecturers_name,
                CASE
                    WHEN l.lecturers_title IS NULL OR l.lecturers_title = ''
                        THEN ''
                    ELSE CONCAT(' ', l.lecturers_title)
                END
            ) AS lecturer,
            r.woocommerce_order_id,
            r.payment_status
        FROM {$regs_table} r
        LEFT JOIN {$courses_table} c
            ON c.id = r.course_id
        LEFT JOIN {$lecturers_table} l
            ON l.lecturers_code = c.lecturer_code
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.created_at DESC
    ";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, ...$params);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    // 以 Woo 為訂單層級資料來源
    $orders_map = array();
    $order_ids = array();
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
        $orders_map[$oid] = array(
            'status'        => $order->get_status(),
            'total'         => $order->get_total(),
            'contact_name'  => $order->get_billing_first_name(),
            'contact_email' => $order->get_billing_email(),
            'company_name'  => $order->get_billing_company(),
            'phone'         => $order->get_billing_phone(),
            'address'       => $order->get_billing_address_1(),
            'receiver'      => $order->get_shipping_first_name(),
            'receipt_type'  => $order->get_meta('_tpma_receipt_type', true),
            'tax_id'        => $order->get_meta('_billing_vat_id', true),
            'remit_amount_total' => $order->get_meta('_tpma_remit_amount_total', true),
            'remit_paid_at'      => $order->get_meta('_tpma_remit_paid_at', true),
            'remit_account'      => $order->get_meta('_tpma_remit_account', true),
        );
    }

    // 將 Woo 資料覆寫到回傳 rows（訂單層級只信 Woo）
    foreach ($rows as &$r) {
        $oid = !empty($r['woocommerce_order_id']) ? (int) $r['woocommerce_order_id'] : 0;
        if ($oid && isset($orders_map[$oid])) {
            $o = $orders_map[$oid];
            $r['payment_status']  = $o['status']; // 用 Woo 狀態
            $r['order_status']    = $o['status'];
            $r['order_total']     = $o['total'];
            $r['contact_name']    = $o['contact_name'];
            $r['contact_email']   = $o['contact_email'];
            $r['company_name']    = $o['company_name'];
            $r['phone']           = $o['phone'];
            $r['address']         = $o['address'];
            $r['receiver']        = $o['receiver'];
            $r['receipt_type']    = $o['receipt_type'] !== '' ? $o['receipt_type'] : $r['receipt_type'];
            $r['tax_id']          = $o['tax_id'] !== '' ? $o['tax_id'] : $r['tax_id'];
            $r['remit_amount_total'] = $o['remit_amount_total'];
            $r['remit_paid_at']      = $o['remit_paid_at'] ?: $r['remit_paid_at'];
            $r['remit_account']      = $o['remit_account'] ?: $r['remit_account'];
        }
    }
    unset($r);

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
    $woo_status = $order ? $order->get_status() : '';
    $can_touch_woo_total = in_array($woo_status, array('pending', 'processing'), true);

    // TPMA-only 欄位（不含 Woo 專責欄位、金額另行處理）
    $tpma_fields = array(
        'class_date',
        'student_name',
        'department',
        'job_title',
        'mobile',
        'emails',
        'status',          // 報名狀態
        'receipt_status',
        'remit_paid_at',   // 匯款日期（仍存在 regs，給教務參考）
        'note',
        'test_score',
        'certificate_id',
    );

    // Woo 專責欄位映射
    $woo_field_map = array(
        'contact_name'  => array('type' => 'billing', 'field' => 'first_name'),
        'contact_email' => array('type' => 'billing', 'field' => 'email'),
        'company_name'  => array('type' => 'billing', 'field' => 'company'),
        'phone'         => array('type' => 'billing', 'field' => 'phone'),
        'address'       => array('type' => 'billing', 'field' => 'address_1'),
        'receiver'      => array('type' => 'shipping', 'field' => 'first_name'),
        'receipt_type'  => array('type' => 'meta',    'field' => '_tpma_receipt_type'),
        'tax_id'        => array('type' => 'meta',    'field' => '_billing_vat_id'),
    );

    $tpma_update = array();
    foreach ($tpma_fields as $f) {
        if (isset($d[$f])) {
            $tpma_update[$f] = sanitize_text_field($d[$f]);
        }
    }

    $has_change = !empty($tpma_update);

    // Woo 欄位更新
    if ($order) {
        foreach ($woo_field_map as $payload_key => $info) {
            if (!isset($d[$payload_key])) {
                continue;
            }
            $val = sanitize_text_field($d[$payload_key]);
            $has_change = true;
            if ($info['type'] === 'billing') {
                // 只更新單一欄位，避免覆蓋其他 billing 欄位
                $addr = $order->get_address('billing');
                $addr[$info['field']] = $val;
                $order->set_address($addr, 'billing');
            } elseif ($info['type'] === 'shipping') {
                $addr = $order->get_address('shipping');
                $addr[$info['field']] = $val;
                $order->set_address($addr, 'shipping');
            } elseif ($info['type'] === 'meta') {
                $order->update_meta_data($info['field'], $val);
            }
        }
    }

    // remit_amount 特別處理：同單同價且需回寫 Woo 總額
    if (isset($d['remit_amount'])) {
        $has_change = true;
        $new_amount = (int) sanitize_text_field($d['remit_amount']);
        if ($order_id <= 0 || !$order) {
            return new WP_Error('no_order', '找不到對應的 Woo 訂單，無法同步金額', array('status' => 400));
        }
        if (!$can_touch_woo_total) {
            return new WP_Error('order_locked', '訂單狀態不允許改金額（僅 pending / processing 可改）', array('status' => 400));
        }

        // 將同一張訂單的 regs remit_amount 全部同步為同一金額
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

        // 更新單一 line item（目前設計：單一商品 + qty=人數）
        foreach ($order->get_items() as $item_id => $item) {
            $item->set_subtotal($order_total);
            $item->set_total($order_total);
            $item->save();
            break; // 只有一個商品
        }

        $order->set_total($order_total);
        $order->calculate_totals(false);
        $order->save();
    }

    if (empty($tpma_update) && !$has_change) {
        return new WP_Error('no_data', '沒有可更新欄位', array('status' => 400));
    }

    if (!empty($tpma_update)) {
        $wpdb->update($regs_table, $tpma_update, array('id' => $id));
    }

    if ($order && $has_change) {
        $order->save();
    }

    return rest_ensure_response(array('success' => true));
}


    /* ---------- 講師 ---------- */

    public static function admin_get_lecturers($request)
    {
        global $wpdb;

        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $rows = $wpdb->get_results("
            SELECT
                id,
                lecturers_code       AS code,
                lecturers_name       AS name,
                lecturers_title      AS title,
                lecturers_sort_order AS sort_order
            FROM {$lecturers_table}
            ORDER BY lecturers_sort_order ASC, lecturers_name ASC
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

        // 檢查代碼唯一（用 lecturers_code）
        if ($id > 0) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$lecturers_table}
                     WHERE lecturers_code = %s AND id != %d",
                    $code,
                    $id
                )
            );
        } else {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$lecturers_table}
                     WHERE lecturers_code = %s",
                    $code
                )
            );
        }

        if ($exists) {
            return new WP_Error('duplicate', '講師代碼已存在', array('status' => 400));
        }

        // sort_order（用 lecturers_sort_order）
        if ($sort === null) {
            $max = (int)$wpdb->get_var("SELECT MAX(lecturers_sort_order) FROM {$lecturers_table}");
            $sort = $max + 10;
        }

        // shift_sort：將 >= sort 的講師序往後移
        if ($shift && $sort !== null) {
            if ($id > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$lecturers_table}
                         SET lecturers_sort_order = lecturers_sort_order + 1
                         WHERE lecturers_sort_order >= %d AND id != %d",
                        $sort,
                        $id
                    )
                );
            } else {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$lecturers_table}
                         SET lecturers_sort_order = lecturers_sort_order + 1
                         WHERE lecturers_sort_order >= %d",
                        $sort
                    )
                );
            }
        }

        // 寫入資料：欄位用新的，值用前端傳進來的 code/name/title/sort_order
        $data = array(
            'lecturers_code'       => $code,
            'lecturers_name'       => $name,
            'lecturers_title'      => $title,
            'lecturers_sort_order' => $sort,
        );

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
                    lecturers_code       AS code,
                    lecturers_name       AS name,
                    lecturers_title      AS title,
                    lecturers_sort_order AS sort_order
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
