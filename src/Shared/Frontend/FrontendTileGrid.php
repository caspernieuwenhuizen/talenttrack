<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Icons\IconRenderer;
use TT\Shared\Tiles\TileRegistry;

/**
 * FrontendTileGrid — v2.21.0 tile landing page for the frontend
 * dashboard shortcode.
 *
 * When a user lands on the dashboard without a specific ?tt_view
 * query param, they see a grid of tiles appropriate to their role:
 *
 *   - Player      → my card, my team, my evals, my sessions, my goals, my profile
 *   - Coach       → teams, players, evals, sessions, goals, podium, rate cards, comparison
 *   - Admin       → coach tiles + "Go to admin" + access control
 *   - Observer    → same discovery as coach (cap-gated), write actions blocked inside
 *
 * Tile visibility is driven entirely by WordPress capabilities so the
 * same tile set automatically respects the tt_readonly_observer role
 * (same as coach for viewing; write-blocked at controller level).
 *
 * Tapping a tile appends ?tt_view=<slug> and reloads. Handling of the
 * sub-views is left to the existing Player/Coach dashboard classes —
 * this layer is pure navigation chrome.
 */
class FrontendTileGrid {

    /**
     * Render the tile grid for the current user. Assumes we're inside
     * a `<div class="tt-dashboard">` already.
     */
    public static function render(): void {
        $user_id = get_current_user_id();
        $greeting = self::greeting( $user_id );

        // #0033 finalisation — tiles come from `TileRegistry`. The
        // registry filters by module-enabled state and per-user
        // capability; we only resolve URLs from the per-tile
        // `view_slug` against the current request's base URL.
        $base   = self::shortcodeBaseUrl();
        $groups = TileRegistry::tilesForUserGrouped( $user_id );
        foreach ( $groups as &$group ) {
            foreach ( $group['tiles'] as &$tile ) {
                if ( ! isset( $tile['url'] ) || $tile['url'] === '' ) {
                    $slug = (string) ( $tile['view_slug'] ?? '' );
                    $tile['url'] = $slug !== ''
                        ? add_query_arg( 'tt_view', $slug, $base )
                        : '';
                }
            }
            unset( $tile );
        }
        unset( $group );

        // #0036 — tile scale (50–150) drives padding, icon, and font sizes
        // via a single CSS custom property. 100 = baseline.
        $scale = (int) QueryHelpers::get_config( 'tile_scale', '100' );
        if ( $scale < 50 || $scale > 150 ) $scale = 100;
        $scale_factor = $scale / 100;

        ?>
        <style>
        .tt-ftile-grid-wrap { --tt-tile-scale: <?php echo esc_html( (string) $scale_factor ); ?>; }
        .tt-ftile-greeting {
            font-size: calc(17px * var(--tt-tile-scale));
            font-weight: 600;
            margin: 16px 0 14px;
            color: #1a1d21;
        }
        .tt-ftile-section-label {
            font-size: calc(10px * var(--tt-tile-scale));
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #8a9099;
            margin: calc(18px * var(--tt-tile-scale)) 0 calc(8px * var(--tt-tile-scale));
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tt-ftile-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dcdcde;
        }
        .tt-ftile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(calc(220px * var(--tt-tile-scale)), 1fr));
            gap: calc(10px * var(--tt-tile-scale));
        }
        .tt-ftile {
            display: flex;
            align-items: center;
            gap: calc(11px * var(--tt-tile-scale));
            background: #fff;
            border: 1px solid #e5e7ea;
            border-radius: 8px;
            padding: calc(12px * var(--tt-tile-scale)) calc(14px * var(--tt-tile-scale));
            text-decoration: none;
            color: #1a1d21;
            min-height: calc(60px * var(--tt-tile-scale));
            transition: transform 180ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 180ms ease,
                        border-color 180ms ease;
        }
        .tt-ftile:hover, .tt-ftile:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #d0d4d8;
            color: #1a1d21;
        }
        .tt-ftile-icon {
            flex-shrink: 0;
            width: calc(38px * var(--tt-tile-scale));
            height: calc(38px * var(--tt-tile-scale));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .tt-ftile-icon .tt-icon {
            width: calc(20px * var(--tt-tile-scale));
            height: calc(20px * var(--tt-tile-scale));
        }
        .tt-ftile-body {
            flex: 1;
            min-width: 0;
        }
        .tt-ftile-label {
            font-weight: 600;
            font-size: calc(14px * var(--tt-tile-scale));
            line-height: 1.25;
            margin: 0 0 calc(2px * var(--tt-tile-scale));
            color: #1a1d21;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .tt-ftile-desc {
            color: #6b7280;
            font-size: calc(12px * var(--tt-tile-scale));
            line-height: 1.35;
            margin: 0;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        @media (max-width: 640px) {
            .tt-ftile-grid { grid-template-columns: 1fr; }
        }
        </style>

        <div class="tt-ftile-grid-wrap">
        <div class="tt-ftile-greeting"><?php echo esc_html( $greeting ); ?></div>

        <?php
        // #0033 Sprint 4 — split groups into "Today's work" + "Setup &
        // administration" sections. Daily-use tiles (Me / Tasks / People
        // / Performance / Analytics) go up top, open by default;
        // admin/configuration tiles (Development / Administration) go
        // into a collapsible section, open only for admin personas.
        [ $work_groups, $setup_groups ] = self::splitByKind( $groups );
        $is_admin_persona = current_user_can( 'tt_edit_settings' );
        ?>

        <details class="tt-ftile-section" open>
            <summary class="tt-ftile-section-summary"><?php esc_html_e( "Today's work", 'talenttrack' ); ?></summary>
            <?php self::renderGroups( $work_groups ); ?>
        </details>

        <?php if ( ! empty( $setup_groups ) ) : ?>
            <details class="tt-ftile-section"<?php echo $is_admin_persona ? ' open' : ''; ?>>
                <summary class="tt-ftile-section-summary"><?php esc_html_e( 'Setup & administration', 'talenttrack' ); ?></summary>
                <?php self::renderGroups( $setup_groups ); ?>
            </details>
        <?php endif; ?>
        </div>
        <style>
        .tt-ftile-section { margin-top: calc(14px * var(--tt-tile-scale)); }
        .tt-ftile-section-summary {
            font-size: calc(13px * var(--tt-tile-scale));
            font-weight: 700;
            letter-spacing: 0.04em;
            color: #1a1d21;
            margin: 0 0 calc(8px * var(--tt-tile-scale));
            cursor: pointer;
            user-select: none;
        }
        .tt-ftile-section[open] > .tt-ftile-section-summary { color: #0a0d12; }
        </style>
        <?php
    }

    /**
     * Sprint 4 — split rendered groups into work + setup buckets by
     * label. Groups not declared in either map default to work.
     *
     * @param array<int, array{label:string, tiles:array}> $groups
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private static function splitByKind( array $groups ): array {
        $setup_labels = [
            __( 'Development', 'talenttrack' ),
            __( 'Administration', 'talenttrack' ),
        ];
        $work = [];
        $setup = [];
        foreach ( $groups as $g ) {
            if ( in_array( (string) $g['label'], $setup_labels, true ) ) {
                $setup[] = $g;
            } else {
                $work[] = $g;
            }
        }
        return [ $work, $setup ];
    }

    /**
     * Render the section heading + tile grid for each visible group.
     * Module-enabled + capability + persona filtering already happened
     * inside `TileRegistry::tilesForUserGrouped()`; this method only
     * paints the markup.
     *
     * @param array<int, array{label:string, tiles:array}> $groups
     */
    private static function renderGroups( array $groups ): void {
        foreach ( $groups as $group ) {
            $tiles = $group['tiles'];
            if ( empty( $tiles ) ) continue;
            ?>
            <div class="tt-ftile-section-label">
                <span><?php echo esc_html( (string) $group['label'] ); ?></span>
            </div>
            <div class="tt-ftile-grid">
                <?php foreach ( $tiles as $tile ) : ?>
                    <a class="tt-ftile" href="<?php echo esc_url( (string) ( $tile['url'] ?? '' ) ); ?>">
                        <span class="tt-ftile-icon" style="background:<?php echo esc_attr( (string) ( $tile['color'] ?? '#5b6e75' ) ); ?>;">
                            <?php echo IconRenderer::render( (string) ( $tile['icon'] ?? '' ) ); ?>
                        </span>
                        <div class="tt-ftile-body">
                            <div class="tt-ftile-label"><?php echo esc_html( (string) ( $tile['label'] ?? '' ) ); ?></div>
                            <p class="tt-ftile-desc"><?php echo esc_html( (string) ( $tile['desc'] ?? '' ) ); ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }


    private static function greeting( int $user_id ): string {
        $user = get_userdata( $user_id );
        $name = $user ? $user->display_name : '';
        return $name !== ''
            ? sprintf(
                /* translators: %s is user display name */
                __( 'Welcome, %s', 'talenttrack' ),
                $name
            )
            : __( 'Welcome', 'talenttrack' );
    }
    /**
     * Base URL of the current page without tt_view or any drill-down
     * params. Tiles append ?tt_view=<slug> to this.
     */
    private static function shortcodeBaseUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab' ],
            $current ?: home_url( '/' )
        );
    }
}
