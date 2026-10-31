<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\PlayerAvailability;
use TT\Modules\MatchExecution\MatchExecutionModule;
use TT\Modules\MatchPrep\MatchPrepModule;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\FormationLayoutResolver;
use TT\Modules\MatchPrep\Services\MatchPrepState;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\TrainingModule;

/**
 * Everything one week's blocks share: the viewer, the window, the teams,
 * the activities, and every source a block reads, each read in one batch
 * for the whole week and only when a block first asks for it.
 *
 * `once()` is the batching rule for new blocks too: wrap a read in it and
 * the week reads that source once, whatever the number of rows.
 */
final class WeekContext {

    /** @var array<string, mixed> */
    private array $memo = [];

    /** @var list<WeekRow>|null */
    private ?array $rows = null;

    /**
     * @param array<int, string>          $teams      team id => name
     * @param list<array<string, mixed>>  $activities the window's activities, repository rows
     * @param array<string, true>         $on         block key => true, when on for at least one device
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $persona,
        public readonly string $today,
        public readonly string $start,
        public readonly string $end,
        public readonly array $teams,
        public readonly array $activities,
        public readonly bool $canEdit,
        private readonly array $on
    ) {}

    /** The block is on for at least one device of this viewer's persona. */
    public function isOn( string $block_key ): bool {
        return isset( $this->on[ $block_key ] );
    }

    /**
     * The value `$read` returns, read once for this week.
     *
     * @template T
     * @param callable():T $read
     * @return T
     */
    public function once( string $key, callable $read ) {
        if ( ! array_key_exists( $key, $this->memo ) ) {
            $this->memo[ $key ] = $read();
        }
        return $this->memo[ $key ];
    }

    /** @return list<WeekRow> */
    public function rows(): array {
        if ( $this->rows === null ) {
            $this->rows = [];
            foreach ( $this->activities as $a ) $this->rows[] = new WeekRow( $a, $this->today );
        }
        return $this->rows;
    }

    /** @return list<int> */
    public function activityIds(): array {
        return array_map( static fn( WeekRow $r ): int => $r->activityId, $this->rows() );
    }

    public function moduleOn( string $module_class ): bool {
        return $this->once( 'module:' . $module_class, static fn(): bool => ModuleRegistry::isEnabled( $module_class ) );
    }

    public function prepOn(): bool {
        return $this->moduleOn( MatchPrepModule::class );
    }

    public function executionOn(): bool {
        return $this->moduleOn( MatchExecutionModule::class );
    }

    /** At least one recorded attendance row on this activity. */
    public function recorded( int $activity_id ): bool {
        $this->once( 'register', function (): bool {
            ActivityRegisterProgress::prime( array_map( static fn( array $a ): object => (object) $a, $this->activities ) );
            return true;
        } );
        return ActivityRegisterProgress::recordedCount( $activity_id ) > 0;
    }

    /**
     * Team id => the ids of its current players, one query for every team.
     *
     * @return list<int>
     */
    public function roster( int $team_id ): array {
        $all = $this->once( 'rosters', function (): array {
            $out = [];
            foreach ( QueryHelpers::get_players_for_teams( array_keys( $this->teams ) ) as $player ) {
                $fields = (array) $player;
                $pid    = (int) ( $fields['id'] ?? 0 );
                if ( $pid > 0 ) $out[ (int) ( $fields['team_id'] ?? 0 ) ][] = $pid;
            }
            return $out;
        } );
        return $all[ $team_id ] ?? [];
    }

    /**
     * Planned squad against the roster, or null when no plan was captured.
     * A stored Absent is "not coming" and Excused is "maybe"; anything else
     * is expected, as `GET activities/{id}/planned-attendance` reads it.
     *
     * @return array{expected:int, roster:int}|null
     */
    public function planned( WeekRow $row ): ?array {
        $counts   = $this->once( 'planned', fn(): array => ( new ActivitiesRepository() )->plannedStatusCounts( $this->activityIds() ) );
        $statuses = $counts[ $row->activityId ] ?? [];
        if ( $statuses === [] ) return null;
        $expected = 0;
        foreach ( $statuses as $status => $n ) {
            $canonical = AttendanceStatus::normalise( (string) $status );
            if ( $canonical === AttendanceStatus::ABSENT || $canonical === AttendanceStatus::EXCUSED ) continue;
            $expected += $n;
        }
        return [ 'expected' => $expected, 'roster' => count( $this->roster( $row->teamId ) ) ];
    }

