<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;

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
     *     'direction', 'latest_value', 'latest_date', 'flag', 'series' ]
     */
    public function forPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        $age_group   = $this->ageGroupFor( $player_id );
        $definitions = $this->definitions->listActive();
        $latest      = $this->results->latestPerDefinitionForPlayer( $player_id );

        $grouped = [];
        foreach ( $definitions as $def ) {
            $def_id      = (int) $def->id;
            $is_status   = (string) $def->value_type === 'status';
            $latest_row  = $latest[ $def_id ] ?? null;
            $value       = $this->displayValue( $def, $latest_row );
            $flag        = '';
            $level_token = '';

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
            } elseif ( $latest_row && $latest_row->value_numeric !== null && $age_group !== '' ) {
                $target = $this->targets->forDefinitionAndAge( $def_id, $age_group );
                $flag   = $this->targets->flagFor(
                    (float) $latest_row->value_numeric,
                    $target,
                    (string) $def->direction
                );
            }

            $series = array_map(
                static function ( $row ) {
                    return [
                        'date'  => (string) $row->recorded_date,
                        'value' => $row->value_numeric !== null ? (float) $row->value_numeric : null,
                        'text'  => $row->value_text !== null ? (string) $row->value_text : null,
                    ];
                },
                $this->results->listSeriesForPlayer( $player_id, $def_id )
            );

            $cat = (string) ( $def->category_label ?: $def->category_name ?: '' );
            if ( ! isset( $grouped[ $cat ] ) ) {
                $grouped[ $cat ] = [ 'category' => $cat, 'tests' => [] ];
            }
            $grouped[ $cat ]['tests'][] = [
                'definition_id' => $def_id,
                'name'          => (string) $def->name,
                'unit'          => (string) ( $def->unit ?? '' ),
                'value_type'    => (string) $def->value_type,
                'frequency'     => (string) $def->frequency,
                'direction'     => (string) $def->direction,
                'latest_value'  => $value,
                'latest_date'   => $latest_row ? (string) $latest_row->recorded_date : '',
                'flag'          => $flag,
                'level_token'   => $level_token,
                'series'        => $series,
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
    public function summaryForPlayer( int $player_id ): array {
        $empty = [ 'tracked' => 0, 'ok' => 0, 'warn' => 0, 'bad' => 0, 'flagged' => 0 ];
        if ( $player_id <= 0 ) return $empty;

        $out = $empty;
        foreach ( $this->forPlayer( $player_id ) as $cat ) {
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
     * Render a result's value for display, honouring the test's value type.
     */
    private function displayValue( object $def, ?object $row ): string {
        if ( ! $row ) return '';
        if ( $row->value_text !== null && $row->value_text !== '' ) {
            return (string) $row->value_text;
        }
        if ( $row->value_numeric === null ) return '';
        // Trim trailing zeros from the decimal so 30.000 reads "30".
        $num = rtrim( rtrim( number_format( (float) $row->value_numeric, 3, '.', '' ), '0' ), '.' );
        $unit = (string) ( $def->unit ?? '' );
        return $unit !== '' ? $num . ' ' . $unit : $num;
    }

    private function ageGroupFor( int $player_id ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT age_group FROM {$p}tt_players WHERE id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
    }
}
