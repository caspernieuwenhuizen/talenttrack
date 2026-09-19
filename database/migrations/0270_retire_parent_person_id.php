<?php
/**
 * Migration 0270 — retire `tt_players.parent_person_id` (#3572).
 *
 * TalentTrack stored "who is this player's parent" twice: the
 * `tt_player_parents` pivot (a parent's WordPress account, written by the
 * Parent accounts screen and every REST link, read by every access check)
 * and `tt_players.parent_person_id` (a people record, written only by the
 * wp-admin player form's picker). The players list read the second, so a
 * parent linked the supported way showed as "no parent", and an admin
 * concluded the link had failed.
 *
 * The pivot is the one model now. This migration carries the old links
 * across:
 *
 *   - a `parent_person_id` whose people record has a WordPress account is
 *     linked through `ParentAccountService::linkToPlayer()`, the same rule
 *     every other link obeys, so it also gets the parent role, and an
 *     existing link is a no-op;
 *   - one whose people record has no account cannot become a pivot row
 *     (the pivot keys on the account). It is reported to the error log —
 *     player and guardian named — and left in place, so an admin can
 *     re-enter the guardian as the player's contact fields. Nothing is
 *     deleted.
 *
 * The column stays for one release, written and read by nothing, and is
 * dropped in a follow-up. Idempotent: re-running links nothing new.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Players\ParentAccountService;

return new class extends Migration {

    public function getName(): string {
        return '0270_retire_parent_person_id';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $players = "{$p}tt_players";
        $people  = "{$p}tt_people";
        $pivot   = "{$p}tt_player_parents";

        foreach ( [ $players, $people, $pivot ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;
        }
        $has_column = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_person_id'",
            $players
        ) );
        if ( $has_column === 0 ) return;

        $rows = $wpdb->get_results(
            "SELECT pl.id AS player_id, pl.first_name AS player_first, pl.last_name AS player_last,
                    pe.id AS person_id, pe.first_name AS person_first, pe.last_name AS person_last,
                    pe.email AS person_email, pe.wp_user_id
               FROM {$players} pl
               LEFT JOIN {$people} pe ON pe.id = pl.parent_person_id AND pe.club_id = pl.club_id
              WHERE pl.parent_person_id IS NOT NULL AND pl.parent_person_id > 0"
        );
        if ( ! is_array( $rows ) || $rows === [] ) return;

        $service = new ParentAccountService();
        foreach ( $rows as $row ) {
            $r         = (array) $row;
            $player_id = (int) ( $r['player_id'] ?? 0 );
            $user_id   = (int) ( $r['wp_user_id'] ?? 0 );
            $context   = [
                'player_id' => $player_id,
                'player'    => trim( (string) ( $r['player_first'] ?? '' ) . ' ' . (string) ( $r['player_last'] ?? '' ) ),
                'person_id' => (int) ( $r['person_id'] ?? 0 ),
                'guardian'  => trim( (string) ( $r['person_first'] ?? '' ) . ' ' . (string) ( $r['person_last'] ?? '' ) ),
                'email'     => (string) ( $r['person_email'] ?? '' ),
            ];

            if ( $user_id <= 0 ) {
                Logger::warning( 'migration.0270.parent_without_account', $context );
                continue;
            }

            $result = $service->linkToPlayer( $player_id, $user_id );
            if ( empty( $result['ok'] ) ) {
                Logger::warning( 'migration.0270.parent_not_linked', $context + [ 'code' => (string) $result['code'] ] );
            }
        }
    }
};
