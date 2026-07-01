<?php
namespace TT\Modules\Methodology;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * MethodologyModule (#0027) — football methodology library.
 *
 * Owns:
 *   - Schema (migration 0015): formations, formation_positions,
 *     principles, set_pieces, methodology_visions, principle_links,
 *     session_principles + tt_goals.linked_principle_id column.
 *   - Repositories under Repositories/.
 *   - wp-admin browser + edit pages.
 *   - Frontend read-only view (registered as a coaching tile slug).
 *   - Capabilities: tt_view_methodology + tt_edit_methodology.
 *
 * Frontend authoring is a v2 concern; v1 is wp-admin authoring +
 * frontend reading, mirroring the #0019 pattern that other admin
 * surfaces took.
 */
class MethodologyModule implements ModuleInterface {

    public function getName(): string { return 'methodology'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        // #2225 — frontend authoring. The Principles manage tab registers
        // itself into the extensible MethodologyManageRegistry (sibling
        // entities #2226–#2230 register their own tab the same way, without
        // editing a shared switch). The REST base + Principles controller
        // expose the same CRUD over /wp-json/talenttrack/v1/methodology/*.
        Frontend\Manage\PrinciplesManageTab::register();
        Rest\PrinciplesRestController::init();

        // #2228 — set-pieces manage tab + REST, built on the #2225 scaffold.
        Frontend\Manage\SetPiecesManageTab::register();
        Rest\SetPiecesRestController::init();

        if ( is_admin() ) {
            Admin\MethodologyPage::init();
            Admin\PrincipleEditPage::init();
            Admin\PositionEditPage::init();
            Admin\SetPieceEditPage::init();
            Admin\VisionEditPage::init();
            Admin\FrameworkPrimerEditPage::init();
            Admin\PhaseEditPage::init();
            Admin\LearningGoalEditPage::init();
            Admin\InfluenceFactorEditPage::init();
            Admin\FootballActionsPage::init();
            Admin\FootballActionEditPage::init();
        }

        // #1064 — printable methodology reference card. Coach prints
        // once per season, laminates, brings to every 1:1. Up to 3
        // pages with selectable inclusion via the `sections` query.
        Print\MethodologyReferencePrintRouter::init();
    }

    /**
     * Idempotent capability assignment. tt_view_methodology lands on
     * coaches + admins (anyone who reads the dashboard); editing is
     * limited to club admins + head-of-development.
     */
    public static function ensureCapabilities(): void {
        $view = 'tt_view_methodology';
        $edit = 'tt_edit_methodology';

        $view_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach', 'tt_readonly_observer' ];
        $edit_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];

        foreach ( $view_roles as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $view ) ) $role->add_cap( $view );
        }
        foreach ( $edit_roles as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $edit ) ) $role->add_cap( $edit );
        }
    }
}
