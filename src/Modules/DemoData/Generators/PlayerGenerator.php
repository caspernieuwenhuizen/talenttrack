<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;

/**
 * PlayerGenerator — fills each team with age-appropriate players.
 *
 * Structural decisions:
 *
 *   1. Names come from 100×100 Dutch first/last seeds (see
 *      src/Modules/DemoData/seeds/). Duplicates allowed — that's
 *      realistic for a real club.
 *   2. Age is derived from the team's JOxx label (JO11 == 11-year-olds).
 *      DOB is set to a random day within a 12-month window around the
 *      nominal birth-year.
 *   3. Heights/weights scale with age using a simple lookup table.
 *   4. Archetype is assigned deterministically per player and stored
 *      in tt_demo_tags.extra_json so EvaluationGenerator (Checkpoint 2)
 *      can read it back. Distribution per spec:
 *        Rising star 15%, In-a-slump 10%, Steady-solid 30%,
 *        Late bloomer 15%, Inconsistent 15%, New arrival 15%.
 *   5. player1..player5 WP users are bound to the first 5 generated
 *      players via wp_user_id so they can log in and see a real
 *      profile. Bindings are reset on re-run (users survive wipes;
 *      only the binding is transient).
 */
class PlayerGenerator {

    /** Archetype distribution as cumulative weights out of 100. */
    private const ARCHETYPES = [
        [ 15,  'rising_star' ],
        [ 25,  'in_a_slump' ],
        [ 55,  'steady_solid' ],
        [ 70,  'late_bloomer' ],
        [ 85,  'inconsistent' ],
        [ 100, 'new_arrival' ],
    ];

    private const POSITIONS = [ 'GK','CB','LB','RB','CDM','CM','CAM','LW','RW','ST' ];
    private const FEET      = [ 'Right', 'Right', 'Right', 'Left', 'Both' ]; // weighted

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private int $perTeam;

    /**
     * @param object[] $teams {id, name, age_group, head_coach_id}
     * @param array<string,int> $users slot => user id
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $teams,
        array $users,
        int $perTeam = 12
    ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->perTeam  = $perTeam;
    }

    /**
     * @return object[] Inserted player rows.
     */
    public function generate(): array {
        global $wpdb;

        $first = SeedLoader::firstNames();
        $last  = SeedLoader::lastNames();
        if ( ! $first || ! $last ) {
            throw new \RuntimeException( 'Demo name seeds are missing or empty.' );
        }

        // Clear stale player<N> bindings on any pre-existing demo players
        // so only the freshest batch owns those wp_user_id values.
        $prior_ids = DemoBatchRegistry::allEntityIds( 'player' );
        if ( $prior_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $prior_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = 0 WHERE id IN ({$placeholders})",
                ...$prior_ids
            ) );
        }

        $player_binding_slot = 1;
        $all = [];

        foreach ( $this->teams as $team ) {
            $age = $this->ageFromGroup( (string) $team->age_group );
            $used_jerseys = [];

            for ( $i = 0; $i < $this->perTeam; $i++ ) {
                $fn = $first[ mt_rand( 0, count( $first ) - 1 ) ];
                $ln = $last[ mt_rand( 0, count( $last ) - 1 ) ];
                $dob = $this->randomDobForAge( $age );
                $height = $this->heightForAge( $age );
                $weight = $this->weightForAge( $age, $height );
                $foot = self::FEET[ mt_rand( 0, count( self::FEET ) - 1 ) ];
                $pos  = $this->pickPositions();
                $jersey = $this->pickJersey( $used_jerseys );
                $used_jerseys[ $jersey ] = true;

                $wp_user_id = 0;
                if ( $player_binding_slot <= 5 ) {
                    $slot_key = 'player' . $player_binding_slot;
                    $wp_user_id = (int) ( $this->users[ $slot_key ] ?? 0 );
                    $player_binding_slot++;
                }

                $wpdb->insert( "{$wpdb->prefix}tt_players", [
                    'first_name'          => $fn,
                    'last_name'           => $ln,
                    'date_of_birth'       => $dob,
                    'nationality'         => 'NL',
                    'height_cm'           => $height,
                    'weight_kg'           => $weight,
                    'preferred_foot'      => $foot,
                    'preferred_positions' => (string) wp_json_encode( $pos ),
                    'jersey_number'       => $jersey,
                    'team_id'             => (int) $team->id,
                    'date_joined'         => $this->randomJoinDate(),
                    'wp_user_id'          => $wp_user_id,
                    'status'              => 'active',
                ] );
                $player_id = (int) $wpdb->insert_id;

                $archetype = $this->pickArchetype();
                $this->registry->tag( 'player', $player_id, [
                    'archetype'    => $archetype,
                    'team_id'      => (int) $team->id,
                    'bound_slot'   => $wp_user_id > 0 ? 'player' . ( $player_binding_slot - 1 ) : null,
                ] );

                $all[] = (object) [
                    'id'         => $player_id,
                    'team_id'    => (int) $team->id,
                    'archetype'  => $archetype,
                    'wp_user_id' => $wp_user_id,
                ];
            }
        }
        return $all;
    }

    private function ageFromGroup( string $group ): int {
        if ( preg_match( '/(\d+)/', $group, $m ) ) {
            return (int) $m[1];
        }
        return 11;
    }

    private function randomDobForAge( int $age ): string {
        $now  = current_time( 'timestamp' );
        $year = (int) gmdate( 'Y', $now ) - $age;
        $day  = mt_rand( 1, 365 );
        return gmdate( 'Y-m-d', strtotime( "{$year}-01-01 +{$day} days" ) ?: $now );
    }

    private function heightForAge( int $age ): int {
        // Very simple: 110 cm at 6 years, +6 cm/year through 15, then +2/year.
        $base = 110;
        $height = $age <= 15 ? $base + ( $age - 6 ) * 6 : 164 + ( $age - 15 ) * 2;
        return max( 110, $height + mt_rand( -4, 6 ) );
    }

    private function weightForAge( int $age, int $height_cm ): int {
        $bmi = $age < 12 ? 16 : ( $age < 15 ? 18 : 20 );
        $w   = (int) round( $bmi * ( $height_cm / 100 ) * ( $height_cm / 100 ) );
        return max( 20, $w + mt_rand( -3, 4 ) );
    }

    private function pickJersey( array $used ): int {
        for ( $tries = 0; $tries < 30; $tries++ ) {
            $n = mt_rand( 1, 30 );
            if ( ! isset( $used[ $n ] ) ) return $n;
        }
        return mt_rand( 31, 99 );
    }

    /**
     * @return string[]
     */
    private function pickPositions(): array {
        $primary = self::POSITIONS[ mt_rand( 0, count( self::POSITIONS ) - 1 ) ];
        if ( mt_rand( 0, 100 ) < 40 ) {
            $secondary = self::POSITIONS[ mt_rand( 0, count( self::POSITIONS ) - 1 ) ];
            if ( $secondary !== $primary ) return [ $primary, $secondary ];
        }
        return [ $primary ];
    }

    private function pickArchetype(): string {
        $roll = mt_rand( 1, 100 );
        foreach ( self::ARCHETYPES as [ $cut, $name ] ) {
            if ( $roll <= $cut ) return $name;
        }
        return 'steady_solid';
    }

    private function randomJoinDate(): string {
        $days_ago = mt_rand( 0, 365 * 3 );
        return gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) ?: time() );
    }
}
