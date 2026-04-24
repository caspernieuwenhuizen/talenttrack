<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
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
 *   2. Age is derived from the team's age-group label (e.g. JO11 -> 11).
 *      DOB is set to a random day within a 12-month window around the
 *      nominal birth-year.
 *   3. Heights/weights scale with age using a simple lookup table.
 *   4. Preferred foot is drawn from the configured `foot_option` lookup
 *      so whatever the admin has stored (English "Right" / Dutch
 *      "Rechts" / custom) is what ends up in tt_players.preferred_foot.
 *      Uniform distribution across configured options — a richer
 *      reality-reflecting weighting will come when the reference-data
 *      translation feature lands.
 *   5. Archetype is assigned deterministically per player and stored
 *      in tt_demo_tags.extra_json so EvaluationGenerator can read it
 *      back. Distribution:
 *        Rising star 15%, In-a-slump 10%, Steady-solid 30%,
 *        Late bloomer 15%, Inconsistent 15%, New arrival 15%.
 *   6. player1..player5 WP users are bound to the first 5 generated
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

    /** @var string[]|null cached per-request list of foot-option labels from the lookup */
    private ?array $foot_options = null;

    /** @var string[]|null cached per-request list of position labels from the lookup */
    private ?array $position_options = null;

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
                $foot = $this->pickFoot();
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

                // Sync the bound WP user's names to the generated player so
                // every frontend view that reads wp_user->display_name or
                // the first/last user meta reflects the demo identity.
                if ( $wp_user_id > 0 ) {
                    $this->syncUserNames( $wp_user_id, $fn, $ln );
                }

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

    /**
     * Update the bound WP user's first / last / display name so frontend
     * views that read from wp_users show the demo player's identity
     * rather than the generic "Demo Player 1" slot label. user_login
     * and user_email stay put — those are bound to the persistent slot
     * and must not change across regenerates.
     */
    private function syncUserNames( int $user_id, string $first, string $last ): void {
        $display = trim( $first . ' ' . $last );
        wp_update_user( [
            'ID'           => $user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => $display !== '' ? $display : ( 'Demo Player ' . $user_id ),
            'nickname'     => $first !== '' ? $first : 'Demo Player',
        ] );
    }

    private function pickFoot(): string {
        if ( $this->foot_options === null ) {
            $this->foot_options = [];
            foreach ( QueryHelpers::get_lookups( 'foot_option' ) as $row ) {
                $name = trim( (string) $row->name );
                if ( $name !== '' ) $this->foot_options[] = $name;
            }
        }
        if ( ! $this->foot_options ) return '';
        return $this->foot_options[ mt_rand( 0, count( $this->foot_options ) - 1 ) ];
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
        if ( $this->position_options === null ) {
            $this->position_options = [];
            foreach ( QueryHelpers::get_lookups( 'position' ) as $row ) {
                $name = trim( (string) $row->name );
                if ( $name !== '' ) $this->position_options[] = $name;
            }
        }
        if ( ! $this->position_options ) {
            throw new \RuntimeException(
                'No positions configured. Add entries under TalentTrack → Configuration → Positions before generating demo data.'
            );
        }
        $primary = $this->position_options[ mt_rand( 0, count( $this->position_options ) - 1 ) ];
        if ( mt_rand( 0, 100 ) < 40 && count( $this->position_options ) > 1 ) {
            $secondary = $this->position_options[ mt_rand( 0, count( $this->position_options ) - 1 ) ];
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
