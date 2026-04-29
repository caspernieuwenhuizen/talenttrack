<?php
/**
 * Migration 0048 — Staff development module (#0039).
 *
 * Six new tables for the personal-development surface for academy
 * staff (coaches / scouts / staff / mentors), one column added to
 * `tt_eval_categories` for the staff-tree branch, plus a small seed
 * pass for the `cert_type` lookup and the five staff-eval-category
 * mains.
 *
 * Tables:
 *   tt_staff_goals          — personal goals, optionally linked to a
 *                             cert_type (e.g. "Take UEFA-B")
 *   tt_staff_evaluations    — header row, one per (person, reviewer,
 *                             eval_date)
 *   tt_staff_eval_ratings   — leaf rows, one per category-rated cell
 *   tt_staff_certifications — cert register; expires_on drives the
 *                             expiring-certifications workflow
 *   tt_staff_pdp            — root entity (uuid). One row per
 *                             (person, season). Three structured
 *                             fields + a narrative.
 *   tt_staff_mentorships    — pivot: mentor_person_id × mentee_person_id
 *
 * Idempotent. Re-running the migration leaves existing rows alone.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0048_staff_development';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [];

        $tables[] = "CREATE TABLE IF NOT EXISTS {$p}tt_staff_goals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            person_id BIGINT UNSIGNED NOT NULL,
            season_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            priority VARCHAR(10) NOT NULL DEFAULT 'medium',
            due_date DATE DEFAULT NULL,
            cert_type_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_person (person_id),
            KEY idx_season (season_id),
            KEY idx_status (status),
            KEY idx_club (club_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE IF NOT EXISTS {$p}tt_staff_evaluations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            person_id BIGINT UNSIGNED NOT NULL,
            reviewer_user_id BIGINT UNSIGNED NOT NULL,
            review_kind VARCHAR(20) NOT NULL,
            season_id BIGINT UNSIGNED DEFAULT NULL,
            eval_date DATE NOT NULL,
            notes TEXT,
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_person (person_id),
            KEY idx_reviewer (reviewer_user_id),
            KEY idx_season (season_id),
            KEY idx_club (club_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE IF NOT EXISTS {$p}tt_staff_eval_ratings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            evaluation_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            rating DECIMAL(3,1) NOT NULL,
            comment TEXT,
            PRIMARY KEY (id),
            KEY idx_evaluation (evaluation_id),
            KEY idx_category (category_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE IF NOT EXISTS {$p}tt_staff_certifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            person_id BIGINT UNSIGNED NOT NULL,
            cert_type_lookup_id BIGINT UNSIGNED NOT NULL,
            issuer VARCHAR(120),
            issued_on DATE NOT NULL,
            expires_on DATE DEFAULT NULL,
            document_url TEXT,
            archived_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_person (person_id),
            KEY idx_cert_type (cert_type_lookup_id),
            KEY idx_expires (expires_on),
            KEY idx_club (club_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE IF NOT EXISTS {$p}tt_staff_pdp (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            season_id BIGINT UNSIGNED DEFAULT NULL,
            strengths TEXT,
            development_areas TEXT,
            actions_next_quarter TEXT,
            narrative TEXT,
            last_reviewed_at DATETIME DEFAULT NULL,
            last_reviewed_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_person_season (person_id, season_id),
            KEY idx_person (person_id),
            KEY idx_club (club_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE IF NOT EXISTS {$p}tt_staff_mentorships (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            mentor_person_id BIGINT UNSIGNED NOT NULL,
            mentee_person_id BIGINT UNSIGNED NOT NULL,
            started_on DATE NOT NULL,
            ended_on DATE DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mentor (mentor_person_id),
            KEY idx_mentee (mentee_person_id),
            KEY idx_active (ended_on),
            KEY idx_club (club_id)
        ) {$charset};";

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        $this->addStaffTreeFlag();
        $this->seedCertTypeLookups();
        $this->seedStaffEvalCategoryRoots();
        $this->seedMentorFunctionalRole();
    }

    private function seedMentorFunctionalRole(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_functional_roles";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE role_key = %s", 'mentor'
        ) );
        if ( $existing !== null ) return;
        $wpdb->insert( $table, [
            'role_key'    => 'mentor',
            'label'       => 'Mentor',
            'description' => 'Pairs with a mentee staff member for one-on-one development guidance. Granted manage-scope on the mentee\'s staff-development records.',
            'is_system'   => 1,
            'sort_order'  => 60,
        ] );
    }

    private function addStaffTreeFlag(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_eval_categories";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'is_staff_tree'",
            $table
        ) );
        if ( $exists === null ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_staff_tree TINYINT(1) NOT NULL DEFAULT 0" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_is_staff_tree (is_staff_tree)" );
        }
    }

    private function seedCertTypeLookups(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed = [
            [ 'uefa_a',           'UEFA-A' ],
            [ 'uefa_b',           'UEFA-B' ],
            [ 'uefa_c',           'UEFA-C' ],
            [ 'first_aid',        'First aid' ],
            [ 'gdpr',             'GDPR awareness' ],
            [ 'child_safeguarding', 'Child safeguarding' ],
        ];

        $sort = 0;
        foreach ( $seed as [ $key, $name ] ) {
            $sort += 10;
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE lookup_type = %s AND lookup_key = %s AND club_id = %d",
                'cert_type', $key, 1
            ) );
            if ( $existing !== null ) continue;
            $wpdb->insert( $table, [
                'lookup_type' => 'cert_type',
                'lookup_key'  => $key,
                'name'        => $name,
                'sort_order'  => $sort,
                'club_id'     => 1,
            ] );
        }
    }

    private function seedStaffEvalCategoryRoots(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_eval_categories";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed = [
            [ 'coaching_craft',      'Coaching craft' ],
            [ 'communication',       'Communication' ],
            [ 'methodology_fluency', 'Methodology fluency' ],
            [ 'mentorship',          'Mentorship' ],
            [ 'reliability',         'Reliability' ],
        ];

        $cols = $wpdb->get_col( "DESC {$table}" );
        $has_key       = in_array( 'cat_key', $cols, true );
        $has_parent    = in_array( 'parent_id', $cols, true );
        $has_sort      = in_array( 'sort_order', $cols, true );
        $has_club      = in_array( 'club_id', $cols, true );
        $has_archived  = in_array( 'archived_at', $cols, true );

        $sort = 0;
        foreach ( $seed as [ $key, $name ] ) {
            $sort += 10;
            $where = "name = %s AND is_staff_tree = 1";
            $params = [ $name ];
            if ( $has_club ) {
                $where  .= " AND club_id = %d";
                $params[] = 1;
            }
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$where}", ...$params
            ) );
            if ( $existing !== null ) continue;

            $row = [
                'name'           => $name,
                'is_staff_tree'  => 1,
            ];
            if ( $has_key )      $row['cat_key']    = $key;
            if ( $has_parent )   $row['parent_id']  = 0;
            if ( $has_sort )     $row['sort_order'] = $sort;
            if ( $has_club )     $row['club_id']    = 1;
            if ( $has_archived ) $row['archived_at'] = null;

            $wpdb->insert( $table, $row );
        }
    }
};
