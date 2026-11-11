<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Teams\TeamRoster;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
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

    /**
     * #4991 — everyone this copy of the report is read by. One reader on
     * screen; a scheduled PDF's owner and every recipient together, where 0
     * stands for a recipient whose rights cannot be checked. Empty is the
     * unattended caller of old (viewer 0). Every reader-dependent read asks
     * against all of them, so the report carries only what each may read.
     *
     * @var list<int>
     */
    public readonly array $readers;

    /**
     * #4991 — composed for a shared copy (`TeamMonthlyReport::forReaders()`),
     * as a scheduled PDF is, rather than for the one reader of a screen.
     */
    private bool $shared;

    private ?bool $reads_injuries = null;

    private ?ReaderSet $rights = null;

    /** @var Window the window a delta is taken against */
    public readonly array $previous;

    /** @var array<string,array<string,mixed>> block key => option bag */
    private array $options;

    private bool $team_loaded = false;
    private ?object $team_row = null;

    private ?bool $reads_attendance = null;

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

    /** @var list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string, played:bool}>|null */
    private ?array $fixtures = null;

    /** @var array{recorded:int, played:int, tournaments:int}|null */
    private ?array $match_minutes_counts = null;

    private ?int $played_tournaments = null;

    /**
     * @param array<string,array<string,mixed>> $options per-block option bags,
     *        block key => bag. Never throws: an option a later version dropped
     *        must still open a saved report.
     * @param list<int>|null $readers #4991 — everyone the report is read by;
     *        null is the viewer alone (none for viewer 0).
     * @throws \InvalidArgumentException on a malformed window.
     */
    public function __construct( int $team_id, string $from, string $to, int $viewer_user_id = 0, array $options = [], ?array $readers = null ) {
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            throw new \InvalidArgumentException( 'from and to must be Y-m-d dates with from <= to.' );
        }

        $this->team_id        = $team_id;
        $this->from           = $from;
        $this->to             = $to;
        $this->viewer_user_id = $viewer_user_id;
        $this->readers        = $readers !== null
            ? array_values( array_unique( array_map( 'intval', $readers ) ) )
            : ( $viewer_user_id > 0 ? [ $viewer_user_id ] : [] );
        $this->shared         = $readers !== null;
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
     * #4949 — may the readers read this team's attendance? The attendance
     * section and the attendance tile follow the attendance read right, the
     * check `GET /reports/attendance-at-risk` asks
     * (`AuthorizationService::canReadTeamAttendance()`), not the reports
     * read right the rest of the report answers to. Asked once per report,
     * of every reader (#4991). An unattended caller (no readers) reads none,
     * the conservative set.
     */
    public function readsAttendance(): bool {
        if ( $this->reads_attendance === null ) {
            $team_id                = $this->team_id;
            $this->reads_attendance = $this->readers !== [] && $this->rights()->every(
                static fn( int $reader ): bool => \TT\Infrastructure\Security\AuthorizationService::canReadTeamAttendance( $reader, $team_id )
            );
        }
        return $this->reads_attendance;
    }

    /**
     * #4991 — the measurement levels the Tests section may show. A shared
     * copy shows only the test levels every reader holds
     * (`ReaderSet::testLevels()`; public only for a reader without rights).
     * The report for one reader on screen shows every level, as it always
     * did: it answers to the team report read rule there.
     *
     * @return list<string>
     */
    public function testLevels(): array {
        return $this->shared ? $this->rights()->testLevels() : RecordVisibility::measurementLevels();
    }

    /** #4991 — the readers' shared rights, worked out once per report. */
    private function rights(): ReaderSet {
        return $this->rights ??= ReaderSet::of( $this->readers );
    }

    /**
     * #4991 — may every reader of a shared copy read the squad's injury
     * records (`player_injuries`, per player)? The roster's injury flag and
     * the count of injured players in "what changed" are injury information,
     * so a shared copy carries them only then. The report for one reader on
     * screen keeps them as it always did: it follows the team report read
     * rule there. Asked once per report.
     */
    public function readsInjuries(): bool {
        if ( ! $this->shared ) return true;
        if ( $this->reads_injuries === null ) {
            $players              = array_keys( $this->players() );
            $this->reads_injuries = $this->rights()->every( static function ( int $reader ) use ( $players ): bool {
                foreach ( $players as $player_id ) {
                    if ( ! \TT\Infrastructure\Security\AuthorizationService::canReadPlayerSection( $reader, (int) $player_id, 'player_injuries' ) ) return false;
                }
                return true;
            } );
        }
        return $this->reads_injuries;
    }

    /**
     * #4991 — the journey visibility levels every reader holds, for "what
     * changed". An unattended caller (no readers) gets the conservative set
     * it always had: public and coaching-staff entries, never medical or
     * safeguarding ones. A reader of 0 holds the public level only.
     *
     * @return list<string>
     */
    public function journeyVisibilities(): array {
        if ( $this->readers === [] ) return [ 'public', 'coaching_staff' ];
        return $this->rights()->journeyLevels();
    }

    /**
     * #4991 — journey entries as every reader may see them: an entry that
     * describes a record of its own (a PDP verdict, an injury, a suspension,
     * a trial) shows only when every reader holds that record's read right.
     * An unattended caller holds none.
     *
     * @param list<object> $rows
     * @return list<object>
     */
    public function readableEntries( array $rows ): array {
        return \TT\Infrastructure\Journey\PlayerEventsRepository::readableByAll( $this->readers, $rows );
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
            // #4945 — the squad in one pass: one statement per input, not
            // one per player.
            $this->verdicts[ $as_of ] = ( new PlayerStatusCalculator() )->calculateMany( array_map( 'intval', array_keys( $this->players() ) ), $as_of );
        }
        return $this->verdicts[ $as_of ];
    }

    /**
     * Every live fixture in the window, tournaments excluded, each with
     * `played` ({@see TeamMatchRecord}). The record is counted over them, as
     * the statistics tab counts it (#4572, #4957).
     *
     * @return list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string, played:bool}>
     */
    public function fixtures(): array {
        if ( $this->fixtures === null ) {
            $this->fixtures = TeamMatchRecord::fixtures( $this->team_id, $this->from, $this->to );
        }
        return $this->fixtures;
    }

    /**
     * #4957 — the played fixtures of {@see fixtures()}: the matches the match
     * section lists and the data-quality section checks. A fixture still to
     * come is not a match missing its opponent or its result.
     *
     * @return list<array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string, played:bool}>
     */
    public function playedFixtures(): array {
        return TeamMatchRecord::played( $this->fixtures() );
    }

    /**
     * #4957 — the minutes-bearing matches played in the window (fixtures and
     * tournament days, `PlayedMatch`), how many of them carry recorded
     * minutes, and how many of the played ones were tournament days. The
     * same counts `MinutesQuery::matchCountsForTeam()` gives every minutes
     * report, so "minutes recorded for N of M played matches" says the same
     * thing here as there. `played` is the match section's played count plus
     * `tournaments`.
     *
     * @return array{recorded:int, played:int, tournaments:int}
     */
    public function matchMinutesCounts(): array {
        if ( $this->match_minutes_counts === null ) {
            $counts = ( new MinutesQuery() )->matchCountsForTeam( $this->team_id, $this->from, $this->to );
            $this->match_minutes_counts = [
                'recorded'    => $counts['recorded'],
                'played'      => $counts['played'],
                'tournaments' => $this->playedTournaments(),
            ];
        }
        return $this->match_minutes_counts;
    }

    /**
     * #4957 — the tournament days played in the window: what the match
     * section leaves out of its record and the minutes section includes.
     */
    public function playedTournaments(): int {
        return $this->played_tournaments ??= ( new ActivitiesRepository() )->tournamentCountInWindow( $this->team_id, $this->from, $this->to );
    }

    private static function isDate( string $d ): bool {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }
}
