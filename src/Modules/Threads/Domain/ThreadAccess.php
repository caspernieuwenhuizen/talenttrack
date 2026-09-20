<?php
namespace TT\Modules\Threads\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * ThreadAccess — who may read a thread, who may read the staff-only
 * messages inside it, and who may mark a message staff-only.
 *
 * Lifted out of `ThreadsRestController` (#3302) once a second reader
 * appeared: the PDP evidence packet carries a player's staff notes, and a
 * packet that answered the visibility question its own way would be the
 * fifth place in the plugin where "can this person see this note" gets
 * decided. The REST controller delegates here, so both answers come from
 * one place (CLAUDE.md §4 — the gate is domain logic, not view logic).
 */
final class ThreadAccess {

    /**
     * #3858 — the matrix entity that governs the staff-only flag.
     *
     * Its own entity, because the flag deserves a gate that names what it
     * guards. It used to borrow `tt_edit_evaluations`, which put a first
     * aider writing "mum rang, he's been unwell" behind the right to
     * change an evaluation — a right they will never hold and never need.
     * Activity is `change`: marking a note staff-only is an act on the
     * note, not a record of its own, the same shape as
     * `impersonation_action`.
     */
    public const STAFF_ONLY_ENTITY = 'staff_only_notes';

    /**
     * Can this user read the thread at all?
     */
    public static function canRead( string $thread_type, int $thread_id, int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        $adapter = ThreadTypeRegistry::get( $thread_type );
        if ( ! $adapter ) return false;
        return $adapter->canRead( $user_id, $thread_id );
    }

    /**
     * Can this user see the messages marked staff-only?
     *
     * A global thread reader always can. Otherwise the user must be able to
     * read the thread AND count as staff for this purpose — a parent who
     * can read a goal thread is not staff, and never sees the staff-only
     * side of it.
     *
     * #3858 keeps the legacy `tt_edit_evaluations` arm here deliberately.
     * The issue's decision covers who may WRITE a staff-only note and says
     * in as many words that who may read one is out of scope, so the new
     * right is unioned in rather than swapped for the old one: every
     * reader who sees a staff-only note today still sees it, and the
     * people the new right reaches can read back what they just wrote.
     */
    public static function canSeePrivate( string $thread_type, int $thread_id, int $user_id ): bool {
        if ( self::hasGlobalAccess( $user_id, 'read' ) ) return true;
        if ( ! self::canRead( $thread_type, $thread_id, $user_id ) ) return false;
        return self::holdsStaffOnlyRight( $user_id ) || user_can( $user_id, 'tt_edit_evaluations' );
    }

    /**
     * #3858 — may this user mark a message on this thread staff-only, or
     * take that mark off again?
     *
     * Two conditions, both required: they reach the thread at all, and they
     * hold the staff-only right. The thread half is what keeps the right
     * scoped — a coach holds it on the squads they coach because that is
     * where `canRead()` lets them in, not because the right itself carries
     * a scope.
     *
     * Unlike the old write path this is an answer, not a correction: a
     * caller who fails it is refused, and nothing is stored. Rewriting the
     * requested visibility to `public` is what published a first aider's
     * note about a child to that child's guardian.
     */
    public static function canWritePrivate( string $thread_type, int $thread_id, int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( self::hasGlobalAccess( $user_id, 'change' ) ) return true;
        if ( ! self::canRead( $thread_type, $thread_id, $user_id ) ) return false;
        return self::holdsStaffOnlyRight( $user_id );
    }

    /**
     * Does the user hold `staff_only_notes: change` anywhere?
     *
     * `canAnyScope()` rather than a scoped `can()`: the seed grants the
     * right at team scope to the coaches and the team manager and at global
     * scope to the head of development and academy admin, and the thread
     * adapter — not this entity — is what decides whether this particular
     * conversation is theirs. Asking for a scope here would mean guessing
     * a team id out of a thread type, which only some adapters can answer.
     */
    private static function holdsStaffOnlyRight( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) return false;
        return MatrixGate::canAnyScope( $user_id, self::STAFF_ONLY_ENTITY, MatrixGate::CHANGE );
    }

    /**
     * Does the user hold `thread_messages/<activity>/global` in the matrix?
     * Falls back to WP `manage_options` for matrix-dormant installs, then
     * to the v3.0 umbrella capability for back-compat.
     *
     * @param string $activity 'read' | 'change'
     */
    public static function hasGlobalAccess( int $user_id, string $activity ): bool {
        if ( $user_id <= 0 ) return false;
        if ( class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            $matrix_activity = $activity === 'read' ? MatrixGate::READ : MatrixGate::CHANGE;
            if ( MatrixGate::can( $user_id, 'thread_messages', $matrix_activity, MatrixGate::SCOPE_GLOBAL ) ) {
                return true;
            }
        }
        if ( user_can( $user_id, 'manage_options' ) ) return true;
        return user_can( $user_id, 'tt_view_settings' );
    }
}
