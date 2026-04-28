<?php
/**
 * audit-tenancy.php (#0052 PR-A) — verify the SaaS-readiness scaffold.
 *
 * One-shot script. Run from a WP-CLI capable host:
 *
 *     wp eval-file wp-content/plugins/talenttrack/bin/audit-tenancy.php
 *
 * Or from PHP CLI inside a bootstrapped WordPress (see usage below).
 *
 * Verifies:
 *   1. Every tenant-scoped table declared by migration 0038 carries a
 *      `club_id` column.
 *   2. Every row in those tables has `club_id` set (NOT NULL, NOT 0).
 *   3. The five root entities (`tt_players`, `tt_teams`, `tt_evaluations`,
 *      `tt_activities`, `tt_goals`) carry a `uuid` column populated for
 *      every row, all values unique.
 *   4. `tt_config` has the composite `(club_id, config_key)` primary key.
 *
 * Exit code 0 on success, 1 on failure. Per-row report on stdout.
 *
 * Lives under bin/ so it isn't autoloaded — runs only when invoked.
 * Future SaaS-migration sprint can resurrect it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "audit-tenancy.php must be run inside a bootstrapped WordPress (use `wp eval-file`).\n" );
    exit( 1 );
}

global $wpdb;

$failures = [];

$club_id_tables = [
    // Tables that already shipped with club_id (skip — but verify column exists)
    'tt_trial_cases', 'tt_trial_tracks', 'tt_trial_letter_templates',
    'tt_player_events', 'tt_player_injuries',

    // Tables that 0038 added club_id to
    'tt_players', 'tt_teams', 'tt_people', 'tt_team_people',
    'tt_user_role_scopes', 'tt_player_parents', 'tt_player_team_history',
    'tt_evaluations', 'tt_eval_ratings', 'tt_eval_categories',
    'tt_category_weights', 'tt_activities', 'tt_attendance', 'tt_goals',
    'tt_goal_links', 'tt_seasons',
    'tt_pdp_files', 'tt_pdp_conversations', 'tt_pdp_verdicts', 'tt_pdp_calendar_links',
    'tt_player_reports', 'tt_report_presets',
    'tt_team_formations', 'tt_team_playing_styles', 'tt_formation_templates',
    'tt_team_chemistry_pairings',
    'tt_custom_fields', 'tt_custom_values',
    'tt_invitations', 'tt_audit_log',
    'tt_authorization_changelog', 'tt_authorization_matrix',
    'tt_workflow_tasks', 'tt_workflow_triggers', 'tt_workflow_event_log', 'tt_workflow_template_config',
    'tt_module_state', 'tt_dev_ideas', 'tt_dev_tracks',
    'tt_functional_roles', 'tt_functional_role_auth_roles', 'tt_roles', 'tt_role_permissions',
    'tt_methodology_assets', 'tt_methodology_framework_primers',
    'tt_methodology_influence_factors', 'tt_methodology_learning_goals',
    'tt_methodology_phases', 'tt_methodology_principle_links',
    'tt_methodology_visions', 'tt_principles', 'tt_set_pieces',
    'tt_football_actions', 'tt_formation_positions', 'tt_formations',
    'tt_session_principles',
    'tt_lookups',
    'tt_trial_case_staff', 'tt_trial_extensions', 'tt_trial_case_staff_inputs',
    'tt_translation_source_meta', 'tt_translations_cache', 'tt_translations_usage',
    'tt_usage_events', 'tt_demo_tags',
];

$root_entities = [ 'tt_players', 'tt_teams', 'tt_evaluations', 'tt_activities', 'tt_goals' ];

echo "TalentTrack tenancy audit — #0052 PR-A\n";
echo "======================================\n";

// 1 + 2: club_id column exists, every row has club_id set.
foreach ( $club_id_tables as $bare ) {
    $table = $wpdb->prefix . $bare;
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        echo "  [skip] $bare — table not present on this install\n";
        continue;
    }
    $col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'club_id' ) );
    if ( ! $col ) {
        $failures[] = "$bare: missing club_id column";
        echo "  [FAIL] $bare — no club_id column\n";
        continue;
    }
    $bad = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE club_id IS NULL OR club_id = 0" );
    if ( $bad > 0 ) {
        $failures[] = "$bare: $bad rows with NULL/0 club_id";
        echo "  [FAIL] $bare — $bad rows with NULL or 0 club_id\n";
    } else {
        echo "  [ ok ] $bare\n";
    }
}

// 3: uuid column populated + unique on root entities.
echo "\nRoot entities (uuid):\n";
foreach ( $root_entities as $bare ) {
    $table = $wpdb->prefix . $bare;
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        echo "  [skip] $bare — table not present\n";
        continue;
    }
    $col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'uuid' ) );
    if ( ! $col ) {
        $failures[] = "$bare: missing uuid column";
        echo "  [FAIL] $bare — no uuid column\n";
        continue;
    }
    $unset = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE uuid IS NULL OR uuid = ''" );
    $dupes = (int) $wpdb->get_var( "SELECT COUNT(*) - COUNT(DISTINCT uuid) FROM `$table` WHERE uuid IS NOT NULL AND uuid != ''" );
    if ( $unset > 0 ) {
        $failures[] = "$bare: $unset rows with empty uuid";
        echo "  [FAIL] $bare — $unset rows with empty uuid\n";
    } elseif ( $dupes > 0 ) {
        $failures[] = "$bare: $dupes duplicate uuids";
        echo "  [FAIL] $bare — $dupes duplicate uuids\n";
    } else {
        echo "  [ ok ] $bare\n";
    }
}

// 4: tt_config primary key check.
echo "\ntt_config primary key:\n";
$pk = $wpdb->get_col( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
        AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX ASC",
    $wpdb->prefix . 'tt_config'
) );
$pk_str = strtolower( implode( ',', (array) $pk ) );
if ( $pk_str === 'club_id,config_key' ) {
    echo "  [ ok ] composite (club_id, config_key)\n";
} else {
    $failures[] = "tt_config primary key is '$pk_str', expected 'club_id,config_key'";
    echo "  [FAIL] tt_config — primary key is '$pk_str', expected 'club_id,config_key'\n";
}

echo "\n--------------------------------------\n";
if ( empty( $failures ) ) {
    echo "PASS — every check returned ok.\n";
    exit( 0 );
}
echo "FAIL — " . count( $failures ) . " issue(s):\n";
foreach ( $failures as $f ) echo "  - $f\n";
exit( 1 );
