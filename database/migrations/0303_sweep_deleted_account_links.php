<?php
/**
 * Migration 0303 — clear account links that point at missing accounts (#4398).
 *
 * `WpUserUnlink` clears an account's links when it is deleted, but only
 * since #1772. An account removed before that can still be referenced by
 * `tt_player_parents` rows and by `wp_user_id` / `parent_user_id` on
 * `tt_players` and `wp_user_id` on `tt_people`, and a re-issued user id
 * would then link a different person to the record.
 *
 * For every id that no longer exists in `wp_users`: the parent-pivot rows
 * are deleted, the player and person links are set to NULL. Player and
 * person records are kept. Not club-scoped, for the reason `WpUserUnlink`
 * gives: a WP user id is global to the install. Each affected player gets
 * one `tt_audit_log` entry under its own `club_id`.
 *
 * Idempotent: a second run finds no link to a missing account and writes
 * nothing. The counts changed are logged.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0303_sweep_deleted_account_links';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $users   = $wpdb->users;
        $players = "{$p}tt_players";
        $pivot   = "{$p}tt_player_parents";
        $people  = "{$p}tt_people";

        $has_players = $this->tableExists( $players );
        $has_pivot   = $this->tableExists( $pivot );
        $has_parent  = $has_players && $this->columnExists( $players, 'parent_user_id' );

        // Read what is about to change per player, before it changes.
        $affected = [];
        if ( $has_players ) {
            $parent_col = $has_parent ? 'pl.parent_user_id' : 'NULL';
            $parent_cnd = $has_parent
                ? 'OR ( pl.parent_user_id IS NOT NULL AND pl.parent_user_id > 0 AND pu.ID IS NULL )'
                : '';
            $parent_jn  = $has_parent ? "LEFT JOIN {$users} pu ON pu.ID = pl.parent_user_id" : '';
            $parent_sel = $has_parent ? 'pu.ID' : 'NULL';
            $rows = $wpdb->get_results(
                "SELECT pl.id, pl.club_id,
                        pl.wp_user_id, wu.ID AS wp_user_exists,
                        {$parent_col} AS parent_user_id, {$parent_sel} AS parent_user_exists
                   FROM {$players} pl
                   LEFT JOIN {$users} wu ON wu.ID = pl.wp_user_id
                   {$parent_jn}
                  WHERE ( pl.wp_user_id IS NOT NULL AND pl.wp_user_id > 0 AND wu.ID IS NULL )
                        {$parent_cnd}"
            );
            foreach ( (array) $rows as $r ) {
                $entry = [ 'club_id' => (int) $r->club_id, 'parent_links_removed' => [] ];
                if ( (int) $r->wp_user_id > 0 && $r->wp_user_exists === null ) {
                    $entry['wp_user_id'] = (int) $r->wp_user_id;
                }
                if ( (int) $r->parent_user_id > 0 && $r->parent_user_exists === null ) {
                    $entry['parent_user_id'] = (int) $r->parent_user_id;
                }
                $affected[ (int) $r->id ] = $entry;
            }
        }
        if ( $has_pivot && $has_players ) {
            $links = $wpdb->get_results(
                "SELECT pp.player_id, pp.parent_user_id, pl.club_id
                   FROM {$pivot} pp
                   JOIN {$players} pl ON pl.id = pp.player_id
                   LEFT JOIN {$users} u ON u.ID = pp.parent_user_id
                  WHERE u.ID IS NULL"
            );
            foreach ( (array) $links as $l ) {
                $id = (int) $l->player_id;
                if ( ! isset( $affected[ $id ] ) ) {
                    $affected[ $id ] = [ 'club_id' => (int) $l->club_id, 'parent_links_removed' => [] ];
                }
                $affected[ $id ]['parent_links_removed'][] = (int) $l->parent_user_id;
            }
        }

        $links_deleted  = 0;
        $players_nulled = 0;
        $parents_nulled = 0;
        $people_nulled  = 0;

        if ( $has_pivot ) {
            $links_deleted = $this->exec(
                "DELETE pp FROM {$pivot} pp
                   LEFT JOIN {$users} u ON u.ID = pp.parent_user_id
                  WHERE u.ID IS NULL"
            );
        }

        if ( $has_players ) {
            $players_nulled = $this->exec(
                "UPDATE {$players} pl
                   LEFT JOIN {$users} u ON u.ID = pl.wp_user_id
                    SET pl.wp_user_id = NULL
                  WHERE pl.wp_user_id IS NOT NULL
                    AND pl.wp_user_id > 0
                    AND u.ID IS NULL"
            );
        }

        if ( $has_parent ) {
            $parents_nulled = $this->exec(
                "UPDATE {$players} pl
                   LEFT JOIN {$users} u ON u.ID = pl.parent_user_id
                    SET pl.parent_user_id = NULL
                  WHERE pl.parent_user_id IS NOT NULL
                    AND pl.parent_user_id > 0
                    AND u.ID IS NULL"
            );
        }

        if ( $this->tableExists( $people ) ) {
            $people_nulled = $this->exec(
                "UPDATE {$people} pe
                   LEFT JOIN {$users} u ON u.ID = pe.wp_user_id
                    SET pe.wp_user_id = NULL
                  WHERE pe.wp_user_id IS NOT NULL
                    AND pe.wp_user_id > 0
                    AND u.ID IS NULL"
            );
        }

        $this->audit( $affected );

        if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
            \TT\Infrastructure\Logging\Logger::info( 'migration.0303.missing_account_links_cleared', [
                'parent_links_deleted'  => $links_deleted,
                'player_links_nulled'   => $players_nulled,
                'player_parents_nulled' => $parents_nulled,
                'person_links_nulled'   => $people_nulled,
                'players_audited'       => count( $affected ),
            ] );
        }
    }

    /**
     * One entry per affected player, under that player's own club.
     *
     * @param array<int, array<string, mixed>> $affected player id => what was cleared
     */
    private function audit( array $affected ): void {
        global $wpdb;
        $audit = $wpdb->prefix . 'tt_audit_log';
        if ( ! $affected || ! $this->tableExists( $audit ) ) return;

        $now = current_time( 'mysql' );
        foreach ( $affected as $player_id => $entry ) {
            $club_id = (int) $entry['club_id'];
            unset( $entry['club_id'] );
            $payload = array_merge( [ 'reason' => 'migration_0303' ], $entry );

            $this->exec( $wpdb->prepare(
                "INSERT INTO {$audit} (club_id, user_id, action, entity_type, entity_id, payload, created_at)
                 VALUES (%d, 0, %s, %s, %d, %s, %s)",
                $club_id,
                'player.account_links_cleared',
                'player',
                (int) $player_id,
                (string) wp_json_encode( $payload ),
                $now
            ) );
        }
    }

    private function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1",
            $table, $column
        ) );
    }
};
