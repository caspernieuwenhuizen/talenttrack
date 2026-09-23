<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoAnthropometry;
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
 *   7. Media consent (#3846) is stated on every player rather than left
 *      to the column default. Every fifth player in a squad has none on
 *      record, so a generated academy carries both states and the
 *      dossier check has something to find. Positional, not random —
 *      see hasMediaConsent().
 */
class PlayerGenerator implements GeneratorInterface {

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

    private int $weeks;

    public static function category(): string {
        return 'players';
    }

    /**
     * @param object[] $teams {id, name, age_group, head_coach_user_id}
     * @param array<string,int> $users slot => user id
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $teams,
        array $users,
        int $perTeam = 12,
        int $weeks = 8
    ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->perTeam  = $perTeam;
        $this->weeks    = max( 1, $weeks );
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
            $params = array_merge( $prior_ids, [ CurrentClub::id() ] );
            // #1772 — unlink via NULL, not 0; multiple 0s would now
            // collide on the UNIQUE (club_id, wp_user_id) index.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = NULL WHERE id IN ({$placeholders}) AND club_id = %d",
                ...$params
            ) );
        }

        $player_binding_slot = 1;
        $all = [];

        foreach ( $this->teams as $team ) {
            $team_id = (int) $team->id;
            $age     = $this->ageFromGroup( (string) $team->age_group );
            $used_jerseys = [];
            $recorder = $this->consentRecorderFor( $team );

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

                $date_joined = $this->randomJoinDate();
                $consented   = $this->hasMediaConsent( $i, $this->perTeam );
                $wpdb->insert( "{$wpdb->prefix}tt_players", [
                    'club_id'             => CurrentClub::id(),
                    'first_name'          => $fn,
                    'last_name'           => $ln,
                    'date_of_birth'       => $dob,
                    // #2894 — demo players carry a sex so the BMI-for-age
                    // report (#2895) has something to read on a generated
                    // academy. Every tenth is left blank on purpose: the
                    // blank path is the one most likely to be forgotten, and
                    // a demo set where every record is populated hides it.
                    'sex'                 => ( $i % 10 === 0 )
                        ? \TT\Domain\Vocabularies\Lookups\PlayerSex::NONE
                        : ( ( $i % 2 === 0 )
                            ? \TT\Domain\Vocabularies\Lookups\PlayerSex::MALE
                            : \TT\Domain\Vocabularies\Lookups\PlayerSex::FEMALE ),
                    'nationality'         => 'NL',
                    'height_cm'           => $height,
                    'weight_kg'           => $weight,
                    'preferred_foot'      => $foot,
                    'preferred_positions' => (string) wp_json_encode( $pos ),
                    'jersey_number'       => $jersey,
                    'team_id'             => $team_id,
                    'date_joined'         => $date_joined,
                    // #1772 — NULL (not 0) for an unbound demo player so
                    // the UNIQUE (club_id, wp_user_id) index holds.
                    'wp_user_id'          => $wp_user_id > 0 ? $wp_user_id : null,
                    'status'              => PlayerStatus::ACTIVE,
                    // #3846 — consent is stated, not left to the column
                    // default. A demo where every child is unconsented
                    // cannot show the field doing anything, and it ships
                    // a dossier state that would be a real problem in a
                    // real club. Provenance is written only alongside a
                    // yes: a recorded date under "no consent" would be a
                    // contradiction the surfaces have to guess about.
                    'media_consent'       => $consented ? 1 : 0,
                    'media_consent_at'    => $consented ? $date_joined . ' 09:00:00' : null,
                    'media_consent_by'    => $consented && $recorder > 0 ? $recorder : null,
                ] );
                $player_id = (int) $wpdb->insert_id;

                // v3.91.7 — fire the same hook the runtime player-create
                // path fires so JourneyEventSubscriber writes a
                // `joined_academy` event for this player. Without this
                // hook, demo runs leave `tt_player_events` empty.
                if ( $player_id > 0 ) {
                    do_action( 'tt_player_created', $player_id, [
                        'date_joined' => $date_joined,
                        'team_id'     => $team_id,
                        'status'      => PlayerStatus::ACTIVE,
                    ] );
                }

                // Sync the bound WP user's names to the generated player so
                // every frontend view that reads wp_user->display_name or
                // the first/last user meta reflects the demo identity.
                if ( $wp_user_id > 0 ) {
                    $this->syncUserNames( $wp_user_id, $fn, $ln );
                }

                $archetype = $this->pickArchetype();
                $this->registry->tag( 'player', $player_id, [
                    'archetype'    => $archetype,
                    'team_id'      => $team_id,
                    'bound_slot'   => $wp_user_id > 0 ? 'player' . ( $player_binding_slot - 1 ) : null,
                ] );

                $all[] = (object) [
                    'id'         => $player_id,
                    'team_id'    => $team_id,
                    'archetype'  => $archetype,
                    'wp_user_id' => $wp_user_id,
                ];
            }

            foreach ( $this->generateDeparted( $team_id, $age + 1, $first, $last ) as $gone ) {
                $all[] = $gone;
            }
        }
        return $all;
    }

    /**
     * #3846 — does this player's family have media consent on record?
     *
     * Every fifth player in a squad does not. The rule is positional, not
     * random, because `run_order` is a reproducibility contract: the same
     * (seed, preset) has to produce the same academy, and a consent flag
     * drawn from the shared MT stream would both move with any upstream
     * change and shift every value after it.
     *
     * The point of the mix is that a demo academy has to be able to show
     * both states. A squad where every child is consented cannot
     * demonstrate the dossier gap an administrator checks for; one where
     * nobody is cannot show the field doing anything at all.
     *
     * @param int $index      0-based position in the squad
     * @param int $squad_size how many players that squad holds
     */
    private function hasMediaConsent( int $index, int $squad_size ): bool {
        // A squad of one has no room for both states; consent is the
        // less surprising of the two to show on its own.
        if ( $squad_size <= 1 ) return true;
        // Under five, "every fifth" would never fire — put the
        // unconsented case last so every team still carries one.
        if ( $squad_size < 5 ) return $index !== $squad_size - 1;
        return ( $index % 5 ) !== 4;
    }

    /**
     * #3846 — the staff member recorded as having taken the consent.
     *
     * The team's head coach is who a club would name; the academy
     * administrator is the fallback for a squad with no coach on record,
     * and the operator running the generation is the last resort so a
     * consented player always carries a recorder as well as a date.
     *
     * @param object|null $team a generated team row, or null off-squad
     */
    private function consentRecorderFor( ?object $team ): int {
        $coach = (int) ( $team->head_coach_user_id ?? 0 );
        if ( $coach > 0 ) return $coach;

        $admin = (int) ( $this->users['admin'] ?? 0 );
        if ( $admin > 0 ) return $admin;

        return (int) get_current_user_id();
    }

    /**
     * #3402 — the squad has to change between seasons.
     *
     * A handful of players per team left the academy at the end of the last
     * completed season. They are `released`, so they are off every current
     * roster and out of `DemoGenerator::loadPlayers()`; what they leave
     * behind is the history the window covers — the trainings they attended,
     * the evaluations written about them, and the dossier whose verdict is
     * the release itself. A roster that never moves is the one thing every
     * academy would notice as false.
     *
     * Nothing is generated when the window covers a single season: a squad
     * cannot have changed between seasons there are not two of.
     *
     * @param int      $age   a year above this season's squad — they were in
     *                        this age group when they left, and that was at
     *                        least a season ago
     * @param string[] $first
     * @param string[] $last
     * @return list<object>
     */
    private function generateDeparted( int $team_id, int $age, array $first, array $last ): array {
        global $wpdb;

        if ( ( new \TT\Modules\DemoData\DemoCalendar( $this->weeks ) )->seasonCount() < 2 ) {
            return [];
        }

        // A quarter of a squad's worth, which is roughly the churn a youth
        // academy has between seasons.
        $count = max( 1, (int) floor( $this->perTeam / 4 ) );

        // A departed player's consent was taken by the academy
        // administrator — `generateDeparted()` works from a team id, not
        // the team row the coach hangs off.
        $recorder = $this->consentRecorderFor( null );

        $out = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $fn = $first[ mt_rand( 0, count( $first ) - 1 ) ];
            $ln = $last[ mt_rand( 0, count( $last ) - 1 ) ];

            $height      = $this->heightForAge( $age );
            $date_joined = gmdate( 'Y-m-d', strtotime( '-' . ( $this->weeks + 52 ) . ' weeks' ) ?: time() );
            $consented   = $this->hasMediaConsent( $i, $count );
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'             => CurrentClub::id(),
                'first_name'          => $fn,
                'last_name'           => $ln,
                'date_of_birth'       => $this->randomDobForAge( $age ),
                'sex'                 => \TT\Domain\Vocabularies\Lookups\PlayerSex::MALE,
                'nationality'         => 'NL',
                'height_cm'           => $height,
                'weight_kg'           => $this->weightForAge( $age, $height ),
                'preferred_foot'      => $this->pickFoot(),
                'preferred_positions' => (string) wp_json_encode( $this->pickPositions() ),
                'jersey_number'       => null,
                'team_id'             => $team_id,
                'date_joined'         => $date_joined,
                'wp_user_id'          => null,
                'status'              => PlayerStatus::RELEASED,
                // #3846 — a departed player states their consent too. It
                // is what the club held while they were here, and it is
                // what a dossier check finds when it reaches back.
                'media_consent'       => $consented ? 1 : 0,
                'media_consent_at'    => $consented ? $date_joined . ' 09:00:00' : null,
                'media_consent_by'    => $consented && $recorder > 0 ? $recorder : null,
            ] );
            $player_id = (int) $wpdb->insert_id;
            if ( $player_id <= 0 ) continue;

            // The same two hooks the real create-then-release path fires, so
            // the timeline carries both the arrival and the departure rather
            // than a player who appears already gone.
            do_action( 'tt_player_created', $player_id, [
                'team_id' => $team_id,
                'status'  => PlayerStatus::ACTIVE,
            ] );
            do_action(
                'tt_player_save_diff',
                $player_id,
                [ 'status' => PlayerStatus::ACTIVE ],
                [ 'status' => PlayerStatus::RELEASED ]
            );

            $this->registry->tag( 'player', $player_id, [
                'archetype' => \TT\Modules\DemoData\DemoRoster::ARCHETYPE_DEPARTED,
                'team_id'   => $team_id,
            ] );

            $out[] = (object) [
                'id'         => $player_id,
                'team_id'    => $team_id,
                'archetype'  => \TT\Modules\DemoData\DemoRoster::ARCHETYPE_DEPARTED,
                'wp_user_id' => 0,
            ];
        }
        return $out;
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

    /**
     * #4036 — the body model lives in `DemoAnthropometry` now, because the
     * measurement battery derives its Height and Weight bands from the same
     * curve. Two copies is how a U7 record said 114 cm while their test
     * history said 117.5.
     */
    private function heightForAge( int $age ): int {
        return DemoAnthropometry::heightForAge( $age );
    }

    private function weightForAge( int $age, int $height_cm ): int {
        return DemoAnthropometry::weightForAge( $age, $height_cm );
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
