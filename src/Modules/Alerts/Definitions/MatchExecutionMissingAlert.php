<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\License\LicenseGate;
use TT\Modules\MatchExecution\MatchExecutionModule;

/**
 * MatchExecutionMissingAlert (#4060, epic #2629).
 *
 * The game was marked completed and minutes were recorded for it, but it was
 * never run through match execution. The minutes are real; what is missing is
 * the timeline behind them — who was on at kick-off, who came on, who went
 * off — so the minutes report shows 0 starts, 0 substitute appearances and 0
 * substitutions for that game. Those zeros read as "this player was never
 * rotated", which is usually the opposite of what happened.
 *
 * Same shape of problem as `AttendanceUnrecordedAlert`: the match looks
 * finished on every screen, and only the reports know part of it is empty.
 * The report keeps showing 0 rather than inventing a figure; this alert is
 * how the absence of the record becomes visible to the people who can fix
 * it.
 *
 * Which player question does this answer? *How much did this player really
 * play, and were they given a fair share of the game?* Starts and
 * substitutions are what a coach reads rotation and game-time fairness from.
 *
 * Self-resolving: creating the match-execution record clears it on the next
 * sweep. Tournament days and their fixtures are left out — their minutes are
 * kept in the tournament planner, which has its own record of who started.
 *
 * 48 hours of grace, for the reason #2631 gives: writing the match up the
 * next morning is normal practice.
 */
final class MatchExecutionMissingAlert extends AbstractActivityAlert {

    private const GRACE_HOURS       = 48;
    private const URGENT_AFTER_DAYS = 14;

    public function key(): string {
        return 'activities.match_execution_missing';
    }

    public function label(): string {
        return __( 'Match played without a match record', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A game is completed and has minutes recorded, but it was never run through match execution. Its starts, substitute appearances and substitutions cannot be counted, so the minutes report shows 0 for them.', 'talenttrack' );
    }

    /**
     * Ages up after a fortnight — past that, nobody reliably remembers who
     * came on when, and the record is effectively unrecoverable.
     */
    protected function severityFor( object $row ): string {
        $days = $this->daysSince( (string) ( $row->session_date ?? '' ) );
        return $days >= self::URGENT_AFTER_DAYS ? Severity::URGENT : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = trim( (string) ( $row->title ?? '' ) );
        if ( $name === '' ) $name = __( 'Untitled activity', 'talenttrack' );

        return sprintf(
            /* translators: %s: match name */
            __( '%s has minutes but no match record, so its starts and substitutions cannot be counted.', 'talenttrack' ),
            $name
        );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        // An academy without match execution cannot create the record this
        // asks for. Telling them it is missing would be nagging about a
        // feature they do not have, on every game they play.
        if ( ! ModuleRegistry::isEnabled( MatchExecutionModule::class ) ) return [];
        if ( ! LicenseGate::allows( 'match_execution' ) ) return [];

        global $wpdb;
        $p = $wpdb->prefix;

        $completed = ActivityLifecycle::completedClause( 'a' );

        // Minutes are the same predicate every minutes report reads
        // (`MinutesQuery::persistedMinutes`): recorded, non-guest, effective
        // minutes above zero. A completed game with no minutes at all is
        // `AttendanceUnrecordedAlert`'s subject, not this one's.
        $sql = $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.team_id, a.coach_id
               FROM {$p}tt_activities a
              WHERE " . $this->baseWhere( 'a' ) . "
                AND a.trashed_at IS NULL
                AND LOWER(a.activity_type_key) IN ( 'match', 'game' )
                AND ( a.tournament_id IS NULL OR a.tournament_id = 0 )
                AND {$completed}
                AND a.session_date < DATE_SUB( NOW(), INTERVAL %d HOUR )
                AND EXISTS (
                    SELECT 1 FROM {$p}tt_attendance att
                     WHERE att.activity_id = a.id
                       AND att.record_type = 'actual'
                       AND att.is_guest = 0
                       AND COALESCE( att.minutes_override, att.minutes_played, 0 ) > 0
                )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_match_execution me
                     WHERE me.activity_id = a.id
                       AND me.club_id = a.club_id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_tournament_matches tm
                     WHERE tm.activity_id = a.id
                )"
            . $context->applyScope( self::SUBJECT_TYPE, 'a.id' ) . "
              ORDER BY a.session_date ASC, a.id ASC",
            self::GRACE_HOURS
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : [];
    }
}
