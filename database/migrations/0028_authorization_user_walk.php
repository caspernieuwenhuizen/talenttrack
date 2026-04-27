<?php
/**
 * Migration 0028 — Authorization Sprint 2 user walk + matrix data fixes (#0033).
 *
 * Two parts:
 *
 * 1. Data fixes for the matrix table from Sprint 1's seed:
 *    - The seed had a leftover `TT\Modules\Sessions\ActivitiesModule`
 *      module_class string that's a dead namespace after #0035 (the
 *      class lives at `TT\Modules\Activities\ActivitiesModule` now).
 *      Update existing rows. New seeds via reseed() pick up the fix
 *      automatically (the seed file is now correct).
 *    - The "my_sessions" entity was a Sprint-1 leftover; the renamed
 *      tile slug is "my_activities". Update existing rows.
 *
 * 2. User walk for the new matrix-driven cap path (Sprint 2):
 *    - For every WP user with a `tt_*` role, verify their persona
 *      derivation works (PersonaResolver returns at least one persona).
 *    - Log a per-user one-line summary so admins can audit who maps
 *      to what before Sprint 8's apply toggle goes live.
 *    - Output writes to error_log() prefixed `[#0033 user-walk]` AND
 *      to `tt_audit_log` if the table exists, so the walk is visible
 *      in the Audit log surface (#0021 when it lands).
 *
 * The walk is non-mutating — it does NOT touch `tt_user_role_scopes`
 * or any user state. Sprint 2's user_has_cap filter is gated by the
 * `tt_authorization_active` config flag (default 0); the matrix-driven
 * path stays dormant until Sprint 8 ships the apply toggle.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0028_authorization_user_walk';
    }

    public function up(): void {
        $this->fixMatrixModuleClass();
        $this->fixMatrixEntityRename();
        $this->walkUsers();
    }

    /**
     * Update matrix rows that carry the stale `TT\Modules\Sessions\ActivitiesModule`
     * to the live `TT\Modules\Activities\ActivitiesModule`. Idempotent.
     */
    private function fixMatrixModuleClass(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_authorization_matrix";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET module_class = %s WHERE module_class = %s",
            'TT\\Modules\\Activities\\ActivitiesModule',
            'TT\\Modules\\Sessions\\ActivitiesModule'
        ) );
    }

    /**
     * Rename the `my_sessions` entity to `my_activities` in matrix rows
     * so they line up with the renamed tile slug from #0035. Idempotent.
     */
    private function fixMatrixEntityRename(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_authorization_matrix";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Use INSERT ... ON DUPLICATE KEY UPDATE because the unique key
        // (persona, entity, activity, scope_kind) means a straight UPDATE
        // could collide if both rows already exist. Two-step: copy with
        // new entity if not already present, then delete the old.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE entity = %s
                AND EXISTS (
                  SELECT 1 FROM (SELECT * FROM {$table}) AS m2
                  WHERE m2.entity = %s
                    AND m2.persona = {$table}.persona
                    AND m2.activity = {$table}.activity
                    AND m2.scope_kind = {$table}.scope_kind
                )",
            'my_sessions',
            'my_activities'
        ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET entity = %s WHERE entity = %s",
            'my_activities',
            'my_sessions'
        ) );
    }

    /**
     * Walk every WP user with a tt_* role; log per-user persona summary.
     * Non-mutating.
     */
    private function walkUsers(): void {
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) {
            return;
        }

        global $wpdb;
        $audit_table = "{$wpdb->prefix}tt_audit_log";
        $audit_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) === $audit_table;

        $args = [
            'number'       => -1,
            'role__in'     => [
                'tt_player', 'tt_parent', 'tt_coach', 'tt_scout', 'tt_staff',
                'tt_head_dev', 'tt_club_admin', 'tt_readonly_observer', 'tt_team_manager',
                'administrator',
            ],
            'fields'       => [ 'ID', 'user_login', 'user_email' ],
        ];
        $users = function_exists( 'get_users' ) ? get_users( $args ) : [];

        $count = 0;
        $no_persona = 0;
        foreach ( (array) $users as $u ) {
            $user_id = (int) $u->ID;
            $personas = \TT\Modules\Authorization\PersonaResolver::personasFor( $user_id );
            $persona_str = empty( $personas ) ? 'NONE' : implode( ',', $personas );
            $line = sprintf(
                '[#0033 user-walk] user=%d login=%s personas=%s',
                $user_id,
                (string) ( $u->user_login ?? '' ),
                $persona_str
            );
            if ( function_exists( 'error_log' ) ) {
                error_log( $line );
            }
            if ( $audit_exists ) {
                $wpdb->insert( $audit_table, [
                    'user_id'     => 0, // system actor — no acting user during migration
                    'action'      => 'authorization.user_walk',
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                    'payload'     => wp_json_encode( [
                        'login'    => (string) ( $u->user_login ?? '' ),
                        'personas' => $personas,
                    ] ),
                    'ip_address'  => '',
                    'created_at'  => current_time( 'mysql' ),
                ] );
            }
            $count++;
            if ( empty( $personas ) ) $no_persona++;
        }

        if ( function_exists( 'error_log' ) ) {
            error_log( sprintf(
                '[#0033 user-walk] complete — %d users walked, %d had no persona derivation',
                $count,
                $no_persona
            ) );
        }
    }
};
