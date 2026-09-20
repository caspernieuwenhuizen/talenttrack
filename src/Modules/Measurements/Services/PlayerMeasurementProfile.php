<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Modules\Measurements\Units\UnitContext;

/**
 * PlayerMeasurementProfile (#1856).
 *
 * The shared read model for "a player's measurements" — categories, each
 * with its tests, each test carrying the player's latest value, the
 * green/amber/red flag against their age-group target, and the trend
 * series. The REST controller and the frontend Metingen view both call
 * this, so a future SaaS front end gets the same answer as the rendered
 * HTML (CLAUDE.md §4 — business logic out of views).
 */
class PlayerMeasurementProfile {

    private MeasurementDefinitionsRepository $definitions;
    private MeasurementResultsRepository $results;
    private MeasurementTargetsRepository $targets;
    private MeasurementLevelsRepository $levels;

    public function __construct(
        ?MeasurementDefinitionsRepository $definitions = null,
        ?MeasurementResultsRepository $results = null,
        ?MeasurementTargetsRepository $targets = null,
        ?MeasurementLevelsRepository $levels = null
    ) {
        $this->definitions = $definitions ?? new MeasurementDefinitionsRepository();
        $this->results     = $results ?? new MeasurementResultsRepository();
        $this->targets     = $targets ?? new MeasurementTargetsRepository();
        $this->levels      = $levels ?? new MeasurementLevelsRepository();
    }

    /**
     * Grouped measurement profile for one player.
     *
     * @return array<int, array<string, mixed>> categories, each:
     *   [ 'category' => string, 'tests' => array<int, array<string,mixed>> ]
     *   where a test is:
     *   [ 'definition_id', 'name', 'unit', 'value_type', 'frequency',
     *     'direction', 'latest_value', 'latest_date', 'flag', 'band',
     *     'series', 'overdue' ]
     *
     * `latest_value` is the reading in the test's entry unit, as a bare number
     * with a dot decimal (`mm:ss` for a duration, the recorded text for a level
     * or a pass). `unit` carries the symbol; a surface composes the two.
     *
     * `band` (#2536) is the age-group "on target" range as
     * `[ 'min' => ?float, 'max' => ?float ]`, or null when the test has no
     * target for this player's age group. The trend chart shades it; the
     * flag is the same target expressed as a verdict on the latest value.
     */
    public function forPlayer( int $player_id, ?int $viewer_id = null ): array {
        if ( $player_id <= 0 ) return [];

        $age_group   = $this->ageGroupFor( $player_id );
        // Only tests the operator has kept visible on the profile (#2204),
        // and among those, only the ones this reader's clearance admits
        // (#3392). A test toggled off still records results and appears in
        // reports / exports — it just stops rendering on the player profile.
        //
        // The viewer defaults to the current user rather than being
        // required, so every existing caller keeps working; but the filter
        // lives HERE rather than in the views, because the rendered HTML
        // and the REST response must not be able to disagree about what a
        // given reader may see.
        $viewer_id   = $viewer_id ?? get_current_user_id();
        $definitions = $this->definitions->listActiveForProfile(
            \TT\Infrastructure\Visibility\RecordVisibility::forMeasurements( $viewer_id )
        );
        $latest      = $this->results->latestPerDefinitionForPlayer( $player_id );

        $grouped = [];
        foreach ( $definitions as $def ) {
            $def_id      = (int) $def->id;
            $is_status   = (string) $def->value_type === 'status';
            $latest_row  = $latest[ $def_id ] ?? null;
            $value       = $this->displayValue( $def, $latest_row );
            $flag        = '';
            $level_token = '';
            $band        = null;

            if ( $is_status ) {
                // Status colour comes from the matched level's token, not the
                // green/amber target maths. Resolve the latest label back to
                // its current level (so a recoloured level repaints history).
                $label = $latest_row && $latest_row->value_text !== null ? (string) $latest_row->value_text : '';
                if ( $label !== '' ) {
                    $level = $this->levels->findByLabel( $def_id, $label );
                    $level_token = $level
                        ? MeasurementLevelPalette::safe( (string) $level->color_token )
                        : MeasurementLevelPalette::DEFAULT_TOKEN;
                }
            } elseif ( $age_group !== '' ) {
                // #2536 — the target is resolved whenever the player has an
                // age group, not only when a value exists: the trend chart
                // shades the band even on a test whose latest result is
                // missing, and the band is a property of the age group, not
                // of the reading.
                $target = $this->targets->forDefinitionAndAge( $def_id, $age_group );
                // Read once: the flag and the shaded band have to agree about
                // which side of the target is the better one (#3028).
                $direction = (string) $def->direction;
                if ( $latest_row && $latest_row->value_numeric !== null ) {
                    $flag = $this->targets->flagFor(
                        (float) $latest_row->value_numeric,
                        $target,
                        $direction
                    );
                }
                $band = self::bandFrom( $target, $direction );
            }

            // #3273 — the chart plots what staff read, not the canonical base:
            // a height series is 182 / 184 / 187, never 1.82 / 1.84 / 1.87. The
            // band converts with it so the shading stays on the same scale as
            // the points.
            $units  = UnitContext::forDefinition( $def );
            $series = array_map(
                static function ( $row ) use ( $units ) {
                    return [
                        'date'  => (string) $row->recorded_date,
                        'value' => $row->value_numeric !== null ? $units->fromBase( (float) $row->value_numeric ) : null,
                        'text'  => $row->value_text !== null ? (string) $row->value_text : null,
                    ];
                },
                $this->results->listSeriesForPlayer( $player_id, $def_id )
            );
            $band = self::bandToEntryUnit( $band, $units );

            // Read once and reused below: the latest date and the frequency
            // are each wanted twice now, and asking the row twice would be a
            // second place for the two answers to differ.
            $latest_date = $latest_row ? (string) $latest_row->recorded_date : '';
            $frequency   = (string) $def->frequency;

            $cat = (string) ( $def->category_label ?: $def->category_name ?: '' );
            if ( ! isset( $grouped[ $cat ] ) ) {
                $grouped[ $cat ] = [ 'category' => $cat, 'tests' => [] ];
            }
            $grouped[ $cat ]['tests'][] = [
                'definition_id' => $def_id,
                'name'          => (string) $def->name,
                'unit'          => $units->symbol(),
                'value_type'    => (string) $def->value_type,
                'frequency'     => $frequency,
                'direction'     => (string) $def->direction,
                'latest_value'  => $value,
                'latest_date'   => $latest_date,
                'flag'          => $flag,
                'level_token'   => $level_token,
                'band'          => $band,
                'series'        => $series,
                // #3526 — is this test past its own measuring frequency? A
                // derivation rather than presentation, so it lives here and
                // reaches REST as well: a front end that renders the register
                // must be able to say "over tijd" without recomputing it from
                // the date and re-deriving what `quarterly` means.
                //
                // A test never measured is not overdue. It is *missing*, which
                // is a different sentence and is counted separately — calling
                // it overdue would bury a trialist's blank profile in a list
                // meant to chase up stale readings.
                'overdue'       => self::isOverdue( $latest_date, $frequency ),
            ];
        }

        return array_values( $grouped );
    }

