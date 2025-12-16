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

		// 信件模板與設定：限管理員使用
		register_rest_route($ns, '/mail/templates', array(
			'methods'  => 'GET',
			'callback' => array(__CLASS__, 'get_mail_templates'),
			'permission_callback' => function(){
				return current_user_can('manage_options');
			},
		));

		register_rest_route($ns, '/mail/templates', array(
			'methods'  => 'POST',
			'callback' => array(__CLASS__, 'save_mail_templates'),
			'permission_callback' => function(){
				return current_user_can('manage_options');
			},
		));

		register_rest_route($ns, '/mail/preview', array(
			'methods'  => 'POST',
			'callback' => array(__CLASS__, 'preview_mail_template'),
			'permission_callback' => function(){
				return current_user_can('manage_options');
			},
		));

		register_rest_route($ns, '/mail/send-test', array(
			'methods'  => 'POST',
			'callback' => array(__CLASS__, 'send_test_mail'),
			'permission_callback' => function(){
				return current_user_can('manage_options');
			},
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

		public static function register($request) {
            global $wpdb;

            $regs_table     = TPMA_CR_DB::table('regs');
            $courses_table  = TPMA_CR_DB::table('courses');
            $sessions_table = TPMA_CR_DB::table('sessions');

            $d = $request->get_json_params();

            // Validate comprehensive payload structure
            if (empty($d['course_id']) || empty($d['learners']) || !is_array($d['learners']) || empty($d['shared'])) {
                return new WP_Error('invalid_data', '缺少必要欄位或資料結構不正確', array('status' => 400));
            }

            $course_id  = intval($d['course_id']);
            $session_id = intval($d['session_id'] ?? 0);
            $learners   = $d['learners'];
            $shared     = $d['shared'];

            // 課程資料
            $course = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $course_id)
            );
            if (!$course) {
                return new WP_Error('course_not_found', '課程不存在', array('status' => 404));
            }

            // 場次資料：此表單為董監事課程，必須選擇有時間的場次，不接受 class_date fallback
            $session = null;
            if ($session_id) {
                $session = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d AND course_id = %d", $session_id, $course_id)
                );
            }
            if (!$session || empty($session->session_datetime)) {
                return new WP_Error('session_required', '需先排定上課時間後才能報名', array('status' => 400));
            }

            // class_date 直接依 session_datetime 推導
            $class_date = date('Y-m-d', strtotime($session->session_datetime));

            // 匯款金額：每小時 1000 元（先算出基礎金額，尚未套用折扣）
            $duration_minutes   = intval($course->duration_minutes ?? 0);
            $hours              = $duration_minutes / 60;
            $base_remit_amount  = (int) round($hours * 1000);
            $remit_amount_per_learner = $base_remit_amount; // Initial amount per learner

            // 產生一個共用的報名編號 (reg_no)
            $reg_no = TPMA_CR_DB::generate_reg_no('A');

            // 計算總學員數，並應用折扣
            $total_learners = count($learners);
            if ($total_learners >= 6) {
                $remit_amount_per_learner = (int) round($base_remit_amount * 0.8);
            }
            $total_order_amount = $remit_amount_per_learner * $total_learners;

            $inserted_reg_ids = [];

            // 逐一插入學員報名資料到 wp_tpma_registrations
            foreach ($learners as $learner) {
                $student_email_for_record = sanitize_email($learner['emails'] ?? '');
                if ($student_email_for_record === sanitize_email($shared['contact_email'] ?? '')) {
                    $student_email_for_record = ''; // If learner email is same as contact email, don't record it separately
                }

                $insert = array(
                    'reg_no'        => $reg_no,
                    'created_at'    => current_time('mysql'),
                    'course_id'     => $course_id,
                    'class_date'    => $class_date,
                    'student_name'  => sanitize_text_field($learner['student_name']),
                    'department'    => sanitize_text_field($learner['department'] ?? ''),
                    'job_title'     => sanitize_text_field($learner['job_title'] ?? ''),
                    'mobile'        => sanitize_text_field($learner['mobile'] ?? ''),
                    'emails'        => $student_email_for_record,
                    'contact_name'  => sanitize_text_field($shared['contact_name'] ?? ''),
                    'contact_email' => sanitize_text_field($shared['contact_email'] ?? ''),
                    'company_name'  => sanitize_text_field($shared['company_name'] ?? ''),
                    'tax_id'        => sanitize_text_field($shared['tax_id'] ?? ''),
                    'phone'         => sanitize_text_field($shared['phone'] ?? ''),
                    'receipt_type'  => in_array(($shared['receipt_type'] ?? ''), ['electronic','paper'], true)
                                        ? $shared['receipt_type'] : 'paper',
                    'address'       => sanitize_text_field($shared['address'] ?? ''),
                    'receiver'      => sanitize_text_field($shared['receiver'] ?? ''),
                    'source'        => sanitize_text_field($shared['source'] ?? ''),
                    'note'          => sanitize_textarea_field($shared['note'] ?? ''),
                    'remit_amount'  => $remit_amount_per_learner, // Amount per learner
                    'status'        => 'pending', // Initial TPMA status
                    'payment_status' => 'pending', // Initial WC payment status
                );

                $wpdb->insert($regs_table, $insert);

                if (!$wpdb->insert_id) {
                    error_log('[TPMA register] INSERT FAILED for reg_no ' . $reg_no . ': ' . $wpdb->last_error);
                    return new WP_Error(
                        'db_error',
                        '無法寫入報名資料：' . ($wpdb->last_error ?: '未知錯誤'),
                        array('status' => 500)
                    );
                }
                $inserted_reg_ids[] = $wpdb->insert_id;
            }

            // === WooCommerce 訂單建立 ===
            $order_result = TPMA_CR_Woo_Service::create_registration_order(array(
                'shared'                   => $shared,
                'learners'                 => $learners,
                'course_id'                => $course_id,
                'session_id'               => $session_id,
                'reg_no'                   => $reg_no,
                'remit_amount_per_learner' => $remit_amount_per_learner,
                'total_learners'           => $total_learners,
                'total_order_amount'       => $total_order_amount,
                'inserted_reg_ids'         => $inserted_reg_ids,
                'regs_table'               => $regs_table,
            ));
            if (is_wp_error($order_result)) {
                return $order_result;
            }
            $order = $order_result['order'];
            $woocommerce_order_id = $order_result['order_id'];

            // === TPMA Mailer: 寄出報名成功通知 ===
            // (保持原有邏輯，但確保使用正確的 reg_no 和 remit_amount)
            $email_class_date = '';
            if ($session && !empty($session->session_datetime)) {
                $start_ts = strtotime($session->session_datetime);
                if ($start_ts) {
                    $date_str  = date('Y/m/d', $start_ts);
                    $weeknames = array('日','一','二','三','四','五','六');
                    $w_index   = (int) date('w', $start_ts);
                    $week_str  = $weeknames[$w_index] ?? '';
                    $start_time = date('H:i', $start_ts);
                    $duration_minutes = intval($course->duration_minutes ?? 0);
                    $time_range = $start_time;
                    if ($duration_minutes > 0) {
                        $end_ts    = $start_ts + $duration_minutes * 60;
                        $end_time  = date('H:i', $end_ts);
                        $time_range = $start_time . '~' . $end_time;
                    }
                    $email_class_date = sprintf('%s（%s） %s', $date_str, $week_str, $time_range);
                }
            } elseif (!empty($class_date)) {
                $ts = strtotime($class_date);
                if ($ts) {
                    $date_str  = date('Y/m/d', $ts);
                    $weeknames = array('日','一','二','三','四','五','六');
                    $w_index   = (int) date('w', $ts);
                    $week_str  = $weeknames[$w_index] ?? '';
                    $email_class_date = sprintf('%s（%s）', $date_str, $week_str);
                } else {
                    $email_class_date = $class_date;
                }
            }

            $duration_minutes = intval($course->duration_minutes ?? 0);
            $course_hours     = $duration_minutes > 0 ? ($duration_minutes / 60) : 0;

            $lecturer_name = '';
            if (!empty($course->lecturer_code) && class_exists('TPMA_CR_DB')) {
                $lecturers_table = TPMA_CR_DB::table('lecturers');
                $lect = $wpdb->get_row($wpdb->prepare(
                    "SELECT lecturers_name, lecturers_title 
                     FROM {$lecturers_table}
                     WHERE lecturers_code = %s",
                    $course->lecturer_code
                ));
                if ($lect && !empty($lect->lecturers_name)) {
                    $lecturer_name = trim(
                        $lect->lecturers_name .
                        (!empty($lect->lecturers_title) ? ' ' . $lect->lecturers_title : '')
                    );
                }
            }

            try {
                $student_email = sanitize_email($learners[0]['emails'] ?? ''); // Use first learner's email for notification
                $contact_email = sanitize_email($shared['contact_email'] ?? '');

                $notify_contact = isset($shared['notify_contact'])
                    ? (bool) $shared['notify_contact']
                    : true;

                $recipients = [];
                if ($student_email !== '') {
                    $recipients[] = $student_email;
                }
                if ($notify_contact && $contact_email !== '' && $contact_email !== $student_email) {
                    $recipients[] = $contact_email;
                }
                $recipients = array_values(array_unique($recipients));

                if ($recipients && class_exists('TPMA_Mailer')) {
                    TPMA_Mailer::send_template(
                        'registration_notice',
                        $recipients,
                        [
                            'reg_context'   => $insert, // Use the last inserted learner's context for email, or create a summary
                            'extra_context' => [
                                'class_date'      => $email_class_date,
                                'class_date_raw'  => $class_date,
                                'course_name'     => $course->course_name ?? '',
                                'course_hours'    => $course_hours,
                                'lecturer_name'   => $lecturer_name,
                                'remit_amount'    => $total_order_amount, // Total amount for email
                                'reg_no'          => $reg_no,
                                'woocommerce_order_id' => $woocommerce_order_id,
                            ],
                        ]
                    );
                }

            } catch (Exception $e) {
                error_log('[TPMA Mailer] registration_notice error: ' . $e->getMessage());
            }

            return rest_ensure_response(array(
                'success' => true,
                'reg_no'  => $reg_no,
                'woocommerce_order_id' => $woocommerce_order_id,
            ));
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

                'remit_date'    => sanitize_text_field($p['remit_date'] ?? ''),

                'remit_amount'  => floatval($p['remit_amount'] ?? 0),

                'status'        => 'submitted',

            ),

            array('id' => $id)

        );



        return rest_ensure_response(array('success' => true));

    }
	
	public static function get_mail_templates($request) {
		if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
			return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
		}

		$templates = TPMA_CR_Mail_Templates::get_all();
		$config    = TPMA_CR_Mail_Config::get_config();

		return rest_ensure_response(array(
			'templates' => $templates,
			'config'    => $config,
		));
	}

	public static function save_mail_templates($request) {
		if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
			return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
		}

		$d = $request->get_json_params();

		$templates = isset($d['templates']) && is_array($d['templates']) ? $d['templates'] : array();
		$config    = isset($d['config']) && is_array($d['config']) ? $d['config'] : array();

		TPMA_CR_Mail_Templates::update_all($templates);
		TPMA_CR_Mail_Config::update_config($config);

		return rest_ensure_response(array(
			'success'   => true,
			'templates' => $templates,
			'config'    => $config,
		));
	}

	public static function preview_mail_template($request) {
		if (!class_exists('TPMA_CR_Mail_Templates') || !class_exists('TPMA_CR_Mail_Config')) {
			return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
		}

		$d = $request->get_json_params();

		$template_key = sanitize_text_field($d['template_key'] ?? '');
		$subject_raw  = (string) ($d['subject'] ?? '');
		$body_raw     = (string) ($d['body_html'] ?? '');
		$context      = is_array($d['context'] ?? null) ? $d['context'] : array();

		if (!$template_key) {
			return new WP_Error('invalid_template_key', '缺少 template_key', array('status' => 400));
		}

		// 簡單版變數替換，模仿 TPMA_CR_Mail_Templates::replace_vars()
		$replace = array();
		foreach ($context as $k => $v) {
			$replace['{{' . $k . '}}'] = esc_html($v);
		}

		$subject = strtr($subject_raw, $replace);
		$body    = strtr($body_raw, $replace);

		// 套用廣告與共通尾巴，模仿 render() 裡的邏輯
		$config  = TPMA_CR_Mail_Config::get_config();
		$tpl_cfg = $config['templates'][$template_key] ?? array();

		// 廣告
		if (!empty($tpl_cfg['use_ad']) && !empty($tpl_cfg['ad_key'])) {
			$ads   = $config['ads'] ?? array();
			$adKey = $tpl_cfg['ad_key'];
			if (!empty($ads[$adKey]['enabled']) && !empty($ads[$adKey]['html'])) {
				$body .= "\n\n" . $ads[$adKey]['html'];
			}
		}

		// 共通尾巴
		if (!empty($config['common_footer_html'])) {
			$body .= "\n\n" . $config['common_footer_html'];
		}

		return rest_ensure_response(array(
			'subject'   => $subject,
			'body_html' => $body,
		));
	}

	public static function send_test_mail($request) {
		if (!class_exists('TPMA_CR_Mail_Service')) {
			return new WP_Error('mail_not_available', 'Mail 模組尚未載入', array('status' => 500));
		}

		$d = $request->get_json_params();
		$to = sanitize_email($d['to'] ?? '');
		$template_key = sanitize_text_field($d['template_key'] ?? 'registration_notice');

		if (!$to) {
			return new WP_Error('invalid_email', '缺少測試收件人', array('status' => 400));
		}

		// 給一組假的 context
		$reg_context = array(
			'id'           => 0,
			'reg_no'       => 'TEST-0001',
			'course_id'    => 0,
			'course_name'  => '測試課程',
			'class_date'   => current_time('mysql'),
			'student_name' => '測試學員',
			'company_name' => '測試公司',
		);

		try {
			TPMA_CR_Mail_Service::send($template_key, array(
				'reg_context' => $reg_context,
				'to'          => $to,
			));
		} catch (Exception $e) {
			return new WP_Error('send_failed', $e->getMessage(), array('status' => 500));
		}

		return rest_ensure_response(array(
			'success' => true,
		));
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
        $note       = sanitize_textarea_field($d['note'] ?? '');

        $draft = TPMA_CR_Woo_Service::build_draft($course_id, $session_id, $learners, $source, $note);
        if (is_wp_error($draft)) {
            return $draft;
        }

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
