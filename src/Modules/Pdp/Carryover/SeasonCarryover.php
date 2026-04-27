<?php
namespace TT\Modules\Pdp\Carryover;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Pdp\Calendar\PdpCalendarWriters;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * SeasonCarryover — one-shot job that runs whenever a new season is
 * set current.
 *
 * For every PDP file in the previous current season:
 *   1. Skip if a file already exists for the new season for the same player.
 *   2. Create a fresh file (auto-templated conversations, blank prose).
 *   3. Copy all non-completed/archived goals from the player's previous
 *      season into the new season — fresh created_at, due_date cleared.
 *
 * The hook seam is `tt_pdp_season_set_current`. Listener installs in
 * PdpModule::boot().
 */
class SeasonCarryover {

    public static function init(): void {
        add_action( 'tt_pdp_season_set_current', [ __CLASS__, 'run' ], 10, 1 );
    }

    public static function run( int $new_season_id ): void {
        if ( $new_season_id <= 0 ) return;

        $seasons = new SeasonsRepository();
        $new = $seasons->find( $new_season_id );
        if ( ! $new ) return;

        // Find the most recent OTHER season — that's the one to carry over from.
        $previous = self::previousSeason( $new_season_id );
        if ( ! $previous ) return;

        $files_repo = new PdpFilesRepository();
        $convs_repo = new PdpConversationsRepository();
        $writer     = PdpCalendarWriters::default();

        $existing = $files_repo->listForSeason( (int) $previous->id );
        if ( empty( $existing ) ) return;

        $cycle_default = self::resolveClubCycleDefault();

        foreach ( $existing as $old ) {
            $player_id = (int) $old->player_id;
            if ( $player_id <= 0 ) continue;

            // Skip if a file already exists for the new season.
            if ( $files_repo->findByPlayerSeason( $player_id, (int) $new->id ) ) continue;

            $cycle_size = (int) ( $old->cycle_size ?? 0 );
            if ( ! in_array( $cycle_size, [ 2, 3, 4 ], true ) ) {
                $cycle_size = self::teamCycleOverride( $player_id ) ?? $cycle_default;
            }

            $new_file_id = $files_repo->create( [
                'player_id'      => $player_id,
                'season_id'      => (int) $new->id,
                'owner_coach_id' => $old->owner_coach_id !== null ? (int) $old->owner_coach_id : null,
                'cycle_size'     => $cycle_size,
                'notes'          => '',
            ] );
            if ( $new_file_id <= 0 ) {
                Logger::error( 'pdp.carryover.create_failed', [
                    'player_id'  => $player_id,
                    'new_season' => (int) $new->id,
                ] );
                continue;
            }

            $convs_repo->createCycle( $new_file_id, $cycle_size, (string) $new->start_date, (string) $new->end_date );
            foreach ( $convs_repo->listForFile( $new_file_id ) as $c ) {
                $writer->onConversationScheduled( (int) $c->id );
            }

            self::copyOpenGoals( $player_id );
        }
    }

    /**
     * Most recent season other than $exclude_id. The "previous" season
     * we carry over from. Returns null when there isn't one (first
     * season in the install).
     */
    private static function previousSeason( int $exclude_id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_seasons WHERE id <> %d ORDER BY start_date DESC, id DESC LIMIT 1",
            $exclude_id
        ) );
        return $row ?: null;
    }

    /**
     * Re-create open goals as fresh rows so the new season starts with
     * the carryover set in its own goal-list. The original goals stay
     * where they are — the player profile + reports surface preserves
     * the historical view via archived_at / season-of-creation joins
     * (those are out of v1 scope; for now goals just live forward).
     *
     * Strategy: insert fresh rows with `created_at = NOW()` and
     * `due_date = NULL`. Same title/description/priority. Status is
     * reset to 'pending' so the new coach picks them up cleanly.
     */
    private static function copyOpenGoals( int $player_id ): void {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, description, priority, created_by FROM {$p}tt_goals
              WHERE player_id = %d
                AND archived_at IS NULL
                AND status NOT IN ('completed', 'archived')",
            $player_id
        ) );
        if ( ! is_array( $rows ) ) return;
        foreach ( $rows as $g ) {
            $wpdb->insert( $p . 'tt_goals', [
                'player_id'   => $player_id,
                'title'       => (string) $g->title,
                'description' => (string) ( $g->description ?? '' ),
                'status'      => 'pending',
                'priority'    => (string) ( $g->priority ?? 'medium' ),
                'due_date'    => null,
                'created_by'  => (int) ( $g->created_by ?? 0 ),
            ] );
        }
    }

    private static function teamCycleOverride( int $player_id ): ?int {
        global $wpdb; $p = $wpdb->prefix;
        $size = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT t.pdp_cycle_size
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
              WHERE pl.id = %d",
            $player_id
        ) );
        return in_array( $size, [ 2, 3, 4 ], true ) ? $size : null;
    }

    private static function resolveClubCycleDefault(): int {
        global $wpdb; $p = $wpdb->prefix;
        $val = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$p}tt_config WHERE config_key = %s",
            'pdp_cycle_default'
        ) );
        return in_array( $val, [ 2, 3, 4 ], true ) ? $val : 3;
    }
}