    /**
     * Journey-narrative summary for one player — the at-a-glance signal
     * surfaced beside the player's other KPIs (#2123). Counts the tests
     * the player has a current value for, and how many of those sit below
     * their age-group target (amber + red against the band). The flag
     * maths is the same `PlayerMeasurementProfile::forPlayer()` runs, so
     * the summary and the full timeline never disagree.
     *
     * @return array{tracked:int, ok:int, warn:int, bad:int, flagged:int}
     *   `tracked`  — tests with a latest value
     *   `ok`/`warn`/`bad` — green / amber / red flag counts
     *   `flagged`  — warn + bad (tests below the target band)
     */
    public function summaryForPlayer( int $player_id, ?int $viewer_id = null ): array {
        $empty = [ 'tracked' => 0, 'ok' => 0, 'warn' => 0, 'bad' => 0, 'flagged' => 0 ];
        if ( $player_id <= 0 ) return $empty;

        $out = $empty;
        // #3392 — counts the same filtered set the list renders, because it
        // reads through forPlayer(). An At-a-glance tile saying "6 tests
        // tracked" above a list of four would be its own small bug.
        foreach ( $this->forPlayer( $player_id, $viewer_id ) as $cat ) {
            foreach ( (array) ( $cat['tests'] ?? [] ) as $test ) {
                if ( (string) ( $test['latest_value'] ?? '' ) === '' ) {
                    continue; // no current value → not a tracked test
                }
                $out['tracked']++;
                $flag = (string) ( $test['flag'] ?? '' );
                if ( $flag === 'ok' || $flag === 'warn' || $flag === 'bad' ) {
                    $out[ $flag ]++;
                }
            }
        }
        $out['flagged'] = $out['warn'] + $out['bad'];
        return $out;
    }

