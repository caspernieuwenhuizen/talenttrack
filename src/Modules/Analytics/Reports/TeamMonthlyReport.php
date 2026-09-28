<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Teams\TeamKpisRepository;
use TT\Infrastructure\Teams\TeamRoster;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Measurements\Reports\TestTrendsQuery;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementSessionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Modules\Measurements\Services\TestVerdict;
use TT\Modules\Measurements\Units\DurationFormat;
use TT\Modules\Measurements\Units\UnitContext;

/**
 * TeamMonthlyReport (#3458, epic #3457) — the document a monthly staff meeting
 * runs on, as data.
 *
 * Twenty report tiles each answer one question. This composes them into one
 * squad picture for one team and one window, keyed by block, so the online view
 * (#3459), the PDF exporter (#3460) and the scheduled mailing (#3462) all render
 * the same numbers and none of them computes any (CLAUDE.md §4). A consumer that
 * deletes every view in the plugin still gets a correct report from REST.
 *
 * ## Composition happens here
 *
 * A deselected block is not queried. The inputs several blocks share — the
 * roster, the window's activities, the attendance rows, the minutes rows, the
 * player verdicts — are each loaded at most once per call, and only when a
 * selected block needs them. A coach who wants the KPI strip does not pay for
 * the per-player roster; that is the point of selection, and it is asserted by a
 * query-count test rather than by inspection.
 *
 * ## Windows and deltas
 *
 * `from` / `to` are the contract; period keys resolve to them before a report
 * is composed. Every KPI carries a delta against the preceding window of equal
 * length — the previous calendar month when the window is a calendar month, the
 * immediately preceding N days otherwise. A predecessor with nothing scheduled
 * yields a `null` delta, which surfaces render as "—" and never as 0%.
 *
 * ## Scheduled versus completed
 *
 * Two activity sets, deliberately. "Scheduled" is every live, uncancelled
 * activity dated in the window — what a coach sees on the activities list, and
 * what `letterhead.activity_count` reports. "Completed" is the subset somebody
 * closed. The gap between them is the report's most important finding: a
 * session that came and went and was never marked completed is a coach failure
 * of its own kind, distinct from one that was closed with nobody on the
 * register, and the coverage block names the two separately.
 *
 * ## Denominators
 *
 * Attendance follows `ActivityRegisterProgress`: the planned roster where one
 * was captured, falling back to the team roster. Minutes share is divided by the
 * minutes available in matches whose minutes were actually recorded, so a month
 * with no recorded minutes has no share rather than a share of zero.
 *
 * @phpstan-type AttendanceRow array{player_id:int, first_name:string, last_name:string, team_name:string, activities:int, total:int, present:int, late:int, absent:int, excused:int, injured:int, suspended:int, present_pct:?float, missed:int, flagged:bool, flag_reasons:list<string>}
 * @phpstan-type MinutesRow array{player_id:int, first_name:string, last_name:string, jersey_number:?int, total_minutes:int, matches:int, starts:int, subs_in:int, subs_off:int, by_type:array<string,int>, available_minutes:int}
 * @phpstan-type Window array{from:string,to:string}
 */
final class TeamMonthlyReport {

    /** Journey event types that belong in "what changed". */
    public const CHANGE_EVENT_TYPES = [
        'injury_started', 'injury_ended', 'team_changed', 'age_group_promoted',
        'position_changed', 'pdp_verdict_recorded', 'signed', 'released', 'graduated',
    ];

    /** Attendance below this reads as a concern. */
    public const ATTENDANCE_AMBER_BELOW = 70.0;

    /** Attendance below this reads as a problem. */
    public const ATTENDANCE_RED_BELOW = 60.0;

    private int $team_id = 0;
    private string $from = '';
    private string $to = '';
    private int $viewer_user_id = 0;

    private bool $team_loaded = false;
    private ?object $team_row = null;

    /** @var array<int,object>|null */
    private ?array $players = null;

    /** @var array<string,list<object>> window key => completed activities */
    private array $activities = [];

    /** @var array<string,list<object>> window key => scheduled (uncancelled) activities */
    private array $scheduled = [];

    /** @var array<string,list<AttendanceRow>> window key => rows */
    private array $attendance = [];

    /** @var array<string,list<MinutesRow>> window key => rows */
    private array $minutes = [];

    /** @var array<string,array<int,StatusVerdict>> as-of date => verdicts */
    private array $verdicts = [];

    /**
     * Per-block options (#3514), block key => bag. A block that was given
     * nothing sees `[]` and renders as it always did.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $options = [];

    /**
     * Compose the report.
     *
     * @param list<string> $blocks Selected block keys; empty means all. Unknown
     *        keys throw — a typo must not quietly produce a report missing a
     *        section nobody asked to remove.
     * @param int $viewer_user_id Whose journey visibility applies to "what
     *        changed". 0 — an unattended caller such as the scheduled mailing —
     *        gets the conservative set: public and coaching-staff events only,
     *        never medical or safeguarding ones.
     * @param array<string,array<string,mixed>> $options Per-block options
     *        (#3514), block key => bag. Unlike `$blocks` these never throw: an
     *        option a later version dropped must still open a saved report.
     * @return array{team_id:int, from:string, to:string, previous:Window, blocks:list<string>, data:array<string,array<string,mixed>>}
     * @throws \InvalidArgumentException on unknown block keys or a malformed window.
     */
    public function forTeam( int $team_id, string $from, string $to, array $blocks = [], int $viewer_user_id = 0, array $options = [] ): array {
        $unknown = TeamMonthlyReportBlock::unknown( $blocks );
        if ( $unknown !== [] ) {
            throw new \InvalidArgumentException( 'Unknown block keys: ' . implode( ', ', $unknown ) );
        }
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            throw new \InvalidArgumentException( 'from and to must be Y-m-d dates with from <= to.' );
        }

        $this->team_id        = $team_id;
        $this->from           = $from;
        $this->to             = $to;
        $this->viewer_user_id = $viewer_user_id;
        $this->team_loaded    = false;
        $this->team_row       = null;
        $this->players        = null;
        $this->activities     = [];
        $this->attendance     = [];
        $this->minutes        = [];
        $this->verdicts       = [];
        $this->options        = $options;

        $selected = TeamMonthlyReportBlock::normalise( $blocks );
        $previous = self::previousWindow( $from, $to );

        $data = [];
        foreach ( $selected as $block ) {
            $data[ $block ] = $this->block( $block, $previous );
        }

