<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Measurements\Units\Dimensions;
use TT\Modules\Measurements\Units\UnitContext;
use TT\Modules\Measurements\Units\UnitRegistry;

/**
 * MeasurementGenerator — the testing battery, its target bands, the team
 * sessions and one result per player per session.
 *
 * Migration 0175 seeds the category and unit vocabularies but no tests, so a
 * fresh install has nothing to schedule and nothing to record. This writes
 * the battery an academy would actually run.
 *
 * Results carry a per-player trend across the window plus noise. That matters
 * more than the absolute values: a flat or purely random series makes the
 * progression charts meaningless, which is the one thing the measurements
 * module exists to show.
 */
class MeasurementGenerator implements DependentGeneratorInterface {

    /**
     * The battery. `direction` says which way is better — a sprint time
     * improves downwards, a jump upwards. Getting it backwards inverts every
     * target-band status on screen, so it is spelled out per test rather
     * than inferred.
     *
     * `base` is a 12-year-old's typical value; `per_year` shifts it by age,
     * and `improve` is the within-season gain a player makes.
     *
     * @var array<int, array{category:string, unit:string, name_en:string, name_nl:string, value_type:string, direction:string, frequency:string, base:float, per_year:float, spread:float, improve:float, decimals:int}>
     */
    private const BATTERY = [
        [
            'category' => 'Anthropometric', 'unit' => 'cm',
            'name_en' => 'Height', 'name_nl' => 'Lengte',
            'value_type' => 'numeric', 'direction' => 'neutral', 'frequency' => 'quarterly',
            'base' => 150.0, 'per_year' => 6.5, 'spread' => 7.0, 'improve' => 1.5, 'decimals' => 1,
        ],
        [
            'category' => 'Anthropometric', 'unit' => 'kg',
            'name_en' => 'Weight', 'name_nl' => 'Gewicht',
            'value_type' => 'numeric', 'direction' => 'neutral', 'frequency' => 'quarterly',
            'base' => 40.0, 'per_year' => 5.0, 'spread' => 6.0, 'improve' => 1.0, 'decimals' => 1,
        ],
        [
            'category' => 'Physical', 'unit' => 's',
            'name_en' => '10 m sprint', 'name_nl' => 'Sprint 10 m',
            'value_type' => 'numeric', 'direction' => 'lower', 'frequency' => 'quarterly',
            'base' => 2.05, 'per_year' => -0.04, 'spread' => 0.15, 'improve' => -0.06, 'decimals' => 2,
        ],
        [
            'category' => 'Physical', 'unit' => 's',
            'name_en' => '30 m sprint', 'name_nl' => 'Sprint 30 m',
            'value_type' => 'numeric', 'direction' => 'lower', 'frequency' => 'quarterly',
            'base' => 5.10, 'per_year' => -0.10, 'spread' => 0.30, 'improve' => -0.12, 'decimals' => 2,
        ],
        [
            // #3273 — a duration test, so the demo install exercises mm:ss
            // entry, the seconds-canonical storage and the minute→second
            // conversion instead of only unit factors of 1.
            'category' => 'Physical', 'unit' => 'min', 'numeric_format' => 'duration',
            'name_en' => '1500 m run', 'name_nl' => 'Loop 1500 m',
            'value_type' => 'numeric', 'direction' => 'lower', 'frequency' => 'biannual',
            'base' => 7.2, 'per_year' => -0.18, 'spread' => 0.9, 'improve' => -0.15, 'decimals' => 2,
        ],
        [
            'category' => 'Physical', 'unit' => 'cm',
            'name_en' => 'Countermovement jump', 'name_nl' => 'Verticale sprong',
            'value_type' => 'numeric', 'direction' => 'higher', 'frequency' => 'quarterly',
            'base' => 28.0, 'per_year' => 2.2, 'spread' => 5.0, 'improve' => 2.0, 'decimals' => 1,
        ],
        [
            'category' => 'Physical', 'unit' => 'level',
            'name_en' => 'Shuttle run', 'name_nl' => 'Shuttle run',
            'value_type' => 'numeric', 'direction' => 'higher', 'frequency' => 'quarterly',
            'base' => 6.5, 'per_year' => 0.5, 'spread' => 1.5, 'improve' => 0.6, 'decimals' => 1,
        ],
        [
            'category' => 'Technical', 'unit' => 'reps',
            'name_en' => 'Juggling', 'name_nl' => 'Hooghouden',
            'value_type' => 'numeric', 'direction' => 'higher', 'frequency' => 'quarterly',
            'base' => 25.0, 'per_year' => 8.0, 'spread' => 18.0, 'improve' => 9.0, 'decimals' => 0,
        ],
        [
            'category' => 'Technical', 'unit' => '%',
            'name_en' => 'Passing accuracy', 'name_nl' => 'Passnauwkeurigheid',
            'value_type' => 'numeric', 'direction' => 'higher', 'frequency' => 'quarterly',
            'base' => 62.0, 'per_year' => 2.5, 'spread' => 9.0, 'improve' => 4.0, 'decimals' => 0,
        ],
        [
            'category' => 'Technical', 'unit' => 's',
            'name_en' => 'Dribble circuit', 'name_nl' => 'Dribbelparcours',
            'value_type' => 'numeric', 'direction' => 'lower', 'frequency' => 'quarterly',
            'base' => 18.5, 'per_year' => -0.6, 'spread' => 1.8, 'improve' => -0.8, 'decimals' => 2,
        ],
        [
            'category' => 'Mental', 'unit' => 'level',
            'name_en' => 'Focus self-assessment', 'name_nl' => 'Zelfbeoordeling focus',
            'value_type' => 'scale', 'direction' => 'higher', 'frequency' => 'quarterly',
            'base' => 6.0, 'per_year' => 0.2, 'spread' => 1.5, 'improve' => 0.5, 'decimals' => 0,
        ],
    ];

