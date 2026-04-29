<?php
namespace TT\Modules\Threads\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ThreadTypeAdapter — every consumer module implements this.
 *
 * The thread primitive (#0028) is polymorphic: it stores messages
 * keyed on (thread_type, thread_id) without knowing what those ids
 * resolve to. Each consumer registers an adapter that translates
 * those ids into the consumer's own permission and participant model.
 *
 * v1 only registers `goal` (via GoalThreadAdapter); follow-up PRs
 * will add `trial_case` (#0017), `scout_report` (#0014), and
 * `pdp_conversation` (#0044).
 */
interface ThreadTypeAdapter {

    /**
     * Look up the underlying entity row. Returns null when the entity
     * has been deleted — the controller responds with a 404.
     */
    public function findEntity( int $thread_id ): ?object;

    /**
     * Users who get pinged when a new message is posted.
     *
     * @return list<int>
     */
    public function participantUserIds( int $thread_id ): array;

    /**
     * Hard read gate. Composes existing capabilities + entity-specific
     * relationships (player owns the goal, parent linked to the player,
     * coach owns the player's team, etc.).
     */
    public function canRead( int $user_id, int $thread_id ): bool;

    /**
     * Post gate. v1 sets canPost == canRead; future adapters can be
     * stricter (e.g. read-only observers).
     */
    public function canPost( int $user_id, int $thread_id ): bool;

    /**
     * Short human label used in notification subject lines and audit
     * payloads ("Marcus's goal: Improve first-touch under pressure").
     */
    public function entityLabel( int $thread_id ): string;
}
