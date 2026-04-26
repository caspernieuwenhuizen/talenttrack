<?php
namespace TT\Modules\Workflow\Resolvers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * LambdaResolver — escape hatch for one-off resolution. Wraps a closure
 * that takes a TaskContext and returns int[]. Used by tests and by
 * templates with bespoke routing that doesn't fit the other resolvers.
 *
 * Not registerable from runtime config (closures don't serialise). If a
 * template needs custom routing in production, add a real resolver class.
 */
class LambdaResolver implements AssigneeResolver {

    /** @var \Closure(TaskContext): int[] */
    private $callable;

    /** @param \Closure(TaskContext): int[] $callable */
    public function __construct( \Closure $callable ) {
        $this->callable = $callable;
    }

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        $result = ( $this->callable )( $context );
        if ( ! is_array( $result ) ) return [];
        return array_map( 'intval', $result );
    }
}
