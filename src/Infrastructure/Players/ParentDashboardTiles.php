<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ParentDashboardTiles (#1992) — the curated, child-scoped tile subset a
 * parent persona sees on the legacy dashboard grid.
 *
 * The parent does NOT get the full player rail. They get a tight subset
 * focused on "where is my child now / what do they need next" (CLAUDE.md
 * §1): development, card, evaluations, activities, PDP. Each tile reads
 * the child's record (the URL carries `?player_id=N`), so #1991's me-view
 * resolution + canViewPlayer auth pass.
 *
 * This is the business-logic decision ("which tiles, framed how") kept
 * out of the view (§4). The view (FrontendTileGrid) only resolves the
 * child-scoped URL and paints the markup. Icons / colours mirror the
 * canonical Me-tile definitions in CoreSurfaceRegistration so the parent
 * surfaces look like the player surfaces they map to.
 */
final class ParentDashboardTiles {

    /**
     * The curated parent tile set. Labels are child-framed at render time
     * by the caller (it knows the child's name); here we carry the neutral
     * fallback label so a label-less render still reads sensibly.
     *
     * @return list<array{view_slug:string,label:string,icon:string,color:string}>
     */
    public static function tiles(): array {
        $tiles = [
            [
                'view_slug' => 'my-development',
                'label'     => __( 'Development', 'talenttrack' ),
                'icon'      => 'goals',
                'color'     => '#0b3d2e',
            ],
            [
                'view_slug' => 'overview',
                'label'     => __( 'Player card', 'talenttrack' ),
                'icon'      => 'rate-card',
                'color'     => '#1d7874',
            ],
            [
                'view_slug' => 'my-evaluations',
                'label'     => __( 'Evaluations', 'talenttrack' ),
                'icon'      => 'evaluations',
                'color'     => '#7c3a9e',
            ],
            [
                'view_slug' => 'my-activities',
                'label'     => __( 'Activities', 'talenttrack' ),
                'icon'      => 'activities',
                'color'     => '#c9962a',
            ],
            [
                'view_slug' => 'my-pdp',
                'label'     => __( 'Development plan', 'talenttrack' ),
                'icon'      => 'goals',
                'color'     => '#1d7874',
            ],
        ];

        /**
         * Allow an integrator to tune the parent tile subset (add / remove
         * a surface) without forking the view. Filtered value must keep
         * the same row shape.
         *
         * @param list<array{view_slug:string,label:string,icon:string,color:string}> $tiles
         */
        $filtered = apply_filters( 'tt_parent_dashboard_tiles', $tiles );
        return is_array( $filtered ) ? $filtered : $tiles;
    }
}
