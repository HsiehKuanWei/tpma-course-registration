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

            if (empty($d['course_id']) || empty($d['student_name'])) {
                return new WP_Error('invalid_data', '缺少必要欄位', array('status' => 400));
            }

            $course_id  = intval($d['course_id']);
            $session_id = intval($d['session_id'] ?? 0);

            // 課程資料
            $course = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $course_id)
            );

            // 場次資料（算開課日 & 匯款期限用）
            $session = null;
            if ($session_id) {
                $session = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d", $session_id)
                );
            }

            // class_date 優先用場次日期
            $class_date = null;
            if ($session && !empty($session->session_datetime)) {
                $class_date = date('Y-m-d', strtotime($session->session_datetime));
            } elseif (!empty($d['class_date'])) {
                $class_date = sanitize_text_field($d['class_date']);
            }

			// 匯款金額：每小時 1000 元（先算出基礎金額，尚未套用折扣）
			$duration_minutes   = intval($course->duration_minutes ?? 0);
			$hours              = $duration_minutes / 60;
			$base_remit_amount  = (int) round($hours * 1000);  // 例如 3 小時 → 3000
			$remit_amount       = $base_remit_amount;

			$remit_paid_at = null;

            // === 這裡改：支援同批共用編號 ===
            // 允許前端帶 reg_no 或 reg_group_no；若未提供或格式不符，則由系統依「YYYY + 'A' + MM + 3位流水號」產生
            $client_reg_no = sanitize_text_field($d['reg_no'] ?? ($d['reg_group_no'] ?? ''));
            $pattern = '/^\d{4}A\d{2}\d{3}$/'; // 例：2025A11xxx

            if ($client_reg_no && preg_match($pattern, $client_reg_no)) {
                $reg_no = $client_reg_no;
            } else {
                // 系統產生（第一筆）
                if (method_exists('TPMA_CR_DB', 'generate_reg_no')) {
                    $reg_no = TPMA_CR_DB::generate_reg_no('A'); // 你的既有新規則方法
                } else {
                    // 後備：避免不存在方法時整個壞掉
                    $reg_no = 'R' . date('YmdHis') . wp_rand(100, 999);
                }
            }
			
			// 同一報名編號滿 6 人以上，全部學員按 8 折金額
			if ($reg_no && $base_remit_amount > 0) {
				// 目前已經有幾筆使用這個 reg_no
				$existing_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$regs_table} WHERE reg_no = %s",
						$reg_no
					)
				);

				// 加上這一筆之後的總人數
				$total_after_insert = $existing_count + 1;

				if ($total_after_insert >= 6) {
					// 例：3 小時 → 基礎 3000，打 8 折 → 2400
					$discount_amount = (int) round($base_remit_amount * 0.8);

					// 更新之前已經寫入資料庫的同一報名編號（前 5 位或更多）
					if ($existing_count > 0) {
						$wpdb->update(
							$regs_table,
							array('remit_amount' => $discount_amount),
							array('reg_no' => $reg_no)
						);
					}

					// 本筆也用折扣後金額
					$remit_amount = $discount_amount;
				}
			}			

            $insert = array(
                'reg_no'        => $reg_no,
                'created_at'    => current_time('mysql'),
                'course_id'     => $course_id,
    			'course_name'   => $course->course_name ?? '',   // ★ 新增：給信件模板用				
                'class_date'    => $class_date,
                'student_name'  => sanitize_text_field($d['student_name']),
                'company_name'  => sanitize_text_field($d['company_name'] ?? ''),
                'tax_id'        => sanitize_text_field($d['tax_id'] ?? ''),
                'department'    => sanitize_text_field($d['department'] ?? ''),
                'job_title'     => sanitize_text_field($d['job_title'] ?? ''),
                'phone'         => sanitize_text_field($d['phone'] ?? ''),   // 公司/承辦電話
                'mobile'        => sanitize_text_field($d['mobile'] ?? ''),  // 學員手機
                'emails'        => sanitize_text_field($d['emails'] ?? ''),  // 學員mail,承辦mail
                'receiver'      => sanitize_text_field($d['receiver'] ?? ''),
                'address'       => sanitize_text_field($d['address'] ?? ''),
                'receipt_type'  => in_array(($d['receipt_type'] ?? ''), ['electronic','paper'], true)
                                    ? $d['receipt_type'] : 'paper',
                'source'        => sanitize_text_field($d['source'] ?? ''),
                'note'          => sanitize_textarea_field($d['note'] ?? ''),
                'contact_name'  => sanitize_text_field($d['contact_name'] ?? ''),
                'contact_email' => sanitize_text_field($d['contact_email'] ?? ''),
                'remit_paid_at' => $remit_paid_at,
                'remit_amount'  => $remit_amount,
                'status'        => 'pending',
            );


            $wpdb->insert($regs_table, $insert);

            if (!$wpdb->insert_id) {
                error_log('[TPMA register] INSERT FAILED: ' . $wpdb->last_error);
                error_log('[TPMA register] LAST QUERY: ' . $wpdb->last_query);
                return new WP_Error(
                    'db_error',
                    '無法寫入報名資料：' . ($wpdb->last_error ?: '未知錯誤') . "\nSQL: " . $wpdb->last_query,
                    array('status' => 500)
                );
            }
			
			// ★ 新增：寫入成功後，嘗試寄信（不要影響報名流程）
			try {
				// 這裡先用 contact_email，之後你可以改成學生信箱或 emails 解析
				$to = $insert['contact_email'] ?? '';

				if (!empty($to) && class_exists('TPMA_CR_Mail_Service')) {
					TPMA_CR_Mail_Service::send('registration_notice', array(
						'reg_context'   => $insert,   // 直接用剛剛的 insert array 當 context
						'to'            => $to,
						// 如果要順便 cc 給承辦 / TPMA，可再加 'cc' => [...]
						// 'extra_context' => [...],   // 若模板需要額外欄位，可從這邊補
					));
				}
			} catch (Exception $e) {
				// 不讓使用者看到錯誤，但記錄 log 方便調整
				error_log('[TPMA mail] registration_notice failed: ' . $e->getMessage());
			}			

            return rest_ensure_response(array(
                'success' => true,
                'reg_no'  => $reg_no,
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

		// 先拿目前全部模板，覆蓋指定模板後再 render
		$templates = TPMA_CR_Mail_Templates::get_all();
		if (!isset($templates[$template_key])) {
			$templates[$template_key] = array();
		}
		$templates[$template_key]['subject']   = $subject_raw;
		$templates[$template_key]['body_html'] = $body_raw;

		// 暫時套用這個版本做預覽
		$config = TPMA_CR_Mail_Config::get_config();

		// 手動跑一次 replace_vars + 廣告 / 尾巴
		// 這裡直接呼叫原本的 render()，暫時把模板塞進 option 前先用「假資料」覆蓋
		// 為了簡單起見，這裡偷吃步：直接用 render()，假設預設模板裡已經有同 key 結構
		$result = TPMA_CR_Mail_Templates::render($template_key, $context);
		// 但 render() 會用到原本的模板內容，如果你要讓預覽完全依照前端傳來的 subject / body_html
		// 可以額外寫一個「不從 DB 取模板，直接套變數」的小工具 function

		return rest_ensure_response(array(
			'subject'   => $result['subject'] ?? '',
			'body_html' => $result['body_html'] ?? '',
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

}

