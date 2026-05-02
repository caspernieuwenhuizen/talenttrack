<?php
/**
 * Migration 0059 — i18n audit (May 2026): rewrite stored "session" text
 * to "activity" in rows that ship default content.
 *
 * The v3.x "session → activity" rename (migration 0027) renamed the table
 * + column + lookup keys but didn't sweep the seeded text content of
 * `tt_lookups.description` (eval_type rows from migration 0001) or the
 * `tt_roles.description` rows shipped by `Activator::defaultRoleDefinitions()`.
 * Existing installs still display the legacy term.
 *
 * Idempotent — only updates rows whose current value matches the exact
 * pre-rename string; rows that have been edited by an admin are left alone.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0059_session_to_activity_stored_text';
    }

    public function up(): void {
        global $wpdb;

        // tt_lookups.description — eval_type rows seeded by 0001.
        $lookups_tbl = $wpdb->prefix . 'tt_lookups';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_tbl ) ) === $lookups_tbl ) {
            $wpdb->update(
                $lookups_tbl,
                [ 'description' => 'Regular training activity evaluation' ],
                [
                    'lookup_type' => 'eval_type',
                    'name'        => 'Training',
                    'description' => 'Regular training session evaluation',
                ]
            );
        }

        // tt_roles.description — physio + team_member rows seeded by Activator.
        $roles_tbl = $wpdb->prefix . 'tt_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $roles_tbl ) ) === $roles_tbl ) {
            $wpdb->update(
                $roles_tbl,
                [ 'description' => 'Read-only access to players and activities within assigned teams.' ],
                [
                    'role_key'    => 'physio',
                    'description' => 'Read-only access to players and sessions within assigned teams.',
                ]
            );
            $wpdb->update(
                $roles_tbl,
                [ 'description' => 'Minimal read-only access within assigned teams. Default authorization for the "Other" functional role — see only players and activities of the teams you are assigned to, nothing more.' ],
                [
                    'role_key'    => 'team_member',
                    'description' => 'Minimal read-only access within assigned teams. Default authorization for the "Other" functional role — see only players and sessions of the teams you are assigned to, nothing more.',
                ]
            );
        }
    }
};