    /**
     * #2536 — the "on target" range from a target row, as the chart's band.
     * Returns null when the row carries no green range at all; an open-ended
     * target (only a floor, or only a ceiling) keeps its open end as null,
     * which the chart shades to the edge of the plot.
     *
     * @return array{min: ?float, max: ?float}|null
     */
    /**
     * The shaded "on target for this age group" band on the trend chart.
     *
     * #3028 — the band is open on the better side, matching
     * MeasurementTargetsRepository::flagFor(). A closed band drew the green
     * area stopping at green_min on a lower-is-better test, so a player who
     * had improved past the target appeared to have fallen out of it. What
     * the operator entered is the edge a player should reach, not a floor
     * they should stay above.
     *
     * @return array{min: float|null, max: float|null}|null
     */
    private static function bandFrom( ?object $target, string $direction = 'neutral' ): ?array {
        if ( $target === null ) return null;
        $min = isset( $target->green_min ) && $target->green_min !== null ? (float) $target->green_min : null;
        $max = isset( $target->green_max ) && $target->green_max !== null ? (float) $target->green_max : null;
        if ( $direction === 'lower' ) {
            $min = null;
        } elseif ( $direction === 'higher' ) {
            $max = null;
        }
        if ( $min === null && $max === null ) return null;
        return [ 'min' => $min, 'max' => $max ];
    }

    /**
     * A result's value in the unit the test is entered in, honouring the value
     * type. #3273 — through the same unit context MeasurementResultsBrowse
     * uses, so nothing here can disagree with it about a number.
     *
     * #3768 — the number only, never the symbol and never a localised
     * separator. This is what `latest_value` carries to a REST consumer, and a
     * front end that is handed "36,3 kg" cannot render it any other way, nor
     * read it back as a number (CLAUDE.md §4). The symbol travels once, in the
     * sibling `unit` field, and the surface composes the two.
     *
     * A duration keeps its `mm:ss` spelling — that is the canonical shape of a
     * clock time rather than a localisation of it.
     */
    private function displayValue( object $def, ?object $row ): string {
        if ( ! $row ) return '';
        if ( $row->value_text !== null && $row->value_text !== '' ) {
            return (string) $row->value_text;
        }
        if ( $row->value_numeric === null ) return '';
        return UnitContext::forDefinition( $def )->format( (float) $row->value_numeric, false );
    }

    /**
     * Move a target band onto the same scale as the series it shades.
     *
     * @param array{min: float|null, max: float|null}|null $band
     * @return array{min: float|null, max: float|null}|null
     */
    private static function bandToEntryUnit( ?array $band, UnitContext $units ): ?array {
        if ( $band === null ) return null;
        return [
            'min' => $band['min'] !== null ? $units->fromBase( (float) $band['min'] ) : null,
            'max' => $band['max'] !== null ? $units->fromBase( (float) $band['max'] ) : null,
        ];
    }

    /**
     * #3526 — has this test's latest reading outlived the frequency the
     * operator set for it?
     *
     * The window is the frequency's own interval plus a month's grace. Without
     * the grace an annual test is "over tijd" on the anniversary of the last
     * measuring day, which would flag half an academy every time a measuring
     * round slips a fortnight — and a list that is always long is a list
     * nobody reads.
     *
     * A frequency the vocabulary does not name means the operator has not said
     * how often to measure, so there is nothing to be late for.
     */
    private static function isOverdue( string $latest_date, string $frequency ): bool {
        if ( $latest_date === '' ) return false;

        $months = [
            'annual'    => 12,
            'biannual'  => 6,
            'quarterly' => 3,
            'monthly'   => 1,
        ][ $frequency ] ?? 0;
        if ( $months === 0 ) return false;

        $measured = strtotime( $latest_date );
        if ( $measured === false ) return false;

        // Months rather than a day count, so "annual" means the same date next
        // year whatever the month lengths in between.
        return strtotime( '+' . ( $months + 1 ) . ' months', $measured ) < time();
    }

    private function ageGroupFor( int $player_id ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        // Age group lives on the team, not the player row (mirrors
        // MatchLengthResolver / the #2165 fix). Resolve via team_id.
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT t.age_group
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
              WHERE pl.id = %d AND pl.club_id = %d",
            $player_id, CurrentClub::id()
        ) );
    }
}
