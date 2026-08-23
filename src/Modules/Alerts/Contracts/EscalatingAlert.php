<?php
namespace TT\Modules\Alerts\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EscalatingAlert (#2635, epic #2629) — an alert that becomes a task when
 * nobody acts on it.
 *
 * This is the seam between the two engines. An alert is ambient and
 * self-resolving; a task is assigned work with an owner and an audit trail.
 * Escalation turns the first into the second once it has been ignored long
 * enough — "ambient until ignored too long", rather than either engine
 * swallowing the other.
 *
 * ## Why this is a separate interface rather than a method on AlertInterface
 *
 * Escalation is the exception, not the rule: most conditions are worth
 * surfacing and not worth manufacturing assigned work about. Putting
 * `escalatesTo()` on `AlertInterface` would force every one of the sixteen
 * shipped definitions — and every test stub — to declare `return null`, so
 * the common case would pay for the rare one. Opting in by implementing a
 * second interface says what is actually true: this handful escalates, the
 * rest do not.
 *
 * ## The rules escalation obeys
 *
 * - **Once per occurrence, ever.** The occurrence records the task it
 *   created; a later sweep sees that and does nothing.
 * - **One-way.** Resolving the alert afterwards does NOT close the task. A
 *   task that exists has an assignee and an audit trail, and closing it
 *   behind their back breaks the thing tasks are for. The task's own form
 *   closes it.
 * - **The threshold is the club's, not the definition's.** What
 *   `escalatesTo()` returns is a shipped default; the effective value comes
 *   from `ClubAlertPolicy::escalateAfterDays()` (epic decision 13).
 */
interface EscalatingAlert {

    /**
     * The workflow template this alert becomes, and the shipped default for
     * how long it waits first.
     *
     *     return [ 'template_key' => 'chase_unmarked_activity', 'after_days' => 7 ];
     *
     * Returning null means "not right now" — useful for a definition that
     * decides at runtime, e.g. because the template it would dispatch is not
     * registered on this install.
     *
     * @return array{template_key:string,after_days:int}|null
     */
    public function escalatesTo(): ?array;
}
