<?php
namespace TT\Modules\StaffDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;

/**
 * Who may read or write one staff member's development record.
 *
 * A staff member's goals, evaluations, certifications, PDP and mentorships
 * belong to the person they describe. The `staff_development` matrix rows are
 * seeded at self scope for most personas and at team scope for the head
 * coach, and neither of those names a colleague: a team grant is about the
 * players of a squad, not about the other adults who coach it. So the rule is
 *
 *   - global scope (or a WP site admin) reads and writes anyone's record;
 *   - everybody else reaches their own record only, and only when they hold
 *     the activity at some scope.
 *
 * Global is asked with `MatrixGate::can( ..., SCOPE_GLOBAL )`, never with
 * `canAnyScope()`, which would let a team grant answer for every person.
 */
final class StaffDevelopmentAccess {

    private const ENTITY = 'staff_development';

    /**
     * Reads every staff member's record.
     */
    public static function canReadAll( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL )
            || user_can( $user_id, 'manage_options' );
    }

    /**
     * Writes, creates and deletes on every staff member's record.
     */
    public static function canManageAll( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return MatrixGate::can( $user_id, self::ENTITY, MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL )
            || user_can( $user_id, 'manage_options' );
    }

    /**
     * Is `$person_id` the `tt_people` row of this user, in the current club?
     */
    public static function isSelf( int $user_id, int $person_id ): bool {
        if ( $user_id <= 0 || $person_id <= 0 ) return false;
        global $wpdb;
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_people WHERE id = %d AND club_id = %d",
            $person_id,
            CurrentClub::id()
        ) );
        return $owner > 0 && $owner === $user_id;
    }

    /**
     * May this user read this staff member's development record?
     */
    public static function canRead( int $user_id, int $person_id ): bool {
        if ( $user_id <= 0 || $person_id <= 0 ) return false;
        if ( self::canReadAll( $user_id ) ) return true;
        return self::isSelf( $user_id, $person_id )
            && MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::READ );
    }

    /**
     * May this user write to this staff member's development record?
     */
    public static function canWrite( int $user_id, int $person_id ): bool {
        if ( $user_id <= 0 || $person_id <= 0 ) return false;
        if ( self::canManageAll( $user_id ) ) return true;
        return self::isSelf( $user_id, $person_id )
            && MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::CHANGE );
    }
}
