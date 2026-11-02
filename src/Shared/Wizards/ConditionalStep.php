<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Default `applicability()` for a `ConditionalStepInterface` step whose
 * condition is known from the start: it applies unless
 * `notApplicableFor()` says it is skipped. A step whose condition hangs on
 * a later answer implements `applicability()` itself and answers `unknown`.
 */
trait ConditionalStep {

    /** @param array<string,mixed> $state */
    abstract public function notApplicableFor( array $state ): bool;

    /** @param array<string,mixed> $state */
    public function applicability( array $state ): string {
        return $this->notApplicableFor( $state )
            ? ConditionalStepInterface::SKIPPED
            : ConditionalStepInterface::APPLIES;
    }
}
