<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_Import
{
    public static function shortcode_import_admin()
    {
        if (!current_user_can('manage_options')) {
            return '<p>請先登入管理帳號。</p>';
        }
        ob_start();
        include TPMA_CR_PATH . 'views/import-admin.php';
        return ob_get_clean();
    }

    public static function handle_import()
    {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }

        $type     = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $redirect = !empty($_POST['_wp_http_referer']) ? $_POST['_wp_http_referer'] : admin_url();
        $csv_raw  = isset($_POST['csv']) ? (string)wp_unslash($_POST['csv']) : '';

        switch ($type) {
            case 'lecturers':
                check_admin_referer('tpma_import_lecturers');
                $msg = self::import_lecturers_csv($csv_raw);
                break;

            case 'courses':
                check_admin_referer('tpma_import_courses');
                $msg = self::import_courses_csv($csv_raw);
                break;

            case 'registrations':
                check_admin_referer('tpma_import_regs');
                $msg = self::import_regs_csv($csv_raw);
                break;

            default:
                $msg = '未知的匯入類型';
        }

        wp_safe_redirect(add_query_arg('tpma_import_result', urlencode($msg), $redirect));
        exit;
    }

    /* =========================================================
     * 共用：CSV 解析（支援多行欄位）
     * ======================================================= */

    /**
     * 從 textarea 貼上的 CSV 字串，解析成多筆「列」資料。
     * - 支援用 "..." 包起來的多行欄位（例如 outline）。
     * - 使用 str_getcsv() 處理逗號與引號。
     */
    private static function parse_csv_rows($csv_raw)
    {
        $csv_raw = trim($csv_raw);
        if ($csv_raw === '') {
            return array();
        }

        $lines = preg_split("/\r\n|\r|\n/", $csv_raw);
        $rows = array();

        $buffer = '';
        $inQuote = false;

        foreach ($lines as $line) {
            // 把每一行接到 buffer
            if ($buffer !== '') {
                $buffer .= "\n" . $line;
            } else {
                $buffer = $line;
            }

            // 粗略計算雙引號數量（不處理轉義情境，足夠應付一般匯入）
            $quoteCount = substr_count($buffer, '"');

            // 引號成對時視為一完整列
            if ($quoteCount % 2 === 0) {
                $cols = str_getcsv($buffer);
                // 檢查非全空
                $nonEmpty = false;
                foreach ($cols as $c) {
                    if (trim($c) !== '') {
                        $nonEmpty = true;
                        break;
                    }
                }
                if ($nonEmpty) {
                    $rows[] = $cols;
                }
                $buffer = '';
            }
        }

        // 收尾：如果還有殘留 buffer，最後再當一列處理
        if (trim($buffer) !== '') {
            $cols = str_getcsv($buffer);
            $nonEmpty = false;
            foreach ($cols as $c) {
                if (trim($c) !== '') {
                    $nonEmpty = true;
                    break;
                }
            }
            if ($nonEmpty) {
                $rows[] = $cols;
            }
        }

        return $rows;
    }

    /* =========================================================
     * 講師匯入
     * ======================================================= */

    private static function import_lecturers_csv($csv_raw)
    {
        global $wpdb;
        $lecturers_table = TPMA_CR_DB::table('lecturers');

        $rows = self::parse_csv_rows($csv_raw);
        if (empty($rows)) {
            return '沒有資料可匯入。';
        }

        $count = 0; $update = 0; $skip = 0;

        foreach ($rows as $i => $cols) {
            if ($i === 0 && isset($cols[0]) && stripos($cols[0], 'code') !== false) {
                continue; // 跳過標題列
            }

            $code  = sanitize_text_field($cols[0] ?? '');
            $name  = sanitize_text_field($cols[1] ?? '');
            $title = sanitize_text_field($cols[2] ?? '');
            $sort  = isset($cols[3]) && $cols[3] !== '' ? intval($cols[3]) : null;

            if ($code === '' || $name === '') {
                $skip++;
                continue;
            }

            if ($sort === null) {
                $max  = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$lecturers_table}");
                $sort = $max + 10;
            }

            $existing_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$lecturers_table} WHERE code = %s", $code)
            );

            $data = array(
                'code'       => $code,
                'name'       => $name,
                'title'      => $title,
                'sort_order' => $sort,
            );

            if ($existing_id) {
                $wpdb->update($lecturers_table, $data, array('id' => $existing_id));
                $update++;
            } else {
                $wpdb->insert($lecturers_table, $data);
                $count++;
            }
        }

        return "講師匯入完成：新增 {$count} 筆，更新 {$update} 筆，略過 {$skip} 筆。";
    }

    /* =========================================================
     * 課程匯入（支援 outline 多行）
     * ======================================================= */

    private static function import_courses_csv($csv_raw)
    {
        global $wpdb;
        $courses_table  = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');

        $rows = self::parse_csv_rows($csv_raw);
        if (empty($rows)) {
            return '沒有資料可匯入。';
        }

        $count = 0; $update = 0; $skip = 0;

        /*
         * 欄位建議：
         * 0: course_code
         * 1: course_name
         * 2: category_code
         * 3: lecturer_code
         * 4: intro
         * 5: outline (可用 "...." 包住多行)
         * 6: is_active (1/0，可空)
         * 7: sessions (用 | 分隔 "YYYY-MM-DD HH:MM")
         * 8: duration_minutes (選填，預設 180)
         */

        foreach ($rows as $i => $cols) {
            if ($i === 0 && isset($cols[0]) && stripos($cols[0], 'course_code') !== false) {
                continue; // 標題列
            }

            $course_code   = sanitize_text_field($cols[0] ?? '');
            $course_name   = sanitize_text_field($cols[1] ?? '');
            $category_code = sanitize_text_field($cols[2] ?? '');
            $lecturer_code = sanitize_text_field($cols[3] ?? '');
            $intro         = (string)($cols[4] ?? '');
            $outline       = (string)($cols[5] ?? '');
            $is_active_raw = isset($cols[6]) ? trim((string)$cols[6]) : '';
            $sessions_raw  = (string)($cols[7] ?? '');
            $duration_raw  = isset($cols[8]) ? trim((string)$cols[8]) : '';

            if ($course_name === '' || $category_code === '' || $lecturer_code === '') {
                $skip++;
                continue;
            }

            $is_active = ($is_active_raw === '0') ? 0 : 1;
            $duration  = ($duration_raw !== '' ? intval($duration_raw) : 180);
            if ($duration <= 0) {
                $duration = 180;
            }

            // 自動產生 course_code（講師碼 + 類別碼 + 流水號）
            if ($course_code === '') {
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
                $skip++;
                continue;
            }

            // 類別顯示文字（可選）
            $category = '';
            switch ($category_code) {
                case 'A1': $category = '董事的法律義務與責任'; break;
                case 'A2': $category = '董事會的架構與運作'; break;
                case 'A3': $category = '提升董事會績效'; break;
                case 'A4': $category = '財務、會計'; break;
                case 'A5': $category = '永續發展'; break;
                case 'B1': $category = '董事會成員和管理團隊之間的關係與合作'; break;
                case 'B2': $category = '董事與股東會事務'; break;
                case 'B3': $category = '公司所屬產業之業務、商務'; break;
                case 'B4': $category = '風險管理、內部控制、數位治理'; break;
                case 'B5': $category = '其他'; break;
            }

            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$courses_table} WHERE course_code = %s",
                    $course_code
                )
            );

            $data = array(
                'course_code'      => $course_code,
                'course_name'      => $course_name,
                'category_code'    => $category_code,
                'category'         => $category ?: '',
                'lecturer_code'    => $lecturer_code,
                'intro'            => wp_kses_post($intro),
                // outline：保留多行 Markdown 原文
                'outline'          => wp_kses_post($outline),
                'updated_at'       => current_time('mysql'),
                'is_active'        => $is_active ? 1 : 0,
                'duration_minutes' => $duration,
            );

            if ($existing_id) {
                $wpdb->update($courses_table, $data, array('id' => $existing_id));
                $course_id = (int)$existing_id;
                $update++;
            } else {
                $wpdb->insert($courses_table, $data);
                $course_id = (int)$wpdb->insert_id;
                if (!$course_id) {
                    $skip++;
                    continue;
                }
                $count++;
            }

            // 重建場次
            $wpdb->delete($sessions_table, array('course_id' => $course_id));

            $sessions_raw = trim($sessions_raw);
            if ($sessions_raw !== '') {
                $parts = explode('|', $sessions_raw);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p === '') continue;
                    $dt = str_replace('T', ' ', $p);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $dt)) {
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
                }
            }
        }

        return "課程匯入完成：新增 {$count} 筆，更新 {$update} 筆，略過 {$skip} 筆。";
    }

    /* =========================================================
     * 報名 / 學員匯入（簡化版）
     * ======================================================= */

    private static function import_regs_csv($csv_raw)
    {
        global $wpdb;
        $regs_table    = TPMA_CR_DB::table('regs');
        $courses_table = TPMA_CR_DB::table('courses');

        $rows = self::parse_csv_rows($csv_raw);
        if (empty($rows)) {
            return '沒有資料可匯入。';
        }

        $count = 0; $update = 0; $skip = 0;

        foreach ($rows as $i => $cols) {
            if ($i === 0 && isset($cols[0]) && stripos($cols[0], 'reg_no') !== false) {
                continue;
            }

            $reg_no       = sanitize_text_field($cols[0] ?? '');
            $course_code  = sanitize_text_field($cols[1] ?? '');
            $course_name  = sanitize_text_field($cols[2] ?? '');
            $lecturer     = sanitize_text_field($cols[3] ?? '');
            $class_date   = sanitize_text_field($cols[4] ?? '');
            $student_name = sanitize_text_field($cols[5] ?? '');
            $company_name = sanitize_text_field($cols[6] ?? '');
            $tax_id       = sanitize_text_field($cols[7] ?? '');
            $department   = sanitize_text_field($cols[8] ?? '');
            $job_title    = sanitize_text_field($cols[9] ?? '');
            $phone        = sanitize_text_field($cols[10] ?? '');
            $emails       = sanitize_text_field($cols[11] ?? '');
            $receiver     = sanitize_text_field($cols[12] ?? '');
            $address      = sanitize_text_field($cols[13] ?? '');
            $source       = sanitize_text_field($cols[14] ?? '');
            $note         = sanitize_textarea_field($cols[15] ?? '');
            $remit_account= sanitize_text_field($cols[16] ?? '');
            $remit_date   = sanitize_text_field($cols[17] ?? '');
            $remit_amount = ($cols[18] ?? '') !== '' ? floatval($cols[18]) : null;
            $status       = sanitize_text_field($cols[19] ?? 'pending');

            if ($student_name === '') {
                $skip++;
                continue;
            }

            // 找 course_id
            $course_id = 0;
            if ($course_code !== '') {
                $course_id = (int)$wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$courses_table} WHERE course_code = %s",
                        $course_code
                    )
                );
            }

            if ($reg_no === '') {
                $reg_no = 'I' . date('YmdHis') . wp_rand(100,999);
            }

            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$regs_table} WHERE reg_no = %s",
                    $reg_no
                )
            );

            $data = array(
                'reg_no'       => $reg_no,
                'created_at'   => current_time('mysql'),
                'course_id'    => $course_id ?: 0,
                'course_name'  => $course_name,
                'lecturer'     => $lecturer,
                'class_date'   => $class_date ?: null,
                'student_name' => $student_name,
                'company_name' => $company_name,
                'tax_id'       => $tax_id,
                'department'   => $department,
                'job_title'    => $job_title,
                'phone'        => $phone,
                'emails'       => $emails,
                'receiver'     => $receiver,
                'address'      => $address,
                'source'       => $source,
                'note'         => $note,
                'remit_account'=> $remit_account,
                'remit_date'   => $remit_date ?: null,
                'remit_amount' => $remit_amount,
                'status'       => $status ?: 'pending',
            );

            if ($existing_id) {
                unset($data['reg_no'], $data['created_at']);
                $wpdb->update($regs_table, $data, array('id' => $existing_id));
                $update++;
            } else {
                $wpdb->insert($regs_table, $data);
                if ($wpdb->insert_id) {
                    $count++;
                } else {
                    $skip++;
                }
            }
        }

        return "報名匯入完成：新增 {$count} 筆，更新 {$update} 筆，略過 {$skip} 筆。";
    }
}
