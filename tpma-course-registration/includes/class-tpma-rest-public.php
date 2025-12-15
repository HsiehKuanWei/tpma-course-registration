<?php

if (!defined('ABSPATH')) {

    exit;

}

// WooCommerce hooks for暫存草稿
add_action('woocommerce_before_calculate_totals', array('TPMA_CR_REST_Public', 'apply_cart_price'));
add_action('woocommerce_review_order_before_order_total', array('TPMA_CR_REST_Public', 'render_checkout_summary'));



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

            'methods'  => 'POST',

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

            // === 程式化建立 WooCommerce 訂單 ===
            $woocommerce_product_id = 1083; // 上市櫃董事課程的 WooCommerce 商品 ID
            $product = wc_get_product($woocommerce_product_id);

            if (!$product) {
                error_log("TPMA Integration: WooCommerce product ID {$woocommerce_product_id} not found.");
                return new WP_Error('wc_product_not_found', 'WooCommerce 課程商品不存在', array('status' => 500));
            }

            $order = wc_create_order();
            $item = $order->add_product($product, $total_learners); // Add the single WC product with quantity = total learners
            // Determine billing name and email based on contact_same_first
            $billing_first_name = sanitize_text_field($shared['contact_name'] ?? '');
            $billing_email      = sanitize_email($shared['contact_email'] ?? '');

            if (!empty($shared['contact_same_first']) && !empty($learners[0])) {
                $billing_first_name = sanitize_text_field($learners[0]['student_name'] ?? '');
                $billing_email      = sanitize_email($learners[0]['emails'] ?? '');
            }

            $order->set_address([
                'first_name' => $billing_first_name,
                'email'      => $billing_email,
                'company'    => sanitize_text_field($shared['company_name'] ?? ''),
                'phone'      => sanitize_text_field($shared['phone'] ?? ''),
                'address_1'  => sanitize_text_field($shared['address'] ?? ''),
                'city'       => '',
                'state'      => '',
                'postcode'   => '',
                'country'    => 'TW',
            ], 'billing');
            $order->set_address([
                'first_name' => sanitize_text_field($shared['receiver'] ?? $billing_first_name), // Receiver or billing name
                'address_1'  => sanitize_text_field($shared['address'] ?? ''),
                'city'       => '',
                'state'      => '',
                'postcode'   => '',
                'country'    => 'TW',
            ], 'shipping');

            $order->set_total($total_order_amount);
            $order->set_currency('TWD'); // Assuming New Taiwan Dollar
            $order->set_status('pending'); // Set initial status to pending (等待付款)
            $order->set_payment_method('bacs'); // Set payment method to Bank Transfer (銀行轉帳)
            $order->set_payment_method_title('銀行轉帳'); // Set payment method title

            // Add notes to WooCommerce order
            if (!empty($shared['note'])) {
                $order->add_order_note(sanitize_textarea_field($shared['note']));
            }

            // Store TPMA reg_no as order meta
            $order->update_meta_data('_tpma_reg_no', $reg_no);
            $order->update_meta_data('_tpma_course_id', $course_id); // Store the TPMA course_id as well
            $order->update_meta_data('_tpma_session_id', $session_id); // Store the TPMA session_id as well
            $order->update_meta_data('_tpma_receipt_type', sanitize_text_field($shared['receipt_type'] ?? ''));
            $order->update_meta_data('_billing_vat_id', sanitize_text_field($shared['tax_id'] ?? ''));
            $order->update_meta_data('_tpma_learner_count', $total_learners);

            // Ensure WC line item/total 等於「每人金額 x 人數」
            if ($item) {
                $line_total = $total_order_amount;
                $item->set_subtotal($line_total);
                $item->set_total($line_total);
            }
            $order->set_total($total_order_amount);
            $order->calculate_totals(false);
            $order->save();
            $woocommerce_order_id = $order->get_id();

            // 更新 wp_tpma_registrations 表中的 woocommerce_order_id
            if (!empty($inserted_reg_ids) && $woocommerce_order_id) {
                $ids_in = implode(',', array_map('intval', $inserted_reg_ids));
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$regs_table} SET woocommerce_order_id = %d, payment_status = %s WHERE id IN ({$ids_in})",
                        $woocommerce_order_id,
                        $order->get_status()
                    )
                );
            }

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
        if (!function_exists('WC')) {
            return new WP_Error('no_woocommerce', 'WooCommerce 尚未載入', array('status' => 500));
        }

        $d = $request->get_json_params();
        if (empty($d['course_id']) || empty($d['session_id']) || empty($d['learners']) || !is_array($d['learners'])) {
            return new WP_Error('invalid_data', '缺少必要欄位', array('status' => 400));
        }

        $course_id  = intval($d['course_id']);
        $session_id = intval($d['session_id']);
        $learners   = $d['learners'];
        $source     = sanitize_text_field($d['source'] ?? '');
        $note       = sanitize_textarea_field($d['note'] ?? '');

        global $wpdb;
        $courses_table   = TPMA_CR_DB::table('courses');
        $sessions_table  = TPMA_CR_DB::table('sessions');
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $course = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$courses_table} WHERE id = %d", $course_id)
        );
        if (!$course) {
            return new WP_Error('course_not_found', '課程不存在', array('status' => 404));
        }

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d AND course_id = %d", $session_id, $course_id)
        );
        if (!$session || empty($session->session_datetime)) {
            return new WP_Error('session_required', '需先排定上課時間後才能報名', array('status' => 400));
        }

        $duration_minutes   = intval($course->duration_minutes ?? 0);
        $hours              = $duration_minutes / 60;
        $base_remit_amount  = (int) round($hours * 1000);
        $remit_amount_per_learner = $base_remit_amount;

        $clean_learners = array();
        foreach ($learners as $learner) {
            $name = sanitize_text_field($learner['student_name'] ?? '');
            if ($name === '') continue;
            $clean_learners[] = array(
                'student_name' => $name,
                'department'   => sanitize_text_field($learner['department'] ?? ''),
                'job_title'    => sanitize_text_field($learner['job_title'] ?? ''),
                'mobile'       => sanitize_text_field($learner['mobile'] ?? ''),
                'emails'       => sanitize_email($learner['emails'] ?? ''),
            );
        }

        $total_learners = count($clean_learners);
        if ($total_learners === 0) {
            return new WP_Error('no_learners', '請至少填寫一位學員', array('status' => 400));
        }
        if ($total_learners >= 6) {
            $remit_amount_per_learner = (int) round($base_remit_amount * 0.8);
        }
        $total_order_amount = $remit_amount_per_learner * $total_learners;

        $lecturer_name = '';
        if (!empty($course->lecturer_code)) {
            $lect = $wpdb->get_row($wpdb->prepare(
                "SELECT lecturers_name, lecturers_title FROM {$lecturers_table} WHERE lecturers_code = %s",
                $course->lecturer_code
            ));
            if ($lect && !empty($lect->lecturers_name)) {
                $lecturer_name = trim($lect->lecturers_name . (!empty($lect->lecturers_title) ? ' ' . $lect->lecturers_title : ''));
            }
        }

        $draft = array(
            'course_id'    => $course_id,
            'session_id'   => $session_id,
            'course_name'  => $course->course_name,
            'lecturer'     => $lecturer_name,
            'session_datetime' => $session->session_datetime,
            'class_date'   => date('Y-m-d', strtotime($session->session_datetime)),
            'learners'     => $clean_learners,
            'total_learners' => $total_learners,
            'remit_amount_per_learner' => $remit_amount_per_learner,
            'total_order_amount'       => $total_order_amount,
            'source'       => $source,
            'note'         => $note,
        );

        WC()->session->set('tpma_reg_draft', $draft);

        $product_id = 1083; // 隱藏商品
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('wc_product_not_found', 'WooCommerce 課程商品不存在', array('status' => 500));
        }

        $cart = WC()->cart;
        if (!$cart) {
            return new WP_Error('no_cart', '無法初始化購物車', array('status' => 500));
        }

        // 移除舊草稿品項，避免混淆
        foreach ($cart->get_cart() as $key => $item) {
            if (!empty($item['tpma_reg_draft'])) {
                $cart->remove_cart_item($key);
            }
        }

        $cart->add_to_cart($product_id, $total_learners, 0, array(), array(
            'tpma_reg_draft' => true,
        ));

        $checkout_url = wc_get_checkout_url();

        return rest_ensure_response(array(
            'success'      => true,
            'checkout_url' => $checkout_url,
        ));
    }

    /**
     * 結帳頁右側摘要：課程＋學員清單
     */
    public static function render_checkout_summary() {
        $draft = WC()->session ? WC()->session->get('tpma_reg_draft') : null;
        if (empty($draft) || empty($draft['course_name'])) {
            return;
        }
        $date_str = self::format_class_datetime($draft['session_datetime'] ?? '');
        echo '<div class=\"tpma-checkout-summary\" style=\"margin-bottom:12px;padding:10px;border:1px solid #ddd;\">';
        echo '<strong>課程：</strong>' . esc_html($draft['course_name']) . '<br>';
        if ($date_str) {
            echo '<strong>日期：</strong>' . esc_html($date_str) . '<br>';
        }
        if (!empty($draft['learners'])) {
            echo '<strong>學員：</strong><ul style=\"margin:6px 0 0 16px;padding:0;\">';
            foreach ($draft['learners'] as $l) {
                $line = $l['student_name'] ?? '';
                if (!empty($l['job_title'])) {
                    $line .= '（' . $l['job_title'] . '）';
                }
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /**
     * 依暫存草稿覆寫 cart 價格
     */
    public static function apply_cart_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !WC()->session) {
            return;
        }
        $draft = WC()->session->get('tpma_reg_draft');
        if (empty($draft) || empty($draft['remit_amount_per_learner'])) {
            return;
        }
        $price = floatval($draft['remit_amount_per_learner']);
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['tpma_reg_draft'])) {
                $cart_item['data']->set_price($price);
            }
        }
    }

    /**
     * 2025-12-20 09:00:00 -> 2025/12/20(六) 09:00~12:00
     */
    private static function format_class_datetime($datetime) {
        if (empty($datetime)) return '';
        $start_ts = strtotime($datetime);
        if (!$start_ts) return '';
        $date_str  = date('Y/m/d', $start_ts);
        $weeknames = array('日','一','二','三','四','五','六');
        $week_str  = $weeknames[(int)date('w', $start_ts)] ?? '';
        $time_range = date('H:i', $start_ts);
        return sprintf('%s(%s) %s', $date_str, $week_str, $time_range);
    }

}
