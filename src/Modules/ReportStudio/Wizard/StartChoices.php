<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * StartChoices (#4570) — what the start wizard's steps share: a fieldset of
 * one starting choice with its switched-off options and their reasons, and
 * the settling of the wizard state after a step, which moves a later choice
 * an earlier one ruled out and records that it moved.
 *
 * Every answer comes from `ReportStartRules`; nothing here decides a rule.
 */
final class StartChoices {

    public static function enqueue(): void {
        wp_enqueue_style(
            'tt-report-start-wizard',
            TT_PLUGIN_URL . 'assets/css/frontend-report-start-wizard.css',
            [],
            TT_VERSION
        );
    }

    /**
     * The starting choices held in the wizard state, as the rules read them.
     *
     * @param array<string,mixed> $state
     * @return array<string,string>
     */
    public static function choicesIn( array $state ): array {
        $out = [];
        foreach ( ReportStartRules::FIELDS as $field ) {
            $value = $state[ $field ] ?? null;
            if ( is_string( $value ) && $value !== '' ) $out[ $field ] = $value;
        }
        if ( ! isset( $out['scope'] ) ) $out['scope'] = ReportContext::SCOPE_ME;
        return $out;
    }

    /**
     * The wizard state as the body `POST /reports` takes, for the one
     * create path both use.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function toInput( array $state ): array {
        $in   = self::choicesIn( $state );
        $mode = $in['period_mode'] ?? '';
        $in['period_value'] = $mode === ReportStartRules::PERIOD_ROLLING
            ? (string) ( $state['period_rolling'] ?? '' )
            : ( $mode === ReportStartRules::PERIOD_FIXED ? (string) ( $state['period_from'] ?? '' ) . '/' . (string) ( $state['period_to'] ?? '' ) : '' );
        $in['name'] = (string) ( $state['name'] ?? '' );

        $scope = $in['scope'];
        if ( $scope === ReportContext::SCOPE_PLAYER ) $in['player_id'] = (int) ( $state['player_id'] ?? 0 );
        if ( $scope === ReportContext::SCOPE_TEAM ) $in['team_id'] = (int) ( $state['team_id'] ?? 0 );
        if ( $scope === ReportContext::SCOPE_GROUP ) {
            $in['cohort'] = [
                'team_ids'  => array_map( 'intval', is_array( $state['cohort_team_ids'] ?? null ) ? $state['cohort_team_ids'] : [] ),
                'age_group' => (string) ( $state['cohort_age_group'] ?? '' ),
            ];
        }
        return $in;
    }

    /**
     * One choice as radio options: allowed options can be picked, the others
     * are switched off and say why. A choice the wizard moved says so above
     * the options.
     *
     * @param array<string,mixed> $state
     */
    public static function fieldset( string $field, string $legend, array $state ): void {
        self::enqueue();
        $answer = ReportStartRules::options( self::choicesIn( $state ), get_current_user_id() );
        if ( $answer instanceof StudioError ) {
            echo '<p class="tt-notice">' . esc_html( $answer->message ) . '</p>';
            return;
        }

        $current = is_string( $state[ $field ] ?? null ) && $state[ $field ] !== ''
            ? (string) $state[ $field ]
            : (string) ( $answer['choices'][ $field ] ?? '' );
        $moved = is_array( $state['moved'] ?? null ) ? $state['moved'] : [];

        echo '<fieldset class="tt-rsw-choices">';
        echo '<legend>' . esc_html( $legend ) . '</legend>';
        if ( isset( $moved[ $field ] ) && is_string( $moved[ $field ] ) ) {
            echo '<p class="tt-rsw-moved" role="status">' . esc_html(
                sprintf(
                    /* translators: %s: why an earlier choice changed this one */
                    __( 'Changed to the first option that still fits: %s', 'talenttrack' ),
                    $moved[ $field ]
                )
            ) . '</p>';
        }
        foreach ( $answer['options'][ $field ] ?? [] as $option ) {
            $id        = 'tt-rsw-' . $field . '-' . $option['value'];
            $reason_id = $id . '-why';
            $class     = 'tt-rsw-option' . ( $option['ok'] ? '' : ' tt-rsw-option--off' );
            echo '<label class="' . esc_attr( $class ) . '" for="' . esc_attr( $id ) . '">';
            echo '<input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $option['value'] ) . '"'
                . checked( $current, $option['value'], false )
                . disabled( ! $option['ok'], true, false )
                . ( $option['ok'] ? '' : ' aria-describedby="' . esc_attr( $reason_id ) . '"' )
                . ' />';
            echo '<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">' . esc_html( $option['label'] ) . '</span>';
            if ( ! $option['ok'] && is_string( $option['reason'] ) ) {
                echo '<span class="tt-rsw-reason" id="' . esc_attr( $reason_id ) . '">' . esc_html( $option['reason'] ) . '</span>';
            }
            echo '</span></label>';
        }
        echo '</fieldset>';
    }

    /**
     * Read this step's choices from the post: each must be an option the
     * rules allow after the earlier choices.
     *
     * @param list<string>        $fields
     * @param array<string,mixed> $post
     * @param array<string,mixed> $state
     * @return array<string,string>|\WP_Error
     */
    public static function answers( array $fields, array $post, array $state ) {
        $choices = self::choicesIn( $state );
        $out     = [];
        foreach ( $fields as $field ) {
            $value = isset( $post[ $field ] ) ? sanitize_key( (string) $post[ $field ] ) : '';
            if ( ! in_array( $value, ReportStartRules::values( $field ), true ) ) {
                return new \WP_Error( 'tt_rsw_' . $field, __( 'Choose one of the options.', 'talenttrack' ) );
            }
            $choices[ $field ] = $value;
            $out[ $field ]     = $value;
        }

        $check = ReportStartRules::normalise( $choices, get_current_user_id() );
        if ( $check instanceof StudioError ) return new \WP_Error( 'tt_rsw_rules', $check->message );
        foreach ( $fields as $field ) {
            if ( isset( $check['moved'][ $field ] ) ) return new \WP_Error( 'tt_rsw_' . $field, $check['moved'][ $field ] );
        }
        return $out;
    }

    /**
     * The state to merge after a step: its answers, every later choice an
     * answer ruled out moved to the first option that fits, and which moved.
     * A choice this step answered is no longer reported as moved.
     *
     * @param array<string,mixed>  $state
     * @param array<string,string> $answers
     * @return array<string,mixed>
     */
    public static function settle( array $state, array $answers ): array {
        $choices = array_merge( self::choicesIn( $state ), $answers );
        $moved   = is_array( $state['moved'] ?? null ) ? $state['moved'] : [];
        foreach ( array_keys( $answers ) as $field ) unset( $moved[ $field ] );

        $result = ReportStartRules::normalise( $choices, get_current_user_id() );
        if ( $result instanceof StudioError ) return $answers + [ 'moved' => $moved ];

        // A later choice the user has not reached yet takes its first fitting
        // option without a notice; one they already answered says it moved.
        foreach ( $result['moved'] as $field => $reason ) {
            if ( isset( $state[ $field ] ) && $state[ $field ] !== '' ) $moved[ $field ] = $reason;
        }
        $merge = $answers;
        foreach ( $result['choices'] as $field => $value ) {
            if ( isset( $state[ $field ] ) || isset( $answers[ $field ] ) ) $merge[ $field ] = $value;
        }
        return $merge + [ 'moved' => $moved ];
    }
}
