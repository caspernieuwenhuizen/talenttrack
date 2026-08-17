<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchDayGenerator — turns a generated fixture into a match.
 *
 * Prep for every fixture (availability, lineup, roles, per-player intent);
 * execution for the ones already played (score, goal events, substitutions,
 * a light tracked-event stream). Future fixtures get prep and no execution,
 * which is what a coach's screen looks like mid-week.
 *
 * Two consistency rules matter more than the volume here:
 *
 *  - **Availability agrees with the injury record.** A player marked
 *    available on a date they were injured is a visible contradiction
 *    between two screens.
 *  - **Minutes reconcile.** Substitutions are drawn against the starting XI
 *    and the bench, so derived minutes-played never exceeds the match length
 *    and the team's outfield total lands on 11 x match length. Minutes
 *    reporting reads these rows; incoherent ones make every minutes report
 *    look broken.
 */
class MatchDayGenerator implements DependentGeneratorInterface {

    private const HALF_LENGTH = 35;      // youth football, per half
    private const SQUAD_SIZE  = 11;

    /** Roles the prep screen assigns. */
    private const ROLES = [ 'captain', 'penalties', 'corners', 'free_kicks' ];

    /** Tracked actions, used when the club has no configured action list. */
    private const FALLBACK_ACTIONS = [
        'shot_on_target' => 'Shot on target',
        'key_pass'       => 'Key pass',
        'duel_won'       => 'Duel won',
        'turnover'       => 'Turnover',
    ];

