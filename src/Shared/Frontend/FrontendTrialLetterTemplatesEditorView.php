<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Letters\DefaultLetterTemplates;
use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Modules\Trials\Repositories\TrialLetterTemplatesRepository;

/**
 * FrontendTrialLetterTemplatesEditorView (#0017 Sprint 6) — letter
 * template editor for the three decision outcomes.
 *
 *   ?tt_view=trial-letter-templates-editor                    list
 *   ?tt_view=trial-letter-templates-editor&key=admittance     edit form
 *   …with optional ?locale=nl_NL to pick the locale.
 *
 * Also exposes the acceptance-slip toggle + response-deadline + club
 * address fields, persisted in `wp_options`.
 */
class FrontendTrialLetterTemplatesEditorView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $letters_label = __( 'Letter templates', 'talenttrack' );
        $parent_crumb  = [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'trials', __( 'Trials', 'talenttrack' ) ) ];

        // v3.85.5 — Trials Pro-tier gate.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'trial_module' )
        ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $letters_label, $parent_crumb );
            self::renderHeader( $letters_label );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Trial cases', 'talenttrack' ), 'pro' );
            return;
        }

        if ( ! current_user_can( 'tt_manage_trials' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $letters_label, $parent_crumb );
            self::renderHeader( $letters_label );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit letter templates.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( $user_id );

        $key    = isset( $_GET['key'] )    ? sanitize_key( (string) $_GET['key'] )    : '';
        $locale = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['locale'] ) ) : ( get_locale() ?: 'en_US' );

        if ( $key === '' || ! in_array( $key, DefaultLetterTemplates::listKeys(), true ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $letters_label, $parent_crumb );
            self::renderHeader( $letters_label );
            self::renderList( $locale );
            self::renderSettings();
            return;
        }

        $editor_chain = array_merge(
            $parent_crumb,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'trial-letter-templates-editor', $letters_label ) ]
        );
        $title = sprintf( __( 'Edit letter — %s (%s)', 'talenttrack' ), $key, $locale );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title, $editor_chain );
        self::renderHeader( $title );
        self::renderEditor( $key, $locale );
    }

    private static function renderList( string $locale ): void {
        $base    = remove_query_arg( [ 'key' ] );
        $locales = self::availableLocales();

        echo '<form method="get" class="tt-filter-row"><input type="hidden" name="tt_view" value="trial-letter-templates-editor"/>';
        echo '<label>' . esc_html__( 'Locale', 'talenttrack' ) . ' <select name="locale">';
        foreach ( $locales as $loc ) {
            $sel = selected( $locale, $loc, false );
            echo '<option value="' . esc_attr( $loc ) . '" ' . $sel . '>' . esc_html( $loc ) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="tt-button">' . esc_html__( 'Switch locale', 'talenttrack' ) . '</button>';
        echo '</form>';

        $repo = new TrialLetterTemplatesRepository();
        echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Template', 'talenttrack' ) . '</th><th>' . esc_html__( 'Customized?', 'talenttrack' ) . '</th><th></th></tr></thead><tbody>';
        foreach ( DefaultLetterTemplates::listKeys() as $k ) {
            $custom = $repo->findCustom( $k, $locale );
            $url = add_query_arg( [ 'tt_view' => 'trial-letter-templates-editor', 'key' => $k, 'locale' => $locale ], $base );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $k ) . '</a></td>';
            echo '<td>' . esc_html( $custom ? __( 'Customized', 'talenttrack' ) : __( 'Default (shipped)', 'talenttrack' ) ) . '</td>';
            echo '<td><a class="tt-button tt-button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Edit', 'talenttrack' ) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderSettings(): void {
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Acceptance slip', 'talenttrack' ) . '</h2>';
        $enabled  = LetterTemplateEngine::acceptanceSlipEnabled();
        // #0052 PR-A — read tenant-scoped settings from tt_config.
        $deadline = (int) \TT\Infrastructure\Query\QueryHelpers::get_config( 'tt_trial_acceptance_response_days', '14' );
        if ( $deadline <= 0 ) $deadline = 14;
        $address  = \TT\Infrastructure\Query\QueryHelpers::get_config( 'tt_trial_acceptance_club_address', '' );
        echo '<form method="post"><input type="hidden" name="tt_trial_letter_action" value="save_settings">';
        wp_nonce_field( 'tt_trial_letter_settings', 'tt_trial_letter_settings_nonce' );
        echo '<label><input type="checkbox" name="acceptance_slip_enabled" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Include the acceptance slip on admittance letters', 'talenttrack' ) . '</label>';
        echo '<label>' . esc_html__( 'Response deadline (days from letter date)', 'talenttrack' ) . ' <input type="number" inputmode="numeric" min="1" max="60" name="acceptance_response_days" value="' . esc_attr( (string) $deadline ) . '"></label>';
        echo '<label>' . esc_html__( 'Club return address', 'talenttrack' ) . ' <textarea name="acceptance_club_address" rows="2">' . esc_textarea( $address ) . '</textarea></label>';
        echo '<button type="submit" class="tt-button">' . esc_html__( 'Save settings', 'talenttrack' ) . '</button>';
        echo '</form></section>';
    }

    private static function renderEditor( string $key, string $locale ): void {
        $repo  = new TrialLetterTemplatesRepository();
        $tpl   = $repo->getForKey( $key, $locale );
        $engine = new LetterTemplateEngine();

        echo '<form method="post" class="tt-trial-letter-edit">';
        wp_nonce_field( 'tt_trial_letter_save_' . $key, 'tt_trial_letter_save_nonce' );
        echo '<input type="hidden" name="tt_trial_letter_action" value="save_template">';
        echo '<input type="hidden" name="key"    value="' . esc_attr( $key )    . '">';
        echo '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '">';

        echo '<div class="tt-letter-edit-grid">';
        echo '<div class="tt-letter-edit-source">';
        echo '<label>' . esc_html__( 'Template HTML', 'talenttrack' ) . '</label>';
        echo '<textarea name="html_content" rows="20" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:.92rem;">' . esc_textarea( $tpl ) . '</textarea>';
        echo '</div>';

        echo '<aside class="tt-letter-edit-legend"><h3>' . esc_html__( 'Variable legend', 'talenttrack' ) . '</h3><dl>';
        foreach ( DefaultLetterTemplates::variableLegend() as $var => $desc ) {
            echo '<dt><code>{' . esc_html( $var ) . '}</code></dt><dd>' . esc_html( $desc ) . '</dd>';
        }
        echo '</dl></aside>';
        echo '</div>';

        echo '<div class="tt-form-actions">';
        echo '<button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Save template', 'talenttrack' ) . '</button> ';
        echo '<button type="submit" formaction="' . esc_attr( add_query_arg( [ 'tt_view' => 'trial-letter-templates-editor', 'key' => $key, 'locale' => $locale ] ) ) . '" name="tt_trial_letter_action" value="reset_template" class="tt-button tt-button-danger" onclick="return confirm(\'' . esc_js( __( 'Reset this template to the shipped default?', 'talenttrack' ) ) . '\');">' . esc_html__( 'Reset to default', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';

        // Sample preview against a fake case.
        $sample = (object) [
            'id' => 0, 'player_id' => 0, 'track_id' => 0,
            'start_date' => gmdate( 'Y-m-d', time() - 28 * 86400 ),
            'end_date'   => gmdate( 'Y-m-d' ),
            'decision' => null, 'decision_made_at' => null, 'decision_made_by' => 0,
            'strengths_summary' => __( 'Strong work rate, good first touch, listens carefully to coaching cues.', 'talenttrack' ),
            'growth_areas'      => __( 'Defensive positioning under pressure; communication on the pitch.', 'talenttrack' ),
        ];
        $audience_for = [
            'admittance'         => \TT\Modules\Reports\AudienceType::TRIAL_ADMITTANCE,
            'deny_final'         => \TT\Modules\Reports\AudienceType::TRIAL_DENIAL_FINAL,
            'deny_encouragement' => \TT\Modules\Reports\AudienceType::TRIAL_DENIAL_ENCOURAGE,
        ];
        $audience = $audience_for[ $key ] ?? \TT\Modules\Reports\AudienceType::TRIAL_ADMITTANCE;
        $preview  = $engine->render( $audience, $sample, [
            'player_first_name' => __( 'Sample', 'talenttrack' ),
            'player_last_name'  => __( 'Player', 'talenttrack' ),
            'player_full_name'  => __( 'Sample Player', 'talenttrack' ),
            'player_age'        => '14',
            'club_name'         => get_bloginfo( 'name' ) ?: 'Demo FC',
            'head_of_development_name' => __( 'A. Coach', 'talenttrack' ),
            'track_name'        => __( 'Standard', 'talenttrack' ),
            'current_season'    => '2025/2026',
            'next_season'       => '2026/2027',
        ] );

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Preview (sample data)', 'talenttrack' ) . '</h2>';
        echo '<div class="tt-letter-preview">' . wp_kses_post( $preview ) . '</div></section>';
    }

    private static function handlePost( int $user_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        $action = isset( $_POST['tt_trial_letter_action'] ) ? sanitize_key( (string) $_POST['tt_trial_letter_action'] ) : '';
        if ( $action === '' ) return;

        if ( $action === 'save_settings' ) {
            if ( ! isset( $_POST['tt_trial_letter_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_trial_letter_settings_nonce'] ) ), 'tt_trial_letter_settings' ) ) return;
            // #0052 PR-A — write tenant-scoped settings to tt_config.
            \TT\Infrastructure\Query\QueryHelpers::set_config(
                'tt_trial_admittance_include_acceptance_slip',
                ! empty( $_POST['acceptance_slip_enabled'] ) ? '1' : '0'
            );
            \TT\Infrastructure\Query\QueryHelpers::set_config(
                'tt_trial_acceptance_response_days',
                (string) max( 1, (int) ( $_POST['acceptance_response_days'] ?? 14 ) )
            );
            \TT\Infrastructure\Query\QueryHelpers::set_config(
                'tt_trial_acceptance_club_address',
                sanitize_textarea_field( wp_unslash( (string) ( $_POST['acceptance_club_address'] ?? '' ) ) )
            );
            return;
        }

        $key    = isset( $_POST['key'] )    ? sanitize_key( (string) $_POST['key'] )    : '';
        $locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['locale'] ) ) : '';
        if ( $key === '' || $locale === '' ) return;
        if ( ! isset( $_POST['tt_trial_letter_save_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_trial_letter_save_nonce'] ) ), 'tt_trial_letter_save_' . $key ) ) return;

        $repo = new TrialLetterTemplatesRepository();
        if ( $action === 'save_template' ) {
            $html = (string) wp_unslash( (string) ( $_POST['html_content'] ?? '' ) );
            $repo->save( $key, $locale, wp_kses_post( $html ), $user_id );
        } elseif ( $action === 'reset_template' ) {
            $repo->resetToDefault( $key, $locale );
        }
    }

    /**
     * @return string[]
     */
    private static function availableLocales(): array {
        $locales = [ 'en_US', 'nl_NL' ];
        $current = get_locale();
        if ( $current && ! in_array( $current, $locales, true ) ) {
            $locales[] = $current;
        }
        return $locales;
    }
}
