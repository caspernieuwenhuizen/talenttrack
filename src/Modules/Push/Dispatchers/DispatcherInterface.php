<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DispatcherInterface — one channel that can deliver a notification
 * to a user (#0042 Sprint 3). Lives next to the workflow engine's
 * existing notification path; the chain is iterated in order and the
 * first success wins. Failures fall through to the next link.
 *
 * `deliver()` returns true when the channel handled the message — even
 * if the underlying network attempt fails. The return is "did this
 * dispatcher claim the message?" not "did the user definitely see it."
 * The chain stops once a dispatcher returns true; downstream HTTP-410
 * cleanup happens inside the dispatcher.
 *
 * `applicableTo()` is the eligibility check: PushDispatcher returns
 * false when the user has no active subscription, ParentEmailDispatcher
 * returns false when the player has no linked parent, etc. This keeps
 * the chain logic in one place.
 *
 * @phpstan-type DispatchContext array{
 *   user_id:int,
 *   title:string,
 *   body:string,
 *   url?:string,
 *   tag?:string,
 *   data?:array<string,mixed>
 * }
 */
interface DispatcherInterface {

    public function key(): string;

    /**
     * Whether this dispatcher can plausibly deliver to the given user.
     * Cheap to call; the chain skips dispatchers that return false.
     *
     * @param DispatchContext $context
     */
    public function applicableTo( array $context ): bool;

    /**
     * Attempt delivery. Returns true if this dispatcher claims the
     * message — the chain stops here. Returns false to fall through
     * to the next link.
     *
     * @param DispatchContext $context
     */
    public function deliver( array $context ): bool;
}
