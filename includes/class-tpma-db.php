<?php

if (!defined('ABSPATH')) {

    exit;

}



class TPMA_CR_DB

{
    const SCHEMA_VERSION = '1.5.2';

    private static $table_columns_cache = array();

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

            case 'magic_tokens':

                return $wpdb->prefix . 'tpma_magic_tokens';

        }

        return '';

    }

    public static function generate_reg_no( $type = 'A' ) {
        global $wpdb;
        $regs_table = self::table('regs');

        // 用 WP 本地時間
        $ts    = current_time('timestamp');
        $year  = date('Y', $ts);
        $month = date('m', $ts);

        // $type 用來控制中間那個字元，目前你要的是 'A'
        $prefix = $year . $type . $month;

        // 找出該年月前綴下最後一筆編號
        $last = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT reg_no
                    FROM {$regs_table}
                    WHERE reg_no LIKE %s
                    ORDER BY reg_no DESC
                    LIMIT 1",
                $prefix . '%'
            )
        );

        $seq = 1;
        if ( $last && preg_match('/^' . preg_quote($prefix, '/') . '(\d{3})$/', $last, $m) ) {
            $seq = (int) $m[1] + 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    public static function ensure_reg_no_not_unique() {
    global $wpdb;
    $table = self::table('regs'); // 會自帶 prefix

    // 刪掉舊的 UNIQUE（名稱可能是 reg_no_unique）
    $hasUnique = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = %s
                          AND INDEX_NAME = 'reg_no_unique'
                          AND NON_UNIQUE = 0", $table)
    );
    if ($hasUnique) {
        $wpdb->query("ALTER TABLE {$table} DROP INDEX reg_no_unique");
    }

    // 確保有一般索引
    $hasIdx = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = %s
                          AND INDEX_NAME = 'reg_no_idx'", $table)
    );
    if (!$hasIdx) {
        $wpdb->query("CREATE INDEX reg_no_idx ON {$table} (reg_no)");
    }
}

    public static function ensure_schema_current() {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $current = (string) get_option('tpma_cr_schema_version', '');
        if ($current === self::SCHEMA_VERSION) {
            return;
        }

        self::ensure_reg_no_not_unique();
        self::maybe_upgrade();
        update_option('tpma_cr_schema_version', self::SCHEMA_VERSION, false);
    }

    /**
     * Runtime schema migration: add new columns & tables without breaking existing installs.
     * Safe to call on every activation and on ensure_schema_current().
     */
    public static function maybe_upgrade(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // ── courses: tutor_course_id ─────────────────────────────
        $courses_table = self::table('courses');
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$courses_table} LIKE 'tutor_course_id'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$courses_table} ADD COLUMN tutor_course_id BIGINT UNSIGNED DEFAULT NULL");
        }

        // ── registrations: tutor_enrolled_id ────────────────────
        $regs_table = self::table('regs');
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$regs_table} LIKE 'tutor_enrolled_id'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$regs_table} ADD COLUMN tutor_enrolled_id BIGINT UNSIGNED DEFAULT NULL");
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$regs_table} LIKE 'contact_emails'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$regs_table} ADD COLUMN contact_emails TEXT DEFAULT NULL AFTER contact_email");
        }

        // ── lecturers: wp_user_id ────────────────────────────────
        $lecturers_table = self::table('lecturers');
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$lecturers_table} LIKE 'wp_user_id'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$lecturers_table} ADD COLUMN wp_user_id BIGINT UNSIGNED DEFAULT NULL");
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$lecturers_table} LIKE 'is_active'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$lecturers_table} ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
            self::$table_columns_cache[$lecturers_table][] = 'is_active';
        }
        $lecturer_cols = self::get_table_columns('lecturers');
        if (!in_array('lecturers_sort_order', $lecturer_cols, true) && !in_array('sort_order', $lecturer_cols, true)) {
            $wpdb->query("ALTER TABLE {$lecturers_table} ADD COLUMN lecturers_sort_order INT NOT NULL DEFAULT 0");
            self::$table_columns_cache[$lecturers_table][] = 'lecturers_sort_order';
        }

        // ── sessions: visibility_override ───────────────────────
        $sessions_table = self::table('sessions');
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$sessions_table} LIKE 'visibility_override'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$sessions_table} ADD COLUMN visibility_override VARCHAR(20) NOT NULL DEFAULT ''");
        }

        // ── magic_tokens table ───────────────────────────────────
        $tokens_table = self::table('magic_tokens');
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$tokens_table} (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash      VARCHAR(64)     NOT NULL,
                wp_user_id      BIGINT UNSIGNED NOT NULL,
                registration_id BIGINT UNSIGNED NOT NULL,
                tutor_course_id BIGINT UNSIGNED DEFAULT NULL,
                target_type     VARCHAR(20)     NOT NULL DEFAULT 'course',
                target_url      TEXT            DEFAULT NULL,
                expires_at      DATETIME        NOT NULL,
                created_at      DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token_hash_idx (token_hash),
                KEY user_reg_idx (wp_user_id, registration_id)
            ) {$charset_collate};"
        );
    }

    public static function get_table_columns($key): array
    {
        global $wpdb;

        $table = self::table($key);
        if ($table === '') {
            return array();
        }

        if (isset(self::$table_columns_cache[$table])) {
            return self::$table_columns_cache[$table];
        }

        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (!is_array($rows)) {
            self::$table_columns_cache[$table] = array();
            return self::$table_columns_cache[$table];
        }

        self::$table_columns_cache[$table] = array_values(array_filter(array_map(
            static function ($row) {
                return isset($row['Field']) ? (string) $row['Field'] : '';
            },
            $rows
        )));

        return self::$table_columns_cache[$table];
    }

    public static function get_lecturer_schema(): array
    {
        $columns = self::get_table_columns('lecturers');

        $pick = static function (array $candidates, string $fallback) use ($columns): string {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    return $candidate;
                }
            }
            return $fallback;
        };

        return array(
            'code' => $pick(array('lecturers_code', 'lecturer_code'), 'lecturer_code'),
            'name' => $pick(array('lecturers_name', 'lecturer_name'), 'lecturer_name'),
            'title' => $pick(array('lecturers_title', 'lecturer_title', 'title'), 'title'),
            'sort_order' => $pick(array('lecturers_sort_order', 'sort_order'), ''),
        );
    }

    public static function sql_lecturer_display(string $lecturer_alias = 'l'): string
    {
        $schema = self::get_lecturer_schema();
        $name_col = $schema['name'];
        $title_col = $schema['title'];

        return "CONCAT(
            {$lecturer_alias}.{$name_col},
            CASE
                WHEN {$lecturer_alias}.{$title_col} IS NULL OR {$lecturer_alias}.{$title_col} = ''
                THEN ''
                ELSE CONCAT(' ', {$lecturer_alias}.{$title_col})
            END
        )";
    }

    public static function sql_lecturer_join_on_course(string $lecturer_alias = 'l', string $course_alias = 'c'): string
    {
        $schema = self::get_lecturer_schema();
        return "{$lecturer_alias}.{$schema['code']} = {$course_alias}.lecturer_code";
    }




    /**

     * 啟用 / 更新資料表

     */

    public static function on_activate()

    {

        global $wpdb;
$charset_collate = $wpdb->get_charset_collate();



        $courses_table   = self::table('courses');

        $regs_table      = self::table('regs');

        $sessions_table  = self::table('sessions');

        $lecturers_table = self::table('lecturers');



        // 課程表

        $sql_courses = "CREATE TABLE IF NOT EXISTS {$courses_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            course_code VARCHAR(50) NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            category VARCHAR(100) DEFAULT NULL,
            category_code VARCHAR(10) DEFAULT NULL,
            lecturer_code VARCHAR(20) DEFAULT NULL,
            intro TEXT DEFAULT NULL,
            outline LONGTEXT DEFAULT NULL,
            class_date DATE NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            duration_minutes INT NOT NULL DEFAULT 180,
            PRIMARY KEY (id)
        ) {$charset_collate};";



        // 報名表

		$sql_regs = "CREATE TABLE IF NOT EXISTS {$regs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			reg_no VARCHAR(30) NOT NULL,
			created_at DATETIME NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			class_date DATE DEFAULT NULL,
			student_name VARCHAR(255) NOT NULL,
			department VARCHAR(255) DEFAULT NULL,
			job_title VARCHAR(255) DEFAULT NULL,
			mobile VARCHAR(50) DEFAULT NULL,
			emails TEXT DEFAULT NULL,
			contact_name VARCHAR(255) DEFAULT NULL,
			contact_email VARCHAR(255) DEFAULT NULL,
			contact_emails TEXT DEFAULT NULL,
			company_name VARCHAR(255) DEFAULT NULL,
			tax_id VARCHAR(20) DEFAULT NULL,
			phone VARCHAR(50) DEFAULT NULL,
			receipt_type VARCHAR(20) NOT NULL DEFAULT 'electronic',
			receipt_status VARCHAR(20) DEFAULT NULL,
			address VARCHAR(500) DEFAULT NULL,
			receiver VARCHAR(255) DEFAULT NULL,
			source VARCHAR(100) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			remit_account VARCHAR(100) DEFAULT NULL,
			remit_paid_at DATE DEFAULT NULL,
			remit_amount INT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'cert_pending',
			test_score VARCHAR(30) DEFAULT NULL,
			certificate_id VARCHAR(30) DEFAULT NULL,
			wp_user_id BIGINT UNSIGNED DEFAULT NULL,
			is_virtual_user TINYINT(1) NOT NULL DEFAULT 0,
			woocommerce_order_id BIGINT UNSIGNED DEFAULT NULL,
			payment_status VARCHAR(50) DEFAULT NULL,
			PRIMARY KEY (id)
		) {$charset_collate};";


        // 場次表

        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$sessions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            course_id BIGINT UNSIGNED NOT NULL,
            session_datetime DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            visibility_override VARCHAR(20) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";



        // 講師表

        $sql_lecturers = "CREATE TABLE IF NOT EXISTS {$lecturers_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lecturer_code VARCHAR(20) NOT NULL,
            lecturer_name VARCHAR(255) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";



        $wpdb->query($sql_courses);

        $wpdb->query($sql_regs);

        $wpdb->query($sql_sessions);

        $wpdb->query($sql_lecturers);
        
        // Ensure schema is current after initial activation
        self::ensure_schema_current();

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
