<?php
namespace TT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Activator {

    public static function activate() {
        self::create_tables();
        self::add_roles();
        self::seed_defaults();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = [];

        /* ── Lookups: single polymorphic table for all configurable lists ── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_lookups (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lookup_type VARCHAR(100) NOT NULL,
            name        VARCHAR(255) NOT NULL,
            description TEXT,
            meta        TEXT,
            sort_order  INT DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type (lookup_type)
        ) $c;";

        /* ── Config key-value store ──────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_config (
            config_key   VARCHAR(191) NOT NULL,
            config_value LONGTEXT,
            PRIMARY KEY (config_key)
        ) $c;";

        /* ── Teams ───────────────────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_teams (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(255) NOT NULL,
            age_group     VARCHAR(100) DEFAULT '',
            head_coach_id BIGINT UNSIGNED DEFAULT 0,
            notes         TEXT,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $c;";

        /* ── Players ─────────────────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_players (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name          VARCHAR(255) NOT NULL,
            last_name           VARCHAR(255) NOT NULL,
            date_of_birth       DATE,
            nationality         VARCHAR(100) DEFAULT '',
            height_cm           SMALLINT UNSIGNED DEFAULT NULL,
            weight_kg           SMALLINT UNSIGNED DEFAULT NULL,
            preferred_foot      VARCHAR(20) DEFAULT '',
            preferred_positions TEXT,
            jersey_number       SMALLINT UNSIGNED DEFAULT NULL,
            team_id             BIGINT UNSIGNED DEFAULT 0,
            date_joined         DATE,
            photo_url           VARCHAR(500) DEFAULT '',
            guardian_name       VARCHAR(255) DEFAULT '',
            guardian_email      VARCHAR(255) DEFAULT '',
            guardian_phone      VARCHAR(50) DEFAULT '',
            wp_user_id          BIGINT UNSIGNED DEFAULT 0,
            status              VARCHAR(50) DEFAULT 'active',
            created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_team (team_id),
            KEY idx_user (wp_user_id)
        ) $c;";

        /* ── Evaluations (header) ────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_evaluations (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id       BIGINT UNSIGNED NOT NULL,
            coach_id        BIGINT UNSIGNED NOT NULL,
            eval_type_id    BIGINT UNSIGNED DEFAULT 0,
            eval_date       DATE NOT NULL,
            notes           TEXT,
            opponent        VARCHAR(255) DEFAULT '',
            competition     VARCHAR(255) DEFAULT '',
            match_result    VARCHAR(50) DEFAULT '',
            home_away       VARCHAR(10) DEFAULT '',
            minutes_played  SMALLINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player (player_id),
            KEY idx_coach  (coach_id),
            KEY idx_type   (eval_type_id)
        ) $c;";

        /* ── Evaluation Ratings (detail per category) ────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_eval_ratings (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            evaluation_id BIGINT UNSIGNED NOT NULL,
            category_id   BIGINT UNSIGNED NOT NULL,
            rating        DECIMAL(4,1) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_eval (evaluation_id),
            KEY idx_cat  (category_id)
        ) $c;";

        /* ── Training Sessions ───────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_sessions (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title        VARCHAR(255) NOT NULL,
            session_date DATE NOT NULL,
            location     VARCHAR(255) DEFAULT '',
            team_id      BIGINT UNSIGNED DEFAULT 0,
            coach_id     BIGINT UNSIGNED DEFAULT 0,
            notes        TEXT,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_team (team_id)
        ) $c;";

        /* ── Attendance ──────────────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_attendance (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id  BIGINT UNSIGNED NOT NULL,
            player_id   BIGINT UNSIGNED NOT NULL,
            status      VARCHAR(50) DEFAULT 'present',
            notes       TEXT,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_player  (player_id)
        ) $c;";

        /* ── Goals ───────────────────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_goals (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id   BIGINT UNSIGNED NOT NULL,
            title       VARCHAR(255) NOT NULL,
            description TEXT,
            status      VARCHAR(50) DEFAULT 'pending',
            priority    VARCHAR(50) DEFAULT 'medium',
            due_date    DATE,
            created_by  BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player (player_id)
        ) $c;";

        /* ── Saved Report Presets ────────────────────────────────────────── */
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_report_presets (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255) NOT NULL,
            config      LONGTEXT NOT NULL,
            created_by  BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }

