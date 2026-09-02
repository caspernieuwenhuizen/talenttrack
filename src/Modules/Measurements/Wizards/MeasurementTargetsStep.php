<?php
namespace TT\Modules\Measurements\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Modules\Measurements\Units\Dimensions;
use TT\Modules\Measurements\Units\UnitContext;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 (final) — optional per-age-group target bands, then persist.
 *
 * Each age group gets a green band [green_min, green_max] and an amber
 * band [amber_min, amber_max]; values outside both flag red. Every field
 * is optional — an academy can add targets later. Pass/fail tests skip
 * bands entirely. Submitting creates the definition and its targets.
 */
final class MeasurementTargetsStep implements WizardStepInterface {

    public function slug(): string  { return 'targets'; }
    public function label(): string { return __( 'Targets', 'talenttrack' ); }

    public function render( array $state ): void {
        $value_type = (string) ( $state['value_type'] ?? 'numeric' );
        if ( $value_type === 'passfail' ) {
            echo '<p class="tt-notice">' . esc_html__( 'Pass/fail tests have no target bands. Click Finish to create the test.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $value_type === 'status' ) {
            echo '<p class="tt-notice">' . esc_html__( 'Status tests use coloured levels instead of target bands. Click Finish to create the test, then add its levels on the edit screen.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<p class="tt-wizard-hint">' . esc_html__( 'Optional. Per age group, the green band is on target and the amber band is a warning; anything outside flags red. Leave blank to add targets later.', 'talenttrack' ) . '</p>';

        $age_groups = QueryHelpers::get_lookups( 'age_group' );
        $existing   = (array) ( $state['targets'] ?? [] );
        // Draft bands are held canonically, so a step revisited on the way back
        // has to render them in the unit they were typed in.
        $units      = self::unitsFromState( $state );

        foreach ( $age_groups as $row ) {
            $ag    = (string) $row->name;
            $label = LookupTranslator::name( $row );
            $vals  = (array) ( $existing[ $ag ] ?? [] );

            echo '<fieldset class="tt-meas-target-set">';
            echo '<legend>' . esc_html( $label ) . '</legend>';
            self::numField( $ag, 'green_min', __( 'Green from', 'talenttrack' ), $vals, $units );
            self::numField( $ag, 'green_max', __( 'Green to', 'talenttrack' ), $vals, $units );
            self::numField( $ag, 'amber_min', __( 'Amber from', 'talenttrack' ), $vals, $units );
            self::numField( $ag, 'amber_max', __( 'Amber to', 'talenttrack' ), $vals, $units );
            echo '</fieldset>';
        }
    }

    /**
     * @param array<string, mixed> $vals
     */
    private static function numField( string $ag, string $key, string $label, array $vals, UnitContext $units ): void {
        $v = isset( $vals[ $key ] ) && $vals[ $key ] !== null
            ? $units->formatForInput( (float) $vals[ $key ] )
            : '';

        $attrs = '';
        foreach ( $units->inputAttributes() as $k => $val ) {
            if ( $k === 'placeholder' ) continue;
            $attrs .= esc_attr( $k ) . '="' . esc_attr( $val ) . '" ';
        }

        echo '<label><span>' . esc_html( $label ) . '</span>'
            . '<input ' . $attrs . 'name="band[' . esc_attr( $ag ) . '][' . esc_attr( $key ) . ']" ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — every attribute escaped above
            . 'value="' . esc_attr( $v ) . '" /></label>';
    }

    public function validate( array $post, array $state ) {
        // #3273 — the bands are typed in the unit chosen on the previous step
        // and stored canonically, so they convert here rather than at the point
        // they are compared against a reading.
        $units = self::unitsFromState( $state );

        $bands = isset( $post['band'] ) && is_array( $post['band'] ) ? $post['band'] : [];
        $targets = [];
        foreach ( $bands as $ag => $row ) {
            if ( ! is_array( $row ) ) continue;
            $ag = sanitize_text_field( (string) $ag );
            $entry = [];
            foreach ( [ 'green_min', 'green_max', 'amber_min', 'amber_max' ] as $k ) {
                $raw = isset( $row[ $k ] ) ? trim( (string) $row[ $k ] ) : '';
                if ( $raw === '' ) continue;
                $parsed = $units->parse( $raw );
                if ( $parsed['value'] !== null ) {
                    $entry[ $k ] = $parsed['value'];
                }
            }
            if ( ! empty( $entry ) ) {
                $targets[ $ag ] = $entry;
            }
        }
        return [ 'targets' => $targets ];
    }

    /**
     * The unit context the draft has chosen so far.
     *
     * @param array<string, mixed> $state
     */
    private static function unitsFromState( array $state ): UnitContext {
        return UnitContext::forDefinition( (object) [
            'unit'           => (string) ( $state['unit'] ?? '' ),
            'dimension'      => (string) ( $state['dimension'] ?? Dimensions::DIMENSIONLESS ),
            'entry_unit_id'  => $state['entry_unit_id'] ?? null,
            'numeric_format' => (string) ( $state['numeric_format'] ?? 'plain' ),
            'value_type'     => (string) ( $state['value_type'] ?? 'numeric' ),
        ] );
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $definition_id = ( new MeasurementDefinitionsRepository() )->create( [
            'category_id' => (int) ( $state['category_id'] ?? 0 ),
            'name'        => (string) ( $state['name'] ?? '' ),
            'value_type'  => (string) ( $state['value_type'] ?? 'numeric' ),
            'unit'        => (string) ( $state['unit'] ?? '' ),
            // #3273 — resolved in the options step, carried through the draft.
            'dimension'      => (string) ( $state['dimension'] ?? Dimensions::DIMENSIONLESS ),
            'entry_unit_id'  => $state['entry_unit_id'] ?? null,
            'numeric_format' => (string) ( $state['numeric_format'] ?? 'plain' ),
            'direction'   => (string) ( $state['direction'] ?? 'higher' ),
            'frequency'   => (string) ( $state['frequency'] ?? 'adhoc' ),
        ] );
        if ( $definition_id <= 0 ) {
            return new \WP_Error( 'create_failed', __( 'Could not create the test.', 'talenttrack' ) );
        }

        $targets = (array) ( $state['targets'] ?? [] );
        if ( ! empty( $targets ) ) {
            $repo = new MeasurementTargetsRepository();
            foreach ( $targets as $age_group => $bands ) {
                $repo->upsert( $definition_id, (string) $age_group, (array) $bands );
            }
        }

        // A status test has no levels yet — land the operator on its edit
        // screen so they can define the coloured levels straight away.
        if ( (string) ( $state['value_type'] ?? '' ) === 'status' ) {
            return [ 'redirect_url' => add_query_arg(
                [ 'tt_view' => 'measurement-tests', 'action' => 'edit', 'id' => $definition_id ],
                RecordLink::dashboardUrl()
            ) ];
        }

        return [ 'redirect_url' => add_query_arg(
            [ 'tt_view' => 'measurements-entry' ],
            RecordLink::dashboardUrl()
        ) ];
    }
}
