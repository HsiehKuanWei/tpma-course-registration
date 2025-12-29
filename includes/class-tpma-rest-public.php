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

        // Checkout init：暫存學員資料、加車並回傳 Woo 結帳網址
        register_rest_route($ns, '/checkout-init', array(

            'methods'  => ['POST'],

            'callback' => array(__CLASS__, 'checkout_init'),

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

        // 匯款回報（thankyou 頁用）
        register_rest_route($ns, '/remit-report', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'submit_remit_report'),
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

        $courses_table   = TPMA_CR_DB::table('courses');
        $sessions_table  = TPMA_CR_DB::table('sessions');
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $now = current_time('mysql');

        $sql = $wpdb->prepare("
            SELECT
                c.id            AS course_id,
                c.course_code,
                c.course_name,
                c.category,
                c.category_code,
                c.lecturer_code,
                CONCAT(
                    l.lecturers_name,
                    CASE
                        WHEN l.lecturers_title IS NULL OR l.lecturers_title = ''
                        THEN ''
                        ELSE CONCAT(' ', l.lecturers_title)
                    END
                ) AS lecturer,
                c.intro,
                c.outline,
                c.duration_minutes,
                s.id             AS session_id,
                s.session_datetime
            FROM {$courses_table}  c
            INNER JOIN {$sessions_table} s
                ON s.course_id = c.id
            LEFT JOIN {$lecturers_table} l
                ON l.lecturers_code = c.lecturer_code
            WHERE
                c.is_active = 1
                AND s.is_active = 1
                AND s.session_datetime >= %s
            ORDER BY s.session_datetime ASC
        ", $now);

        $rows = $wpdb->get_results($sql);

        if ($wpdb->last_error) {
            error_log("TPMA REST Public: get_courses SQL Error: " . $wpdb->last_error);
            return new WP_Error('db_error', '課程資料庫查詢失敗', array('status' => 500));
        }
        error_log("TPMA REST Public: get_courses returned " . count($rows) . " rows.");

        return rest_ensure_response($rows);
    }



    /**

     * POST /register

     */

    public static function register($request)
    {
        // ✅ 舊版路徑相容：避免「未下單就落庫」造成髒資料，/register 一律導向 checkout-init 流程
        $d = $request->get_json_params();

        // 舊版 payload 可能是：
        // - course_id, session_id, learners, shared{source,note}
        // 或新版：
        // - course_id, session_id, learners, source, note
        if (empty($d['course_id']) || empty($d['session_id']) || empty($d['learners']) || !is_array($d['learners'])) {
            return new WP_Error('invalid_data', '缺少必要欄位 course_id / session_id / learners', array('status' => 400));
        }

        $source = sanitize_text_field($d['source'] ?? ($d['shared']['source'] ?? ''));
        $note   = sanitize_textarea_field($d['note'] ?? ($d['shared']['note'] ?? ''));

        $payload = array(
            'course_id'  => intval($d['course_id']),
            'session_id' => intval($d['session_id']),
            'learners'   => $d['learners'],
            'source'     => $source,
            'note'       => $note,
        );

        $req = new WP_REST_Request('POST', '/tpma/v1/checkout-init');
        $req->set_body_params($payload);

        return self::checkout_init($req);
    }





    /**

     * POST /registration/search

     */

    public static function search_registration($request)
    {
        global $wpdb;

        $regs_table      = TPMA_CR_DB::table('regs');
        $courses_table   = TPMA_CR_DB::table('courses');
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $p = $request->get_json_params();

        $where  = array();
        $params = array();

        // 支援模糊查詢的欄位
        $like_fields = array(
            'reg_no'       => 'r.reg_no',
            'course_name'  => 'c.course_name',
            'student_name' => 'r.student_name',
            'company_name' => 'r.company_name',
            'tax_id'       => 'r.tax_id',
            'phone'        => 'r.phone',
        );

        foreach ($like_fields as $key => $col) {
            if (!empty($p[$key])) {
                $where[]  = "$col LIKE %s";
                $params[] = '%' . $wpdb->esc_like($p[$key]) . '%';
            }
        }

        if (empty($where)) {
            return new WP_Error('no_criteria', '缺少查詢條件', array('status' => 400));
        }

        $sql = "
            SELECT
                r.id,
                r.reg_no,
                c.course_name,
                CONCAT(
                    l.lecturers_name,
                    CASE
                        WHEN l.lecturers_title IS NULL OR l.lecturers_title = ''
                        THEN ''
                        ELSE CONCAT(' ', l.lecturers_title)
                    END
                ) AS lecturer,
                r.class_date,
                r.student_name,
                r.company_name,
                r.tax_id,
                r.status
            FROM {$regs_table} r
            LEFT JOIN {$courses_table} c
                ON c.id = r.course_id
            LEFT JOIN {$lecturers_table} l
                ON l.lecturers_code = c.lecturer_code
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
            //    'remit_date'    => sanitize_text_field($p['remit_date'] ?? ''),
                'remit_paid_at' => sanitize_text_field($p['remit_date'] ?? null),
                'remit_amount'  => floatval($p['remit_amount'] ?? 0),
                'status'        => 'submitted',

            ),

            array('id' => $id)

        );



        return rest_ensure_response(array('success' => true));

    }

    /**
     * POST /remit-report
     * thankyou 頁匯款回報：寫入 regs（同一筆訂單下所有學員）+ 通知管理員 + Woo 訂單改為 processing
     *
     * 參數：
     * - order_id
     * - order_key（thankyou 頁可取得，用來做簡單授權）
     * - remit_date (YYYY-MM-DD)
     * - remit_account (digits or company name)
     */
    public static function submit_remit_report($request)
    {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('wc_missing', 'WooCommerce 未啟用', array('status' => 500));
        }

        $p = $request->get_json_params();

        $order_id   = intval($p['order_id'] ?? 0);
        $order_key  = sanitize_text_field($p['order_key'] ?? '');
        $remit_date = sanitize_text_field($p['remit_date'] ?? '');

        // ✅ 允許「公司戶名」或「末五碼」：不再只保留數字
        $remit_account_raw = (string)($p['remit_account'] ?? '');
        $remit_account     = sanitize_text_field(trim($remit_account_raw));

        if (!$order_id || !$order_key) {
            return new WP_Error('bad_request', '缺少訂單資訊', array('status' => 400));
        }
        if (!$remit_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $remit_date)) {
            return new WP_Error('bad_request', '匯款日期格式錯誤', array('status' => 400));
        }

        // ✅ 驗證：5 碼數字 OR 公司戶名（2~50字）
        $is_last5 = (bool)preg_match('/^\d{5}$/', $remit_account);
        $is_name  = (mb_strlen($remit_account, 'UTF-8') >= 2 && mb_strlen($remit_account, 'UTF-8') <= 50);

        if (!($is_last5 || $is_name)) {
            return new WP_Error('bad_request', '公司戶名或匯款帳號末五碼格式錯誤', array('status' => 400));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', '找不到訂單', array('status' => 404));
        }

        // 授權：必須提供正確 order_key
        if ($order->get_order_key() !== $order_key) {
            return new WP_Error('forbidden', '訂單驗證失敗', array('status' => 403));
        }

        // 僅允許未完成/未取消的訂單回報
        $st = $order->get_status();
        if (in_array($st, array('completed', 'cancelled', 'refunded', 'failed'), true)) {
            return new WP_Error('not_allowed', '此訂單狀態不可回報匯款', array('status' => 400));
        }

        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        // ✅ 寫入 regs：同一筆訂單下所有學員
        $updated = $wpdb->update(
            $regs_table,
            array(
                'remit_account'  => $remit_account,
                // 'remit_date'   => $remit_date,   // 你先前 DB 沒有 remit_date 就先別寫
                'remit_paid_at'  => $remit_date,
                'payment_status' => 'processing',
            ),
            array('woocommerce_order_id' => (int)$order_id),
            array('%s','%s','%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', '資料表寫入失敗：' . $wpdb->last_error, array('status' => 500));
        }

        // ✅ 寫入 order meta（方便追溯）
        $order->update_meta_data('_tpma_remit_date', $remit_date);
        $order->update_meta_data('_tpma_remit_account', $remit_account);

        // ✅ 先把 Woo 訂單改為「處理中」
        if ($st !== 'processing') {
            $order->update_status('processing', '學員於 thankyou 頁回報匯款：' . $remit_date . ' / ' . $remit_account);
        } else {
            $order->save();
        }

        // 通知管理員（如果 mail dispatcher 有提供）
        if (class_exists('TPMA_CR_Mail_Dispatcher') && method_exists('TPMA_CR_Mail_Dispatcher', 'notify_admin_remit_report')) {
            TPMA_CR_Mail_Dispatcher::notify_admin_remit_report($order, $remit_date, $remit_account);
        }

        return rest_ensure_response(array('success' => true));
    }

	

    /**
     * checkout-init：暫存學員資料到 Woo session、加車並回傳 checkout URL
     */
    public static function checkout_init($request) {
        $d = $request->get_json_params();
        if (empty($d['course_id']) || empty($d['session_id']) || empty($d['learners']) || !is_array($d['learners'])) {
            return new WP_Error('invalid_data', '缺少必要欄位', array('status' => 400));
        }

        $course_id  = intval($d['course_id']);
        $session_id = intval($d['session_id']);
        $learners   = $d['learners'];
        $source     = sanitize_text_field($d['source'] ?? '');
        $note       = '';

        $draft = TPMA_CR_Woo_Service::build_draft($course_id, $session_id, $learners, $source, $note);
        if (is_wp_error($draft)) {
            return $draft;
        }

        // ★ NEW：由 REST 決定模板 key，但 REST 不寄信
        $draft['mail_templates'] = [
            'student' => 'registration_notice', // 學員資料信（寄到 form 填的信箱）
            'order'   => 'registration_order', 
            'completed' => 'registration_completed',  // 訂單核帳信（寄到 woo 結帳信箱）
        ];

        $checkout_url = TPMA_CR_Woo_Service::add_to_cart_from_draft($draft);
        if (is_wp_error($checkout_url)) {
            return $checkout_url;
        }

        return rest_ensure_response(array(
            'success'      => true,
            'checkout_url' => $checkout_url,
        ));
    }

}
