<?php
namespace TT\Modules\Tournaments\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TournamentDayActivity (#4031) — the tournament day's own entry on the team's
 * calendar, created with the tournament instead of at the first kick-off.
 *
 * ## What was wrong
 *
 * Nothing at all existed in `tt_activities` for a tournament until somebody
 * tapped **Kick off** on one of its fixtures. So a tournament day planned three
 * weeks ahead was invisible to everybody who works from the team's activity
 * list — the team manager sorting transport and kit, the assistant coach,
 * the parents reading the calendar — and there was no activity to register
 * availability against. On the demo install only the one fixture that had been
 * kicked off had an activity; its three siblings, scheduled for the same
 * morning, had none.
 *
 * ## The shape
 *
 * A tournament **day** is the unit (#2686): per-fixture surfaces exclude
 * `tournament`, so the day is the activity and its register is the day's. That
 * is the activity this service creates — `activity_type_key = tournament`,
 * linked by `tournament_id`, which is exactly the row an operator used to have
 * to add by hand.
 *
 * The fixtures keep an activity each, created on kick-off. That is #3857's
 * model and the minutes audit reads it: the **day** is a roll-up of its
 * fixtures' minutes and refuses to be edited, while a fixture activity is where
 * its own register, its score (#4021) and its confirmed per-player minutes
 * (#4032) live. Pointing every fixture at one shared day activity instead would
 * make completing the second fixture wipe the first one's register, because
 * `complete_match()` rebuilds it from scratch.
 *
 * ## Why a listener
 *
 * Two paths create a tournament — the REST route and the wizard's final step,
 * which writes its rows directly — and both fire `tt_tournament_created`. One
 * listener covers both, and keeps the decision out of the controller
 * (CLAUDE.md §4). Everything here is idempotent, so a path that fires twice
 * costs a SELECT.
 */
final class TournamentDayActivity {

    public static function init(): void {
        add_action( 'tt_tournament_created', [ self::class, 'onTournamentCreated' ], 10, 1 );
        add_action( 'tt_tournament_updated', [ self::class, 'onTournamentUpdated' ], 10, 1 );
        add_action( 'tt_tournament_match_created', [ self::class, 'onTournamentCreated' ], 10, 1 );
    }

    public static function onTournamentCreated( $tournament_id ): void {
        self::ensureFor( (int) $tournament_id );
    }

    public static function onTournamentUpdated( $tournament_id ): void {
        self::syncFor( (int) $tournament_id );
    }

    /**
     * The day activity's id, creating it when the tournament has none.
     *
     * @return int the activity id, or 0 when there is nothing to hang one on
     *   (the tournament is gone, has no team, or has no start date). A
     *   tournament is never refused over its calendar entry.
     */
    public static function ensureFor( int $tournament_id ): int {
        $existing = self::idFor( $tournament_id );
        if ( $existing > 0 ) return $existing;

        $tournament = self::tournamentRow( $tournament_id );
        if ( ! $tournament ) return 0;

        $team_id      = (int) ( $tournament->team_id ?? 0 );
        $session_date = self::dateOf( $tournament );
        if ( $team_id <= 0 || $session_date === '' ) return 0;

        global $wpdb; $p = $wpdb->prefix;
        $ok = $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => CurrentClub::id(),
            'team_id'             => $team_id,
            'session_date'        => $session_date,
            'title'               => (string) ( $tournament->name ?? '' ),
            'activity_type_key'   => ActivityTypeKey::TOURNAMENT,
            'activity_status_key' => ActivityStatusKey::PLANNED,
            'activity_source_key' => 'tournament',
            'tournament_id'       => $tournament_id,
            'coach_id'            => (int) ( $tournament->created_by ?? get_current_user_id() ),
        ] );
        if ( $ok === false ) {
            Logger::error( 'tournament.day_activity.failed', [
                'tournament_id' => $tournament_id,
                'db_error'      => (string) $wpdb->last_error,
            ] );
            return 0;
        }
        $activity_id = (int) $wpdb->insert_id;

        // Tag demo-on rows so they stay visible to demo-scoped queries.
        if ( class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            \TT\Modules\DemoData\DemoMode::tagIfActive( 'activity', $activity_id );
        }

        do_action( 'tt_tournament_day_activity_created', $tournament_id, $activity_id );

        return $activity_id;
    }

    /**
     * Keep the calendar entry on the tournament's own date, name and team.
     *
     * A stale date is worse than no entry: somebody turns up. Only these three
     * fields are written, so anything else an operator has edited on the day —
     * its notes, its status, its register — survives a rename.
     */
    public static function syncFor( int $tournament_id ): void {
        $activity_id = self::ensureFor( $tournament_id );
        if ( $activity_id <= 0 ) return;

        $tournament = self::tournamentRow( $tournament_id );
        if ( ! $tournament ) return;

        $session_date = self::dateOf( $tournament );
        if ( $session_date === '' ) return;

        global $wpdb; $p = $wpdb->prefix;
        $wpdb->update(
            "{$p}tt_activities",
            [
                'session_date' => $session_date,
                'title'        => (string) ( $tournament->name ?? '' ),
                'team_id'      => (int) ( $tournament->team_id ?? 0 ),
            ],
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * The id of this tournament's day activity, or 0 when it has none.
     */
    public static function idFor( int $tournament_id ): int {
        if ( $tournament_id <= 0 ) return 0;
        global $wpdb; $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_activities
              WHERE club_id = %d
                AND tournament_id = %d
                AND activity_type_key = %s
                AND archived_at IS NULL
                AND trashed_at IS NULL
           ORDER BY id ASC
              LIMIT 1",
            CurrentClub::id(), $tournament_id, ActivityTypeKey::TOURNAMENT
        ) );
    }

    /**
     * @return object|null
     */
    private static function tournamentRow( int $tournament_id ): ?object {
        if ( $tournament_id <= 0 ) return null;
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, name, start_date, created_by
               FROM {$p}tt_tournaments
              WHERE id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );
        return is_object( $row ) ? $row : null;
    }

    private static function dateOf( object $tournament ): string {
        return substr( (string) ( $tournament->start_date ?? '' ), 0, 10 );
    }
}
