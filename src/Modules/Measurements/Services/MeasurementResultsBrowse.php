<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;

/**
 * MeasurementResultsBrowse (#2145).
 *
 * The shared read model for the "Test results" browser: for one test
 * definition (+ optional team / age-group / date-window filters), one row
 * per player carrying their latest in-window value, the status-level colour
 * (status tests) or the green/amber flag against their age-group target
 * (numeric / scale tests), and the trend versus their previous value.
 *
 * The REST controller and the rendered view both call this, so a future
 * SaaS front end gets the same answer as the plugin HTML (CLAUDE.md §4 —
 * business logic out of views). Player-centric (§1): every row is a player.
 */
class MeasurementResultsBrowse {

    private MeasurementResultsRepository $results;
    private MeasurementDefinitionsRepository $definitions;
    private MeasurementTargetsRepository $targets;
    private MeasurementLevelsRepository $levels;

    public function __construct(
        ?MeasurementResultsRepository $results = null,
        ?MeasurementDefinitionsRepository $definitions = null,
        ?MeasurementTargetsRepository $targets = null,
        ?MeasurementLevelsRepository $levels = null
    ) {
        $this->results     = $results ?? new MeasurementResultsRepository();
        $this->definitions = $definitions ?? new MeasurementDefinitionsRepository();
        $this->targets     = $targets ?? new MeasurementTargetsRepository();
        $this->levels      = $levels ?? new MeasurementLevelsRepository();
    }

    /**
     * Rows for the chosen definition + filters.
     *
     * @param array<string, mixed> $filters team_id, age_group, date_from, date_to
     * @return array<int, array<string, mixed>> each:
     *   [ 'player_id', 'name', 'team_name', 'age_group', 'recorded_date',
     *     'value', 'unit', 'value_type',
     *     'level_label', 'level_token',   // status tests
     *     'flag', 'trend' ]               // numeric / scale tests
     */
    public function rows( int $definition_id, array $filters = [] ): array {
        if ( $definition_id <= 0 ) return [];

        $def = $this->definitions->find( $definition_id );
        if ( ! $def ) return [];

        $value_type = (string) $def->value_type;
        $unit       = (string) ( $def->unit ?? '' );
        $direction  = (string) $def->direction;
        $is_status  = $value_type === 'status';

        $raw = $this->results->listLatestWithPreviousForDefinition( $definition_id, $filters );

        $out = [];
        foreach ( $raw as $row ) {
            $age_group = (string) ( $row->age_group ?? '' );
            $name      = trim( (string) $row->first_name . ' ' . (string) $row->last_name );

            $entry = [
                'player_id'     => (int) $row->player_id,
                'name'          => $name,
                'team_name'     => (string) ( $row->team_name ?? '' ),
                'age_group'     => $age_group,
                'recorded_date' => (string) $row->recorded_date,
                'value'         => $this->displayValue( $row, $unit ),
                'unit'          => $unit,
                'value_type'    => $value_type,
                'level_label'   => '',
                'level_token'   => '',
                'flag'          => '',
                'trend'         => '',
                'value_sort'    => $this->sortValue( $row, $is_status ),
            ];

            if ( $is_status ) {
                $label = $row->value_text !== null ? (string) $row->value_text : '';
                if ( $label !== '' ) {
                    $level = $this->levels->findByLabel( $definition_id, $label );
                    $entry['level_label'] = $label;
                    $entry['level_token'] = $level
                        ? MeasurementLevelPalette::safe( (string) $level->color_token )
                        : MeasurementLevelPalette::DEFAULT_TOKEN;
                }
            } else {
                if ( $row->value_numeric !== null && $age_group !== '' ) {
                    $target = $this->targets->forDefinitionAndAge( $definition_id, $age_group );
                    $entry['flag'] = $this->targets->flagFor( (float) $row->value_numeric, $target, $direction );
                }
                $entry['trend'] = $this->trend(
                    $row->value_numeric !== null ? (float) $row->value_numeric : null,
                    isset( $row->prev_value_numeric ) && $row->prev_value_numeric !== null ? (float) $row->prev_value_numeric : null,
                    $direction
                );
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Render a result's value for display, honouring unit. Trims trailing
     * zeros so 30.000 reads "30". Mirrors PlayerMeasurementProfile so the
     * browser and the profile never disagree.
     */
    private function displayValue( object $row, string $unit ): string {
        if ( $row->value_text !== null && $row->value_text !== '' ) {
            return (string) $row->value_text;
        }
        if ( $row->value_numeric === null ) return '';
        $num = rtrim( rtrim( number_format( (float) $row->value_numeric, 3, '.', '' ), '0' ), '.' );
        return $unit !== '' ? $num . ' ' . $unit : $num;
    }

    /**
     * A stable sort key for the value column: the numeric value for
     * numeric/scale tests, or the level ordinal-ish text for status (so the
     * client-side table sort orders by magnitude, not the rendered chip).
     */
    private function sortValue( object $row, bool $is_status ): string {
        if ( $is_status ) {
            return $row->value_text !== null ? (string) $row->value_text : '';
        }
        return $row->value_numeric !== null ? (string) (float) $row->value_numeric : '';
    }

    /**
     * Trend versus the previous value, direction-aware: 'up' means the
     * change is an improvement, 'down' a regression, 'flat' unchanged, ''
     * when there is no previous value to compare. For a 'lower-is-better'
     * test a numeric decrease is an improvement ('up').
     */
    private function trend( ?float $current, ?float $previous, string $direction ): string {
        if ( $current === null || $previous === null ) return '';
        $delta = $current - $previous;
        if ( abs( $delta ) < 1e-9 ) return 'flat';
        $better = $direction === 'lower' ? $delta < 0 : $delta > 0;
        if ( $direction === 'neutral' ) {
            return $delta > 0 ? 'up' : 'down';
        }
        return $better ? 'up' : 'down';
    }
}
