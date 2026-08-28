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
 *    and the team's outfield total lands on squad size x match length.
 *    Minutes reporting reads these rows; incoherent ones make every minutes
 *    report look broken.
 */
class MatchDayGenerator implements DependentGeneratorInterface {

    private const HALF_LENGTH = 35;      // youth football, per half

    /**
     * Players on the pitch by age. Youth football is small-sided until the
     * early teens — an under-8 team fields six or seven, not eleven, and a
     * twelve-player squad can never put out an eleven anyway.
     *
     * Keyed by the oldest age the size applies to.
     */
    private const SQUAD_SIZE_BY_AGE = [
        9  => 6,
        12 => 8,
        99 => 11,
    ];

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

        $age_by_team = [];
        foreach ( $this->teams as $t ) {
            $age_by_team[ (int) $t->id ] = isset( $t->age_group ) ? (string) $t->age_group : '';
        }

        $total = 0;
        foreach ( $fixtures as $fixture ) {
            $activity_id = (int) $fixture->id;
            $team_id     = (int) $fixture->team_id;
            $match_date  = (string) $fixture->session_date;
            $roster      = $players_by_team[ $team_id ] ?? [];
            $squad_size  = self::squadSizeFor( $age_by_team[ $team_id ] ?? '' );
            if ( count( $roster ) < $squad_size ) continue;

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
            $optional_absences = [];
            foreach ( $roster as $player_id ) {
                if ( $this->isUnavailableOn( $unavailable, $player_id, $match_date ) ) {
                    $avail_rows[ $player_id ] = [ 'status' => 'Injured', 'reason' => null ];
                    continue;
                }
                // A couple of absences that aren't injuries.
                if ( mt_rand( 1, 100 ) <= 6 ) {
                    $avail_rows[ $player_id ] = [ 'status' => 'Absent', 'reason' => null ];
                    $optional_absences[] = $player_id;
                    continue;
                }
                $avail_rows[ $player_id ] = [ 'status' => 'Present', 'reason' => null ];
                $available[] = $player_id;
            }

            // A twelve-player squad can't afford invented absences: a couple
            // of them and there is no side to pick, so the fixture would
            // silently produce no lineup at all. Injuries are real data and
            // stay; the invented absences give way until a team can be
            // fielded.
            while ( count( $available ) < $squad_size && $optional_absences ) {
                $restored = array_shift( $optional_absences );
                $avail_rows[ $restored ] = [ 'status' => 'Present', 'reason' => null ];
                $available[] = $restored;
            }

            $prep_repo->replaceAvailability( $prep_id, $avail_rows );
            $total += count( $avail_rows );
            $this->tagRowsFor( 'match_prep_availability', 'tt_match_prep_availability', 'match_prep_id', $prep_id );

            if ( count( $available ) < $squad_size ) continue;

            // Starting XI + bench, drawn only from available players.
            $starting = array_slice( $available, 0, $squad_size );
            $bench    = array_slice( $available, $squad_size );

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
        // #2856 — roughly half carry an assist and one in eight has no
        // scorer at all, so the demo academy shows the states a real match
        // produces: an attributed goal, a goal nobody could attribute, and
        // the "needs a scorer" prompt the review raises for the latter.
        for ( $i = 0; $i < $home_goals; $i++ ) {
            $unattributed = mt_rand( 1, 8 ) === 1;
            $scorer = $unattributed ? 0 : (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];

            $assist = null;
            if ( ! $unattributed && count( $starting ) > 1 && mt_rand( 0, 1 ) === 1 ) {
                do {
                    $candidate = (int) $starting[ mt_rand( 0, count( $starting ) - 1 ) ];
                } while ( $candidate === $scorer );
                $assist = $candidate;
            }

            $exec_repo->logGoalEvent(
                $execution_id,
                self::uuid(),
                $scorer,
                mt_rand( 1, 2 ),
                mt_rand( 1, self::HALF_LENGTH ),
                'home',
                $assist,
                false
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

        // #3029 — remember when each swap happened so minutes can be derived
        // from it below. player_id => absolute minute of the change.
        $off_at = [];
        $on_at  = [];

        for ( $i = 0; $i < $subs; $i++ ) {
            $player_off = (int) $off_pool[ $i ];
            $player_on  = (int) $bench[ $i ];
            $minute_in_half = mt_rand( 5, self::HALF_LENGTH - 2 );
            $exec_repo->logSubstitution(
                $execution_id,
                self::uuid(),
                2,                                    // youth subs cluster in the second half
                $minute_in_half,
                $player_off,
                $player_on
            );

            // Second half, so the absolute minute is one full half plus the
            // minute within it.
            $absolute = self::HALF_LENGTH + $minute_in_half;
            $off_at[ $player_off ] = $absolute;
            $on_at[ $player_on ]   = $absolute;
        }
        $this->tagRowsFor( 'match_substitution', 'tt_match_execution_substitutions', 'execution_id', $execution_id );
        $total += $subs;

        $this->writeMinutes( $activity_id, $starting, $off_at, $on_at );

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

    /** Players on the pitch for this age group. */
    private static function squadSizeFor( string $age_group ): int {
        $age = 12;
        if ( preg_match( '/(\d+)/', $age_group, $m ) ) {
            $age = (int) $m[1];
        }
        foreach ( self::SQUAD_SIZE_BY_AGE as $max_age => $size ) {
            if ( $age <= $max_age ) return $size;
        }
        return 11;
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
     * Derive minutes played and write them onto the attendance rows (#3029).
     *
     * The docblock at the top of this class has always claimed that "derived
     * minutes-played never exceeds the match length and the team's outfield
     * total lands on squad size × match length". That was true of the
     * substitution stream, but nobody ever derived the number back onto
     * `tt_attendance.minutes_played` — so every minutes surface was empty on
     * the dataset the product is demonstrated with, because `MinutesQuery`
     * reads only persisted minutes and never estimates at report time
     * (#2193).
     *
     * The arithmetic, from the stream this method is handed:
     *
     *   starter, never replaced   → the full match
     *   starter off at minute m   → m
     *   substitute on at minute m → match length − m
     *   unused bench              → left alone
     *
     * Each swap therefore contributes exactly one match's worth of minutes
     * across the pair, which is what makes the team total reconcile.
     *
     * An unused bench player keeps `NULL` rather than being written to 0.
     * "Did not feature" and "played nothing" are different facts, and a
     * demo dataset that blurs them would teach the wrong thing about a
     * surface whose whole job is minutes distribution.
     *
     * @param int[]           $starting Starting XI player ids.
     * @param array<int,int>  $off_at   player id => absolute minute taken off.
     * @param array<int,int>  $on_at    player id => absolute minute brought on.
     */
    private function writeMinutes( int $activity_id, array $starting, array $off_at, array $on_at ): void {
        global $wpdb;

        $full = self::HALF_LENGTH * 2;

        $minutes = [];
        foreach ( $starting as $player_id ) {
            $player_id = (int) $player_id;
            $minutes[ $player_id ] = isset( $off_at[ $player_id ] )
                ? (int) $off_at[ $player_id ]
                : $full;
        }
        foreach ( $on_at as $player_id => $minute ) {
            $minutes[ (int) $player_id ] = $full - (int) $minute;
        }

        foreach ( $minutes as $player_id => $played ) {
            if ( $played <= 0 ) continue;

            $wpdb->update(
                "{$wpdb->prefix}tt_attendance",
                [ 'minutes_played' => $played ],
                [
                    'club_id'     => CurrentClub::id(),
                    'activity_id' => $activity_id,
                    'player_id'   => $player_id,
                    'record_type' => 'actual',
                ],
                [ '%d' ],
                [ '%d', '%d', '%d', '%s' ]
            );
        }
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
