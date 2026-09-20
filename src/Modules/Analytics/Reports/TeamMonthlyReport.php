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
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Measurements\Reports\TestTrendsQuery;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
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
 * @phpstan-type AttendanceRow array{player_id:int, first_name:string, last_name:string, team_name:string, activities:int, total:int, present:int, late:int, absent:int, excused:int, injured:int, present_pct:?float, missed:int, flagged:bool}
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

    /** @var list<AttendanceRow>|null */
    private ?array $attendance = null;

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
        $this->attendance     = null;
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
            case TeamMonthlyReportBlock::ATTENDANCE: return $this->attendanceBlock();
            case TeamMonthlyReportBlock::MINUTES:    return $this->minutesBlock();
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

        $att_now  = $kpis->avgAttendanceBetween( $this->team_id, $this->from, $this->to );
        $att_prev = $has_prev ? $kpis->avgAttendanceBetween( $this->team_id, $previous['from'], $previous['to'] ) : null;

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

    /** @return array<string,mixed> */
    private function attendanceBlock(): array {
        $rows = [];
        $sum  = 0.0;
        $n    = 0;
        foreach ( $this->attendanceRows() as $r ) {
            if ( $r['present_pct'] !== null ) {
                $sum += $r['present_pct'];
                $n++;
            }
            $rows[] = [
                'player_id'   => $r['player_id'],
                'name'        => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'activities'  => $r['activities'],
                'present'     => $r['present'],
                'late'        => $r['late'],
                'absent'      => $r['absent'],
                'excused'     => $r['excused'],
                'injured'     => $r['injured'],
                'present_pct' => $r['present_pct'],
                'band'        => self::attendanceBand( $r['present_pct'] ),
                'flagged'     => $r['flagged'],
            ];
        }
        // #3518 — shirt order. The rows come from the shared attendance
        // ranking query, which other surfaces order for their own reasons, so
        // the report sorts its own copy rather than changing that query.
        $rows = PlayerOrder::sort( $rows, PlayerOrder::jerseys( $this->players() ) );

        return [
            'team_avg_pct' => $n > 0 ? round( $sum / $n, 1 ) : null,
            'amber_below'  => self::ATTENDANCE_AMBER_BELOW,
            'red_below'    => self::ATTENDANCE_RED_BELOW,
            'rows'         => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function minutesBlock(): array {
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

        return [
            'matches_recorded' => $counts['recorded'],
            'matches_played'   => $counts['played'],
            // #3589 — the academy's one target, the one the minutes-share
            // report reads, not a second number of this report's own.
            'target_pct'       => MinutesShareQuery::targetPct(),
            'median_share_pct' => self::medianShare( $source ),
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
            'shows'                => [
                'record'  => MatchesBlockOptions::shows( $options, MatchesBlockOptions::RECORD ),
                'scorers' => MatchesBlockOptions::shows( $options, MatchesBlockOptions::SCORERS ),
                'squads'  => MatchesBlockOptions::shows( $options, MatchesBlockOptions::SQUADS ),
            ],
            'record'   => self::matchRecord( $activities ),
            'fixtures' => [],
            'scorers'  => [],
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
            $out['scorers'] = PlayerOrder::sort( $rows, $jerseys );
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
            return [ 'rounds' => array_values( $held ), 'show' => $show ];
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

        return [ 'rounds' => $out, 'show' => $show ];
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
        $trends  = new TestTrendsQuery();
        $squad   = count( $this->players() );
        $jerseys = PlayerOrder::jerseys( $this->players() );

        $out = [];
        foreach ( ( new MeasurementSessionsRepository() )->listForTeam( $this->team_id ) as $s ) {
            $def_id  = (int) ( $s->definition_id ?? 0 );
            $planned = (string) ( $s->planned_date ?? '' );
            if ( $def_id <= 0 || isset( $out[ $def_id ] ) ) continue;
            if ( $planned === '' || $planned < $this->from || $planned > $this->to ) continue;
            if ( $wanted !== [] && ! in_array( $def_id, $wanted, true ) ) continue;

            $trend = $trends->forDefinition( $def_id, [ 'team_id' => $this->team_id, 'date_to' => $this->to ] );

            $in_window = [];
            foreach ( $trend['dates'] as $d ) {
                if ( $d >= $this->from && $d <= $this->to ) $in_window[] = $d;
            }
            if ( $in_window === [] ) continue;
            $date = $in_window[ count( $in_window ) - 1 ];

            $tested   = 0;
            $improved = [];
            $declined = [];
            $readings = [];
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

                if ( TestsBlockOptions::showsPlayers( $show ) ) {
                    // A player tested for the first time has a reading but no
                    // step: null reads as "nothing to compare with", which is
                    // not the same as no change.
                    $readings[] = $entry + [
                        'value' => $values[ $date ],
                        'trend' => is_array( $step ) ? (string) ( $step['trend'] ?? '' ) : '',
                        'first' => ! is_array( $step ),
                    ];
                }

                if ( ! is_array( $step ) ) continue;
                $trend_key = (string) ( $step['trend'] ?? '' );
                if ( $trend_key === 'up' )   $improved[] = $entry;
                if ( $trend_key === 'down' ) $declined[] = $entry;
            }

            $definition = $trend['definition'];
            $out[ $def_id ] = [
                'definition_id' => $def_id,
                'name'          => is_array( $definition ) ? (string) ( $definition['name'] ?? '' ) : (string) ( $s->definition_name ?? '' ),
                'unit'          => is_array( $definition ) ? (string) ( $definition['unit'] ?? '' ) : '',
                'date'          => $date,
                'tested'        => $tested,
                'squad'         => $squad,
                // #3518 — these are player lists, so they read in shirt order
                // like every other player list in the report.
                'improved'      => PlayerOrder::sort( $improved, $jerseys ),
                'declined'      => PlayerOrder::sort( $declined, $jerseys ),
                'readings'      => PlayerOrder::sort( $readings, $jerseys ),
            ];
        }

        return $out;
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

        return [
            'definition_id' => $definition_id,
            'name'          => (string) ( $definition->name ?? '' ),
            'unit'          => (string) ( $definition->unit ?? '' ),
            'date'          => '',
            'tested'        => 0,
            'squad'         => count( $this->players() ),
            'improved'      => [],
            'declined'      => [],
            'readings'      => [],
            'empty'         => true,
        ];
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

        return [
            'activities_without_register'    => $coverage['missing'],
            'activities_never_closed'        => $coverage['never_closed'],
            'matches_without_minutes'        => max( 0, $counts['played'] - $counts['recorded'] ),
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
                  WHERE p.team_id = %d
                    AND p.club_id = %d
                    AND p.status = 'active'
                    AND " . ArchiveRepository::filterClause( 'active', 'p' ) . "
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
        if ( $this->attendance === null ) {
            $this->attendance = ( new AttendanceRankingQuery() )->rows( $this->from, $this->to, $this->team_id );
        }
        return $this->attendance;
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