    /** @var array<string, array{general:string, attack:string, defend:string, attention:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'general'   => 'Play out from the back, stay compact when we lose it.',
            'attack'    => 'Switch the play early and attack the far post.',
            'defend'    => 'Press as a unit; first defender sets the angle.',
            'attention' => 'Look for the forward pass before playing back.',
        ],
        'nl_NL' => [
            'general'   => 'Van achteruit opbouwen, compact blijven bij balverlies.',
            'attack'    => 'Snel het spel verleggen en de tweede paal aanvallen.',
            'defend'    => 'Als team druk zetten; de eerste verdediger bepaalt de hoek.',
            'attention' => 'Zoek eerst de voorwaartse pass voordat je terugspeelt.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    public static function category(): string {
        return 'match_day';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->teams, $ctx->users, $ctx->contentLanguage );
    }

    /**
     * @param object[] $players
     * @param object[] $teams
     * @param array<string,int> $users
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        array $users,
        string $language = ''
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $fixtures = $this->demoFixtures();
        if ( ! $fixtures ) return 0;

        $prep_repo = new MatchPrepRepository();
        $exec_repo = new MatchExecutionRepository();
        $copy      = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $author    = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $unavailable = InjuryGenerator::unavailabilityByPlayer();

        $players_by_team = [];
        foreach ( $this->players as $p ) {
            $players_by_team[ (int) ( $p->team_id ?? 0 ) ][] = (int) $p->id;
        }

        $total = 0;
        foreach ( $fixtures as $fixture ) {
            $activity_id = (int) $fixture->id;
            $team_id     = (int) $fixture->team_id;
            $match_date  = (string) $fixture->session_date;
            $roster      = $players_by_team[ $team_id ] ?? [];
            if ( count( $roster ) < self::SQUAD_SIZE ) continue;

            $prep_id = $prep_repo->ensureForActivity( $activity_id, self::HALF_LENGTH );
            if ( $prep_id <= 0 ) continue;
            $this->registry->tag( 'match_prep', $prep_id, [ 'activity_id' => $activity_id ] );
            $total++;

            $prep_repo->updatePrep( $prep_id, [
                'goals_general' => $copy['general'],
                'goals_attack'  => $copy['attack'],
                'goals_defend'  => $copy['defend'],
                'created_by'    => $author,
            ] );

            // Availability first — the injured list decides who can be picked.
            $available = [];
            $avail_rows = [];
            foreach ( $roster as $player_id ) {
                $injured = $this->isUnavailableOn( $unavailable, $player_id, $match_date );
                if ( $injured ) {
                    $avail_rows[ $player_id ] = [ 'status' => 'Injured', 'reason' => null ];
                    continue;
                }
                // A couple of absences that aren't injuries.
                if ( mt_rand( 1, 100 ) <= 6 ) {
                    $avail_rows[ $player_id ] = [ 'status' => 'Absent', 'reason' => null ];
                    continue;
                }
                $avail_rows[ $player_id ] = [ 'status' => 'Present', 'reason' => null ];
                $available[] = $player_id;
            }
            $prep_repo->replaceAvailability( $prep_id, $avail_rows );
            $total += count( $avail_rows );
            $this->tagRowsFor( 'match_prep_availability', 'tt_match_prep_availability', 'match_prep_id', $prep_id );

            if ( count( $available ) < self::SQUAD_SIZE ) continue;

            // Starting XI + bench, drawn only from available players.
            $starting = array_slice( $available, 0, self::SQUAD_SIZE );
            $bench    = array_slice( $available, self::SQUAD_SIZE );

            $slots = [];
            foreach ( $starting as $i => $player_id ) {
                $slots[ $i + 1 ] = $player_id;
            }
            $prep_repo->replaceLineupForHalf( $prep_id, 1, $slots );
            $prep_repo->replaceLineupForHalf( $prep_id, 2, $slots );
            $this->tagRowsFor( 'match_prep_lineup', 'tt_match_prep_lineup', 'match_prep_id', $prep_id );
            $total += count( $slots ) * 2;

            foreach ( self::ROLES as $role_key ) {
                $prep_repo->setRole( $prep_id, $role_key, (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ] );
            }
            $this->tagRowsFor( 'match_prep_role', 'tt_match_prep_roles', 'match_prep_id', $prep_id );
            $total += count( self::ROLES );

            // Per-player intent on about half the squad.
            $goal_rows = [];
            foreach ( $starting as $player_id ) {
                if ( mt_rand( 1, 100 ) > 50 ) continue;
                $goal_rows[ $player_id ] = [
                    'attention_text'    => $copy['attention'],
                    'is_specific_goal'  => 1,
                    'analyst_appointed' => 0,
                ];
            }
            if ( $goal_rows ) {
                $prep_repo->replacePlayerGoals( $prep_id, $goal_rows );
                $this->tagRowsFor( 'match_prep_player_goal', 'tt_match_prep_player_goals', 'match_prep_id', $prep_id );
                $total += count( $goal_rows );
            }

            // Future fixtures stop at prep.
            if ( strtotime( $match_date ) > time() ) continue;

            $total += $this->generateExecution( $exec_repo, $activity_id, $prep_id, $match_date, $starting, $bench, $author );
        }

        return $total;
    }

    /**
     * Score, goal events, substitutions and a light tracked-event stream.
     *
     * @param int[] $starting
     * @param int[] $bench
     */
    private function generateExecution(
        MatchExecutionRepository $exec_repo,
        int $activity_id,
        int $prep_id,
        string $match_date,
        array $starting,
        array $bench,
        int $author
    ): int {
        global $wpdb;

        $execution_id = $exec_repo->ensureForActivity( $activity_id, $prep_id );
        if ( $execution_id <= 0 ) return 0;
        $this->registry->tag( 'match_execution', $execution_id, [ 'activity_id' => $activity_id ] );
        $total = 1;

        $kickoff = strtotime( $match_date . ' 10:30:00' ) ?: time();
        $half_seconds = self::HALF_LENGTH * MINUTE_IN_SECONDS;

        // Realistic youth scorelines: mostly 0–4 a side.
        $home_goals = $this->drawGoals();
        $away_goals = $this->drawGoals();

        $exec_repo->update( $execution_id, [
            'state'                   => 'finished',
            'first_half_started_at'   => gmdate( 'Y-m-d H:i:s', $kickoff ),
            'first_half_ended_at'     => gmdate( 'Y-m-d H:i:s', $kickoff + $half_seconds ),
            'second_half_started_at'  => gmdate( 'Y-m-d H:i:s', $kickoff + $half_seconds + ( 15 * MINUTE_IN_SECONDS ) ),
            'second_half_ended_at'    => gmdate( 'Y-m-d H:i:s', $kickoff + ( 2 * $half_seconds ) + ( 15 * MINUTE_IN_SECONDS ) ),
            'first_half_pause_seconds'  => 0,
            'second_half_pause_seconds' => 0,
            'home_score'              => $home_goals,
            'away_score'              => $away_goals,
            'created_by'              => $author,
        ] );

        // Our goals get a scorer from the XI; the opponent's don't.
        for ( $i = 0; $i < $home_goals; $i++ ) {
            $scorer = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
            $exec_repo->logGoalEvent(
                $execution_id,
                self::uuid(),
                $scorer,
                mt_rand( 1, 2 ),
                mt_rand( 1, self::HALF_LENGTH ),
                'home'
            );
        }
        for ( $i = 0; $i < $away_goals; $i++ ) {
            $exec_repo->logGoalEvent( $execution_id, self::uuid(), 0, mt_rand( 1, 2 ), mt_rand( 1, self::HALF_LENGTH ), 'away' );
        }
        $this->tagRowsFor( 'match_goal_event', 'tt_match_execution_goal_events', 'execution_id', $execution_id );
        $total += $home_goals + $away_goals;

        // Substitutions: each bench player replaces a distinct starter, so a
        // player is never on the pitch twice and minutes stay coherent.
        $subs = min( count( $bench ), mt_rand( 2, 5 ) );
        $off_pool = $starting;
        shuffle( $off_pool );
        for ( $i = 0; $i < $subs; $i++ ) {
            $player_off = (int) $off_pool[ $i ];
            $player_on  = (int) $bench[ $i ];
            $exec_repo->logSubstitution(
                $execution_id,
                self::uuid(),
                2,                                    // youth subs cluster in the second half
                mt_rand( 5, self::HALF_LENGTH - 2 ),
                $player_off,
                $player_on
            );
        }
        $this->tagRowsFor( 'match_substitution', 'tt_match_execution_substitutions', 'execution_id', $execution_id );
        $total += $subs;

        // A light tracked-event stream — enough to populate the feed without
        // pretending a youth match was fully scouted.
        $actions = $this->trackedActions();
        $events  = mt_rand( 6, 14 );
        for ( $i = 0; $i < $events; $i++ ) {
            $player_id = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
            $key       = (string) array_rand( $actions );
            $wpdb->insert( "{$wpdb->prefix}tt_match_execution_tracked_events", [
                'event_uuid'     => self::uuid(),
                'club_id'        => CurrentClub::id(),
                'execution_id'   => $execution_id,
                'player_id'      => $player_id,
                'half'           => mt_rand( 1, 2 ),
                'minute_in_half' => mt_rand( 1, self::HALF_LENGTH ),
                'action_key'     => $key,
                'action_label'   => (string) $actions[ $key ],
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'match_tracked_event', $id );
                $total++;
            }
        }

        return $total;
    }

