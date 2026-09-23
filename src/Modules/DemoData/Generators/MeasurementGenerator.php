<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoAnthropometry;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoMeasurementModel;
use TT\Modules\DemoData\DemoRoster;
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

    /**
     * What each test can actually read, keyed by `name_en` (#4036): the
     * lowest value it can physically produce, and what the youngest age group
     * typically manages. Kept beside the battery rather than in them so the
     * shape of `BATTERY` stays exactly what it was.
     *
     * `min` is the hard limit — zero for a count nobody managed, a time no
     * child beats. `young` is only consulted where the age line has already
     * gone below it by the bottom rung, which is the case juggling showed.
     *
     * @var array<string, array{min:float, young:float}>
     */
    private const RANGE = [
        'Height'                => [ 'min' => 100.0, 'young' => 110.0 ],
        'Weight'                => [ 'min' => 16.0,  'young' => 20.0 ],
        '10 m sprint'           => [ 'min' => 1.5,   'young' => 2.5 ],
        '30 m sprint'           => [ 'min' => 4.0,   'young' => 6.5 ],
        '1500 m run'            => [ 'min' => 4.5,   'young' => 9.0 ],
        'Countermovement jump'  => [ 'min' => 5.0,   'young' => 12.0 ],
        'Shuttle run'           => [ 'min' => 0.5,   'young' => 2.5 ],
        'Juggling'              => [ 'min' => 0.0,   'young' => 4.0 ],
        'Passing accuracy'      => [ 'min' => 10.0,  'young' => 30.0 ],
        'Dribble circuit'       => [ 'min' => 9.0,   'young' => 24.0 ],
        'Focus self-assessment' => [ 'min' => 1.0,   'young' => 4.0 ],
    ];

    /**
     * Where a test has a hard top as well. A percentage cannot read 104.
     *
     * @var array<string, float>
     */
    private const CEILINGS = [
        'Passing accuracy' => 100.0,
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

    private DemoCalendar $calendar;

    private DemoRoster $roster;

    public static function category(): string {
        return 'measurements';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->historicPlayers(),
            $ctx->teams,
            $ctx->users,
            $ctx->weeks(),
            $ctx->contentLanguage,
            $ctx->calendar(),
            $ctx->roster()
        );
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
        string $language = '',
        ?DemoCalendar $calendar = null,
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
        $this->roster   = $roster ?? new DemoRoster( $this->calendar, $teams, $players );
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
                $model   = $this->modelFor( $spec, $age );
                $typical = $model['typical'];
                $spread  = $model['spread'];

                if ( $spec['direction'] === 'neutral' ) {
                    // Height and weight: neither end is "better", so the
                    // band is centred and amber contains green, which is
                    // what `MeasurementTargetsRepository` reads it as.
                    $green_min = $typical - ( $spread * 0.5 );
                    $green_max = $typical + ( $spread * 0.5 );
                    $amber_min = $typical - $spread;
                    $amber_max = $typical + $spread;
                } else {
                    $better = $spec['direction'] === 'lower' ? -1 : 1;

                    // Green is the better half of the spread, amber the next
                    // band out; below that the status reads as a concern.
                    $green_edge = $typical + ( $better * $spread * 0.5 );
                    $amber_edge = $typical - ( $better * $spread * 0.5 );

                    $green_min = min( $typical, $green_edge );
                    $green_max = max( $typical, $green_edge );
                    $amber_min = min( $amber_edge, $typical );
                    $amber_max = max( $amber_edge, $typical );
                }

                // #4036 — the band edges are held inside what the test can
                // read, so a young age group never opens at a negative count.
                $floor     = self::floorFor( $spec );
                $ceiling   = self::ceilingFor( $spec );
                $green_min = DemoMeasurementModel::clamp( $green_min, $floor, $ceiling );
                $green_max = DemoMeasurementModel::clamp( $green_max, $floor, $ceiling );
                $amber_min = DemoMeasurementModel::clamp( $amber_min, $floor, $ceiling );
                $amber_max = DemoMeasurementModel::clamp( $amber_max, $floor, $ceiling );

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

        $window_start = $this->calendar->windowStart();

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

                $age = self::ageFromGroup( isset( $team->age_group ) ? (string) $team->age_group : '' );

                for ( $r = 0; $r <= $rounds; $r++ ) {
                    $when = $window_start + ( $r * $cadence * WEEK_IN_SECONDS );
                    $is_future = $when > time();

                    // #3402 — who is tested is who was in this squad on the
                    // day, not who is in it now. A team that had not been
                    // formed yet is not tested at all.
                    $roster = $this->roster->rosterFor( $team_id, gmdate( 'Y-m-d', $when ) );
                    if ( ! $roster ) continue;

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
                        $anchor    = $this->recordAnchor( $spec, $p, $when );

                        if ( $anchor !== null ) {
                            // #4036 — height and weight are not a second
                            // opinion about the player record. The reading is
                            // the record, walked back to the age the player
                            // was on the day, so the latest round agrees with
                            // their profile and the earlier ones read as growth.
                            $value = $anchor + ( ( mt_rand( -4, 4 ) / 10 ) );
                        } else {
                            $model = $this->modelFor( $spec, $age );
                            $value = $model['typical']
                                + ( $offsets[ $player_id ] * $model['spread'] * 0.5 )
                                + ( $t * $model['improve'] )
                                + ( ( mt_rand( -30, 30 ) / 100 ) * $model['spread'] * 0.2 );
                        }

                        $value = DemoMeasurementModel::clamp(
                            $value,
                            self::floorFor( $spec ),
                            self::ceilingFor( $spec )
                        );
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

    /**
     * What one battery entry looks like at one age — the typical value, the
     * cohort's spread around it and the gain across the window (#4036).
     *
     * Height and weight come off the shared body model, so the band an age
     * group is judged against is centred on the value `PlayerGenerator` wrote
     * to the record. Everything else comes off the battery's age curve.
     *
     * @param array<string,mixed> $spec
     * @return array{typical:float, spread:float, improve:float}
     */
    private function modelFor( array $spec, int $age ): array {
        $body = self::bodyTypical( $spec, $age );
        if ( $body !== null ) {
            return [
                'typical' => $body,
                'spread'  => (float) $spec['spread'],
                'improve' => (float) $spec['improve'],
            ];
        }

        $range = self::rangeFor( $spec );

        return DemoMeasurementModel::forAge( [
            'base'      => (float) $spec['base'],
            'per_year'  => (float) $spec['per_year'],
            'spread'    => (float) $spec['spread'],
            'improve'   => (float) $spec['improve'],
            'min'       => $range['min'],
            'young'     => $range['young'],
            'direction' => (string) $spec['direction'],
        ], $age );
    }

    /**
     * The body model's typical value for an anthropometric test, or null for
     * every other entry in the battery.
     *
     * @param array<string,mixed> $spec
     */
    private static function bodyTypical( array $spec, int $age ): ?float {
        if ( (string) $spec['category'] !== 'Anthropometric' ) return null;

        switch ( (string) $spec['name_en'] ) {
            case 'Height':
                return DemoAnthropometry::typicalHeight( $age );
            case 'Weight':
                return DemoAnthropometry::typicalWeight( $age );
        }
        return null;
    }

    /**
     * One player's own height or weight, walked back to the age they were on
     * the day of the round (#4036).
     *
     * A measurement of a child's body is a reading of a fact the player
     * record already states. Generating it from a separate age line is what
     * put 15 kg in a U7 player's history while their profile said 24.
     *
     * Null for every test that is not height or weight, and for a player
     * whose record does not carry the figure.
     *
     * @param array<string,mixed> $spec
     */
    private function recordAnchor( array $spec, object $player, int $when ): ?float {
        if ( (string) $spec['category'] !== 'Anthropometric' ) return null;

        $row    = (array) $player;
        $record = (string) $spec['name_en'] === 'Height'
            ? (float) ( $row['height_cm'] ?? 0 )
            : (float) ( $row['weight_kg'] ?? 0 );
        if ( $record <= 0.0 ) return null;

        $dob      = isset( $row['date_of_birth'] ) ? (string) $row['date_of_birth'] : '';
        $age_then = self::ageOn( $dob, $when );
        $age_now  = self::ageOn( $dob, $this->calendar->now() );
        if ( $age_then === null || $age_now === null ) return $record;

        $typical_then = self::bodyTypicalAt( $spec, $age_then );
        $typical_now  = self::bodyTypicalAt( $spec, $age_now );
        if ( $typical_then === null || $typical_now === null ) return $record;

        return $record - ( $typical_now - $typical_then );
    }

    /**
     * The body model between two birthdays. Whole years would step the series
     * on the child's birthday and hold it flat in between, which is not what a
     * growth chart is for.
     *
     * @param array<string,mixed> $spec
     */
    private static function bodyTypicalAt( array $spec, float $age ): ?float {
        $lower = (int) floor( $age );
        $below = self::bodyTypical( $spec, $lower );
        $above = self::bodyTypical( $spec, $lower + 1 );
        if ( $below === null || $above === null ) return null;

        return $below + ( ( $above - $below ) * ( $age - $lower ) );
    }

    /** Age in years, fractional, or null when the date of birth is unusable. */
    private static function ageOn( string $dob, int $ts ): ?float {
        if ( $dob === '' ) return null;

        $born = strtotime( $dob . ' 00:00:00 UTC' );
        if ( $born === false ) return null;

        return max( 0.0, ( $ts - $born ) / YEAR_IN_SECONDS );
    }

    /**
     * What this test can read at the bottom, and what the youngest age group
     * typically manages.
     *
     * @param array<string,mixed> $spec
     * @return array{min:float, young:float}
     */
    private static function rangeFor( array $spec ): array {
        return self::RANGE[ (string) $spec['name_en'] ] ?? [ 'min' => 0.0, 'young' => 0.0 ];
    }

    /**
     * The lowest reading this test can produce.
     *
     * @param array<string,mixed> $spec
     */
    private static function floorFor( array $spec ): float {
        return self::rangeFor( $spec )['min'];
    }

    /**
     * The highest reading this test can produce, where it has one.
     *
     * @param array<string,mixed> $spec
     */
    private static function ceilingFor( array $spec ): ?float {
        $name = (string) $spec['name_en'];

        return isset( self::CEILINGS[ $name ] ) ? (float) self::CEILINGS[ $name ] : null;
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
