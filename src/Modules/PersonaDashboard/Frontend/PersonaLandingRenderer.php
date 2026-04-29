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
        // Sprint 3 flag flip — default ON. Sites that need to roll back to
        // the legacy FrontendTileGrid path can set persona_dashboard.enabled
        // to '0' in tt_config (one-release rollback window).
        $flag = \TT\Infrastructure\Query\QueryHelpers::get_config( 'persona_dashboard.enabled', '1' );
        return $flag !== '0';
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
        if ( in_array( $persona, [ 'head_coach', 'assistant_coach' ], true ) ) {
            self::renderTeamTabs( $user_id );
        }
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

    /**
     * Coach landings render team tabs above the grid (sprint 3).
     * Tabs are driven by QueryHelpers::get_teams_for_coach($user_id);
     * the active tab is persisted via user-meta `tt_active_team_tab`
     * and picked up via the ?tt_team_tab= query arg. Sprint 3 ships
     * the visual + persistence; downstream widgets that want to
     * filter by team read the active tab the same way.
     */
    private static function renderTeamTabs( int $user_id ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Query\\QueryHelpers' ) ) return;
        $teams = \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( $user_id );
        if ( ! is_array( $teams ) || count( $teams ) < 2 ) return;

        $active = self::activeTeamTab( $user_id, $teams );
        echo '<nav class="tt-pd-team-tabs" role="tablist" aria-label="' . esc_attr__( 'My teams', 'talenttrack' ) . '">';
        $all_cls = $active === 0 ? 'tt-pd-team-tab is-active' : 'tt-pd-team-tab';
        echo '<a class="' . esc_attr( $all_cls ) . '" role="tab" aria-selected="' . ( $active === 0 ? 'true' : 'false' ) . '" href="?tt_team_tab=0">'
            . esc_html__( 'All', 'talenttrack' )
            . '</a>';
        foreach ( $teams as $t ) {
            $tid = (int) $t->id;
            $cls = $tid === $active ? 'tt-pd-team-tab is-active' : 'tt-pd-team-tab';
            $name = (string) ( $t->name ?? $t->team_name ?? '' );
            echo '<a class="' . esc_attr( $cls ) . '" role="tab" aria-selected="' . ( $tid === $active ? 'true' : 'false' ) . '" href="?tt_team_tab=' . (int) $tid . '">'
                . esc_html( $name )
                . '</a>';
        }
        echo '</nav>';
    }

    /**
     * @param array<int,object> $teams
     */
    private static function activeTeamTab( int $user_id, array $teams ): int {
        $valid_ids = array_map( static fn( $t ): int => (int) $t->id, $teams );
        if ( isset( $_GET['tt_team_tab'] ) ) {
            $picked = absint( $_GET['tt_team_tab'] );
            if ( $picked === 0 || in_array( $picked, $valid_ids, true ) ) {
                update_user_meta( $user_id, 'tt_active_team_tab', $picked );
                return $picked;
            }
        }
        $stored = (int) get_user_meta( $user_id, 'tt_active_team_tab', true );
        if ( $stored === 0 || in_array( $stored, $valid_ids, true ) ) {
            return $stored;
        }
        return 0;
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
