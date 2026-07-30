<?php

if (!defined('ABSPATH')) {

    exit;

}



class TPMA_CR_DB

{
    const SCHEMA_VERSION = '1.9.4';

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

            case 'portal_tokens':
                return $wpdb->prefix . 'tpma_portal_tokens';

            case 'quiz_contexts':
                return $wpdb->prefix . 'tpma_quiz_attempt_contexts';

            case 'portal_audit':
                return $wpdb->prefix . 'tpma_portal_audit';

            case 'receipts':
                return $wpdb->prefix . 'tpma_receipts';

            case 'receipt_orders':
                return $wpdb->prefix . 'tpma_receipt_orders';

            case 'receipt_revisions':
                return $wpdb->prefix . 'tpma_receipt_revisions';

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
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$regs_table} LIKE 'session_id'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$regs_table} ADD COLUMN session_id BIGINT UNSIGNED DEFAULT NULL AFTER course_id");
            delete_option('tpma_cr_session_backfill_cursor');
            delete_option('tpma_cr_session_backfill_complete');
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$regs_table} LIKE 'access_mode'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$regs_table} ADD COLUMN access_mode VARCHAR(20) NOT NULL DEFAULT 'live' AFTER session_id");
            $access_mode_added = true;
        } else {
            $access_mode_added = false;
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
        $delivery_mode_added = false;
        foreach (array(
            'delivery_mode'            => "VARCHAR(20) NOT NULL DEFAULT 'live'",
            'tutor_topic_id'           => 'BIGINT UNSIGNED DEFAULT NULL',
            'tutor_meet_post_id'       => 'BIGINT UNSIGNED DEFAULT NULL',
            'recording_available_from' => 'DATETIME DEFAULT NULL',
            'recording_available_until'=> 'DATETIME DEFAULT NULL',
            'tutor_resources_cleaned_at' => 'DATETIME DEFAULT NULL',
        ) as $column => $definition) {
            $col = $wpdb->get_results("SHOW COLUMNS FROM {$sessions_table} LIKE '{$column}'");
            if (empty($col)) {
                $wpdb->query("ALTER TABLE {$sessions_table} ADD COLUMN {$column} {$definition}");
                if ($column === 'delivery_mode') $delivery_mode_added = true;
            }
        }
        if (!empty($delivery_mode_added)) $wpdb->query("UPDATE {$sessions_table}
                      SET delivery_mode = CASE
                        WHEN tutor_meet_post_id IS NOT NULL AND tutor_meet_post_id > 0 THEN 'live'
                        WHEN recording_available_from IS NOT NULL AND recording_available_until IS NOT NULL THEN 'recorded'
                        ELSE 'live' END
                      WHERE delivery_mode = 'live'");
        if ($access_mode_added || !empty($delivery_mode_added)) $wpdb->query("UPDATE {$regs_table} r INNER JOIN {$sessions_table} s ON s.id=r.session_id
                      SET r.access_mode = CASE WHEN s.delivery_mode='recorded' THEN 'recorded' ELSE 'live' END
                      WHERE r.access_mode IS NULL OR r.access_mode = '' OR " . ($access_mode_added ? "1=1" : "1=0"));
        $session_index = $wpdb->get_var("SHOW INDEX FROM {$sessions_table} WHERE Key_name = 'course_datetime_idx'");
        if (!$session_index) {
            $wpdb->query("ALTER TABLE {$sessions_table} ADD KEY course_datetime_idx (course_id, session_datetime)");
        }
        $reg_session_index = $wpdb->get_var("SHOW INDEX FROM {$regs_table} WHERE Key_name = 'session_id_idx'");
        if (!$reg_session_index) {
            $wpdb->query("ALTER TABLE {$regs_table} ADD KEY session_id_idx (session_id)");
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

        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::table('portal_tokens') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            encrypted_token LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id), UNIQUE KEY token_hash_idx (token_hash), KEY order_active_idx (order_id, revoked_at)
        ) {$charset_collate};");
        $quiz_contexts_table = self::table('quiz_contexts');
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$quiz_contexts_table} (
            attempt_id BIGINT UNSIGNED NOT NULL,
            registration_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            manual_override TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (attempt_id), KEY registration_idx (registration_id)
        ) {$charset_collate};");
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$quiz_contexts_table} LIKE 'manual_override'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$quiz_contexts_table} ADD COLUMN manual_override TINYINT(1) NOT NULL DEFAULT 0 AFTER session_id");
        }
        $portal_audit_table = self::table('portal_audit');
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$portal_audit_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            registration_id BIGINT UNSIGNED DEFAULT NULL,
            event_key VARCHAR(40) NOT NULL,
            ip_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY order_created_idx (order_id, created_at)
        ) {$charset_collate};");
        // Older manual score assignments predate quiz_contexts.manual_override. They wrote the
        // context and portal audit entry in the same request, so this narrow timestamp match
        // restores only those confirmed assignments without guessing from learner names.
        $wpdb->query("UPDATE {$quiz_contexts_table} qc
                      INNER JOIN {$portal_audit_table} pa
                        ON pa.registration_id=qc.registration_id
                       AND pa.order_id=qc.order_id
                       AND pa.event_key='quiz_score_manual_rebind'
                       AND ABS(TIMESTAMPDIFF(SECOND, qc.created_at, pa.created_at)) <= 5
                      SET qc.manual_override=1
                      WHERE qc.manual_override=0");
        $tutor_attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tutor_attempts_table))) {
            // A confirmed historical reassignment belongs to the target learner in Tutor as well
            // as TPMA. This keeps Tutor's attempt list and quiz result lookups consistent.
            $wpdb->query("UPDATE {$tutor_attempts_table} a
                          INNER JOIN {$quiz_contexts_table} qc ON qc.attempt_id=a.attempt_id
                          INNER JOIN " . self::table('regs') . " r ON r.id=qc.registration_id
                          SET a.user_id=r.wp_user_id
                          WHERE qc.manual_override=1 AND r.is_virtual_user=1 AND r.wp_user_id>0
                            AND a.user_id<>r.wp_user_id");
        }
        // Legacy virtual accounts may predate the per-registration display name. Tutor renders
        // attempt students from wp_users.display_name, so fill only truly blank names.
        $wpdb->query("UPDATE {$wpdb->users} u
                      INNER JOIN " . self::table('regs') . " r ON r.wp_user_id=u.ID
                      SET u.display_name=r.student_name
                      WHERE r.is_virtual_user=1 AND r.student_name IS NOT NULL AND TRIM(r.student_name)<>''
                        AND (u.display_name IS NULL OR TRIM(u.display_name)='')");

        // ── receipts: immutable serials, current snapshot, and source-order links ──
        $receipts_table = self::table('receipts');
        $receipt_orders_table = self::table('receipt_orders');
        $receipt_revisions_table = self::table('receipt_revisions');
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$receipts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            serial VARCHAR(24) NOT NULL,
            receipt_type VARCHAR(20) NOT NULL DEFAULT 'electronic',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            revision INT UNSIGNED NOT NULL DEFAULT 0,
            snapshot LONGTEXT NOT NULL,
            generated_file VARCHAR(255) DEFAULT NULL,
            scanned_file VARCHAR(255) DEFAULT NULL,
            generated_at DATETIME DEFAULT NULL,
            scanned_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            voided_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY serial_unique (serial),
            KEY status_idx (status),
            KEY receipt_type_idx (receipt_type)
        ) {$charset_collate};");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$receipt_orders_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            active_slot TINYINT UNSIGNED DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_active_receipt_unique (order_id, active_slot),
            UNIQUE KEY receipt_order_unique (receipt_id, order_id),
            KEY receipt_idx (receipt_id)
        ) {$charset_collate};");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$receipt_revisions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            snapshot LONGTEXT NOT NULL,
            generated_file VARCHAR(255) DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY receipt_revision_unique (receipt_id, revision),
            KEY receipt_idx (receipt_id)
        ) {$charset_collate};");
        if (!(bool)get_option('tpma_cr_portal_tokens_migrated_v1', false)) {
            $wpdb->query($wpdb->prepare("UPDATE {$tokens_table} SET expires_at=%s WHERE expires_at>%s", current_time('mysql'), current_time('mysql')));
            update_option('tpma_cr_portal_tokens_migrated_v1', 1, false);
        }
    }

    /**
     * Incrementally attach legacy registrations to stable session IDs.
     * Woo order meta wins; course/date matching is used only when unique.
     */
    public static function backfill_registration_session_ids(int $limit = 100): array {
        if ((bool) get_option('tpma_cr_session_backfill_complete', false)) {
            return array('processed' => 0, 'updated' => 0, 'complete' => true);
        }

        global $wpdb;
        $regs_table     = self::table('regs');
        $sessions_table = self::table('sessions');
        $cursor          = max(0, (int) get_option('tpma_cr_session_backfill_cursor', 0));
        $limit           = max(1, min(500, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, course_id, class_date, woocommerce_order_id
                 FROM {$regs_table}
                 WHERE id > %d AND session_id IS NULL
                 ORDER BY id ASC LIMIT %d",
                $cursor,
                $limit
            ),
            ARRAY_A
        );

        $updated = 0;
        foreach ((array) $rows as $row) {
            $reg_id    = (int) ($row['id'] ?? 0);
            $course_id = (int) ($row['course_id'] ?? 0);
            $session_id = 0;

            $order_id = (int) ($row['woocommerce_order_id'] ?? 0);
            if ($order_id > 0 && function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                $candidate = $order ? (int) $order->get_meta('_tpma_session_id', true) : 0;
                if ($candidate > 0) {
                    $session_id = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$sessions_table} WHERE id = %d AND course_id = %d",
                        $candidate,
                        $course_id
                    ));
                }
            }

            if ($session_id <= 0 && $course_id > 0 && !empty($row['class_date'])) {
                $matches = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$sessions_table}
                     WHERE course_id = %d AND DATE(session_datetime) = %s
                     ORDER BY id ASC LIMIT 2",
                    $course_id,
                    (string) $row['class_date']
                ));
                if (count((array) $matches) === 1) {
                    $session_id = (int) $matches[0];
                }
            }

            if ($session_id > 0) {
                $ok = $wpdb->update($regs_table, array('session_id' => $session_id), array('id' => $reg_id), array('%d'), array('%d'));
                if ($ok !== false) {
                    $updated++;
                }
            }
            $cursor = max($cursor, $reg_id);
        }

        update_option('tpma_cr_session_backfill_cursor', $cursor, false);
        $complete = count((array) $rows) < $limit;
        if ($complete) {
            update_option('tpma_cr_session_backfill_complete', 1, false);
        }
        return array('processed' => count((array) $rows), 'updated' => $updated, 'complete' => $complete);
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
			session_id BIGINT UNSIGNED DEFAULT NULL,
			access_mode VARCHAR(20) NOT NULL DEFAULT 'live',
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
			PRIMARY KEY (id),
			KEY session_id_idx (session_id)
		) {$charset_collate};";


        // 場次表

        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$sessions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            course_id BIGINT UNSIGNED NOT NULL,
            session_datetime DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            visibility_override VARCHAR(20) NOT NULL DEFAULT '',
            delivery_mode VARCHAR(20) NOT NULL DEFAULT 'live',
            tutor_topic_id BIGINT UNSIGNED DEFAULT NULL,
            tutor_meet_post_id BIGINT UNSIGNED DEFAULT NULL,
            recording_available_from DATETIME DEFAULT NULL,
            recording_available_until DATETIME DEFAULT NULL,
            tutor_resources_cleaned_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY course_datetime_idx (course_id, session_datetime)
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
