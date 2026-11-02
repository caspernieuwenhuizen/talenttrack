<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Opt-in contract for a wizard step that only runs under some answers.
 *
 * `notApplicableFor()` tells the runner to skip the step when it is
 * reached. `applicability()` tells the step counter whether the step will
 * run: `yes`, `no`, or `unknown` while it hangs on an answer not given
 * yet. The counter drops only `no`, so the "Step X of Y" total never grows
 * while the user moves forward without changing an answer. The
 * `ConditionalStep` trait derives `applicability()` from
 * `notApplicableFor()` for a step whose condition is known from the start.
 */
interface ConditionalStepInterface {

    public const APPLIES = 'yes';
    public const SKIPPED = 'no';
    public const UNKNOWN = 'unknown';

    /**
     * Whether the runner skips this step when it is reached.
     *
     * @param array<string,mixed> $state
     */
    public function notApplicableFor( array $state ): bool;

    /**
     * Whether the step will run, for the step counter.
     *
     * @param array<string,mixed> $state
     * @return string One of the APPLIES / SKIPPED / UNKNOWN constants.
     */
    public function applicability( array $state ): string;
}
