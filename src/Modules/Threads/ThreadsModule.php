<?php
namespace TT\Modules\Threads;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Threads\Adapters\GoalThreadAdapter;
use TT\Modules\Threads\Adapters\PlayerThreadAdapter;
use TT\Modules\Threads\Rest\ThreadsRestController;

/**
 * ThreadsModule (#0028) — polymorphic conversation primitive.
 *
 * Owns:
 *   - Schema (migration 0043): tt_thread_messages + tt_thread_reads.
 *   - ThreadTypeRegistry (v1 wires `goal` from inside this module).
 *   - REST: /threads/{type}/{id}/* — list, post, edit, delete, read.
 *   - Frontend component: TT\Shared\Frontend\Components\FrontendThreadView.
 *   - GoalSystemMessageSubscriber: hooks tt_goal_saved + tt_goal_status_changed
 *     to write is_system=1 messages.
 *   - GoalNotificationSubscriber: forwards thread_message_posted events to
 *     EmailDispatcher fan-out (and PushDispatcher when #0042 ships).
 *   - Audit: 4 event types (thread_message_posted, _edited, _deleted,
 *     _visibility_changed).
 *
 * Future consumers (#0017 trial cases, #0014 scout reports, #0044 PDP)
 * register their own adapters from their own boot().
 */
class ThreadsModule implements ModuleInterface {

    public function getName(): string { return 'threads'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // v1 — register the goal adapter from this module itself.
        ThreadTypeRegistry::register( 'goal', new GoalThreadAdapter() );
        // #0085 — second registered thread type. Anchors a thread on
        // a player record for staff-only running observations.
        ThreadTypeRegistry::register( 'player', new PlayerThreadAdapter() );

        ThreadsRestController::init();
        Subscribers\GoalSystemMessageSubscriber::init();
        Subscribers\AuditSubscriber::init();
        Subscribers\NotificationSubscriber::init();

        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
    }

    /**
     * No new caps in v1 — authorization runs through the
     * ThreadTypeAdapter's canRead/canPost. Method exists as a hook
     * point in case future thread types need their own gates.
     */
    public static function ensureCapabilities(): void {}
}
