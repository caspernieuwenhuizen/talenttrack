<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureStatusService;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendFeaturesView (#1486) — read-only "what's switched on" status
 * surface, reachable by every persona at `?tt_view=features`.
 *
 * The management page (`?tt_view=modules`) stays the write surface,
 * gated by `tt_manage_modules`. This view never mutates anything: it
 * lists each user-facing module with an ON/OFF badge, what it provides,
 * and any sub-feature toggles beneath it. All shaping lives in
 * FeatureStatusService (CLAUDE.md §4); the view only composes.
 */
class FrontendFeaturesView extends FrontendViewBase {

    /** Wire the read-only REST endpoint. Called from Kernel::boot. */
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'registerRest' ] );
    }

    public static function registerRest(): void {
        register_rest_route( 'talenttrack/v1', '/feature-status', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restList' ],
                'permission_callback' => static fn() => is_user_logged_in(),
            ],
        ] );
    }

    /** @return \WP_REST_Response */
    public static function restList() {
        return new \WP_REST_Response( FeatureStatusService::overview(), 200 );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Features', 'talenttrack' ) );

        $actions = '';
        if ( current_user_can( 'tt_manage_modules' ) ) {
            $manage_url = add_query_arg( [ 'tt_view' => 'modules' ], RecordLink::dashboardUrl() );
            $actions = '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $manage_url ) . '">'
                . esc_html__( 'Manage modules & features', 'talenttrack' ) . '</a>';
        }
        self::renderHeader( __( 'Features', 'talenttrack' ), $actions );

        echo '<p style="max-width:680px; color:#5b6e75;">'
            . esc_html__( 'Which parts of TalentTrack are switched on for your academy. This is a read-only overview — ask an administrator to change anything.', 'talenttrack' )
            . '</p>';

        $overview = FeatureStatusService::overview();
        if ( empty( $overview ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Nothing to show yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-feature-status" style="display:grid; gap:12px; max-width:760px;">';
        foreach ( $overview as $module ) {
            self::renderModuleCard( $module );
        }
        echo '</div>';
    }

    /**
     * @param array{label:string, enabled:bool, always_on:bool, provides:list<string>, features:list<array{key:string,label:string,description:string,enabled:bool}>} $module
     */
    private static function renderModuleCard( array $module ): void {
        echo '<section class="tt-feature-card" style="border:1px solid #e3e7ea; border-radius:10px; padding:14px 16px; background:#fff;">';

        echo '<div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">';
        echo '<h2 style="margin:0; font-size:16px; color:#1a1d21;">' . esc_html( $module['label'] ) . '</h2>';
        echo self::badge( $module['enabled'], $module['always_on'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() returns escaped HTML.
        echo '</div>';

        if ( ! empty( $module['provides'] ) ) {
            echo '<p style="margin:6px 0 0; color:#5b6e75; font-size:13px;">'
                . esc_html__( 'Provides:', 'talenttrack' ) . ' '
                . esc_html( implode( ', ', $module['provides'] ) )
                . '</p>';
        }

        if ( ! empty( $module['features'] ) ) {
            echo '<ul style="list-style:none; margin:12px 0 0; padding:0; display:grid; gap:8px;">';
            foreach ( $module['features'] as $feature ) {
                echo '<li style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:8px 10px; background:#f6f8f9; border-radius:8px;">';
                echo '<span style="min-width:0;">';
                echo '<strong style="font-size:14px; color:#1a1d21;">' . esc_html( (string) $feature['label'] ) . '</strong>';
                if ( (string) $feature['description'] !== '' ) {
                    echo '<span style="display:block; color:#5b6e75; font-size:12px;">' . esc_html( (string) $feature['description'] ) . '</span>';
                }
                echo '</span>';
                echo self::badge( (bool) $feature['enabled'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() returns escaped HTML.
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</section>';
    }

    /**
     * On/Off pill. Carries the word as well as the colour so it never
     * relies on colour alone (CLAUDE.md §2 accessibility), and an
     * aria-label so screen readers announce the state.
     */
    private static function badge( bool $enabled, bool $always_on ): string {
        if ( $always_on ) {
            $text = __( 'Always on', 'talenttrack' );
            $bg   = '#e7f0e9'; $fg = '#1e6b3a';
        } elseif ( $enabled ) {
            $text = __( 'On', 'talenttrack' );
            $bg   = '#e7f0e9'; $fg = '#1e6b3a';
        } else {
            $text = __( 'Off', 'talenttrack' );
            $bg   = '#eceff1'; $fg = '#5b6e75';
        }
        return '<span class="tt-feature-badge" aria-label="' . esc_attr( $text ) . '" '
            . 'style="flex:0 0 auto; font-size:12px; font-weight:600; padding:4px 10px; border-radius:999px; '
            . 'background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . ';">'
            . esc_html( $text ) . '</span>';
    }
}
