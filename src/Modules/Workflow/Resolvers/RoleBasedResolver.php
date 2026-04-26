<?php
namespace TT\Modules\Workflow\Resolvers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * RoleBasedResolver — returns every WP user with a given role slug.
 * Used by the Quarterly HoD review template to fan a single task out
 * to all users with `tt_head_dev`.
 *
 * Performance note: WP_User_Query is loaded; on academies with hundreds
 * of accounts this still runs in milliseconds. If a role has thousands
 * of users, batch creation is a Phase 2 concern.
 */
class RoleBasedResolver implements AssigneeResolver {

    public function __construct( private readonly string $role_slug ) {}

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        if ( ! function_exists( 'get_users' ) ) return [];
        $users = get_users( [
            'role'   => $this->role_slug,
            'fields' => 'ID',
        ] );
        return array_map( 'intval', is_array( $users ) ? $users : [] );
    }
}
