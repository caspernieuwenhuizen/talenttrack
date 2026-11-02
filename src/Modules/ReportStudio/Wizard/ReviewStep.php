<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportCreation;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 6 — the name, and the choices read back. Final step: it creates the
 * report through `ReportCreation`, the path `POST /reports` takes, and opens
 * it in the Studio. Nothing is stored before this step.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string  { return 'review'; }
    public function label(): string { return __( 'Name and review', 'talenttrack' ); }

    public function render( array $state ): void {
        StartChoices::enqueue();
        $name = (string) ( $state['name'] ?? '' );
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsw-name">' . esc_html__( 'Name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-rsw-name" class="tt-input" name="report_name" maxlength="' . esc_attr( (string) ReportStartRules::MAX_NAME ) . '" required autocomplete="off" value="' . esc_attr( $name ) . '" />';
        echo '</div>';

        $in = StartChoices::toInput( $state );
        echo '<dl class="tt-rsw-review">';
        $rows = [
            __( 'What', 'talenttrack' )             => ReportStartRules::label( 'kind', (string) ( $in['kind'] ?? '' ) ),
            __( 'About', 'talenttrack' )            => $this->subjectText( $in ),
            __( 'Written for', 'talenttrack' )      => ReportStartRules::label( 'audience', (string) ( $in['audience'] ?? '' ) ),
            __( 'Subject', 'talenttrack' )          => ReportStartRules::label( 'binding', (string) ( $in['binding'] ?? '' ) ),
            __( 'Period', 'talenttrack' )           => $this->periodText( $in ),
            __( 'Layout', 'talenttrack' )           => ReportStartRules::label( 'layout', (string) ( $in['layout'] ?? '' ) ),
            __( 'Sharing', 'talenttrack' )          => ReportStartRules::label( 'sharing', (string) ( $in['sharing'] ?? '' ) ),
        ];
        foreach ( $rows as $term => $value ) {
            echo '<dt>' . esc_html( (string) $term ) . '</dt><dd>' . esc_html( $value !== '' ? $value : '—' ) . '</dd>';
        }
        echo '</dl>';

        $moved = is_array( $state['moved'] ?? null ) ? $state['moved'] : [];
        foreach ( $moved as $field => $reason ) {
            if ( ! is_string( $reason ) ) continue;
            echo '<p class="tt-rsw-moved" role="status">' . esc_html(
                sprintf(
                    /* translators: 1: the choice that changed, 2: why */
                    __( '%1$s was changed to the first option that still fits: %2$s', 'talenttrack' ),
                    $this->fieldName( (string) $field ),
                    $reason
                )
            ) . '</p>';
        }
    }

    public function validate( array $post, array $state ) {
        // `report_name`, not `name`: WordPress reads `name` as a public query
        // var and would 404 the step's POST.
        $name = isset( $post['report_name'] ) ? trim( sanitize_text_field( (string) $post['report_name'] ) ) : '';
        if ( $name === '' ) return new \WP_Error( 'tt_rsw_name', __( 'Give the report a name.', 'talenttrack' ) );
        if ( mb_strlen( $name ) > ReportStartRules::MAX_NAME ) {
            /* translators: %d: maximum number of characters */
            return new \WP_Error( 'tt_rsw_name', sprintf( __( 'A report name is at most %d characters.', 'talenttrack' ), ReportStartRules::MAX_NAME ) );
        }
        return [ 'name' => $name ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $id = ReportCreation::create( StartChoices::toInput( $state ), get_current_user_id() );
        if ( $id instanceof StudioError ) return new \WP_Error( $id->code, $id->message );

        return [ 'redirect_url' => add_query_arg(
            [ 'tt_view' => 'report-studio', 'id' => $id ],
            WizardEntryPoint::dashboardBaseUrl()
        ) ];
    }

    /** @param array<string,mixed> $in */
    private function subjectText( array $in ): string {
        $scope = (string) ( $in['scope'] ?? '' );
        $label = ReportStartRules::label( 'scope', $scope );
        if ( $scope === ReportContext::SCOPE_PLAYER && ! empty( $in['player_id'] ) ) {
            $player = QueryHelpers::get_player( (int) $in['player_id'] );
            return $player ? QueryHelpers::player_display_name( $player ) : $label;
        }
        if ( $scope === ReportContext::SCOPE_TEAM && ! empty( $in['team_id'] ) ) {
            $team = QueryHelpers::get_team( (int) $in['team_id'] );
            return $team ? (string) ( $team->name ?? $label ) : $label;
        }
        if ( $scope === ReportContext::SCOPE_GROUP && is_array( $in['cohort'] ?? null ) ) {
            $names = [];
            foreach ( (array) ( $in['cohort']['team_ids'] ?? [] ) as $tid ) {
                $team = QueryHelpers::get_team( (int) $tid );
                if ( $team ) $names[] = (string) ( $team->name ?? '' );
            }
            $age = (string) ( $in['cohort']['age_group'] ?? '' );
            return implode( ', ', array_filter( $names ) ) . ( $age !== '' ? ' · ' . $age : '' );
        }
        return $label;
    }

    /** @param array<string,mixed> $in */
    private function periodText( array $in ): string {
        $mode  = (string) ( $in['period_mode'] ?? '' );
        $value = (string) ( $in['period_value'] ?? '' );
        if ( $mode === ReportStartRules::PERIOD_ROLLING ) {
            $labels = ReportFilters::periodLabels();
            return (string) ( $labels[ $value ] ?? $value );
        }
        if ( $mode === ReportStartRules::PERIOD_FIXED ) return str_replace( '/', ' – ', $value );
        return ReportStartRules::label( 'period_mode', $mode );
    }

    private function fieldName( string $field ): string {
        switch ( $field ) {
            case 'audience':    return __( 'Written for', 'talenttrack' );
            case 'binding':     return __( 'Subject', 'talenttrack' );
            case 'period_mode': return __( 'Period', 'talenttrack' );
            case 'layout':      return __( 'Layout', 'talenttrack' );
            case 'sharing':     return __( 'Sharing', 'talenttrack' );
        }
        return __( 'What', 'talenttrack' );
    }
}
