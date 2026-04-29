<?php
namespace TT\Modules\PersonaDashboard\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Registry\PersonaTemplateRegistry;
use TT\Shared\Frontend\FrontendTileGrid;

/**
 * PersonaLandingRenderer — top-level dashboard renderer for #0060.
 *
 * DashboardShortcode delegates here when the tt_persona_dashboard_enabled
 * config flag is on. The renderer:
 *   1. Resolves the active persona (user-meta override → first
 *      available → null).
 *   2. Loads the (persona, club_id) template from PersonaTemplateRegistry.
 *   3. Bumps tt_user_meta.tt_last_visited_at on every render.
 *   4. Renders hero band → task band → grid via GridRenderer.
 *
 * Sprint 1 ships behind the flag (default off). When the flag is off
 * the legacy FrontendTileGrid stays the live render path.
 */
final class PersonaLandingRenderer {

    public static function shouldRender(): bool {
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) return false;
        $flag = \TT\Infrastructure\Query\QueryHelpers::get_config( 'persona_dashboard.enabled', '' );
        return $flag === '1';
    }

    public static function render( int $user_id, string $base_url ): void {
        self::enqueueAssets();

        $persona = self::resolvePersona( $user_id );
        $club_id = self::currentClubId();

        // Bump last-visited so "since you last visited" recap diffs work.
        update_user_meta( $user_id, 'tt_last_visited_at', current_time( 'mysql' ) );

        if ( $persona === null ) {
            // No mapped persona — fall back to the legacy tile grid.
            FrontendTileGrid::render();
            return;
        }

        $template = PersonaTemplateRegistry::resolve( $persona, $club_id );
        $ctx      = new RenderContext( $user_id, $club_id, $persona, $base_url );

        echo '<div class="tt-pd-landing" data-tt-pd-persona="' . esc_attr( $persona ) . '">';
        self::renderRoleSwitcher( $user_id, $persona );
        GridRenderer::render( $template, $ctx );
        echo '</div>';

        // If the template's grid was empty (and we have no hero/task either),
        // fall back to the legacy tile grid so admins always see something.
        if ( $template->hero === null && $template->task === null && $template->grid->isEmpty() ) {
            FrontendTileGrid::render();
        }
    }

    private static function enqueueAssets(): void {
        wp_enqueue_style(
            'tt-persona-dashboard',
            TT_PLUGIN_URL . 'assets/css/persona-dashboard.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-persona-dashboard',
            TT_PLUGIN_URL . 'assets/js/persona-dashboard.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-persona-dashboard', 'TT_PersonaDashboard', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    private static function resolvePersona( int $user_id ): ?string {
        if ( $user_id <= 0 ) return null;
        $active = PersonaResolver::activePersona( $user_id );
        if ( $active !== null ) return $active;
        $available = PersonaResolver::personasFor( $user_id );
        return $available[0] ?? null;
    }

    private static function currentClubId(): int {
        if ( class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' ) ) {
            return (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
        }
        return 1;
    }

    private static function renderRoleSwitcher( int $user_id, string $active ): void {
        $available = PersonaResolver::personasFor( $user_id );
        if ( count( $available ) < 2 ) return;

        $labels = [
            'player'              => __( 'Player',              'talenttrack' ),
            'parent'              => __( 'Parent',              'talenttrack' ),
            'head_coach'          => __( 'Head coach',          'talenttrack' ),
            'assistant_coach'     => __( 'Assistant coach',     'talenttrack' ),
            'team_manager'        => __( 'Team manager',        'talenttrack' ),
            'head_of_development' => __( 'Head of Development', 'talenttrack' ),
            'scout'               => __( 'Scout',               'talenttrack' ),
            'academy_admin'       => __( 'Academy admin',       'talenttrack' ),
            'readonly_observer'   => __( 'Read-only observer',  'talenttrack' ),
        ];

        echo '<div class="tt-pd-role-switcher" role="group" aria-label="' . esc_attr__( 'Switch persona', 'talenttrack' ) . '">';
        echo '<span class="tt-pd-role-switcher-label">' . esc_html__( 'Viewing as', 'talenttrack' ) . '</span>';
        foreach ( $available as $p ) {
            $cls   = $p === $active ? 'tt-pd-role-pill is-active' : 'tt-pd-role-pill';
            $label = $labels[ $p ] ?? str_replace( '_', ' ', $p );
            echo '<button type="button" class="' . esc_attr( $cls ) . '" data-tt-pd-active-persona="' . esc_attr( $p ) . '">'
                . esc_html( $label )
                . '</button>';
        }
        echo '</div>';
    }
}