    /** Whether a training-plan run is attached; null when the Training module is off. */
    public function hasTrainingRun( int $activity_id ): ?bool {
        if ( ! $this->moduleOn( TrainingModule::class ) ) return null;
        $runs = $this->once( 'runs', fn(): array => ( new TrainingPlanRunsRepository() )->activityIdsWithRun( $this->activityIds() ) );
        return isset( $runs[ $activity_id ] );
    }

    /**
     * The matches still to play, as facts per activity id.
     *
     * @return array<int, array{team_id:int, type:string, date:string, player_ids:list<int>}>
     */
    public function openMatches(): array {
        return $this->once( 'open_matches', function (): array {
            $out = [];
            foreach ( $this->rows() as $row ) {
                if ( ! $row->isOpenMatch() ) continue;
                $out[ $row->activityId ] = [
                    'team_id'    => $row->teamId,
                    'type'       => $row->type,
                    'date'       => $row->date,
                    'player_ids' => $this->roster( $row->teamId ),
                ];
            }
            return $out;
        } );
    }

    /** Players of the squad who cannot play in this match (counted, never named in the payload). */
    public function unavailableCount( int $activity_id ): int {
        $all = $this->once( 'unavailable', function (): array {
            $matches = $this->openMatches();
            return $matches !== [] ? PlayerAvailability::unavailableForActivities( $matches ) : [];
        } );
        return count( $all[ $activity_id ] ?? [] );
    }

    /**
     * First-half starters placed against the formation's target, or null
     * when the match has no prep or the Match prep module is off.
     *
     * @return array{placed:int, target:int}|null
     */
    public function prepProgress( int $activity_id ): ?array {
        if ( ! $this->prepOn() ) return null;
        $all = $this->once( 'prep', function (): array {
            $matches = $this->openMatches();
            return $matches !== [] ? self::prepProgressFor( $matches ) : [];
        } );
        return $all[ $activity_id ] ?? null;
    }

    /**
     * Per match with a prep: first-half starters placed on the slots the
     * formation draws, against the formation's starter target. The same
     * count `MatchPrepRepository::listLineupForPrep()` and
     * `MatchPrepState::starterTarget()` give one match at a time, read in
     * two queries for the week, with each formation's layout resolved once.
     *
     * @param array<int, array{team_id:int, type:string, date:string, player_ids:list<int>}> $matches activity id => facts
     * @return array<int, array{placed:int, target:int}> activity id => progress; absent when no prep
     */
    private static function prepProgressFor( array $matches ): array {
        $repo  = new MatchPrepRepository();
        $preps = $repo->findByActivities( array_keys( $matches ) );
        if ( $preps === [] ) return [];

        $prep_ids = [];
        foreach ( $preps as $prep ) $prep_ids[] = (int) ( ( (array) $prep )['id'] ?? 0 );
        $lineups = $repo->listLineupForPreps( $prep_ids );

        $layouts = [];
        $out     = [];
        foreach ( $preps as $aid => $prep ) {
            $p           = (array) $prep;
            $template_id = (int) ( $p['formation_template_id'] ?? 0 );
            $team_id     = (int) ( $matches[ $aid ]['team_id'] ?? 0 );
            $key         = $template_id . '|' . $team_id;
            if ( ! isset( $layouts[ $key ] ) ) {
                $drawn = [];
                foreach ( FormationLayoutResolver::layoutFor( $template_id, $team_id ) as $slot ) {
                    $drawn[ (int) $slot['num'] ] = true;
                }
                $layouts[ $key ] = $drawn;
            }
            $drawn  = $layouts[ $key ];
            $target = $drawn !== [] ? count( $drawn ) : MatchPrepState::FALLBACK_STARTER_TARGET;

            $placed = 0;
            foreach ( $lineups[ (int) ( $p['id'] ?? 0 ) ] ?? [] as $slot ) {
                $s = (array) $slot;
                if ( (int) ( $s['half'] ?? 0 ) !== 1 || (int) ( $s['player_id'] ?? 0 ) <= 0 ) continue;
                if ( isset( $drawn[ (int) ( $s['slot_number'] ?? 0 ) ] ) ) $placed++;
            }
            $out[ (int) $aid ] = [ 'placed' => min( $placed, $target ), 'target' => $target ];
        }
        return $out;
    }
}