    /** Weeks between testing rounds, by category. */
    private const CADENCE_WEEKS = [
        'Anthropometric' => 8,
        'Physical'       => 6,
        'Technical'      => 6,
        'Mental'         => 12,
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'measurements';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->teams, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
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
        int $weeks,
        string $language = ''
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        $categories = $this->lookupIds( 'measurement_category' );
        if ( ! $categories ) return 0;

        $dutch      = strpos( $this->language, 'nl' ) === 0;
        $author     = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $definitions = $this->ensureDefinitions( $categories, $dutch );
        if ( ! $definitions ) return 0;

        $total  = count( $definitions );
        $total += $this->generateTargets( $definitions );
        $total += $this->generateSessionsAndResults( $definitions, $author );
        return $total;
    }

    /**
     * Create the battery, reusing any definition the club already has under
     * the same name so a second run doesn't duplicate the test list.
     *
     * @param array<string,int> $categories
     * @return array<int, array{id:int, spec:array<string,mixed>}>
     */
    private function ensureDefinitions( array $categories, bool $dutch ): array {
        global $wpdb;

        $out = [];
        foreach ( self::BATTERY as $sort => $spec ) {
            /** @var array<string, mixed> $spec */
            $name        = $dutch ? $spec['name_nl'] : $spec['name_en'];
            $category_id = (int) ( $categories[ $spec['category'] ] ?? 0 );
            if ( $category_id <= 0 ) continue;

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_measurement_definitions
                  WHERE club_id = %d AND name = %s LIMIT 1",
                CurrentClub::id(), $name
            ) );
            if ( $existing > 0 ) {
                $out[] = [ 'id' => $existing, 'spec' => $spec ];
                continue;
            }

            // #3273 — the seeded battery declares real units, so the demo data
            // exercises the conversion rather than accidentally working
            // because every factor happened to be 1.
            $unit_row = ( new UnitRegistry() )->bySymbol( (string) $spec['unit'] );
            $format   = (string) ( $spec['numeric_format'] ?? 'plain' );