    /** Youth scorelines skew low; blowouts are rare but not impossible. */
    private function drawGoals(): int {
        $roll = mt_rand( 1, 100 );
        if ( $roll <= 18 ) return 0;
        if ( $roll <= 42 ) return 1;
        if ( $roll <= 66 ) return 2;
        if ( $roll <= 84 ) return 3;
        if ( $roll <= 95 ) return 4;
        return mt_rand( 5, 7 );
    }

    /**
     * True when the player was injured on the fixture date, per the injury
     * rows this batch generated.
     *
     * @param array<int, array<int, array{0:string, 1:?string}>> $unavailable
     */
    private function isUnavailableOn( array $unavailable, int $player_id, string $date ): bool {
        foreach ( $unavailable[ $player_id ] ?? [] as [ $start, $end ] ) {
            if ( $date < $start ) continue;
            if ( $end === null || $date <= $end ) return true;
        }
        return false;
    }

    /** @return array<string,string> action key => label */
    private function trackedActions(): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( 'football_action' ) as $item ) {
            $out[ (string) $item->name ] = (string) $item->name;
        }
        return $out ?: self::FALLBACK_ACTIONS;
    }

    /**
     * Tag every untagged row a repository just wrote for one parent, so rows
     * the domain layer inserted are still reachable by the wipe.
     */
    private function tagRowsFor( string $entity_type, string $table, string $parent_column, int $parent_id ): void {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.id FROM {$wpdb->prefix}{$table} t
               LEFT JOIN {$wpdb->prefix}tt_demo_tags d
                      ON d.entity_type = %s AND d.entity_id = t.id AND d.club_id = %d
              WHERE t.{$parent_column} = %d AND t.club_id = %d AND d.id IS NULL",
            $entity_type, CurrentClub::id(), $parent_id, CurrentClub::id()
        ) );
        foreach ( (array) $ids as $id ) {
            $this->registry->tag( $entity_type, (int) $id );
        }
    }

    /**
     * Generated fixtures — game-type activities from this batch.
     *
     * @return object[]
     */
    private function demoFixtures(): array {
        global $wpdb;

        $ids = $this->registry->entityIds( 'activity' );
        if ( ! $ids ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, team_id, session_date FROM {$wpdb->prefix}tt_activities
              WHERE id IN ({$placeholders}) AND club_id = %d AND activity_type_key = 'game'
              ORDER BY session_date",
            ...array_merge( $ids, [ CurrentClub::id() ] )
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function uuid(): string {
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::COPY_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::COPY_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
