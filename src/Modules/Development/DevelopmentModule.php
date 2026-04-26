<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Development\Notifications\AuthorNotifier;
use TT\Modules\Development\Notifications\GoalSpawner;

/**
 * DevelopmentModule — TalentTrack development management (#0009).
 *
 * One module covering both halves of the bundled epic:
 *   A. Submission → refinement → lead-dev approval → GitHub commit
 *      (plugin's own dev-workflow tool).
 *   B. Player-development tracks + tagged ideas + progress
 *      (product-feature roadmap surface).
 *
 * The cap matrix:
 *   - `tt_submit_idea`   → granted to every TT role except player + parent
 *   - `tt_refine_idea`   → administrator + tt_head_dev + tt_club_admin
 *   - `tt_view_dev_board`→ administrator + tt_head_dev + tt_club_admin
 *   - `tt_promote_idea`  → administrator only
 *
 * Caps are seeded by migration 0024 on first install. `ensureCaps()`
 * runs on `init` so role re-grants land idempotently in subsequent
 * versions without a fresh activation.
 */
class DevelopmentModule implements ModuleInterface {

    public function getName(): string { return 'development'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCaps' ] );
        add_action( 'init', [ AuthorNotifier::class, 'register' ] );
        add_action( 'init', [ GoalSpawner::class, 'register' ] );

        // Form-post handlers — frontend submits to admin-post.php so we
        // get a clean redirect after the write.
        add_action( 'admin_post_tt_dev_idea_submit',  [ Frontend\IdeaSubmitHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_dev_idea_refine',  [ Frontend\IdeaRefineHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_dev_idea_promote', [ Frontend\IdeaPromoteHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_dev_idea_reject',  [ Frontend\IdeaRejectHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_dev_track_save',   [ Frontend\TrackSaveHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_dev_track_delete', [ Frontend\TrackDeleteHandler::class, 'handle' ] );
    }

    public static function ensureCaps(): void {
        $submit_roles = [
            'administrator',
            'tt_head_dev',
            'tt_club_admin',
            'tt_coach',
            'tt_scout',
            'tt_staff',
            'tt_readonly_observer',
        ];
        foreach ( $submit_roles as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_submit_idea' ) ) {
                $role->add_cap( 'tt_submit_idea' );
            }
        }

        foreach ( [ 'administrator', 'tt_head_dev', 'tt_club_admin' ] as $slug ) {
            $role = get_role( $slug );
            if ( ! $role ) continue;
            if ( ! $role->has_cap( 'tt_refine_idea' ) ) {
                $role->add_cap( 'tt_refine_idea' );
            }
            if ( ! $role->has_cap( 'tt_view_dev_board' ) ) {
                $role->add_cap( 'tt_view_dev_board' );
            }
        }

        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( 'tt_promote_idea' ) ) {
            $admin->add_cap( 'tt_promote_idea' );
        }
    }
}