            $wpdb->insert( "{$wpdb->prefix}tt_measurement_definitions", [
                'club_id'     => CurrentClub::id(),
                'uuid'        => self::uuid(),
                'category_id' => $category_id,
                'name'        => $name,
                'value_type'  => $spec['value_type'],
                'unit'        => $spec['unit'],
                'dimension'      => $unit_row ? (string) $unit_row->dimension : Dimensions::DIMENSIONLESS,
                'entry_unit_id'  => $unit_row ? (int) $unit_row->id : null,
                'numeric_format' => $format,
                'scale_min'   => $spec['value_type'] === 'scale' ? 1 : null,
                'scale_max'   => $spec['value_type'] === 'scale' ? 10 : null,
                'frequency'   => $spec['frequency'],
                'direction'   => $spec['direction'],
                'is_active'   => 1,
                'sort_order'  => ( $sort + 1 ) * 10,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) continue;

            $this->registry->tag( 'measurement_definition', $id, [ 'name' => $name ] );
            $out[] = [ 'id' => $id, 'spec' => $spec ];
        }
        return $out;
    }

    /**
     * The unit context for a battery entry — built from the spec rather than
     * re-read from the row, because the generator knows what it just wrote.
     *
     * @param array<string, mixed> $spec
     */
    private static function unitsFor( array $spec ): UnitContext {
        $unit_row = ( new UnitRegistry() )->bySymbol( (string) $spec['unit'] );

        return UnitContext::forDefinition( (object) [
            'unit'           => (string) $spec['unit'],
            'dimension'      => $unit_row ? (string) $unit_row->dimension : Dimensions::DIMENSIONLESS,
            'entry_unit_id'  => $unit_row ? (int) $unit_row->id : null,
            'numeric_format' => (string) ( $spec['numeric_format'] ?? 'plain' ),
            'value_type'     => (string) $spec['value_type'],
        ] );
    }

    /**
     * Green / amber bands per age group present in the roster, derived from
     * the same age model the results use so the bands and the values agree.
     *
     * @param array<int, array{id:int, spec:array<string,mixed>}> $definitions
     */
    private function generateTargets( array $definitions ): int {
        global $wpdb;

        $age_groups = [];
        foreach ( $this->teams as $t ) {
            $ag = isset( $t->age_group ) ? (string) $t->age_group : '';
            if ( $ag !== '' ) $age_groups[ $ag ] = self::ageFromGroup( $ag );
        }
        if ( ! $age_groups ) return 0;

        $total = 0;
        foreach ( $definitions as $def ) {
            $spec  = $def['spec'];
            $units = self::unitsFor( $spec );
            foreach ( $age_groups as $group => $age ) {
                $typical = (float) $spec['base'] + ( ( $age - 12 ) * (float) $spec['per_year'] );
                $spread  = (float) $spec['spread'];
                $better  = $spec['direction'] === 'lower' ? -1 : 1;

                // Green is the better half of the spread, amber the next
                // band out; below that the status reads as a concern.
                $green_edge = $typical + ( $better * $spread * 0.5 );
                $amber_edge = $typical - ( $better * $spread * 0.5 );

                $green_min = min( $typical, $green_edge );
                $green_max = max( $typical, $green_edge );
                $amber_min = min( $amber_edge, $typical );
                $amber_max = max( $amber_edge, $typical );

                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}tt_measurement_targets
                        (club_id, uuid, definition_id, age_group, green_min, green_max, amber_min, amber_max)
                     VALUES (%d, %s, %d, %s, %f, %f, %f, %f)",
                    CurrentClub::id(), self::uuid(), (int) $def['id'], $group,
                    // #3273 — bands are stored in the dimension's base, the
                    // same as the readings they are compared against.
                    round( $units->toBase( $green_min ), 5 ), round( $units->toBase( $green_max ), 5 ),
                    round( $units->toBase( $amber_min ), 5 ), round( $units->toBase( $amber_max ), 5 )
                ) );
                $id = (int) $wpdb->insert_id;
                if ( $ok && $id ) {
                    $this->registry->tag( 'measurement_target', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * @param array<int, array{id:int, spec:array<string,mixed>}> $definitions
     */
    private function generateSessionsAndResults( array $definitions, int $author ): int {
        global $wpdb;

        $players_by_team = [];
        foreach ( $this->players as $p ) {
            $players_by_team[ (int) ( $p->team_id ?? 0 ) ][] = $p;
        }

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        // A per-player talent offset, so the same player sits consistently
        // above or below their age group across every test.
        $offsets = [];
        foreach ( $this->players as $p ) {
            $offsets[ (int) $p->id ] = mt_rand( -100, 100 ) / 100.0;
        }

        $total = 0;
        foreach ( $definitions as $def ) {
            $spec    = $def['spec'];
            $units   = self::unitsFor( $spec );
            $cadence = (int) ( self::CADENCE_WEEKS[ $spec['category'] ] ?? 8 );
            $rounds  = max( 1, (int) floor( $this->weeks / $cadence ) );

            foreach ( $this->teams as $team ) {
                $team_id = (int) $team->id;
                $roster  = $players_by_team[ $team_id ] ?? [];
                if ( ! $roster ) continue;

                $age = self::ageFromGroup( isset( $team->age_group ) ? (string) $team->age_group : '' );

                for ( $r = 0; $r <= $rounds; $r++ ) {
                    $when = $window_start + ( $r * $cadence * WEEK_IN_SECONDS );
                    $is_future = $when > time();

                    // The next round is planned; one round in the middle was
                    // cancelled, so all three states are on screen.
                    $status = 'completed';
                    if ( $is_future ) {
                        $status = 'planned';
                    } elseif ( $rounds > 2 && $r === 1 ) {
                        $status = 'cancelled';
                    }

                    $wpdb->insert( "{$wpdb->prefix}tt_measurement_sessions", [
                        'club_id'       => CurrentClub::id(),
                        'uuid'          => self::uuid(),
                        'definition_id' => (int) $def['id'],
                        'team_id'       => $team_id,
                        'planned_date'  => gmdate( 'Y-m-d', $when ),
                        'status'        => $status,
                        'notes'         => null,
                        'created_by'    => $author,
                    ] );
                    $measurement_session_id = (int) $wpdb->insert_id;
                    if ( ! $measurement_session_id ) continue;
                    $this->registry->tag( 'measurement_session', $measurement_session_id, [ 'team_id' => $team_id, 'status' => $status ] );
                    $total++;

                    if ( $status !== 'completed' ) continue;

                    // Progress through the season, 0 at the first round and 1
                    // at the last, so the improvement curve spans the window.
                    $t = $rounds > 0 ? ( $r / max( 1, $rounds ) ) : 1.0;

                    foreach ( $roster as $p ) {
                        // A few players miss a round — a uniformly complete
                        // coverage indicator tells the operator nothing.
                        if ( mt_rand( 1, 100 ) > 92 ) continue;

                        $player_id = (int) $p->id;
                        $value = (float) $spec['base']
                            + ( ( $age - 12 ) * (float) $spec['per_year'] )
                            + ( $offsets[ $player_id ] * (float) $spec['spread'] * 0.5 )
                            + ( $t * (float) $spec['improve'] )
                            + ( ( mt_rand( -30, 30 ) / 100 ) * (float) $spec['spread'] * 0.2 );

                        $value = round( $value, (int) $spec['decimals'] );
                        if ( $spec['value_type'] === 'scale' ) {
                            $value = max( 1, min( 10, $value ) );
                        }

                        $wpdb->insert( "{$wpdb->prefix}tt_measurement_results", [
                            'club_id'                => CurrentClub::id(),
                            'uuid'                   => self::uuid(),
                            'player_id'              => $player_id,
                            'definition_id'          => (int) $def['id'],
                            'measurement_session_id' => $measurement_session_id,
                            'recorded_date'          => gmdate( 'Y-m-d', $when ),
                            // #3273 — canonical in, with the unit it was
                            // "entered" in recorded beside it, exactly as the
                            // entry grid writes a real reading.
                            'value_numeric'          => $units->toBase( $value ),
                            'value_text'             => null,
                            'entered_unit_id'        => $units->entryUnitId(),
                            'entered_value'          => $value,
                            'recorded_by'            => $author,
                        ] );
                        $result_id = (int) $wpdb->insert_id;
                        if ( $result_id ) {
                            $this->registry->tag( 'measurement_result', $result_id );
                            $total++;
                        }
                    }
                }
            }
        }
        return $total;
    }

    /** JO13 / U13 → 13. Falls back to 12, the model's reference age. */
    private static function ageFromGroup( string $group ): int {
        if ( preg_match( '/(\d+)/', $group, $m ) ) {
            $n = (int) $m[1];
            if ( $n >= 6 && $n <= 21 ) return $n;
        }
        return 12;
    }

    /** @return array<string,int> lookup name => id */
    private function lookupIds( string $type ): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( $type ) as $item ) {
            $out[ (string) $item->name ] = (int) $item->id;
        }
        return $out;
    }

    /**
     * #3102 — outside the seeded stream, so a second run into the same
     * install does not re-mint the uuid the first one already stored. See
     * \TT\Modules\DemoData\DemoUuid.
     */
    private static function uuid(): string {
        return \TT\Modules\DemoData\DemoUuid::mint();
    }
}
