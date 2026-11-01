<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Teams\TeamRoster;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\PlayerOrder;
use TT\Modules\Analytics\Reports\TeamMatchRecord;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;

/**
 * TeamReportContext (#4590) — one team, one window, one reader: what every
 * team block composer is handed.
 *
 * It carries the reads several blocks share — the team, the squad, the
 * window's activities, attendance rows, minutes rows, status verdicts and
 * fixtures — each loaded at most once per context, and only when a composer
 * asks for it. A caller composing several blocks for the same team and window
 * passes one context to all of them, so the reads are not repeated. The
 * subject is fixed on construction; access is the caller's to check
 * (`TeamReportAccess`) before a context is built.
 *
 * @phpstan-import-type AttendanceRow from TeamMonthlyReport
 * @phpstan-import-type MinutesRow from TeamMonthlyReport
 * @phpstan-import-type Window from TeamMonthlyReport
 */
final class TeamReportContext {

    public readonly int $team_id;
    public readonly string $from;
    public readonly string $to;

    /**
     * Whose journey visibility applies to "what changed". 0 is an unattended
     * caller such as the scheduled mailing.
     */
    public readonly int $viewer_user_id;

    /** @var Window the window a delta is taken against */
    public readonly array $previous;

    /** @var array<string,array<string,mixed>> block key => option bag */
    private array $options;

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

    /** @var list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string}>|null */
    private ?array $fixtures = null;

    /**
     * @param array<string,array<string,mixed>> $options per-block option bags,
     *        block key => bag. Never throws: an option a later version dropped
     *        must still open a saved report.
     * @throws \InvalidArgumentException on a malformed window.
     */
    public function __construct( int $team_id, string $from, string $to, int $viewer_user_id = 0, array $options = [] ) {
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            throw new \InvalidArgumentException( 'from and to must be Y-m-d dates with from <= to.' );
        }

        $this->team_id        = $team_id;
        $this->from           = $from;
        $this->to             = $to;
        $this->viewer_user_id = $viewer_user_id;
        $this->previous       = TeamMonthlyReport::previousWindow( $from, $to );
        $this->options        = $options;
    }

    /**
     * One block's options, normalised by whoever owns them. A block given
     * nothing sees `[]` and renders as it always did.
     *
     * @return array<string,mixed>
     */
    public function optionsFor( string $block ): array {
        return TeamMonthlyReportBlockOptions::normalise( $block, $this->options[ $block ] ?? [] );
    }

    public function team(): ?object {
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
    public function players(): array {
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
    public function activitiesIn( string $from, string $to ): array {
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
    public function scheduledIn( string $from, string $to ): array {
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
    public function attendanceRows(): array {
        return $this->attendanceRowsIn( $this->from, $this->to );
    }

    /**
     * Per-player attendance over a window, from the shared ranking query.
     *
     * @return list<AttendanceRow>
     */
    public function attendanceRowsIn( string $from, string $to ): array {
        $key = $from . '|' . $to;
        if ( ! isset( $this->attendance[ $key ] ) ) {
            $this->attendance[ $key ] = ( new AttendanceRankingQuery() )->rows( $from, $to, $this->team_id );
        }
        return $this->attendance[ $key ];
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
    public function minutesIn( string $from, string $to ): array {
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
    public function verdictsAt( string $as_of ): array {
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

    /**
     * The window's fixtures, tournaments excluded ({@see TeamMatchRecord}).
     * The match section and the data-quality section read the same list.
     *
     * @return list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string}>
     */
    public function fixtures(): array {
        if ( $this->fixtures === null ) {
            $this->fixtures = TeamMatchRecord::fixtures( $this->team_id, $this->from, $this->to );
        }
        return $this->fixtures;
    }

    private static function isDate( string $d ): bool {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }
}