    private static function add_roles() {
        add_role( 'tt_player', __( 'Player', 'talenttrack' ), [ 'read' => true ] );
        add_role( 'tt_coach', __( 'Coach', 'talenttrack' ), [
            'read' => true, 'tt_evaluate_players' => true,
        ]);
        add_role( 'tt_head_dev', __( 'Head of Development', 'talenttrack' ), [
            'read' => true, 'tt_manage_players' => true,
            'tt_evaluate_players' => true, 'tt_manage_settings' => true,
        ]);
        add_role( 'tt_staff', __( 'Staff', 'talenttrack' ), [
            'read' => true, 'tt_manage_players' => true,
        ]);

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( [ 'tt_manage_players', 'tt_evaluate_players', 'tt_manage_settings' ] as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    private static function seed_defaults() {
        global $wpdb;
        $p = $wpdb->prefix;

        // Only seed if lookups table is empty
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_lookups" );
        if ( $count > 0 ) return;

        // Evaluation categories
        $cats = [
            ['Technical', 'Ball control, passing, shooting, dribbling', 1],
            ['Tactical',  'Positioning, decision-making, game reading', 2],
            ['Physical',  'Speed, endurance, strength, agility', 3],
            ['Mental',    'Focus, leadership, attitude, resilience', 4],
        ];
        foreach ( $cats as $c ) {
            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type' => 'eval_category',
                'name' => $c[0], 'description' => $c[1], 'sort_order' => $c[2],
            ]);
        }

        // Evaluation types
        $types = [
            ['Training', 'Regular training session evaluation', '{"requires_match_details":false}', 1],
            ['Match',    'Competitive match evaluation', '{"requires_match_details":true}', 2],
            ['Friendly', 'Friendly / scrimmage evaluation', '{"requires_match_details":true}', 3],
        ];
        foreach ( $types as $t ) {
            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type' => 'eval_type',
                'name' => $t[0], 'description' => $t[1], 'meta' => $t[2], 'sort_order' => $t[3],
            ]);
        }

        // Positions
        foreach ( ['GK','CB','LB','RB','CDM','CM','CAM','LW','RW','ST','CF'] as $i => $pos ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'position', 'name' => $pos, 'sort_order' => $i + 1 ] );
        }

        // Preferred foot
        foreach ( ['Left','Right','Both'] as $i => $f ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'foot_option', 'name' => $f, 'sort_order' => $i + 1 ] );
        }

        // Age groups
        foreach ( ['U8','U10','U12','U14','U16','U19','Senior'] as $i => $ag ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'age_group', 'name' => $ag, 'sort_order' => $i + 1 ] );
        }

        // Goal statuses
        foreach ( ['Pending','In Progress','Completed','On Hold','Cancelled'] as $i => $s ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'goal_status', 'name' => $s, 'sort_order' => $i + 1 ] );
        }

        // Goal priorities
        foreach ( ['Low','Medium','High'] as $i => $pr ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'goal_priority', 'name' => $pr, 'sort_order' => $i + 1 ] );
        }

        // Attendance statuses
        foreach ( ['Present','Absent','Late','Injured','Excused'] as $i => $a ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'attendance_status', 'name' => $a, 'sort_order' => $i + 1 ] );
        }

        // Default config
        $defaults = [
            'rating_min'          => '1',
            'rating_max'          => '5',
            'rating_step'         => '0.5',
            'season_label'        => '2025/2026',
            'academy_name'        => 'Soccer Academy',
            'footer_text'         => '',
            'date_format'         => 'Y-m-d',
            'default_report_range'=> '3',
            'composite_weights'   => '{}',
            'modules_enabled'     => '["evaluations","goals","attendance","sessions","reports"]',
            'primary_color'       => '#0b3d2e',
            'secondary_color'     => '#e8b624',
            'logo_url'            => '',
        ];
        foreach ( $defaults as $k => $v ) {
            $wpdb->replace( "{$p}tt_config", [ 'config_key' => $k, 'config_value' => $v ] );
        }
    }
}
