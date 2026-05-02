<?php
/**
 * Migration 0054 — HoD persona narrowing (#0071 child 3).
 *
 * Applies the editorial decision narrowing Head of Development to a
 * read-mostly persona outside player-development surfaces. Strips the
 * write caps the new seed no longer grants:
 *
 *   - tt_edit_settings + the 11 tt_edit_* sub-caps (lookups, branding,
 *     feature_toggles, translations, custom_fields, eval categories,
 *     category_weights, rating_scale, migrations, seasons, setup_wizard)
 *
 * Two-phase, idempotent:
 *   1. Capture: snapshot the per-user cap state into tt_audit_log so
 *      the operator has a reversible audit trail.
 *   2. Apply:   remove the now-deprecated edit caps from each tt_head_dev
 *      user's effective set.
 *
 * Opt-out: define( 'TT_HOD_KEEP_LEGACY_CAPS', true ) in wp-config.php
 * skips the apply phase. For installs that want the old behaviour for
 * organisational reasons.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0054_hod_persona_narrowing';
    }

    public function up(): void {
        if ( ! function_exists( 'get_users' ) ) return;

        if ( defined( 'TT_HOD_KEEP_LEGACY_CAPS' ) && TT_HOD_KEEP_LEGACY_CAPS ) {
            return; // Operator opt-out.
        }

        $deprecated_for_hod = [
            'tt_edit_settings',
            'tt_edit_lookups', 'tt_edit_branding', 'tt_edit_feature_toggles',
            'tt_edit_translations', 'tt_edit_custom_fields',
            'tt_edit_evaluation_categories', 'tt_edit_category_weights',
            'tt_edit_rating_scale', 'tt_edit_migrations', 'tt_edit_seasons',
            'tt_edit_setup_wizard',
            'tt_manage_settings',
            // dev_ideas RCD → C: drop the delete cap if it ever existed
            'tt_promote_idea',
            // workflow templates RCD → R only
            'tt_manage_workflow_templates',
            'tt_configure_workflow_templates',
        ];

        $hod_users = (array) get_users( [ 'role' => 'tt_head_dev', 'fields' => [ 'ID' ] ] );

        global $wpdb;
        $audit_table = $wpdb->prefix . 'tt_audit_log';
        $audit_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) === $audit_table;

        foreach ( $hod_users as $row ) {
            $user_id = (int) $row->ID;
            $u = get_userdata( $user_id );
            if ( ! $u ) continue;

            $stripped = [];
            foreach ( $deprecated_for_hod as $cap ) {
                if ( $u->has_cap( $cap ) ) {
                    $stripped[] = $cap;
                    $u->remove_cap( $cap );
                }
            }

            if ( $audit_exists && ! empty( $stripped ) ) {
                // v3.77.3 fix — column names match the actual `tt_audit_log`
                // schema (created in migration 0002, renamed in 0030,
                // tenancy column added in 0038): `user_id` / `entity_type`
                // / `entity_id` / `payload`. The earlier draft used
                // `actor_user_id` / `object_type` / `object_id` / `meta_json`
                // which never existed and made the migration fail with
                // "Unknown column 'actor_user_id' in 'INSERT INTO'".
                $wpdb->insert( $audit_table, [
                    'club_id'     => 1,
                    'user_id'     => 0,
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                    'action'      => 'hod_narrowing.cap_revoked',
                    'payload'     => wp_json_encode( [ 'caps' => $stripped ] ),
                    'created_at'  => current_time( 'mysql', true ),
                ] );
            }
        }
    }
};
