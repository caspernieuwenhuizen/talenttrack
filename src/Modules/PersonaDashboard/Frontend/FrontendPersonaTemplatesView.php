<?php
namespace TT\Modules\PersonaDashboard\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Modules\PersonaDashboard\Admin\EditorPage;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendPersonaTemplatesView (#2978) — the persona dashboard editor on the
 * frontend, at `?tt_view=persona-templates`.
 *
 * Deciding which tiles each persona sees when they log in is about as
 * academy-specific as configuration gets, and it was reachable only from
 * wp-admin.
 *
 * ## THE TRAP, WHICH IS REAL AND UNGUARDED
 *
 * The editor persists **`data_source` = a tile's view slug** into stored
 * persona-dashboard rows. Renaming a tile slug therefore orphans every
 * stored row that referenced it — silently, with no error and no migration
 * warning. The tile simply stops appearing on the dashboards that asked for
 * it, and the only symptom is somebody saying "my dashboard looks empty".
 *
 * This is the same landmine #2885 hit. So:
 *
 *   - **Do not rename a tile slug as a convenience.** Not in this file, not
 *     in `TileRegistry`, not while tidying.
 *   - If a rename is genuinely needed, it is a **migration** that rewrites
 *     the stored `data_source` values, in its own PR, with its own review.
 *   - This surface reads and writes the **same stored rows** as the wp-admin
 *     editor through the same `/personas/{slug}/template` REST routes. There
 *     is no parallel representation to keep in step.
 *
 * ## One editor, two chromes
 *
 * The markup, the bootstrap and the JS all come from
 * `EditorPage::renderEditor()`. Only the button and wrapper class names
 * differ, because wp-admin styles `.button` and the frontend styles
 * `.tt-btn`. Two copies of a drag-and-drop editor would be two copies of the
 * `data_source` coupling above, which is the last thing worth duplicating.
 */
class FrontendPersonaTemplatesView extends FrontendViewBase {

    public const SLUG = 'persona-templates';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( EditorPage::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to edit dashboard layouts.', 'talenttrack' )
                . '</p>';
            return;
        }

        // #1538 — the same direct-URL guard the wp-admin page applies. The
        // tile is already hidden when the sub-feature is off; this is what
        // stops the URL working anyway.
        if ( ! FeatureRegistry::isEnabled( EditorPage::FEATURE ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Dashboard layouts', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'The dashboard layout editor is turned off for this academy.', 'talenttrack' )
                . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Dashboard layouts', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'configuration', __( 'Configuration', 'talenttrack' ) ) ]
        );

        self::enqueueAssets();
        EditorPage::enqueueEditorAssets();
        wp_enqueue_style(
            'tt-frontend-persona-templates',
            TT_PLUGIN_URL . 'assets/css/frontend-persona-templates.css',
            [ 'tt-persona-dashboard-editor' ],
            TT_VERSION
        );

        // No `renderHeader()`: the editor draws its own toolbar with the
        // page title in it, and a second heading above a toolbar that
        // already says "Dashboard layouts" is chrome for its own sake.
        EditorPage::renderEditor( [
            'wrapper' => 'tt-pde-wrap tt-pde-wrap--frontend',
            'button'  => 'tt-btn tt-btn-secondary tt-pde-btn',
            'primary' => 'tt-btn tt-btn-primary tt-pde-btn-primary',
        ] );
    }
}
