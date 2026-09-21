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

            case 'legacy_registrations':
                check_admin_referer('tpma_import_legacy_regs');
                $msg = self::import_legacy_regs_csv($csv_raw);
                break;

            case 'legacy_backfill_amounts':
                check_admin_referer('tpma_import_legacy_backfill_amounts');
                $msg = self::backfill_legacy_amounts();
                break;

            default:
                $msg = '未知的匯入類型';
        }

        $result_key = sanitize_key('tpma_import_' . wp_generate_password(12, false, false));
        set_transient($result_key, $msg, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('tpma_import_result_key', rawurlencode($result_key), $redirect));
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

    /* =========================================================
     * 舊報名資料匯入：只做查詢留存，不建立 Woo 訂單。
     * ======================================================= */

    private static function normalize_date($value)
    {
        $value = trim(str_replace('/', '-', (string)$value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    private static function normalize_datetime($value)
    {
        $value = trim(str_replace(array('/', 'T', '～', '－', '–', '—'), array('-', ' ', '~', '-', '-', '-'), (string)$value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[（(]\s*[一二三四五六日天週周星期禮拜A-Za-z0-9\s]+\s*[）)]/u', '', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s*(上午|下午|AM|PM|A\.M\.|P\.M\.)?\s*(\d{1,2}):(\d{2})(?::(\d{2}))?)?/iu', $value, $m)) {
            $meridiem = isset($m[4]) ? strtoupper(str_replace('.', '', (string)$m[4])) : '';
            $hour = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
            $min  = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
            $sec  = isset($m[7]) && $m[7] !== '' ? (int)$m[7] : 0;
            if (($meridiem === '下午' || $meridiem === 'PM') && $hour < 12) {
                $hour += 12;
            } elseif (($meridiem === '上午' || $meridiem === 'AM') && $hour === 12) {
                $hour = 0;
            }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3], $hour, $min, $sec);
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }

    private static function sanitize_emails_raw($raw)
    {
        $raw = str_replace(array(';', '，', '、'), ',', (string)$raw);
        $parts = preg_split('/[\s,]+/', $raw);
        $emails = array();
        foreach ((array)$parts as $part) {
            $email = sanitize_email(trim($part));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        return implode(',', array_values(array_unique($emails)));
    }

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

    private static function infer_amount_from_text($text)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/(?:金額|費用|學費|匯款|實收|收款|繳費)[：:\s]*(?:NT\\$|NTD|TWD|\\$)?\\s*([0-9][0-9,]*(?:\\.[0-9]+)?)/iu', $text, $m)) {
            return self::normalize_amount($m[1]);
        }
        if (preg_match('/(?:NT\\$|NTD|TWD|\\$)\\s*([0-9][0-9,]*(?:\\.[0-9]+)?)/iu', $text, $m)) {
            return self::normalize_amount($m[1]);
        }
        if (preg_match('/([0-9][0-9,]*(?:\\.[0-9]+)?)\\s*(?:元|圓)/u', $text, $m)) {
            return self::normalize_amount($m[1]);
        }
        return null;
    }

    private static function infer_legacy_amount_from_duration($duration_minutes)
    {
        return (int)$duration_minutes === 180 ? 3000 : null;
    }

    private static function normalize_duration_minutes($duration_raw, $start_raw = '', $end_raw = '')
    {
        $duration_raw = trim((string)$duration_raw);
        if ($duration_raw !== '') {
            $clean = preg_replace('/[^\d\.]/', '', $duration_raw);
            if ($clean !== '') {
                $value = (float)$clean;
                if ($value > 0) {
                    return (int)round($value <= 24 ? $value * 60 : $value);
                }
            }
        }

        $start = self::normalize_datetime($start_raw);
        $end = trim((string)$end_raw);
        if ($end === '' && preg_match('/(\d{1,2}:\d{2}(?::\d{2})?)\s*(?:~|-|至|到)\s*(\d{1,2}:\d{2}(?::\d{2})?)/u', str_replace(array('～', '－', '–', '—'), array('~', '-', '-', '-'), (string)$start_raw), $m)) {
            $end = $m[2];
        }
        if ($start !== '' && $end !== '') {
            if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $end)) {
                $end = substr($start, 0, 10) . ' ' . $end;
            }
            $end = self::normalize_datetime($end);
            if ($end !== '') {
                $start_ts = strtotime($start);
                $end_ts = strtotime($end);
                if ($start_ts && $end_ts && $end_ts > $start_ts) {
                    return max(1, (int)round(($end_ts - $start_ts) / 60));
                }
            }
        }

        return 180;
    }

    private static function legacy_course_code($legacy_code, $course_name, $lecturer_name = '')
    {
        $source = trim((string)$legacy_code) !== '' ? (string)$legacy_code : (string)$course_name;
        $base = strtoupper(preg_replace('/[^A-Z0-9_-]+/i', '-', $source));
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'COURSE';
        }
        $hash = strtoupper(substr(md5(trim($source) . '|' . trim((string)$course_name) . '|' . trim((string)$lecturer_name)), 0, 8));
        return substr('LEGACY-' . $base . '-' . $hash, 0, 50);
    }

    private static function legacy_lecturer_code($lecturer_name)
    {
        $name = trim((string)$lecturer_name);
        $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $name));
        if ($base === '') {
            $base = 'LECT';
        }
        $hash = strtoupper(substr(md5($name), 0, 8));
        return substr('LGC' . $base . $hash, 0, 20);
    }

    private static function get_or_create_legacy_lecturer($lecturer_name)
    {
        global $wpdb;

        $lecturer_name = sanitize_text_field($lecturer_name);
        if ($lecturer_name === '') {
            return null;
        }

        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $schema = TPMA_CR_DB::get_lecturer_schema();
        $code_col = $schema['code'];
        $name_col = $schema['name'];
        $title_col = $schema['title'];

        $display_sql = $title_col !== ''
            ? "CONCAT({$name_col}, CASE WHEN {$title_col} IS NULL OR {$title_col} = '' THEN '' ELSE CONCAT(' ', {$title_col}) END)"
            : $name_col;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT {$code_col} FROM {$lecturers_table} WHERE {$name_col} = %s OR {$display_sql} = %s ORDER BY id ASC LIMIT 1",
            $lecturer_name,
            $lecturer_name
        ));
        if ($existing) {
            return (string)$existing;
        }

        $code = self::legacy_lecturer_code($lecturer_name);
        $suffix = 2;
        $base = substr($code, 0, 17);
        while ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$lecturers_table} WHERE {$code_col} = %s", $code)) > 0 && $suffix < 100) {
            $code = $base . $suffix;
            $suffix++;
        }

        $data = array(
            $code_col => $code,
            $name_col => $lecturer_name,
            'email' => '',
            'is_active' => 0,
            'updated_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%s', '%d', '%s');
        if ($title_col !== '') {
            $data[$title_col] = '';
            $formats[] = '%s';
        }

        $ok = $wpdb->insert($lecturers_table, $data, $formats);
        return $ok ? $code : null;
    }

    private static function find_exact_existing_course($legacy_code, $course_name, $lecturer_name)
    {
        global $wpdb;

        $legacy_code = sanitize_text_field($legacy_code);
        $course_name = sanitize_text_field($course_name);
        $lecturer_name = sanitize_text_field($lecturer_name);
        if ($legacy_code === '' || $course_name === '') {
            return null;
        }

        $courses_table = TPMA_CR_DB::table('courses');
        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $lecturer_join_sql = TPMA_CR_DB::sql_lecturer_join_on_course('l', 'c');
        $lecturer_display_sql = TPMA_CR_DB::sql_lecturer_display('l');
        $schema = TPMA_CR_DB::get_lecturer_schema();
        $name_col = $schema['name'];

        if ($lecturer_name === '') {
            $sql = "
                SELECT c.id, c.course_name, c.lecturer_code, c.duration_minutes
                FROM {$courses_table} c
                LEFT JOIN {$lecturers_table} l ON {$lecturer_join_sql}
                WHERE c.course_code = %s
                  AND c.course_name = %s
                  AND COALESCE(c.lecturer_code, '') = ''
                ORDER BY c.id ASC
                LIMIT 1
            ";
            return $wpdb->get_row($wpdb->prepare($sql, $legacy_code, $course_name), ARRAY_A);
        }

        $sql = "
            SELECT c.id, c.course_name, c.lecturer_code, c.duration_minutes
            FROM {$courses_table} c
            LEFT JOIN {$lecturers_table} l ON {$lecturer_join_sql}
            WHERE c.course_code = %s
              AND c.course_name = %s
              AND (l.{$name_col} = %s OR {$lecturer_display_sql} = %s)
            ORDER BY c.id ASC
            LIMIT 1
        ";

        return $wpdb->get_row($wpdb->prepare($sql, $legacy_code, $course_name, $lecturer_name, $lecturer_name), ARRAY_A);
    }

    private static function get_or_create_legacy_course($legacy_code, $course_name, $lecturer_name = '', $duration_minutes = 180, $duration_is_explicit = false)
    {
        global $wpdb;
        $courses_table = TPMA_CR_DB::table('courses');

        $course_name = sanitize_text_field($course_name);
        if ($course_name === '') {
            $course_name = '舊資料未命名課程';
        }

        $duration_minutes = max(1, (int)$duration_minutes);
        $exact_existing = self::find_exact_existing_course($legacy_code, $course_name, $lecturer_name);
        if ($exact_existing) {
            return (int)$exact_existing['id'];
        }

        $code = self::legacy_course_code($legacy_code, $course_name, $lecturer_name);
        $lecturer_code = self::get_or_create_legacy_lecturer($lecturer_name);
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, course_name, lecturer_code, duration_minutes FROM {$courses_table} WHERE course_code = %s AND course_name = %s AND COALESCE(lecturer_code, '') = %s ORDER BY id ASC LIMIT 1",
                $code,
                $course_name,
                (string)$lecturer_code
            ),
            ARRAY_A
        );
        if ($existing) {
            if ($duration_is_explicit && (int)($existing['duration_minutes'] ?? 0) !== $duration_minutes) {
                $wpdb->update(
                    $courses_table,
                    array('duration_minutes' => $duration_minutes, 'updated_at' => current_time('mysql')),
                    array('id' => (int)$existing['id']),
                    array('%d', '%s'),
                    array('%d')
                );
            }
            return (int)$existing['id'];
        }

        $collision = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$courses_table} WHERE course_code = %s", $code));
        if ($collision) {
            $suffix = 2;
            $base = substr($code, 0, 47);
            do {
                $code = $base . '-' . $suffix;
                $suffix++;
                $collision = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$courses_table} WHERE course_code = %s", $code));
            } while ($collision && $suffix < 100);
        }

        $ok = $wpdb->insert($courses_table, array(
            'course_code'      => $code,
            'course_name'      => $course_name,
            'category'         => '舊資料',
            'category_code'    => 'LEGACY',
            'lecturer_code'    => $lecturer_code,
            'intro'            => trim((string)$legacy_code) !== '' ? '舊資料匯入；原課程編號：' . sanitize_text_field($legacy_code) : '舊資料匯入',
            'outline'          => '',
            'class_date'       => null,
            'updated_at'       => current_time('mysql'),
            'is_active'        => 0,
            'duration_minutes' => $duration_minutes,
        ), array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d'));

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    private static function get_or_create_legacy_session($course_id, $class_date_raw)
    {
        global $wpdb;
        $course_id = (int)$course_id;
        if ($course_id <= 0) {
            return 0;
        }

        $dt = self::normalize_datetime($class_date_raw);
        if ($dt === '') {
            return 0;
        }

        $sessions_table = TPMA_CR_DB::table('sessions');
        $existing = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$sessions_table} WHERE course_id = %d AND session_datetime = %s ORDER BY id ASC LIMIT 1",
            $course_id,
            $dt
        ));
        if ($existing > 0) {
            return $existing;
        }

        $ok = $wpdb->insert($sessions_table, array(
            'course_id'         => $course_id,
            'session_datetime'  => $dt,
            'is_active'         => 0,
            'visibility_override' => 'force_hide',
            'delivery_mode'     => 'live',
            'created_at'        => current_time('mysql'),
        ), array('%d','%s','%d','%s','%s','%s'));

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    private static function filter_existing_columns($table_key, array $data)
    {
        $columns = TPMA_CR_DB::get_table_columns($table_key);
        if (empty($columns)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($columns));
    }

    private static function generate_legacy_reg_no()
    {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        for ($i = 0; $i < 10; $i++) {
            $candidate = 'L' . date('YmdHis') . wp_rand(100, 999);
            $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$regs_table} WHERE reg_no = %s", $candidate));
            if (!$exists) {
                return $candidate;
            }
            usleep(10000);
        }

        return 'L' . date('YmdHis') . wp_rand(1000, 9999);
    }

    private static function legacy_chinese_fields()
    {
        return array(
            'registered_at'    => '報名時間',
            'course_name'      => '課程名稱',
            'lecturer'         => '講師',
            'class_date'       => '授課日期',
            'student_name'     => '學員姓名',
            'company_name'     => '公司抬頭',
            'tax_id'           => '公司統編',
            'department'       => '任職部門',
            'job_title'        => '職稱',
            'phone'            => '連絡電話',
            'emails'           => 'Email',
            'receiver'         => '收件人',
            'address'          => '通訊地址',
            'source'           => '資訊來源',
            'customer_note'    => '顧客備註',
            'remit_paid_at'    => '繳費日期',
            'remit_account'    => '匯款帳號',
            'remit_amount'     => '金額',
            'association_note' => '學會備註',
            'status'           => '報名狀態',
            'legacy_course_code' => '課程編號',
            'duration_hours'   => '課程時數',
            'class_end_at'     => '結束時間',
        );
    }

    private static function legacy_english_fields()
    {
        return array(
            'reg_no',
            'legacy_course_code',
            'course_name',
            'class_date',
            'student_name',
            'company_name',
            'tax_id',
            'department',
            'job_title',
            'mobile',
            'emails',
            'contact_name',
            'contact_email',
            'phone',
            'receipt_type',
            'address',
            'receiver',
            'source',
            'note',
            'remit_account',
            'remit_paid_at',
            'remit_amount',
            'status',
            'duration_minutes',
            'class_end_at',
        );
    }

    private static function legacy_header_map(array $cols)
    {
        $known = array();
        foreach (self::legacy_chinese_fields() as $key => $label) {
            $known[$label] = $key;
        }
        foreach (self::legacy_english_fields() as $key) {
            $known[$key] = $key;
        }
        $known['舊課程編號'] = 'legacy_course_code';
        $known['狀態'] = 'status';
        $known['取消狀態'] = 'status';
        $known['是否取消'] = 'status';
        $known['費用'] = 'remit_amount';
        $known['課程費用'] = 'remit_amount';
        $known['學費'] = 'remit_amount';
        $known['實收金額'] = 'remit_amount';
        $known['收款金額'] = 'remit_amount';
        $known['繳費金額'] = 'remit_amount';
        $known['匯款金額'] = 'remit_amount';
        $known['報名費'] = 'remit_amount';
        $known['時數'] = 'duration_hours';
        $known['授課時數'] = 'duration_hours';
        $known['課程分鐘'] = 'duration_minutes';
        $known['結束日期'] = 'class_end_at';
        $known['下課時間'] = 'class_end_at';

        $map = array();
        $matches = 0;
        foreach ($cols as $index => $col) {
            $label = trim((string)$col);
            if ($index === 0) {
                $label = preg_replace('/^\xEF\xBB\xBF/', '', $label);
            }
            if (isset($known[$label])) {
                $map[$known[$label]] = $index;
                $matches++;
            }
        }

        return $matches >= 2 ? $map : array();
    }

    private static function legacy_default_map(array $cols)
    {
        $first = trim((string)($cols[0] ?? ''));
        $use_chinese_order = count($cols) === 19
            || count($cols) === 20
            || preg_match('/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}/', $first);
        if ($use_chinese_order) {
            $fields = array(
                'registered_at',
                'course_name',
                'lecturer',
                'class_date',
                'student_name',
                'company_name',
                'tax_id',
                'department',
                'job_title',
                'phone',
                'emails',
                'receiver',
                'address',
                'source',
                'customer_note',
                'remit_paid_at',
                'remit_account',
                'remit_amount',
                'association_note',
            );
            if (count($cols) >= 20) {
                array_splice($fields, 1, 0, array('legacy_course_code'));
            }
            $fields[] = 'duration_hours';
            $fields[] = 'class_end_at';
            $fields[] = 'status';
        } else {
            $fields = self::legacy_english_fields();
        }
        $map = array();
        foreach ($fields as $index => $key) {
            $map[$key] = $index;
        }
        return $map;
    }

    private static function legacy_col(array $cols, array $map, $key, $default = '')
    {
        if (!array_key_exists($key, $map)) {
            return $default;
        }
        $index = (int)$map[$key];
        return array_key_exists($index, $cols) ? $cols[$index] : $default;
    }

    private static function normalize_legacy_status($value)
    {
        $value = trim((string)$value);
        $map = array(
            '保留' => 'hold',
            '取消' => 'cancelled',
            '已取消' => 'cancelled',
            '退費' => 'hold_refunded',
            '退款' => 'hold_refunded',
            '已退款' => 'hold_refunded',
            '結訓' => 'completed',
            '已結訓' => 'completed',
            '完成' => 'completed',
            '課後付款' => 'postpay',
        );
        if (isset($map[$value])) {
            return $map[$value];
        }

        $status = sanitize_key($value);
        if (!in_array($status, array('cert_pending', 'completed', 'hold', 'hold_refunded', 'postpay', 'cancelled'), true)) {
            return 'completed';
        }
        return $status;
    }

    private static function infer_legacy_status_from_notes($status_raw, $customer_note, $association_note, $note = '')
    {
        $status_raw = trim((string)$status_raw);
        if ($status_raw !== '') {
            return self::normalize_legacy_status($status_raw);
        }

        $combined = trim((string)$customer_note . "\n" . (string)$association_note . "\n" . (string)$note);
        if ($combined === '') {
            return 'completed';
        }

        if (preg_match('/退費|退款|已退|待退/u', $combined)) {
            return 'hold_refunded';
        }
        if (preg_match('/取消|作廢|未開班|未開課/u', $combined)) {
            return 'cancelled';
        }
        if (preg_match('/保留|延期|改期/u', $combined)) {
            return 'hold';
        }

        return 'completed';
    }

    private static function legacy_payment_status_from_reg_status($status)
    {
        if ($status === 'hold') {
            return 'on-hold';
        }
        if ($status === 'hold_refunded') {
            return 'refunded';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        return null;
    }

    private static function legacy_amount_hint_keys(array $row)
    {
        $keys = array();
        $session_id = (int)($row['session_id'] ?? 0);
        if ($session_id > 0) {
            $keys[] = 'session:' . $session_id;
        }
        $course_id = (int)($row['course_id'] ?? 0);
        if ($course_id > 0) {
            $keys[] = 'course:' . $course_id;
        }
        $course_name = trim((string)($row['course_name'] ?? ''));
        $lecturer = trim((string)($row['lecturer'] ?? ''));
        if ($course_name !== '' && $lecturer !== '') {
            $keys[] = 'course-lecturer:' . md5($course_name . '|' . $lecturer);
        }
        if ($course_name !== '') {
            $keys[] = 'course-name:' . md5($course_name);
        }
        return $keys;
    }

    private static function legacy_add_amount_hint(array &$hints, array $row)
    {
        $amount = self::normalize_amount($row['remit_amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $amount = self::infer_amount_from_text((string)($row['note'] ?? ''));
        }
        if ($amount === null || $amount <= 0) {
            return;
        }

        foreach (self::legacy_amount_hint_keys($row) as $key) {
            if (!isset($hints[$key])) {
                $hints[$key] = array();
            }
            if (!isset($hints[$key][$amount])) {
                $hints[$key][$amount] = 0;
            }
            $hints[$key][$amount]++;
        }
    }

    private static function legacy_amount_hint_value(array $hints, array $row)
    {
        foreach (self::legacy_amount_hint_keys($row) as $key) {
            if (empty($hints[$key])) {
                continue;
            }
            arsort($hints[$key], SORT_NUMERIC);
            $amounts = array_keys($hints[$key]);
            return isset($amounts[0]) ? (int)$amounts[0] : null;
        }
        return null;
    }

    private static function legacy_amount_unresolved_reason(array $hints, array $row)
    {
        $keys = self::legacy_amount_hint_keys($row);
        if (empty($keys)) {
            return '缺課程/場次/課名，無法建立比對鍵';
        }

        foreach ($keys as $key) {
            if (!empty($hints[$key])) {
                return '同組金額資料異常';
            }
        }

        $course_name = trim((string)($row['course_name'] ?? ''));
        $lecturer = trim((string)($row['lecturer'] ?? ''));
        $session_id = (int)($row['session_id'] ?? 0);
        if ($session_id <= 0) {
            return '沒有場次，且同課程/課名找不到非 0 金額';
        }
        if ($course_name === '' && $lecturer === '') {
            return '缺課名與講師，且同場次找不到非 0 金額';
        }
        return '備註無金額，且同場次/同課程/同課名找不到非 0 金額';
    }

    private static function legacy_amount_sample_label(array $row, $reason)
    {
        $parts = array();
        $parts[] = (string)($row['reg_no'] ?? ('ID ' . (int)($row['id'] ?? 0)));
        $course = trim((string)($row['course_name'] ?? ''));
        $lecturer = trim((string)($row['lecturer'] ?? ''));
        $session_id = (int)($row['session_id'] ?? 0);
        if ($course !== '') {
            $parts[] = $course;
        }
        if ($lecturer !== '') {
            $parts[] = $lecturer;
        }
        if ($session_id > 0) {
            $parts[] = '場次#' . $session_id;
        }
        $parts[] = $reason;
        return mb_substr(implode(' / ', $parts), 0, 140);
    }

    private static function legacy_amount_rows()
    {
        global $wpdb;

        $regs_table = TPMA_CR_DB::table('regs');
        $courses_table = TPMA_CR_DB::table('courses');
        $sessions_table = TPMA_CR_DB::table('sessions');
        $lecturers_table = TPMA_CR_DB::table('lecturers');
        $lecturer_display_sql = TPMA_CR_DB::sql_lecturer_display('l');
        $lecturer_join_sql = TPMA_CR_DB::sql_lecturer_join_on_course('l', 'c');

        $rows = $wpdb->get_results("
            SELECT r.id, r.reg_no, r.course_id, r.session_id, r.note, r.remit_amount,
                   c.course_name, c.duration_minutes, {$lecturer_display_sql} AS lecturer
            FROM {$regs_table} r
            LEFT JOIN {$courses_table} c ON c.id = r.course_id
            LEFT JOIN {$sessions_table} s ON s.id = r.session_id
            LEFT JOIN {$lecturers_table} l ON {$lecturer_join_sql}
            WHERE (r.woocommerce_order_id IS NULL OR r.woocommerce_order_id = 0)
              AND (
                r.source = '舊資料匯入'
                OR r.reg_no LIKE 'L%'
                OR r.note LIKE '%原課程編號%'
              )
            ORDER BY r.id ASC
        ", ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    private static function backfill_legacy_amounts()
    {
        global $wpdb;

        $rows = self::legacy_amount_rows();
        if (empty($rows)) {
            return '沒有找到可回填的舊資料。';
        }

        $hints = array();
        foreach ($rows as $row) {
            self::legacy_add_amount_hint($hints, $row);
        }

        $regs_table = TPMA_CR_DB::table('regs');
        $checked = 0;
        $updated = 0;
        $unresolved = 0;
        $reason_counts = array();
        $samples = array();

        foreach ($rows as $row) {
            $current = self::normalize_amount($row['remit_amount'] ?? null);
            if ($current !== null && $current > 0) {
                continue;
            }

            $checked++;
            $amount = self::infer_amount_from_text((string)($row['note'] ?? ''));
            if ($amount === null || $amount <= 0) {
                $amount = self::legacy_amount_hint_value($hints, $row);
            }
            if ($amount === null || $amount <= 0) {
                $amount = self::infer_legacy_amount_from_duration($row['duration_minutes'] ?? null);
            }
            if ($amount === null || $amount <= 0) {
                $unresolved++;
                $reason = self::legacy_amount_unresolved_reason($hints, $row);
                if (!isset($reason_counts[$reason])) {
                    $reason_counts[$reason] = 0;
                }
                $reason_counts[$reason]++;
                if (count($samples) < 10) {
                    $samples[] = self::legacy_amount_sample_label($row, $reason);
                }
                continue;
            }

            $ok = $wpdb->update(
                $regs_table,
                array('remit_amount' => $amount),
                array('id' => (int)$row['id']),
                array('%d'),
                array('%d')
            );
            if ($ok !== false) {
                $updated++;
                self::legacy_add_amount_hint($hints, array_merge($row, array('remit_amount' => $amount)));
            } else {
                $unresolved++;
                $reason = '資料庫更新失敗';
                if (!isset($reason_counts[$reason])) {
                    $reason_counts[$reason] = 0;
                }
                $reason_counts[$reason]++;
                if (count($samples) < 10) {
                    $samples[] = self::legacy_amount_sample_label($row, $reason);
                }
            }
        }

        $message = "舊資料金額回填完成：檢查 {$checked} 筆 0/空金額，回填 {$updated} 筆，仍無法推算 {$unresolved} 筆。";
        if (!empty($reason_counts)) {
            arsort($reason_counts, SORT_NUMERIC);
            $message .= "\n\n無法推算原因：";
            foreach ($reason_counts as $reason => $count) {
                $message .= "\n- {$reason}：{$count} 筆";
            }
        }
        if (!empty($samples)) {
            $message .= "\n\n樣本（最多 10 筆）：";
            foreach ($samples as $sample) {
                $message .= "\n- " . $sample;
            }
        }
        if ($unresolved > 0) {
            $message .= "\n\n處理建議：若同一批資料完全沒有任何非 0 金額，系統沒有可參照來源，請先在報名管理手動修正其中一筆或重新匯入含「金額」欄位的 CSV，再執行回填。";
        }

        return $message;
    }

    private static function import_legacy_regs_csv($csv_raw)
    {
        global $wpdb;
        $regs_table = TPMA_CR_DB::table('regs');

        $rows = self::parse_csv_rows($csv_raw);
        if (empty($rows)) {
            return '沒有資料可匯入。';
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $courses_created = array();
        $sessions_created = array();
        $conflicts = array();
        $header_map = array();
        if (!empty($rows[0])) {
            $header_map = self::legacy_header_map($rows[0]);
        }

        foreach ($rows as $i => $cols) {
            if ($i === 0 && !empty($header_map)) {
                continue;
            }

            $map = !empty($header_map) ? $header_map : self::legacy_default_map($cols);

            $reg_no          = sanitize_text_field(self::legacy_col($cols, $map, 'reg_no', ''));
            $legacy_code     = sanitize_text_field(self::legacy_col($cols, $map, 'legacy_course_code', ''));
            $registered_at   = self::normalize_datetime(self::legacy_col($cols, $map, 'registered_at', ''));
            $course_name     = sanitize_text_field(self::legacy_col($cols, $map, 'course_name', ''));
            $lecturer_name   = sanitize_text_field(self::legacy_col($cols, $map, 'lecturer', ''));
            $class_raw       = sanitize_text_field(self::legacy_col($cols, $map, 'class_date', ''));
            $student_name    = sanitize_text_field(self::legacy_col($cols, $map, 'student_name', ''));
            $company_name    = sanitize_text_field(self::legacy_col($cols, $map, 'company_name', ''));
            $tax_id          = sanitize_text_field(self::legacy_col($cols, $map, 'tax_id', ''));
            $department      = sanitize_text_field(self::legacy_col($cols, $map, 'department', ''));
            $job_title       = sanitize_text_field(self::legacy_col($cols, $map, 'job_title', ''));
            $phone           = sanitize_text_field(self::legacy_col($cols, $map, 'phone', ''));
            $mobile          = sanitize_text_field(self::legacy_col($cols, $map, 'mobile', $phone));
            $emails          = self::sanitize_emails_raw(self::legacy_col($cols, $map, 'emails', ''));
            $receiver        = sanitize_text_field(self::legacy_col($cols, $map, 'receiver', ''));
            $contact_name    = sanitize_text_field(self::legacy_col($cols, $map, 'contact_name', $receiver));
            $contact_email   = sanitize_email(self::legacy_col($cols, $map, 'contact_email', $emails !== '' ? strtok($emails, ',') : ''));
            $receipt_type    = sanitize_key(self::legacy_col($cols, $map, 'receipt_type', 'electronic'));
            $address         = sanitize_text_field(self::legacy_col($cols, $map, 'address', ''));
            $source          = sanitize_text_field(self::legacy_col($cols, $map, 'source', ''));
            $note            = sanitize_textarea_field(self::legacy_col($cols, $map, 'note', ''));
            $customer_note   = sanitize_textarea_field(self::legacy_col($cols, $map, 'customer_note', ''));
            $association_note= sanitize_textarea_field(self::legacy_col($cols, $map, 'association_note', ''));
            $remit_account   = sanitize_text_field(self::legacy_col($cols, $map, 'remit_account', ''));
            $remit_paid_at   = self::normalize_date(self::legacy_col($cols, $map, 'remit_paid_at', ''));
            $remit_amount    = self::normalize_amount(self::legacy_col($cols, $map, 'remit_amount', null));
            if ($remit_amount === null) {
                $remit_amount = self::infer_amount_from_text($customer_note . "\n" . $association_note . "\n" . $note);
            }
            $status_raw      = self::legacy_col($cols, $map, 'status', '');
            $status          = self::infer_legacy_status_from_notes($status_raw, $customer_note, $association_note, $note);
            $duration_raw    = self::legacy_col($cols, $map, 'duration_hours', self::legacy_col($cols, $map, 'duration_minutes', ''));
            $class_end_raw   = self::legacy_col($cols, $map, 'class_end_at', '');
            $duration_is_explicit = trim((string)$duration_raw) !== '' || trim((string)$class_end_raw) !== '';
            $duration_minutes= self::normalize_duration_minutes(
                $duration_raw,
                $class_raw,
                $class_end_raw
            );
            if ($remit_amount === null || $remit_amount <= 0) {
                $remit_amount = self::infer_legacy_amount_from_duration($duration_minutes);
            }

            if ($student_name === '' || ($legacy_code === '' && $course_name === '')) {
                $skipped++;
                continue;
            }

            if (!in_array($receipt_type, array('electronic', 'paper'), true)) {
                $receipt_type = 'electronic';
            }
            $course_id_before = 0;
            $exact_existing_course = self::find_exact_existing_course($legacy_code, $course_name !== '' ? $course_name : '舊資料未命名課程', $lecturer_name);
            if ($exact_existing_course) {
                $course_id_before = (int)$exact_existing_course['id'];
            } else {
                $course_code = self::legacy_course_code($legacy_code, $course_name, $lecturer_name);
                $lecturer_code_for_match = self::get_or_create_legacy_lecturer($lecturer_name);
                $course_id_before = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM " . TPMA_CR_DB::table('courses') . " WHERE course_code = %s AND course_name = %s AND COALESCE(lecturer_code, '') = %s LIMIT 1",
                    $course_code,
                    $course_name !== '' ? $course_name : '舊資料未命名課程',
                    (string)$lecturer_code_for_match
                ));
            }
            $course_id = self::get_or_create_legacy_course($legacy_code, $course_name, $lecturer_name, $duration_minutes, $duration_is_explicit);
            if ($course_id <= 0) {
                $skipped++;
                $conflicts[] = '第 ' . ($i + 1) . ' 列課程建立失敗：' . ($legacy_code ?: $course_name);
                continue;
            }
            if ($course_id_before <= 0) {
                $courses_created[$course_id] = true;
            }

            $session_id_before = 0;
            $class_date = self::normalize_date($class_raw);
            $session_id = 0;
            if ($class_raw !== '') {
                $dt = self::normalize_datetime($class_raw);
                if ($dt !== '') {
                    $session_id_before = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM " . TPMA_CR_DB::table('sessions') . " WHERE course_id = %d AND session_datetime = %s LIMIT 1",
                        $course_id,
                        $dt
                    ));
                }
                $session_id = self::get_or_create_legacy_session($course_id, $class_raw);
                if ($session_id > 0 && $session_id_before <= 0) {
                    $sessions_created[$session_id] = true;
                }
            }

            if ($reg_no === '') {
                $reg_no = self::generate_legacy_reg_no();
            }

            $existing = $wpdb->get_row($wpdb->prepare("SELECT id, woocommerce_order_id FROM {$regs_table} WHERE reg_no = %s", $reg_no), ARRAY_A);
            $existing_id = $existing ? (int)$existing['id'] : 0;
            if ($existing_id > 0 && (int)($existing['woocommerce_order_id'] ?? 0) > 0) {
                $skipped++;
                $conflicts[] = '第 ' . ($i + 1) . ' 列報名編號已屬於 Woo 報名，已略過：' . $reg_no;
                continue;
            }
            $legacy_note_parts = array();
            if ($note !== '') {
                $legacy_note_parts[] = $note;
            }
            if ($customer_note !== '') {
                $legacy_note_parts[] = '顧客備註：' . $customer_note;
            }
            if ($association_note !== '') {
                $legacy_note_parts[] = '學會備註：' . $association_note;
            }
            $legacy_note = trim(implode("\n", $legacy_note_parts));
            if ($legacy_code !== '') {
                $legacy_note = trim($legacy_note . "\n" . '原課程編號：' . $legacy_code);
            }

            $data = self::filter_existing_columns('regs', array(
                'reg_no'               => $reg_no,
                'created_at'           => $registered_at ?: current_time('mysql'),
                'course_id'            => $course_id,
                'session_id'           => $session_id ?: null,
                'access_mode'          => 'live',
                'class_date'           => $class_date ?: null,
                'student_name'         => $student_name,
                'department'           => $department,
                'job_title'            => $job_title,
                'mobile'               => $mobile,
                'emails'               => $emails,
                'contact_name'         => $contact_name,
                'contact_email'        => $contact_email,
                'contact_emails'       => $contact_email,
                'company_name'         => $company_name,
                'tax_id'               => $tax_id,
                'phone'                => $phone,
                'receipt_type'         => $receipt_type,
                'receipt_status'       => 'pending',
                'address'              => $address,
                'receiver'             => $receiver,
                'source'               => $source !== '' ? $source : '舊資料匯入',
                'note'                 => $legacy_note,
                'remit_account'        => $remit_account,
                'remit_paid_at'        => $remit_paid_at ?: null,
                'remit_amount'         => $remit_amount,
                'status'               => $status,
                'woocommerce_order_id' => null,
                'payment_status'       => self::legacy_payment_status_from_reg_status($status),
            ));

            if ($existing_id > 0) {
                unset($data['reg_no'], $data['created_at']);
                $ok = $wpdb->update($regs_table, $data, array('id' => $existing_id));
                if ($ok === false) {
                    $skipped++;
                    $conflicts[] = '第 ' . ($i + 1) . ' 列更新失敗：' . $reg_no;
                } else {
                    $updated++;
                }
            } else {
                $ok = $wpdb->insert($regs_table, $data);
                if ($ok) {
                    $inserted++;
                } else {
                    $skipped++;
                    $conflicts[] = '第 ' . ($i + 1) . ' 列新增失敗：' . $reg_no;
                }
            }
        }

        $message = "舊報名資料匯入完成：新增 {$inserted} 筆，更新 {$updated} 筆，略過 {$skipped} 筆；新增舊課程 "
            . count($courses_created) . ' 筆，新增舊場次 ' . count($sessions_created) . ' 筆。';
        if (!empty($conflicts)) {
            $message .= "\n需檢查：" . implode('；', array_slice($conflicts, 0, 8));
        }

        return $message;
    }

}