        return [
            'team_id'  => $team_id,
            'from'     => $from,
            'to'       => $to,
            'previous' => $previous,
            'blocks'   => $selected,
            'data'     => $data,
        ];
    }

    /**
     * The window a delta is taken against: the previous calendar month when
     * `[from, to]` is exactly one calendar month, otherwise the N days
     * immediately before `from`.
     *
     * @return Window
     */
    public static function previousWindow( string $from, string $to ): array {
        $f = strtotime( $from . ' 00:00:00 UTC' );
        $t = strtotime( $to . ' 00:00:00 UTC' );
        if ( $f === false || $t === false ) return [ 'from' => $from, 'to' => $to ];

        $is_calendar_month = gmdate( 'd', $f ) === '01' && gmdate( 'Y-m-t', $f ) === $to;
        if ( $is_calendar_month ) {
            return self::monthBefore( $f );
        }

        $days = (int) round( ( $t - $f ) / DAY_IN_SECONDS ) + 1;
        return [
            'from' => gmdate( 'Y-m-d', $f - $days * DAY_IN_SECONDS ),
            'to'   => gmdate( 'Y-m-d', $f - DAY_IN_SECONDS ),
        ];
    }

    /** The period a monthly report opens on when the caller names none. */
    public const DEFAULT_PERIOD = 'last_month';

    /**
     * Resolve a period key to a window, for callers that take `period=`.
     * Delegates to the shared report vocabulary, which carries `last_month`
     * since #3459. Null for an unknown or empty key.
     *
     * @return Window|null
     */
    public static function periodWindow( string $period, string $today ): ?array {
        return ReportFilters::periodWindow( $period, $today );
    }

    /**
     * #4096 — the line beside a section's title saying what it holds: "4
     * played · 2 W 1 D 1 L", "5 players · most urgent first". Read off the
     * block's own data, so a snapshot frozen months ago says the same thing
     * on screen and on paper, and neither renderer counts anything itself.
     *
     * @param array<string,mixed> $d the block's data, as the layout prints it.
     */
    public static function sectionMeta( string $block, array $d ): string {
        $list = static fn( string $key ): array => is_array( $d[ $key ] ?? null ) ? $d[ $key ] : [];

        switch ( $block ) {
            case TeamMonthlyReportBlock::STATUS:
                $counts = $list( 'counts' );
                $total  = 0;
                foreach ( [ 'green', 'amber', 'red', 'unknown' ] as $c ) $total += (int) ( $counts[ $c ] ?? 0 );
                return sprintf(
                    /* translators: 1: players on track, 2: players in the squad */
                    __( '%1$d of %2$d on track', 'talenttrack' ),
                    (int) ( $counts['green'] ?? 0 ),
                    $total
                );

            case TeamMonthlyReportBlock::ATTENDANCE:
                $avg = $d['team_avg_pct'] ?? null;
                if ( ( $d['level'] ?? '' ) === SectionLevel::SUMMARY ) {
                    return is_int( $avg ) || is_float( $avg )
                        /* translators: %s: team average attendance percentage */
                        ? sprintf( __( 'Team average %s.', 'talenttrack' ), self::metaPct( (float) $avg ) )
                        : __( 'No attendance recorded.', 'talenttrack' );
                }
                return is_int( $avg ) || is_float( $avg )
                    /* translators: %s: team average attendance percentage */
                    ? sprintf( __( 'Team average %s. In shirt-number order.', 'talenttrack' ), self::metaPct( (float) $avg ) )
                    : __( 'No attendance recorded.', 'talenttrack' );

            case TeamMonthlyReportBlock::MINUTES:
                return sprintf(
                    /* translators: 1: matches with minutes recorded, 2: matches played, 3: target percentage */
                    __( 'Minutes recorded for %1$d of %2$d matches played. Target %3$d%%.', 'talenttrack' ),
                    (int) ( $d['matches_recorded'] ?? 0 ),
                    (int) ( $d['matches_played'] ?? 0 ),
                    (int) ( $d['target_pct'] ?? 50 )
                );

            case TeamMonthlyReportBlock::MATCHES:
                if ( $list( 'fixtures' ) === [] ) return __( 'No matches played this period.', 'talenttrack' );
                $record = $list( 'record' );
                return sprintf(
                    /* translators: 1: matches played, 2: won, 3: drawn, 4: lost */
                    __( '%1$d played · %2$d W %3$d D %4$d L', 'talenttrack' ),
                    count( $list( 'fixtures' ) ),
                    (int) ( $record['won'] ?? 0 ),
                    (int) ( $record['drawn'] ?? 0 ),
                    (int) ( $record['lost'] ?? 0 )
                );

            case TeamMonthlyReportBlock::ATTENTION:
                $n = count( $list( 'items' ) ) + (int) ( $d['omitted'] ?? 0 );
                if ( $n === 0 ) return __( 'Nobody is flagged this period.', 'talenttrack' );
                /* translators: %d: players who need a conversation */
                return sprintf( _n( '%d player · most urgent first', '%d players · most urgent first', $n, 'talenttrack' ), $n );

            case TeamMonthlyReportBlock::CHANGES:
                $events = count( $list( 'events' ) );
                $open   = (int) ( $d['open_injuries'] ?? 0 );
                return sprintf(
                    /* translators: %d: entries under "what changed" */
                    _nx( '%d change', '%d changes', $events, 'monthly report: entries under what changed', 'talenttrack' ),
                    $events
                ) . ' · ' . sprintf(
                    /* translators: %d: players with an open injury */
                    _n( '%d player currently injured.', '%d players currently injured.', $open, 'talenttrack' ),
                    $open
                );

            case TeamMonthlyReportBlock::TESTS:
                $rounds = count( $list( 'rounds' ) ) + count( $list( 'omitted' ) );
                if ( $rounds === 0 ) return __( 'No tests taken this period.', 'talenttrack' );
                $show = TestsBlockOptions::storedShow( $d['show'] ?? null );
                /* translators: %d: number of tests */
                return sprintf( _n( '%d test', '%d tests', $rounds, 'talenttrack' ), $rounds )
                    . ' · ' . SectionLevel::label( TestsBlockOptions::levelOfShow( $show ) );

            case TeamMonthlyReportBlock::EVALUATIONS:
                $n = (int) ( $d['evaluations'] ?? 0 );
                if ( $n === 0 ) return __( 'No evaluations this period.', 'talenttrack' );
                $meta = sprintf(
                    /* translators: %d: number of evaluations */
                    _n( '%d evaluation', '%d evaluations', $n, 'talenttrack' ),
                    $n
                ) . ' · ' . sprintf(
                    /* translators: 1: players evaluated, 2: players in the squad */
                    __( '%1$d of %2$d players', 'talenttrack' ),
                    (int) ( $d['evaluated'] ?? 0 ),
                    (int) ( $d['squad'] ?? 0 )
                );
                // #4134 — a section that counts some types says which.
                $types = array_map( 'strval', $list( 'types_counted' ) );
                return $meta . ' · ' . ( $types !== [] ? implode( ', ', $types ) : __( 'all types', 'talenttrack' ) );

            case TeamMonthlyReportBlock::ROSTER:
                $n = count( $list( 'rows' ) );
                /* translators: %d: players in the squad */
                return sprintf( _n( '%d player', '%d players', $n, 'talenttrack' ), $n );

            case TeamMonthlyReportBlock::NOTES:
                return __( 'Space to write on', 'talenttrack' );

            case TeamMonthlyReportBlock::QUALITY:
                $gaps = count( $list( 'activities_without_register' ) ) > 0 ? 1 : 0;
                foreach ( [ 'activities_never_closed', 'matches_without_opponent', 'players_not_evaluated', 'players_with_incomplete_status' ] as $key ) {
                    if ( $list( $key ) !== [] ) $gaps++;
                }
                if ( (int) ( $d['matches_without_minutes'] ?? 0 ) > 0 ) $gaps++;
                if ( $gaps === 0 ) return _x( 'Nothing missing', 'monthly report data quality, section header', 'talenttrack' );
                /* translators: %d: kinds of missing data to fix */
                return sprintf( _n( '%d thing to fix', '%d things to fix', $gaps, 'talenttrack' ), $gaps );
        }
        return '';
    }

    /**
     * #4093 — a test round's target as its header prints it: "Target O14:
     * ≤ 12:30", or "no target" on a test without a better or worse. Empty
     * when the round has no target for the age group, which also covers a
     * snapshot frozen before rounds carried one.
     *
     * @param array<string,mixed> $round
     */
    public static function testTargetLabel( array $round ): string {
        if ( ! empty( $round['target_absent'] ) ) {
            return _x( 'no target', 'a test with no better or worse value', 'talenttrack' );
        }
        $target = is_string( $round['target'] ?? null ) ? $round['target'] : '';
        if ( $target === '' ) return '';

        $age_group = is_string( $round['target_age_group'] ?? null ) ? $round['target_age_group'] : '';
        return $age_group !== ''
            /* translators: 1: age group, e.g. "O14", 2: the target, e.g. "≤ 12:30" */
            ? sprintf( _x( 'Target %1$s: %2$s', 'a test target for an age group', 'talenttrack' ), $age_group, $target )
            /* translators: %s: the target, e.g. "≤ 12:30" */
            : sprintf( _x( 'Target: %s', 'a test target', 'talenttrack' ), $target );
    }

    /**
     * #4093 — does any round in this tests block have no target because the
     * test has no better or worse? Surfaces print the explanation once.
     *
     * @param array<string,mixed> $tests
     */
    public static function testsHaveTargetlessRound( array $tests ): bool {
        foreach ( is_array( $tests['rounds'] ?? null ) ? $tests['rounds'] : [] as $round ) {
            if ( is_array( $round ) && ! empty( $round['target_absent'] ) ) return true;
        }
        return false;
    }

    /** A percentage as the report prints one: a decimal only when there is one. */
    private static function metaPct( float $v ): string {
        return number_format_i18n( $v, floor( $v ) != $v ? 1 : 0 ) . '%';
    }

    /**
     * The whole calendar month before the one `$ts` falls in. Integer month
     * arithmetic rather than a "-1 month" relative string, which lands on the
     * wrong month from the 29th to the 31st.
     *
     * @return Window
     */
    private static function monthBefore( int $ts ): array {
        $year  = (int) gmdate( 'Y', $ts );
        $month = (int) gmdate( 'n', $ts ) - 1;
        if ( $month < 1 ) {
            $month = 12;
            $year--;
        }
        $first = (int) gmmktime( 0, 0, 0, $month, 1, $year );
        return [ 'from' => gmdate( 'Y-m-01', $first ), 'to' => gmdate( 'Y-m-t', $first ) ];
    }

    /**
     * @param Window $previous
     * @return array<string,mixed>
     */
    private function block( string $block, array $previous ): array {
        switch ( $block ) {
            case TeamMonthlyReportBlock::LETTERHEAD: return $this->letterhead();
            case TeamMonthlyReportBlock::COVERAGE:   return $this->coverage();
            case TeamMonthlyReportBlock::KPI:        return $this->kpi( $previous );
            case TeamMonthlyReportBlock::STATUS:     return $this->status( $previous );
            case TeamMonthlyReportBlock::EVALUATIONS:
                return TeamMonthlyEvaluations::compose( $this->team_id, $this->from, $this->to, $previous, $this->players(), $this->optionsFor( TeamMonthlyReportBlock::EVALUATIONS ) );
            case TeamMonthlyReportBlock::ATTENDANCE: return $this->attendanceBlock( LevelBlockOptions::level( $this->optionsFor( TeamMonthlyReportBlock::ATTENDANCE ) ) );
            case TeamMonthlyReportBlock::MINUTES:    return $this->minutesBlock( LevelBlockOptions::level( $this->optionsFor( TeamMonthlyReportBlock::MINUTES ) ) );
            case TeamMonthlyReportBlock::MATCHES:    return $this->matches( $this->optionsFor( TeamMonthlyReportBlock::MATCHES ) );
            case TeamMonthlyReportBlock::ATTENTION:  return $this->attention();
            case TeamMonthlyReportBlock::CHANGES:    return $this->changes();
            case TeamMonthlyReportBlock::TESTS:      return $this->tests( $this->optionsFor( TeamMonthlyReportBlock::TESTS ) );
            case TeamMonthlyReportBlock::ROSTER:     return $this->roster();
            case TeamMonthlyReportBlock::NOTES:      return [ 'lines' => 6 ];
            case TeamMonthlyReportBlock::QUALITY:    return $this->quality();
        }
        return [];
    }

    /**
     * One block's options, normalised by whoever owns them. A block given
     * nothing sees `[]` and renders as it always did.
     *
     * @return array<string,mixed>
     */
    private function optionsFor( string $block ): array {
        return TeamMonthlyReportBlockOptions::normalise( $block, $this->options[ $block ] ?? [] );
    }

    /**
     * The tests this team actually has a session for in a window, for the
     * composition panel's picker (#3515).
     *
     * Offering a test the squad did not take is offering an empty section, so
     * the picker lists only what has readings. Static because the panel asks
     * before a report has been composed.
     *
     * @return list<array{definition_id:int, name:string, date:string}>
     */
    public static function testableDefinitions( int $team_id, string $from, string $to ): array {
        if ( $team_id <= 0 || ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) return [];

        $seen = [];
        $out  = [];
        foreach ( ( new MeasurementSessionsRepository() )->listForTeam( $team_id ) as $s ) {
            $def_id  = (int) ( $s->definition_id ?? 0 );
            $planned = (string) ( $s->planned_date ?? '' );
            if ( $def_id <= 0 || isset( $seen[ $def_id ] ) ) continue;
            if ( $planned === '' || $planned < $from || $planned > $to ) continue;
            $seen[ $def_id ] = true;

            $out[] = [
                'definition_id' => $def_id,
                'name'          => (string) ( $s->definition_name ?? '' ),
                'date'          => $planned,
            ];
        }

        usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

        return $out;
    }

    /* ---------------------------------------------------------------
     * Blocks
     * ------------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function letterhead(): array {
        $team = $this->team();
        return [
            'team_id'        => $this->team_id,
            'team_name'      => $team !== null ? (string) ( $team->name ?? '' ) : '',
            'age_group'      => $team !== null ? (string) ( $team->age_group ?? '' ) : '',
            'head_coach'     => ( new EvalCoverageService() )->headCoachNameForTeam( $this->team_id ),
            'from'           => $this->from,
            'to'             => $this->to,
            'squad_size'     => count( $this->players() ),
            // Everything on the coach's activities list for the window, not
            // only what was closed (#3746). A month with eight sessions and
            // one closed one used to print "1 activity".
            'activity_count' => count( $this->scheduledIn( $this->from, $this->to ) ),
            'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * The confidence statement, over the window's scheduled activities.
     *
     * Three outcomes, counted separately because they are three different
     * things to fix (#3746):
     *
     *   - completed with a register — the evidence every other block divides by;
     *   - completed without one — it silently shrinks every denominator, which
     *     is why this block exists at all;
     *   - past its date and never marked completed — nobody closed the session,
     *     so there is no register to be missing yet. Reporting that as "no
     *     register" would send a coach to the wrong screen.
     *
     * A future-dated activity in the window is not yet due and counts as
     * neither gap.
     *
     * @return array{scheduled:int, completed:int, with_register:int, missing:list<array{activity_id:int,title:string,date:string}>, never_closed:list<array{activity_id:int,title:string,date:string}>, state:string}
     */
    private function coverage(): array {
        $scheduled = $this->scheduledIn( $this->from, $this->to );
        $today     = (string) current_time( 'Y-m-d' );

        $closed       = [];
        $never_closed = [];
        foreach ( $scheduled as $a ) {
            if ( strtolower( trim( (string) ( $a->activity_status_key ?? '' ) ) ) === 'completed' ) {
                $closed[] = $a;
                continue;
            }
            $date = substr( (string) ( $a->session_date ?? '' ), 0, 10 );
            if ( $date !== '' && $date <= $today ) $never_closed[] = self::activityRef( $a );
        }

        ActivityRegisterProgress::prime( $closed );

        $with    = 0;
        $missing = [];
        foreach ( $closed as $a ) {
            $progress = ActivityRegisterProgress::forRow( $a );
            if ( $progress === null ) continue;
            if ( $progress['attendance']['recorded'] > 0 ) {
                $with++;
                continue;
            }
            $missing[] = self::activityRef( $a );
        }
        $counted = $with + count( $missing );

        return [
            'scheduled'     => count( $scheduled ),
            'completed'     => $counted,
            'with_register' => $with,
            'missing'       => $missing,
            'never_closed'  => $never_closed,
            // "empty" means nothing was scheduled at all. A window full of
            // sessions nobody closed is the opposite of nothing to report.
            // "complete" is positive evidence, not merely the absence of a warning.
            'state'         => $scheduled === [] ? 'empty' : ( $missing === [] && $never_closed === [] ? 'complete' : 'partial' ),
        ];
    }

    /**
     * One activity as the coverage and quality blocks name it.
     *
     * @return array{activity_id:int,title:string,date:string}
     */
    private static function activityRef( object $a ): array {
        return [
            'activity_id' => (int) ( $a->id ?? 0 ),
            'title'       => (string) ( $a->title ?? '' ),
            'date'        => (string) ( $a->session_date ?? '' ),
        ];
    }

    /**
     * @param Window $previous
     * @return array<string,mixed>
     */
    private function kpi( array $previous ): array {
        $kpis     = new TeamKpisRepository();
        $cover    = new EvalCoverageService();
        // Both windows counted the same way, or the delta compares a scheduled
        // count against a completed one (#3746).
        $prev_n   = count( $this->scheduledIn( $previous['from'], $previous['to'] ) );
        $has_prev = $prev_n > 0;

        // #4068 — the attendance section's own squad average, so the tile and
        // the table under it cannot disagree. Both windows the same way.
        $att_now  = self::squadAttendancePct( $this->attendanceRowsIn( $this->from, $this->to ) );
        $att_prev = $has_prev ? self::squadAttendancePct( $this->attendanceRowsIn( $previous['from'], $previous['to'] ) ) : null;

        $min_now  = self::medianShare( $this->minutesIn( $this->from, $this->to ) );
        $min_prev = $has_prev ? self::medianShare( $this->minutesIn( $previous['from'], $previous['to'] ) ) : null;

        $eval_now      = $cover->coverageBetween( $this->team_id, $this->from, $this->to );
        $eval_pct      = self::pct( $eval_now['evaluated'], $eval_now['squad'] );
        $eval_prev_pct = null;
        if ( $has_prev ) {
            $eval_prev     = $cover->coverageBetween( $this->team_id, $previous['from'], $previous['to'] );
            $eval_prev_pct = self::pct( $eval_prev['evaluated'], $eval_prev['squad'] );
        }

        $rating_now  = $kpis->avgSquadRatingBetween( $this->team_id, $this->from, $this->to );
        $rating_prev = $has_prev ? $kpis->avgSquadRatingBetween( $this->team_id, $previous['from'], $previous['to'] ) : null;

        $attention_now  = self::attentionCount( $this->verdictsAt( $this->to ) );
        $attention_prev = $has_prev ? self::attentionCount( $this->verdictsAt( $previous['to'] ) ) : null;

        return [
            'activities'               => self::measure( count( $this->scheduledIn( $this->from, $this->to ) ), $has_prev ? $prev_n : null ),
            'attendance_pct'           => self::measure( $att_now, $att_prev ),
            'minutes_share_median_pct' => self::measure( $min_now, $min_prev ),
            'evaluated'                => [
                'value' => $eval_now['evaluated'],
                'of'    => $eval_now['squad'],
                'pct'   => $eval_pct,
                'delta' => self::delta( $eval_pct, $eval_prev_pct ),
            ],
            'squad_rating'             => self::measure(
                $rating_now !== null ? round( $rating_now, 1 ) : null,
                $rating_prev !== null ? round( $rating_prev, 1 ) : null
            ),
            'needs_attention'          => self::measure( $attention_now, $attention_prev ),
        ];
    }

    /**
     * Squad status roll-up: green / amber / red / unknown counts now, and the
     * split as it stood at the end of the previous window.
     *
     * @param Window $previous
     * @return array<string,mixed>
     */
    private function status( array $previous ): array {
        $has_prev = count( $this->activitiesIn( $previous['from'], $previous['to'] ) ) > 0;
        return [
            'counts'   => self::statusCounts( $this->verdictsAt( $this->to ) ),
            'previous' => $has_prev ? self::statusCounts( $this->verdictsAt( $previous['to'] ) ) : null,
        ];
    }

    /**
     * #4133 — the rows are there at both levels; `level` says which the
     * section prints, and the summary's figures are carried beside them:
     * who is below the amber line, lowest first, and the absences by kind.
     *
     * @return array<string,mixed>
     */
    private function attendanceBlock( string $level = SectionLevel::DETAILS ): array {
        $rows = [];
        foreach ( $this->attendanceRows() as $r ) {
            $rows[] = [
                'player_id'   => $r['player_id'],
                'name'        => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'activities'  => $r['activities'],
                'present'     => $r['present'],
                'late'        => $r['late'],
                'absent'      => $r['absent'],
                'excused'     => $r['excused'],
                'injured'     => $r['injured'],
                'suspended'   => $r['suspended'],
                'present_pct' => $r['present_pct'],
                'band'        => self::attendanceBand( $r['present_pct'] ),
                'flagged'     => $r['flagged'],
            ];
        }
        // #3518 — shirt order. The rows come from the shared attendance
        // ranking query, which other surfaces order for their own reasons, so
        // the report sorts its own copy rather than changing that query.
        $rows = PlayerOrder::sort( $rows, PlayerOrder::jerseys( $this->players() ) );

        $below    = [];
        $absences = [ 'absent' => 0, 'excused' => 0, 'injured' => 0, 'suspended' => 0 ];
        foreach ( $rows as $r ) {
            foreach ( array_keys( $absences ) as $kind ) $absences[ $kind ] += (int) $r[ $kind ];
            if ( $r['present_pct'] !== null && $r['present_pct'] < self::ATTENDANCE_AMBER_BELOW ) {
                $below[] = [ 'player_id' => $r['player_id'], 'name' => $r['name'], 'pct' => $r['present_pct'] ];
            }
        }
        usort( $below, static fn( array $a, array $b ): int => $a['pct'] <=> $b['pct'] ?: strcmp( $a['name'], $b['name'] ) );

        return [
            'level'        => $level,
            'team_avg_pct' => self::squadAttendancePct( $this->attendanceRows() ),
            'amber_below'  => self::ATTENDANCE_AMBER_BELOW,
            'red_below'    => self::ATTENDANCE_RED_BELOW,
            'below'        => $below,
            'absences'     => $absences,
            'rows'         => $rows,
        ];
    }

    /**
     * #4133 — like attendance: the rows at both levels, `level` saying which
     * the section prints, and the summary's figure beside them — who is under
     * the academy's target, lowest first.
     *
     * @return array<string,mixed>
     */
    private function minutesBlock( string $level = SectionLevel::DETAILS ): array {
        $counts = ( new MinutesQuery() )->matchCountsForTeam( $this->team_id, $this->from, $this->to );
        $source = $this->minutesIn( $this->from, $this->to );

        $rows = [];
        foreach ( $source as $r ) {
            $rows[] = [
                'player_id'         => $r['player_id'],
                'name'              => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'total_minutes'     => $r['total_minutes'],
                'available_minutes' => $r['available_minutes'],
                'matches'           => $r['matches'],
                'starts'            => $r['starts'],
                'share_pct'         => self::share( $r['total_minutes'], $r['available_minutes'] ),
            ];
        }
        usort( $rows, static fn( array $a, array $b ): int => ( $b['share_pct'] ?? -1.0 ) <=> ( $a['share_pct'] ?? -1.0 ) );

        // #3589 — the academy's one target, the one the minutes-share report
        // reads, not a second number of this report's own.
        $target = MinutesShareQuery::targetPct();
        $under  = [];
        foreach ( array_reverse( $rows ) as $r ) {
            if ( $r['share_pct'] !== null && $r['share_pct'] < $target ) {
                $under[] = [ 'player_id' => $r['player_id'], 'name' => $r['name'], 'pct' => $r['share_pct'] ];
            }
        }

        return [
            'level'            => $level,
            'matches_recorded' => $counts['recorded'],
            'matches_played'   => $counts['played'],
            'target_pct'       => $target,
            'median_share_pct' => self::medianShare( $source ),
            'under'            => $under,
            'rows'             => $rows,
        ];
    }

    /**
     * The agenda: every amber or red player, most urgent first, with what the
     * data says about them. The block the meeting is actually for.
     *
     * @return array<string,mixed>
     */
    private function attention(): array {
        $attendance = [];
        foreach ( $this->attendanceRows() as $r ) {
            $attendance[ $r['player_id'] ] = $r;
        }
        $players = $this->players();

        $items = [];
        foreach ( $this->verdictsAt( $this->to ) as $pid => $v ) {
            if ( $v->color !== StatusVerdict::COLOR_AMBER && $v->color !== StatusVerdict::COLOR_RED ) continue;
            $att     = $attendance[ $pid ] ?? null;
            $items[] = [
                'player_id'      => $pid,
                'name'           => isset( $players[ $pid ] ) ? self::name( $players[ $pid ] ) : '',
                'color'          => $v->color,
                'score'          => $v->score,
                'reasons'        => $v->reasons,
                'missing_inputs' => $v->missing_inputs,
                'attendance_pct' => $att !== null ? $att['present_pct'] : null,
                'missed'         => $att !== null ? $att['missed'] : null,
            ];
        }
        usort( $items, static function ( array $a, array $b ): int {
            $ra = $a['color'] === StatusVerdict::COLOR_RED ? 0 : 1;
            $rb = $b['color'] === StatusVerdict::COLOR_RED ? 0 : 1;
            if ( $ra !== $rb ) return $ra <=> $rb;
            return ( $a['score'] ?? 0.0 ) <=> ( $b['score'] ?? 0.0 );
        } );

        return [ 'items' => $items ];
    }

    /** @return array<string,mixed> */
    private function changes(): array {
        $visibilities = $this->viewer_user_id > 0
            ? PlayerEventsRepository::visibilitiesForUser( $this->viewer_user_id )
            : [ 'public', 'coaching_staff' ];

        $events = ( new PlayerEventsRepository() )->forTeamBetween(
            $this->team_id, $this->from, $this->to, self::CHANGE_EVENT_TYPES, $visibilities
        );

        $items = [];
        foreach ( $events as $e ) {
            $items[] = [
                'player_id'  => (int) ( $e->player_id ?? 0 ),
                'name'       => self::name( $e ),
                'event_type' => (string) ( $e->event_type ?? '' ),
                'date'       => substr( (string) ( $e->event_date ?? '' ), 0, 10 ),
                'summary'    => (string) ( $e->summary ?? '' ),
            ];
        }

        return [
            'events'        => $items,
            'open_injuries' => count( ( new InjuryRepository() )->listForTeams( [ $this->team_id ], [ 'status' => 'open' ] ) ),
        ];
    }

    /**
     * Results and match statistics (#3516).
     *
     * The report said a great deal about development and nothing about
     * results, so the score got read off somebody's phone. The data was
     * already recorded; nothing here writes anything new.
     *
     * Three parts, each switchable — see `MatchesBlockOptions`. There is no
     * separate results list: the per-match rows carry date, opponent,
     * home/away and score, so a second table would repeat them.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function matches( array $options = [] ): array {
        $activities = ( new ActivitiesRepository() )->matchesInWindowForTeam( $this->team_id, $this->from, $this->to );

        $out = [
            // Tournaments are multi-game days (#2686): one score line cannot
            // describe one, so they are left out of everything below. The
            // count is carried so the section can *say* so — a record that
            // quietly disagrees with what the coach remembers is worse than
            // one that explains itself.
            'tournaments_excluded' => ( new ActivitiesRepository() )->tournamentCountInWindow( $this->team_id, $this->from, $this->to ),
            // #4133 — the Summary / Details choice; the parts below follow it.
            'level'                => MatchesBlockOptions::level( $options ),
            'shows'                => [
                'record'  => MatchesBlockOptions::shows( $options, MatchesBlockOptions::RECORD ),
                'scorers' => MatchesBlockOptions::shows( $options, MatchesBlockOptions::SCORERS ),
                'squads'  => MatchesBlockOptions::shows( $options, MatchesBlockOptions::SQUADS ),
            ],
            'record'   => self::matchRecord( $activities ),
            'fixtures'      => [],
            'scorers'       => [],
            'scorer_totals' => null,
        ];

        $jerseys = PlayerOrder::jerseys( $this->players() );
        $names   = [];
        foreach ( $this->players() as $player ) {
            $names[ (int) ( $player->id ?? 0 ) ] = trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) );
        }

        if ( $out['shows']['scorers'] ) {
            $contributions = ( new GoalContributionQuery() )->forTeam( $this->team_id, [ 'from' => $this->from, 'to' => $this->to ] );
            $rows = [];
            foreach ( $contributions as $player_id => $c ) {
                $player_id = (int) $player_id;
                $goals     = (int) $c['goals'];
                $assists   = (int) $c['assists'];
                if ( $goals === 0 && $assists === 0 ) continue;

                $rows[] = [
                    'player_id' => $player_id,
                    'name'      => $names[ $player_id ] ?? '',
                    'goals'     => $goals,
                    'assists'   => $assists,
                ];
            }
            // #4079 — the record counts only scored, non-tournament fixtures,
            // so the attributed count is taken over exactly those. The table
            // above keeps tournament goals: it is a leaderboard.
            $scored = [];
            foreach ( $activities as $a ) {
                if ( $a['outcome'] !== '' ) $scored[] = $a['activity_id'];
            }
            $out['scorers']       = self::rankScorers( PlayerOrder::sort( $rows, $jerseys ) );
            $out['scorer_totals'] = [
                'goals'            => array_sum( array_column( $rows, 'goals' ) ),
                'assists'          => array_sum( array_column( $rows, 'assists' ) ),
                // Reconciled against the record, like the minutes grid footer:
                // "7 of 8 goals attributed" says a scorer was not entered.
                'attributed_goals' => ( new GoalContributionQuery() )->attributedGoalsIn( $this->team_id, $scored ),
                'goals_for'        => $out['record']['goals_for'],
            ];
        }

        foreach ( $activities as $a ) {
            $fixture = $a + [ 'squad' => [] ];

            if ( $out['shows']['squads'] ) {
                $minutes = MinutesQuery::squadForActivity( $a['activity_id'] );
                $squad   = [];
                foreach ( $minutes as $player_id => $played ) {
                    $squad[] = [
                        'player_id' => (int) $player_id,
                        'name'      => $names[ (int) $player_id ] ?? '',
                        'minutes'   => (int) $played,
                    ];
                }
                $fixture['squad'] = PlayerOrder::sort( $squad, $jerseys );
            }

            $out['fixtures'][] = $fixture;
        }

        return $out;
    }

    /**
     * Scorers ranked by goals, then assists, then shirt order (#4069) — the
     * order a coach reads a scorers table in, on the page, the PDF and REST
     * alike. It replaces the shirt order #3518 gave this list. The rank is
     * shared on a tie and left out for a player without a goal, who is on
     * the list for assists only.
     *
     * @param list<array<string,mixed>> $rows in shirt order
     * @return list<array<string,mixed>>
     */
    private static function rankScorers( array $rows ): array {
        $sorted = self::stableSort(
            $rows,
            static fn( array $a, array $b ): int => [ (int) $b['goals'], (int) $b['assists'] ] <=> [ (int) $a['goals'], (int) $a['assists'] ]
        );

        $out  = [];
        $rank = 0;
        $last = null;
        foreach ( $sorted as $i => $row ) {
            $key = [ (int) $row['goals'], (int) $row['assists'] ];
            if ( $key !== $last ) $rank = $i + 1;
            $last = $key;

            $row['rank'] = (int) $row['goals'] > 0 ? $rank : null;
            $out[]       = $row;
        }
        return $out;
    }

    /**
     * Played, won, drawn, lost and goals, over the fixtures given.
     *
     * **A match with no score recorded counts as played and nothing else.** It
     * happens constantly — the match was played, nobody typed the result — and
     * treating it as a goalless draw would make the record quietly wrong,
     * which is worse than an obvious gap. It is counted separately so the
     * section can show the gap.
     *
     * @param list<array{outcome:string, team_score:int|null, opp_score:int|null}> $activities
     * @return array<string,int>
     */
    private static function matchRecord( array $activities ): array {
        $record = [
            'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
            'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0,
            'without_score' => 0,
        ];

        foreach ( $activities as $a ) {
            $record['played']++;
            if ( $a['outcome'] === '' ) {
                $record['without_score']++;
                continue;
            }
            if ( $a['outcome'] === 'W' ) $record['won']++;
            if ( $a['outcome'] === 'D' ) $record['drawn']++;
            if ( $a['outcome'] === 'L' ) $record['lost']++;
            $record['goals_for']     += (int) $a['team_score'];
            $record['goals_against'] += (int) $a['opp_score'];
        }
        $record['goal_difference'] = $record['goals_for'] - $record['goals_against'];

        return $record;
    }

    /**
     * Test rounds the team held in the window, who was tested, and who moved
     * in each direction since their previous reading.
     *
     * #3515 — the section can be told which tests to show and how much of each.
     * With no options it reports every test held in the window as a summary,
     * which is what it did before the options existed.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function tests( array $options = [] ): array {
        $wanted = TestsBlockOptions::definitionIds( $options );
        $show   = TestsBlockOptions::show( $options );

        $held = $this->testRoundsInWindow( $wanted, $show );

        if ( $wanted === [] ) {
            return [ 'rounds' => array_values( $held ), 'show' => $show, 'level' => TestsBlockOptions::level( $options ) ];
        }

        // A named test the squad did not take this window still gets a section,
        // saying so. The whole point of a saved composition is that next month
        // is one click, and a section that silently vanished would read as an
        // oversight rather than as "no readings".
        $out = [];
        foreach ( $wanted as $def_id ) {
            if ( isset( $held[ $def_id ] ) ) {
                $out[] = $held[ $def_id ];
                continue;
            }
            $empty = $this->emptyTestRound( $def_id );
            // A definition deleted since the composition was saved resolves to
            // nothing. Drop it: a report saved in September must open in March.
            if ( $empty !== null ) $out[] = $empty;
        }

        return [ 'rounds' => $out, 'show' => $show, 'level' => TestsBlockOptions::level( $options ) ];
    }

    /**
     * Test rounds actually held in the window, keyed by definition id.
     *
     * @param list<int> $wanted Empty means every test held; otherwise only
     *        these are queried, so picking one test does not cost a trend
     *        query per test the squad happened to take.
     * @return array<int,array<string,mixed>>
     */
    private function testRoundsInWindow( array $wanted, string $show ): array {
        $trends    = new TestTrendsQuery();
        $targets   = new MeasurementTargetsRepository();
        $squad     = count( $this->players() );
        $jerseys   = PlayerOrder::jerseys( $this->players() );
        $team      = $this->team();
        $age_group = $team !== null ? (string) ( $team->age_group ?? '' ) : '';

        $out = [];
        foreach ( ( new MeasurementSessionsRepository() )->listForTeam( $this->team_id ) as $s ) {
            $def_id  = (int) ( $s->definition_id ?? 0 );
            $planned = (string) ( $s->planned_date ?? '' );
            if ( $def_id <= 0 || isset( $out[ $def_id ] ) ) continue;
            if ( $planned === '' || $planned < $this->from || $planned > $this->to ) continue;
            if ( $wanted !== [] && ! in_array( $def_id, $wanted, true ) ) continue;

            $trend = $trends->forDefinition( $def_id, [ 'team_id' => $this->team_id, 'date_to' => $this->to ] );

            // #4063 — the trend values are in the test's entry unit; the unit
            // context says how a person reads them (mm:ss for a duration).
            $units         = UnitContext::forDefinition( ( new MeasurementDefinitionsRepository() )->find( $def_id ) );
            $definition    = $trend['definition'];
            $raw_direction = is_array( $definition ) ? (string) ( $definition['direction'] ?? '' ) : '';
            $direction     = $trend['has_direction'] ? $raw_direction : '';

            $in_window = [];
            foreach ( $trend['dates'] as $d ) {
                if ( $d >= $this->from && $d <= $this->to ) $in_window[] = $d;
            }
            if ( $in_window === [] ) continue;
            $date = $in_window[ count( $in_window ) - 1 ];

            // The round before this one, the squad's and each player's
            // comparison point (#4069). The same pair the step is taken over.
            $dates     = array_values( $trend['dates'] );
            $at        = array_search( $date, $dates, true );
            $prev_date = is_int( $at ) && $at > 0 ? $dates[ $at - 1 ] : null;
            $target    = $age_group !== '' ? $targets->forDefinitionAndAge( $def_id, $age_group ) : null;

            // #4093 — the target and each reading's standing, worded by the
            // helper the player profile's register uses, so a reading reads
            // the same on the report as on the player's own page.
            $value_type  = is_array( $definition ) ? (string) ( $definition['value_type'] ?? '' ) : '';
            $target_cell = TestVerdict::target( $value_type, $raw_direction, $target, $units );

            $tested   = 0;
            $improved = [];
            $declined = [];
            $all      = [];
            foreach ( $trend['players'] as $p ) {
                $values = $p['values'] ?? null;
                if ( ! is_array( $values ) || ! array_key_exists( $date, $values ) ) continue;
                $tested++;

                $steps = $p['steps'] ?? null;
                $step  = is_array( $steps ) ? ( $steps[ $date ] ?? null ) : null;

                $entry = [
                    'player_id' => (int) ( $p['player_id'] ?? 0 ),
                    'name'      => (string) ( $p['name'] ?? '' ),
                    'delta'     => is_array( $step ) ? (float) ( $step['delta'] ?? 0 ) : 0.0,
                ];

                // A player tested for the first time has a reading but no
                // step: "nothing to compare with", which is not the same as
                // no change.
                $value    = (float) $values[ $date ];
                $previous = $prev_date !== null && isset( $values[ $prev_date ] ) ? (float) $values[ $prev_date ] : null;
                $flag     = $targets->flagFor( $units->toBase( $value ), $target, $raw_direction !== '' ? $raw_direction : 'neutral' );
                $verdict  = TestVerdict::verdict( $value_type, $raw_direction, $flag );
                $all[]    = $entry + [
                    'value'            => $value,
                    'value_display'    => self::testValueDisplay( $units, $value ),
                    'previous'         => $previous,
                    'previous_display' => $previous !== null ? self::testValueDisplay( $units, $previous ) : '—',
                    'delta_display'    => is_array( $step ) ? self::testDeltaDisplay( $units, $entry['delta'] ) : '—',
                    'trend'            => is_array( $step ) ? (string) ( $step['trend'] ?? '' ) : '',
                    'first'            => ! is_array( $step ),
                    // #4069 — the player's best reading ever, and where it
                    // sits against the age group's target band. Bands hold
                    // canonical values, the trend the entry unit.
                    'pb'               => self::isPersonalBest( $values, $date, $direction ),
                    'flag'             => $flag,
                    'verdict_label'    => $verdict['label'],
                    'verdict_tone'     => $verdict['tone'],
                ];

                if ( ! is_array( $step ) ) continue;
                $trend_key = (string) ( $step['trend'] ?? '' );
                if ( $trend_key === 'up' )   $improved[] = $entry;
                if ( $trend_key === 'down' ) $declined[] = $entry;
            }

            $average = isset( $trend['average'][ $date ] ) ? (float) $trend['average'][ $date ] : null;
            // #4063 — a result table reads best to worst on a test with a
            // direction, shirt order otherwise.
            $ranked = self::withSquadComparison(
                self::rankReadings( PlayerOrder::sort( $all, $jerseys ), $direction ),
                $units,
                $direction,
                $average
            );

            $out[ $def_id ] = [
                'definition_id' => $def_id,
                'name'          => is_array( $definition ) ? (string) ( $definition['name'] ?? '' ) : (string) ( $s->definition_name ?? '' ),
                'unit'          => is_array( $definition ) ? (string) ( $definition['unit'] ?? '' ) : '',
                'unit_label'    => self::testUnitLabel( $units ),
                'is_duration'   => $units->isDuration(),
                'direction'     => $direction,
                'date'          => $date,
                'previous_date' => $prev_date,
                'tested'        => $tested,
                'squad'         => $squad,
                // #3518 — these are player lists, so they read in shirt order
                // like every other player list in the report.
                'improved'      => PlayerOrder::sort( $improved, $jerseys ),
                'declined'      => PlayerOrder::sort( $declined, $jerseys ),
                // #4069 — the round at a glance, for the stat strip.
                'average'       => self::testAverage( $units, $trend['average'], $date, $prev_date, $raw_direction, $trend['has_direction'] ),
                'best'          => self::testExtreme( $ranked, $direction, true ),
                'worst'         => self::testExtreme( $ranked, $direction, false ),
                'moves'         => self::testMoves( $ranked ),
                'bands'         => self::testBands( $ranked, $target !== null ? $age_group : '', $tested ),
                // #4093 — the target as printed, `≤ 12:30`, or null when the
                // age group has no band; `target_absent` when the test has no
                // better or worse and so cannot have one.
                'target'           => $target_cell['text'] !== '' && ! $target_cell['absent'] ? $target_cell['text'] : null,
                'target_age_group' => $age_group,
                'target_absent'    => $target_cell['absent'],
                'history'       => self::testHistory( $units, $trend['average'], $dates, is_int( $at ) ? $at : count( $dates ) - 1 ),
                'readings'      => TestsBlockOptions::showsPlayers( $show ) ? $ranked : [],
            ];
        }

        return $out;
    }

    /**
     * Is this the player's best reading ever? Only on a test with a direction,
     * and only once there is an earlier reading to beat — a first reading is a
     * first reading, not a record.
     *
     * @param array<array-key,mixed> $values date => reading, entry unit
     */
    private static function isPersonalBest( array $values, string $date, string $direction ): bool {
        if ( $direction !== 'lower' && $direction !== 'higher' ) return false;

        $now     = (float) $values[ $date ];
        $earlier = [];
        foreach ( $values as $d => $v ) {
            if ( (string) $d < $date && ( is_int( $v ) || is_float( $v ) ) ) $earlier[] = (float) $v;
        }
        if ( $earlier === [] ) return false;

        return $direction === 'lower' ? $now < min( $earlier ) : $now > max( $earlier );
    }

    /**
     * Rank, bar length and distance to the squad average per reading (#4069).
     *
     * The rank is shared on a tie, and only exists on a test with a direction:
     * without better or worse there is nothing to rank. `worse_than_avg` marks
     * where the squad-average line falls in a best-to-worst table.
     *
     * @param list<array<string,mixed>> $rows ranked
     * @return list<array<string,mixed>>
     */
    private static function withSquadComparison( array $rows, UnitContext $units, string $direction, ?float $average ): array {
        $ranked = $direction === 'lower' || $direction === 'higher';
        $max    = 0.0;
        foreach ( $rows as $r ) $max = max( $max, (float) $r['value'] );

        $out  = [];
        $rank = 0;
        $last = null;
        foreach ( $rows as $i => $r ) {
            $value = (float) $r['value'];
            if ( $last === null || $value !== $last ) $rank = $i + 1;
            $last = $value;

            $vs = $average !== null ? $value - $average : null;

            $r['rank']           = $ranked ? $rank : null;
            $r['bar_pct']        = $max > 0 ? round( $value / $max * 100, 1 ) : 0.0;
            $r['vs_avg']         = $vs;
            $r['vs_avg_display'] = $vs !== null ? self::testGapDisplay( $units, $vs ) : '—';
            $r['worse_than_avg'] = $vs !== null && ( ( $direction === 'lower' && $vs > 0 ) || ( $direction === 'higher' && $vs < 0 ) );
            $out[] = $r;
        }
        return $out;
    }

    /**
     * The squad average this round and the round before, with the move
     * between them judged the way a single reading is (#4069).
     *
     * @param array<string,float> $averages date => squad average, entry unit
     * @return array{value:?float, display:string, previous:?float, previous_display:string, delta_display:string, trend:string}
     */
    private static function testAverage( UnitContext $units, array $averages, string $date, ?string $prev_date, string $direction, bool $has_direction ): array {
        $now  = isset( $averages[ $date ] ) ? (float) $averages[ $date ] : null;
        $then = $prev_date !== null && isset( $averages[ $prev_date ] ) ? (float) $averages[ $prev_date ] : null;

        $delta_display = '—';
        $trend         = '';
        if ( $now !== null && $then !== null ) {
            $delta_display = self::testDeltaDisplay( $units, $now - $then );
            $trend         = TestTrendsQuery::stateFor( $now - $then, $then, $direction, $has_direction )['trend'];
        }

        return [
            'value'            => $now,
            'display'          => $now !== null ? self::testAverageDisplay( $units, $now ) : '—',
            'previous'         => $then,
            'previous_display' => $then !== null ? self::testAverageDisplay( $units, $then ) : '—',
            'delta_display'    => $delta_display,
            'trend'            => $trend,
        ];
    }

    /**
     * The best or the worst reading of the round: the ends of the ranking on
     * a test with a direction, the highest and lowest value without one.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{player_id:int, name:string, value_display:string}|null
     */
    private static function testExtreme( array $rows, string $direction, bool $best ): ?array {
        if ( $rows === [] ) return null;

        if ( $direction === 'lower' || $direction === 'higher' ) {
            $row = $best ? $rows[0] : $rows[ count( $rows ) - 1 ];
        } else {
            $row = $rows[0];
            foreach ( $rows as $r ) {
                $higher = (float) $r['value'] > (float) $row['value'];
                $lower  = (float) $r['value'] < (float) $row['value'];
                if ( ( $best && $higher ) || ( ! $best && $lower ) ) $row = $r;
            }
        }

        return [
            'player_id'     => (int) ( $row['player_id'] ?? 0 ),
            'name'          => (string) ( $row['name'] ?? '' ),
            'value_display' => (string) ( $row['value_display'] ?? '' ),
        ];
    }

    /**
     * How many moved each way since their previous reading, and how many had
     * none. A neutral test's rose / fell count as up / down.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{up:int, down:int, flat:int, first:int}
     */
    private static function testMoves( array $rows ): array {
        $moves = [ 'up' => 0, 'down' => 0, 'flat' => 0, 'first' => 0 ];
        foreach ( $rows as $r ) {
            if ( ! empty( $r['first'] ) ) {
                $moves['first']++;
                continue;
            }
            $t = (string) ( $r['trend'] ?? '' );
            if ( $t === 'up' || $t === 'rose' ) {
                $moves['up']++;
            } elseif ( $t === 'down' || $t === 'fell' ) {
                $moves['down']++;
            } else {
                $moves['flat']++;
            }
        }
        return $moves;
    }

    /**
     * Readings per target band — green, amber, red — against the team's age
     * group. Null when the test has no band for it, so the strip leaves the
     * cell out rather than printing "0 of 15 on target".
     *
     * @param list<array<string,mixed>> $rows
     * @return array{age_group:string, ok:int, warn:int, bad:int, of:int}|null
     */
    private static function testBands( array $rows, string $age_group, int $tested ): ?array {
        if ( $age_group === '' ) return null;

        $counts = [ 'ok' => 0, 'warn' => 0, 'bad' => 0 ];
        foreach ( $rows as $r ) {
            $flag = (string) ( $r['flag'] ?? '' );
            if ( isset( $counts[ $flag ] ) ) $counts[ $flag ]++;
        }
        if ( array_sum( $counts ) === 0 ) return null;

        return [ 'age_group' => $age_group, 'ok' => $counts['ok'], 'warn' => $counts['warn'], 'bad' => $counts['bad'], 'of' => $tested ];
    }

    /**
     * The squad average over the last four rounds up to this one, oldest
     * first, each as a share of the highest so a strip can draw it.
     *
     * @param array<string,float> $averages
     * @param list<string>        $dates
     * @return list<array{date:string, value:float, display:string, pct:float}>
     */
    private static function testHistory( UnitContext $units, array $averages, array $dates, int $at ): array {
        $points = [];
        foreach ( array_slice( $dates, 0, $at + 1 ) as $d ) {
            if ( isset( $averages[ $d ] ) ) $points[] = [ 'date' => $d, 'value' => (float) $averages[ $d ] ];
        }
        $points = array_slice( $points, -4 );

        $max = 0.0;
        foreach ( $points as $pt ) $max = max( $max, $pt['value'] );

        $out = [];
        foreach ( $points as $pt ) {
            $out[] = [
                'date'    => $pt['date'],
                'value'   => $pt['value'],
                'display' => self::testAverageDisplay( $units, $pt['value'] ),
                'pct'     => $max > 0 ? round( $pt['value'] / $max * 100, 1 ) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * A squad average as a person reads it. An average is not a reading, so
     * it does not get a reading's precision: whole seconds on a timed test of
     * a minute or more, two decimals otherwise.
     */
    private static function testAverageDisplay( UnitContext $units, float $value ): string {
        if ( $units->isDuration() ) {
            $seconds = $units->toBase( $value );
            return DurationFormat::format( round( $seconds, abs( $seconds ) >= 60 ? 0 : 2 ) );
        }
        return UnitContext::localeNumber( round( $value, 2 ) );
    }

    /**
     * A reading's distance to the squad average, signed: "−1:36" on a timed
     * test, "+0,45" otherwise.
     */
    private static function testGapDisplay( UnitContext $units, float $gap ): string {
        if ( $units->isDuration() ) {
            $seconds = $units->toBase( $gap );
            $seconds = round( $seconds, abs( $seconds ) >= 60 ? 0 : 1 );
            if ( abs( $seconds ) < 0.05 ) return '0:00';
            return ( $seconds > 0 ? '+' : '−' ) . DurationFormat::format( abs( $seconds ) );
        }
        if ( abs( $gap ) < 0.0001 ) return '0';
        return ( $gap > 0 ? '+' : '−' ) . number_format_i18n( abs( $gap ), abs( $gap ) < 10 ? 2 : 1 );
    }

    /**
     * The placeholder for a test that was asked for but not taken this window.
     * Null when the definition no longer exists.
     *
     * @return array<string,mixed>|null
     */
    private function emptyTestRound( int $definition_id ): ?array {
        $definition = ( new MeasurementDefinitionsRepository() )->find( $definition_id );
        if ( ! $definition ) return null;

        $units = UnitContext::forDefinition( $definition );

        return [
            'definition_id' => $definition_id,
            'name'          => (string) ( $definition->name ?? '' ),
            'unit'          => (string) ( $definition->unit ?? '' ),
            'unit_label'    => self::testUnitLabel( $units ),
            'is_duration'   => $units->isDuration(),
            'direction'     => '',
            'date'          => '',
            'previous_date' => null,
            'average'       => null,
            'best'          => null,
            'worst'         => null,
            'moves'         => null,
            'bands'         => null,
            'target'           => null,
            'target_age_group' => '',
            'target_absent'    => false,
            'history'       => [],
            'tested'        => 0,
            'squad'         => count( $this->players() ),
            'improved'      => [],
            'declined'      => [],
            'readings'      => [],
            'empty'         => true,
        ];
    }

    /**
     * What a test's result column is headed with: `mm:ss` for a duration,
     * the unit's symbol otherwise.
     */
    private static function testUnitLabel( UnitContext $units ): string {
        return $units->isDuration() ? 'mm:ss' : $units->symbol();
    }

    /**
     * One reading as a person reads it (#4063). Trend values arrive in the
     * entry unit; a duration goes back to seconds and prints as m:ss, rounded
     * to the hundredth first so float noise from the unit conversion cannot
     * turn 16:04 into 16:03.99.
     */
    private static function testValueDisplay( UnitContext $units, float $value ): string {
        if ( $units->isDuration() ) {
            return DurationFormat::format( round( $units->toBase( $value ), 2 ) );
        }
        return UnitContext::localeNumber( $value );
    }

    /**
     * A change between two readings (#4063). A duration's change is spoken in
     * seconds ("−7 s"), whatever unit the test is entered in; anything else
     * keeps the entry unit, which the column header names.
     */
    private static function testDeltaDisplay( UnitContext $units, float $delta ): string {
        if ( $units->isDuration() ) {
            $seconds = round( $units->deltaFromBase( $units->toBase( $delta ) ), 1 );
            if ( abs( $seconds ) < 0.05 ) return '0 ' . $units->deltaSymbol();
            return ( $seconds > 0 ? '+' : '−' ) . UnitContext::localeNumber( abs( $seconds ) ) . ' ' . $units->deltaSymbol();
        }
        if ( abs( $delta ) < 0.0001 ) return '0';
        return ( $delta > 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ), abs( $delta ) < 10 ? 2 : 1 );
    }

    /**
     * Readings best to worst on a test with a direction (#4063): ascending on
     * `lower`, descending on `higher`. Without a direction there is no better
     * or worse, so the shirt order they arrive in stands. Ties keep that shirt
     * order too.
     *
     * @param list<array<string,mixed>> $readings in shirt order
     * @return list<array<string,mixed>>
     */
    private static function rankReadings( array $readings, string $direction ): array {
        if ( $direction !== 'lower' && $direction !== 'higher' ) return $readings;

        $sign = $direction === 'lower' ? 1 : -1;
        return self::stableSort(
            $readings,
            static fn( array $a, array $b ): int => $sign * ( (float) ( $a['value'] ?? 0 ) <=> (float) ( $b['value'] ?? 0 ) )
        );
    }

    /**
     * `usort` with ties left in the order the rows arrived — PHP 7.4's sort
     * is not stable, and every ranking here falls back to shirt order.
     *
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>, array<string,mixed>): int $cmp
     * @return list<array<string,mixed>>
     */
    private static function stableSort( array $rows, callable $cmp ): array {
        $indexed = [];
        foreach ( $rows as $i => $row ) {
            $indexed[] = [ $i, $row ];
        }
        usort(
            $indexed,
            /**
             * @param array{int, array<string,mixed>} $a
             * @param array{int, array<string,mixed>} $b
             */
            static function ( array $a, array $b ) use ( $cmp ): int {
                $c = $cmp( $a[1], $b[1] );
                return $c !== 0 ? $c : $a[0] <=> $b[0];
            }
        );

        $out = [];
        foreach ( $indexed as $pair ) {
            $out[] = $pair[1];
        }
        return $out;
    }

    /**
     * One row per player, every measure. The page the discussion moves along.
     *
     * @return array<string,mixed>
     */
    private function roster(): array {
        $attendance = [];
        foreach ( $this->attendanceRows() as $r ) {
            $attendance[ $r['player_id'] ] = $r;
        }

        $minutes = [];
        foreach ( $this->minutesIn( $this->from, $this->to ) as $r ) {
            $minutes[ $r['player_id'] ] = $r;
        }

        $goals    = ( new GoalsRepository() )->openCountsForTeam( $this->team_id );
        $verdicts = $this->verdictsAt( $this->to );

        $injured = [];
        foreach ( ( new InjuryRepository() )->listForTeams( [ $this->team_id ], [ 'status' => 'open' ] ) as $i ) {
            $injured[ (int) ( $i->player_id ?? 0 ) ] = true;
        }

        $rows = [];
        foreach ( $this->players() as $pid => $p ) {
            $att    = $attendance[ $pid ] ?? null;
            $min    = $minutes[ $pid ] ?? null;
            $jersey = $p->jersey_number ?? null;
            $rows[] = [
                'player_id'      => $pid,
                'name'           => self::name( $p ),
                'jersey_number'  => $jersey !== null ? (int) $jersey : null,
                'status'         => isset( $verdicts[ $pid ] ) ? $verdicts[ $pid ]->color : StatusVerdict::COLOR_UNKNOWN,
                'attendance_pct' => $att !== null ? $att['present_pct'] : null,
                'minutes'        => $min !== null ? $min['total_minutes'] : null,
                'share_pct'      => $min !== null ? self::share( $min['total_minutes'], $min['available_minutes'] ) : null,
                'open_goals'     => $goals[ $pid ] ?? 0,
                'injured'        => isset( $injured[ $pid ] ),
                // #4114 — activities the player missed suspended this window
                // (#4102), so a suspension is not a gap nobody can explain.
                'suspended'      => $att !== null ? $att['suspended'] : 0,
            ];
        }
        return [ 'rows' => $rows ];
    }

    /**
     * What is missing and needs fixing before next month.
     *
     * @return array<string,mixed>
     */
    private function quality(): array {
        $coverage = $this->coverage();
        $counts   = ( new MinutesQuery() )->matchCountsForTeam( $this->team_id, $this->from, $this->to );
        $cover    = ( new EvalCoverageService() )->coverageBetween( $this->team_id, $this->from, $this->to );
        $players  = $this->players();

        $evaluated     = array_flip( $cover['evaluated_ids'] );
        $not_evaluated = [];
        foreach ( $players as $pid => $p ) {
            if ( isset( $evaluated[ $pid ] ) ) continue;
            $not_evaluated[] = [ 'player_id' => $pid, 'name' => self::name( $p ) ];
        }

        $incomplete = [];
        foreach ( $this->verdictsAt( $this->to ) as $pid => $v ) {
            if ( $v->isComplete() ) continue;
            $incomplete[] = [
                'player_id'      => $pid,
                'name'           => isset( $players[ $pid ] ) ? self::name( $players[ $pid ] ) : '',
                'missing_inputs' => $v->missing_inputs,
            ];
        }

        // #3860 — a match with no opponent stored prints as "Unknown
        // opponent" in the fixtures above, and the reader cannot tell
        // whether the record is wrong or merely incomplete. Named by date
        // and title, because the title is where the opponent usually is.
        $without_opponent = [];
        foreach ( ( new ActivitiesRepository() )->matchesInWindowForTeam( $this->team_id, $this->from, $this->to ) as $match ) {
            if ( trim( (string) $match['opponent'] ) !== '' ) continue;
            $without_opponent[] = [
                'activity_id' => (int) $match['activity_id'],
                'date'        => (string) $match['date'],
                'title'       => (string) $match['title'],
            ];
        }

        return [
            'activities_without_register'    => $coverage['missing'],
            'activities_never_closed'        => $coverage['never_closed'],
            'matches_without_minutes'        => max( 0, $counts['played'] - $counts['recorded'] ),
            'matches_without_opponent'       => $without_opponent,
            'players_not_evaluated'          => $not_evaluated,
            'players_with_incomplete_status' => $incomplete,
        ];
    }

    /* ---------------------------------------------------------------
     * Shared inputs — each loaded at most once, only when asked for
     * ------------------------------------------------------------- */

    private function team(): ?object {
        if ( ! $this->team_loaded ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
                $this->team_id, CurrentClub::id()
            ) );
            $this->team_row    = is_object( $row ) ? $row : null;
            $this->team_loaded = true;
        }
        return $this->team_row;
    }

    /**
     * The squad as it stands: active, live players on the team, keyed by id.
     * The same roster the attendance rows are counted over ({@see TeamRoster}).
     *
     * @return array<int,object>
     */
    private function players(): array {
        if ( $this->players === null ) {
            global $wpdb;
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.id, p.first_name, p.last_name, p.jersey_number
                   FROM {$wpdb->prefix}tt_players p
                  WHERE " . TeamRoster::memberOfClause( 'p', '%d' ) . "
                    AND p.club_id = %d
                  ORDER BY " . PlayerOrder::sqlOrderBy( 'p' ),
                $this->team_id, CurrentClub::id()
            ) );
            $out = [];
            foreach ( $rows ?? [] as $row ) {
                $out[ (int) ( $row->id ?? 0 ) ] = $row;
            }
            $this->players = $out;
        }
        return $this->players;
    }

    /**
     * Completed, live activities of the team in a window.
     *
     * @return list<object>
     */
    private function activitiesIn( string $from, string $to ): array {
        $key = $from . '|' . $to;
        if ( ! isset( $this->activities[ $key ] ) ) {
            global $wpdb;
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.id, a.team_id, a.title, a.session_date, a.activity_type_key, a.activity_status_key
                   FROM {$wpdb->prefix}tt_activities a
                  WHERE a.team_id = %d
                    AND a.club_id = %d
                    AND " . ArchiveRepository::filterClause( 'active', 'a' ) . "
                    AND " . ActivityLifecycle::completedClause( 'a' ) . "
                    AND a.session_date BETWEEN %s AND %s
                  ORDER BY a.session_date ASC, a.id ASC",
                $this->team_id, CurrentClub::id(), $from, $to
            ) );
            $this->activities[ $key ] = $rows ?? [];
        }
        return $this->activities[ $key ];
    }

    /**
     * Every live activity of the team in a window that was not cancelled,
     * whatever its status — what the coach sees on the activities list for
     * that month. A cancelled session never happened and never will, so it is
     * not a gap and does not belong in a count of what the month held.
     *
     * @return list<object>
     */
    private function scheduledIn( string $from, string $to ): array {
        $key = $from . '|' . $to;
        if ( ! isset( $this->scheduled[ $key ] ) ) {
            global $wpdb;
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.id, a.team_id, a.title, a.session_date, a.activity_type_key, a.activity_status_key
                   FROM {$wpdb->prefix}tt_activities a
                  WHERE a.team_id = %d
                    AND a.club_id = %d
                    AND " . ArchiveRepository::filterClause( 'active', 'a' ) . "
                    AND " . ActivityLifecycle::notCancelledClause( 'a' ) . "
                    AND a.session_date BETWEEN %s AND %s
                  ORDER BY a.session_date ASC, a.id ASC",
                $this->team_id, CurrentClub::id(), $from, $to
            ) );
            $this->scheduled[ $key ] = $rows ?? [];
        }
        return $this->scheduled[ $key ];
    }

    /** @return list<AttendanceRow> */
    private function attendanceRows(): array {
        return $this->attendanceRowsIn( $this->from, $this->to );
    }

    /**
     * Per-player attendance over a window, from the shared ranking query.
     *
     * @return list<AttendanceRow>
     */
    private function attendanceRowsIn( string $from, string $to ): array {
        $key = $from . '|' . $to;
        if ( ! isset( $this->attendance[ $key ] ) ) {
            $this->attendance[ $key ] = ( new AttendanceRankingQuery() )->rows( $from, $to, $this->team_id );
        }
        return $this->attendance[ $key ];
    }

    /**
     * The squad's attendance: the mean of the per-player percentages, one
     * decimal (#4068). The attendance section prints it above its table and
     * the headline tile prints the same number, so the two cannot disagree —
     * the tile used to pool every register row, count only "present" and
     * round to a whole percent, and read 100% above a player at 93.8%.
     *
     * Late counts as attended, as it does in every row (#4013). A value below
     * 100 is capped at 99.9, so 100% means everybody attended everything.
     * Null when nobody has a register row, so a quiet month reads "—".
     *
     * @param list<AttendanceRow> $rows
     */
    private static function squadAttendancePct( array $rows ): ?float {
        $sum = 0.0;
        $n   = 0;
        foreach ( $rows as $r ) {
            if ( $r['present_pct'] === null ) continue;
            $sum += $r['present_pct'];
            $n++;
        }
        if ( $n === 0 ) return null;

        $mean = $sum / $n;
        return $mean < 100.0 ? min( round( $mean, 1 ), 99.9 ) : 100.0;
    }

    /**
     * Minutes for every player in the squad over a window.
     *
     * #3589 — `MinutesQuery::forTeam()` only returns players who got on the
     * pitch, which left out exactly the player the minutes block exists to
     * flag: in the squad, available, never played. Every squad player without
     * a row gets one at zero, against the same available minutes as the rest
     * (the query's figure is squad-wide). The query itself is left as it is;
     * its other callers rely on its shape.
     *
     * @return list<MinutesRow>
     */
    private function minutesIn( string $from, string $to ): array {
        $key = $from . '|' . $to;
        if ( ! isset( $this->minutes[ $key ] ) ) {
            $rows = ( new MinutesQuery() )->forTeam( $this->team_id, $from, $to );

            $seen = [];
            foreach ( $rows as $r ) $seen[ $r['player_id'] ] = true;

            $available = null;
            foreach ( $this->players() as $pid => $player ) {
                if ( isset( $seen[ $pid ] ) ) continue;
                if ( $available === null ) {
                    $available = $rows !== []
                        ? $rows[0]['available_minutes']
                        : ( new MinutesShareQuery() )->availableForTeam( $this->team_id, $from, $to )['minutes'];
                }
                $player = (array) $player;
                $rows[] = [
                    'player_id'         => (int) $pid,
                    'first_name'        => (string) ( $player['first_name'] ?? '' ),
                    'last_name'         => (string) ( $player['last_name'] ?? '' ),
                    'jersey_number'     => isset( $player['jersey_number'] ) ? (int) $player['jersey_number'] : null,
                    'total_minutes'     => 0,
                    'matches'           => 0,
                    'starts'            => 0,
                    'subs_in'           => 0,
                    'subs_off'          => 0,
                    'by_type'           => [],
                    'available_minutes' => $available,
                ];
            }
            $this->minutes[ $key ] = $rows;
        }
        return $this->minutes[ $key ];
    }

    /**
     * Status verdicts for the squad as of a date.
     *
     * @return array<int,StatusVerdict>
     */
    private function verdictsAt( string $as_of ): array {
        if ( ! isset( $this->verdicts[ $as_of ] ) ) {
            $calc = new PlayerStatusCalculator();
            $out  = [];
            foreach ( array_keys( $this->players() ) as $pid ) {
                $out[ $pid ] = $calc->calculate( $pid, $as_of );
            }
            $this->verdicts[ $as_of ] = $out;
        }
        return $this->verdicts[ $as_of ];
    }

    /* ---------------------------------------------------------------
     * Arithmetic
     * ------------------------------------------------------------- */

    /**
     * @param int|float|null $value
     * @param int|float|null $previous
     * @return array{value:int|float|null, previous:int|float|null, delta:int|float|null}
     */
    private static function measure( $value, $previous ): array {
        return [ 'value' => $value, 'previous' => $previous, 'delta' => self::delta( $value, $previous ) ];
    }

    /**
     * @param int|float|null $now
     * @param int|float|null $before
     * @return int|float|null
     */
    private static function delta( $now, $before ) {
        if ( $now === null || $before === null ) return null;
        $d = $now - $before;
        return is_float( $d ) ? round( $d, 1 ) : $d;
    }

    private static function pct( int $part, int $whole ): ?float {
        return $whole > 0 ? round( $part / $whole * 100, 1 ) : null;
    }

    private static function share( int $minutes, int $available ): ?float {
        return $available > 0 ? round( $minutes / $available * 100, 1 ) : null;
    }

    /** @param list<MinutesRow> $rows */
    private static function medianShare( array $rows ): ?float {
        $shares = [];
        foreach ( $rows as $r ) {
            $s = self::share( $r['total_minutes'], $r['available_minutes'] );
            if ( $s !== null ) $shares[] = $s;
        }
        if ( $shares === [] ) return null;
        sort( $shares );
        $n   = count( $shares );
        $mid = intdiv( $n, 2 );
        return $n % 2 === 1 ? $shares[ $mid ] : round( ( $shares[ $mid - 1 ] + $shares[ $mid ] ) / 2, 1 );
    }

    /**
     * @param array<int,StatusVerdict> $verdicts
     * @return array{green:int, amber:int, red:int, unknown:int}
     */
    private static function statusCounts( array $verdicts ): array {
        $counts = [ 'green' => 0, 'amber' => 0, 'red' => 0, 'unknown' => 0 ];
        foreach ( $verdicts as $v ) {
            switch ( $v->color ) {
                case StatusVerdict::COLOR_GREEN: $counts['green']++; break;
                case StatusVerdict::COLOR_AMBER: $counts['amber']++; break;
                case StatusVerdict::COLOR_RED:   $counts['red']++;   break;
                default:                         $counts['unknown']++;
            }
        }
        return $counts;
    }

    /** @param array<int,StatusVerdict> $verdicts */
    private static function attentionCount( array $verdicts ): int {
        $c = self::statusCounts( $verdicts );
        return $c['amber'] + $c['red'];
    }

    private static function attendanceBand( ?float $pct ): ?string {
        if ( $pct === null ) return null;
        if ( $pct < self::ATTENDANCE_RED_BELOW ) return 'red';
        if ( $pct < self::ATTENDANCE_AMBER_BELOW ) return 'amber';
        return 'green';
    }

    private static function name( object $row ): string {
        return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
    }

    private static function isDate( string $d ): bool {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }
}
