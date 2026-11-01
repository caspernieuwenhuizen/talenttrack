<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyComposers\AttendanceComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\AttentionComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\ChangesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\CoverageComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\EvaluationsComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\KpiComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\LetterheadComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MatchesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MinutesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\QualityComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\RosterComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\StatusComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TestsComposer;
use TT\Modules\Measurements\Repositories\MeasurementSessionsRepository;

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
 * Each block is computed by its own composer under `TeamMonthlyComposers\`,
 * which the Report Studio's blocks call too (#4590). A deselected block is not
 * queried. The inputs several blocks share — the roster, the window's
 * activities, the attendance rows, the minutes rows, the player verdicts —
 * live on one `TeamReportContext` per call, each loaded at most once and only
 * when a selected block needs them. A coach who wants the KPI strip does not pay for
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
        // A fresh context per call: every shared read is loaded at most once
        // for this report, and never carried over into the next one.
        $ctx      = new TeamReportContext( $team_id, $from, $to, $viewer_user_id, $options );
        $selected = TeamMonthlyReportBlock::normalise( $blocks );

        $data = [];
        foreach ( $selected as $block ) {
            $data[ $block ] = self::block( $block, $ctx );
        }

        return [
            'team_id'  => $team_id,
            'from'     => $from,
            'to'       => $to,
            'previous' => $ctx->previous,
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
     * One block, by its composer (#4590). The Studio's blocks call the same
     * composers, so a figure reads the same in both.
     *
     * @return array<string,mixed>
     */
    private static function block( string $block, TeamReportContext $ctx ): array {
        switch ( $block ) {
            case TeamMonthlyReportBlock::LETTERHEAD:  return LetterheadComposer::compose( $ctx );
            case TeamMonthlyReportBlock::COVERAGE:    return CoverageComposer::compose( $ctx );
            case TeamMonthlyReportBlock::KPI:         return KpiComposer::compose( $ctx );
            case TeamMonthlyReportBlock::STATUS:      return StatusComposer::compose( $ctx );
            case TeamMonthlyReportBlock::EVALUATIONS: return EvaluationsComposer::compose( $ctx );
            case TeamMonthlyReportBlock::ATTENDANCE:  return AttendanceComposer::compose( $ctx );
            case TeamMonthlyReportBlock::MINUTES:     return MinutesComposer::compose( $ctx );
            case TeamMonthlyReportBlock::MATCHES:     return MatchesComposer::compose( $ctx );
            case TeamMonthlyReportBlock::ATTENTION:   return AttentionComposer::compose( $ctx );
            case TeamMonthlyReportBlock::CHANGES:     return ChangesComposer::compose( $ctx );
            case TeamMonthlyReportBlock::TESTS:       return TestsComposer::compose( $ctx );
            case TeamMonthlyReportBlock::ROSTER:      return RosterComposer::compose( $ctx );
            case TeamMonthlyReportBlock::NOTES:       return [ 'lines' => 6 ];
            case TeamMonthlyReportBlock::QUALITY:     return QualityComposer::compose( $ctx );
        }
        return [];
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

    private static function isDate( string $d ): bool {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }
}
