<?php
namespace TT\Modules\MatchExecution\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MatchRegisterGap (#3445) — "this match is closed and there is no
 * register behind it", derived from the database rather than from a
 * return value somebody remembered to check.
 *
 * Epic #3442's thesis is that an activity must not be able to reach a
 * completed state while the thing that makes it meaningful silently
 * failed. Ending a match is the one transition a coach cannot repeat —
 * the whistle has gone — so the answer here is never to refuse the
 * finish. It is to finish, and then say what is missing, in the same
 * words, in the response payload and on the screen the coach lands on.
 *
 * Deriving the gap from state instead of from the recompute's return
 * value is what keeps those two in step: the REST payload and the
 * rendered notice ask this class the same question and cannot drift
 * (CLAUDE.md §4).
 *
 * Minutes are derived from the match prep (availability + line-up) and
 * the substitution log, so a match run without prep, or with an empty
 * availability list, records neither attendance nor minutes.
 */
final class MatchRegisterGap {

    private int $activity_id;

    private string $reason;

    private function __construct( int $activity_id, string $reason ) {
        $this->activity_id = $activity_id;
        $this->reason      = $reason;
    }

    /**
     * Returns the gap for an activity, or null when the register is
     * there. "There" means at least one `record_type = 'actual'`
     * attendance row — the planned roster (`expected`) is the squad
     * somebody intended, not a record of who played.
     */
    public static function forActivity( int $activity_id ): ?self {
        if ( $activity_id <= 0 ) return null;
        if ( self::countActual( $activity_id ) > 0 ) return null;

        $prep_id = self::prepIdFor( $activity_id );
        if ( $prep_id <= 0 ) {
            return new self( $activity_id, AttendanceRecomputeOutcome::REASON_NO_PREP );
        }
        if ( self::countAvailability( $prep_id ) === 0 ) {
            return new self( $activity_id, AttendanceRecomputeOutcome::REASON_NO_AVAILABILITY );
        }
        // Prep and an availability list are both there, so the write
        // itself is what did not land. The repository logged why.
        return new self( $activity_id, AttendanceRecomputeOutcome::REASON_DB_ERROR );
    }

    public function reason(): string {
        return $this->reason;
    }

    /** The sentence a coach reads. Names the cause and the remedy. */
    public function message(): string {
        switch ( $this->reason ) {
            case AttendanceRecomputeOutcome::REASON_NO_PREP:
                return __( 'This match is closed, but no attendance or minutes could be worked out: it was run without a match plan, and minutes come from the plan\'s line-up plus the substitution log. Record attendance by hand.', 'talenttrack' );
            case AttendanceRecomputeOutcome::REASON_NO_AVAILABILITY:
                return __( 'This match is closed, but no attendance or minutes could be worked out: the match plan has nobody on its availability list. Record attendance by hand.', 'talenttrack' );
            default:
                return __( 'This match is closed, but no attendance or minutes were recorded for it. Record attendance by hand.', 'talenttrack' );
        }
    }

    /** Button label for {@see fixUrl()}. */
    public function fixLabel(): string {
        return __( 'Record attendance', 'talenttrack' );
    }

    /**
     * The `tt_view` slug {@see fixUrl()} points at, so a renderer can
     * gate the affordance on the viewer's reach of that surface.
     */
    public function targetSlug(): string {
        return $this->useGrid() ? 'attendance-grid' : 'activities';
    }

    /**
     * Where the coach repairs it: the attendance grid narrowed to this
     * activity's team and date. Falls back to the activity itself when
     * the grid is switched off, or when the activity carries no team.
     *
     * Returns a bare URL — the caller appends `tt_back` when it is in a
     * request that has somewhere to come back from.
     */
    public function fixUrl(): string {
        $activity = self::loadActivity( $this->activity_id );
        $team_id  = (int) ( $activity['team_id'] ?? 0 );
        $date     = (string) ( $activity['session_date'] ?? '' );

        if ( $this->useGrid() ) {
            return (string) add_query_arg(
                [
                    'tt_view' => 'attendance-grid',
                    'team_id' => $team_id,
                    'from'    => $date,
                    'to'      => $date,
                    'type'    => 'match',
                ],
                RecordLink::dashboardUrl()
            );
        }

        return RecordLink::detailUrlFor( 'activities', $this->activity_id );
    }

    /**
     * The grid is the right target only when it exists on this install
     * and the activity carries the team + date it is filtered by.
     */
    private function useGrid(): bool {
        $activity = self::loadActivity( $this->activity_id );
        return (int) ( $activity['team_id'] ?? 0 ) > 0
            && (string) ( $activity['session_date'] ?? '' ) !== ''
            && FeatureRegistry::isEnabled( 'attendance_grid' );
    }

    /**
     * The machine-readable half of the gap, for a REST payload.
     *
     * @return array{reason:string,message:string,fix_url:string,fix_label:string,fix_view:string}
     */
    public function toArray(): array {
        return [
            'reason'    => $this->reason,
            'message'   => $this->message(),
            'fix_url'   => $this->fixUrl(),
            'fix_label' => $this->fixLabel(),
            'fix_view'  => $this->targetSlug(),
        ];
    }

    private static function prepIdFor( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_match_prep
              WHERE activity_id = %d AND club_id = %d LIMIT 1",
            $activity_id,
            CurrentClub::id()
        ) );
    }

    private static function countAvailability( int $prep_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_prep_availability
              WHERE match_prep_id = %d AND club_id = %d",
            $prep_id,
            CurrentClub::id()
        ) );
    }

    private static function countActual( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND club_id = %d AND record_type = 'actual'",
            $activity_id,
            CurrentClub::id()
        ) );
    }

    /** @return array<string,mixed> */
    private static function loadActivity( int $activity_id ): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, team_id, session_date FROM {$wpdb->prefix}tt_activities
                  WHERE id = %d AND club_id = %d",
                $activity_id,
                CurrentClub::id()
            ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : [];
    }
}
