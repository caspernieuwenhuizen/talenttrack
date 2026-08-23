<?php
namespace TT\Modules\MatchAnalysis\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Modules\Alerts\Definitions\AbstractActivityAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MatchAnalysisMissingAlert (#2724, epic #2704) — a match was played and
 * nobody wrote it up.
 *
 * Which player question does this answer? *What did this player show in
 * this match?* — asked at the last moment anyone can still answer it. An
 * analysis is worth most while the game is fresh; a coach who forgets on
 * Saturday evening has usually lost the detail by Wednesday, and what is
 * lost is every player's record of that match, not just a document.
 *
 * ## The window, and why it has both ends
 *
 * Two days to fourteen.
 *
 *  - **Not before two days.** A coach who intends to write it up on Sunday
 *    morning should not be told on Saturday night that they are late.
 *    Nagging at the whistle is how a feature teaches people to ignore it.
 *  - **Not after fourteen.** By then the memory is gone and the alert is
 *    only guilt. It stops rather than accumulating; the gap belongs in a
 *    report, not on a badge forever.
 *
 * ## What it deliberately does not fire on
 *
 *  - **Tournaments.** They cannot carry an analysis yet — a tournament day
 *    is several games and one analysis row cannot say which. Telling a
 *    coach to do something the product refuses to let them do is worse than
 *    silence.
 *  - **Matches with no attendance recorded at all.** That academy has a
 *    bigger gap and is already getting the attendance alert; two nudges
 *    about one match is how an inbox becomes noise.
 *
 * ## Switchability
 *
 * The definition registers from `MatchAnalysisModule::boot()`, so an
 * academy that switches the module off stops being asked for analyses by
 * construction — there is no separate toggle to keep in step.
 *
 * Badge, not banner: a missing analysis is a prompt, not a problem with the
 * data. It is also `INFO` severity for the same reason, and never ages up —
 * a fortnight-old match does not become more urgent, it becomes less
 * answerable, and the window closing is what expresses that.
 */
final class MatchAnalysisMissingAlert extends AbstractActivityAlert {

    /** Nothing is said for this long after the whistle. */
    private const QUIET_DAYS = 2;

    /** After this, the moment has passed and the alert stops. */
    private const WINDOW_DAYS = 14;

    public function key(): string {
        return 'match_analysis.missing';
    }

    public function module(): string {
        return 'match-analysis';
    }

    public function label(): string {
        return __( 'Match played, no analysis', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A match was played in the last two weeks and has no analysis written. The review is worth most while the game is still fresh.', 'talenttrack' );
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ Surface::BADGE ];
    }

    public function defaultSeverity(): string {
        return Severity::INFO;
    }

    protected function titleFor( object $row ): string {
        $days = $this->daysSince( (string) ( $row->session_date ?? '' ) );
        $name = trim( (string) ( $row->title ?? '' ) );
        if ( $name === '' ) $name = __( 'Untitled match', 'talenttrack' );

        return sprintf(
            /* translators: 1: match name, 2: number of days since it was played */
            _n(
                '%1$s was played %2$d day ago and has no analysis yet.',
                '%1$s was played %2$d days ago and has no analysis yet.',
                $days,
                'talenttrack'
            ),
            $name,
            $days
        );
    }

    /**
     * One query: played matches inside the window, with attendance on file
     * and no analysis row. `NOT EXISTS` rather than a `LEFT JOIN … IS NULL`
     * so the planner can stop at the first analysis row it finds.
     *
     * @return list<object>
     */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $types = "'" . ActivityTypeKey::GAME . "', 'match'";

        $sql = "SELECT a.id, a.title, a.session_date, a.team_id, a.coach_id
                  FROM {$p}tt_activities a
                 WHERE " . $this->baseWhere( 'a' ) . "
                   AND a.activity_type_key IN ( {$types} )
                   AND a.session_date <= DATE_SUB( CURDATE(), INTERVAL " . self::QUIET_DAYS . " DAY )
                   AND a.session_date >= DATE_SUB( CURDATE(), INTERVAL " . self::WINDOW_DAYS . " DAY )
                   AND EXISTS (
                        SELECT 1 FROM {$p}tt_attendance att
                         WHERE att.activity_id = a.id
                           AND att.club_id = a.club_id
                           AND att.record_type = 'actual'
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM {$p}tt_match_analyses ma
                         WHERE ma.activity_id = a.id
                           AND ma.club_id = a.club_id
                   )"
             . $context->applyScope( self::SUBJECT_TYPE, 'a.id' ) . "
                 ORDER BY a.session_date ASC, a.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Point at the analysis, not at the activity: the alert exists to get
     * one written, and a link that lands a coach one click short of the
     * thing being asked for is a link they stop following.
     */
    protected function urlFor( int $activity_id ): string {
        return add_query_arg(
            [ 'tt_view' => 'match-analysis', 'activity_id' => $activity_id ],
            RecordLink::dashboardUrl()
        );
    }
}
