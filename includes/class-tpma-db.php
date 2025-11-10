<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_DB
{
    public static function table($key)
    {
        global $wpdb;
        switch ($key) {
            case 'courses':
                return $wpdb->prefix . 'tpma_courses';
            case 'regs':
                return $wpdb->prefix . 'tpma_registrations';
            case 'sessions':
                return $wpdb->prefix . 'tpma_course_sessions';
            case 'lecturers':
                return $wpdb->prefix . 'tpma_lecturers';
        }
        return '';
    }

    /**
     * 啟用 / 更新資料表
     */
    public static function on_activate()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $courses_table   = self::table('courses');
        $regs_table      = self::table('regs');
        $sessions_table  = self::table('sessions');
        $lecturers_table = self::table('lecturers');

        // 課程表
        $sql_courses = "CREATE TABLE {$courses_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            course_code VARCHAR(50) NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            category VARCHAR(100) DEFAULT NULL,
            category_code VARCHAR(10) DEFAULT NULL,
            lecturer VARCHAR(255) DEFAULT NULL,
            lecturer_code VARCHAR(20) DEFAULT NULL,
            intro TEXT DEFAULT NULL,
            outline LONGTEXT DEFAULT NULL,
            class_date DATE NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            duration_minutes INT NOT NULL DEFAULT 180,
            PRIMARY KEY (id),
            UNIQUE KEY course_code_unique (course_code),
            KEY course_name_idx (course_name),
            KEY category_code_idx (category_code),
            KEY lecturer_code_idx (lecturer_code),
            KEY is_active_idx (is_active)
        ) {$charset_collate};";

        // 報名表
        $sql_regs = "CREATE TABLE {$regs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reg_no VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL,
            course_id BIGINT UNSIGNED NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            lecturer VARCHAR(255) DEFAULT NULL,
            class_date DATE DEFAULT NULL,

            student_name VARCHAR(255) NOT NULL,
            company_name VARCHAR(255) DEFAULT NULL,
            tax_id VARCHAR(20) DEFAULT NULL,
            department VARCHAR(255) DEFAULT NULL,
            job_title VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            emails TEXT DEFAULT NULL,
            receiver VARCHAR(255) DEFAULT NULL,
            address VARCHAR(500) DEFAULT NULL,
            source VARCHAR(100) DEFAULT NULL,
            note TEXT DEFAULT NULL,

            remit_account VARCHAR(100) DEFAULT NULL,
            remit_date DATE DEFAULT NULL,
            remit_amount DECIMAL(10,2) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',

            PRIMARY KEY (id),
            UNIQUE KEY reg_no_unique (reg_no),
            KEY course_id_idx (course_id),
            KEY student_name_idx (student_name),
            KEY phone_idx (phone)
        ) {$charset_collate};";

        // 場次表
        $sql_sessions = "CREATE TABLE {$sessions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            course_id BIGINT UNSIGNED NOT NULL,
            session_datetime DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY course_id_idx (course_id),
            KEY session_datetime_idx (session_datetime),
            KEY is_active_idx (is_active)
        ) {$charset_collate};";

        // 講師表
        $sql_lecturers = "CREATE TABLE {$lecturers_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(255) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY code_unique (code),
            KEY sort_order_idx (sort_order)
        ) {$charset_collate};";

        dbDelta($sql_courses);
        dbDelta($sql_regs);
        dbDelta($sql_sessions);
        dbDelta($sql_lecturers);
    }

    /**
     * 每日：把已過期場次設為 is_active = 0（不刪除）
     */
    public static function cleanup_old_sessions()
    {
        global $wpdb;
        $sessions_table = self::table('sessions');
        $now = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$sessions_table}
                 SET is_active = 0
                 WHERE is_active = 1
                 AND session_datetime < %s",
                $now
            )
        );
    }
}
