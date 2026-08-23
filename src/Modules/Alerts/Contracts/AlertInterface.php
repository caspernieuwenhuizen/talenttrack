<?php
namespace TT\Modules\Alerts\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\AlertContext;

/**
 * AlertInterface (#2631, epic #2629) — one detectable condition.
 *
 * Mirrors `TaskTemplateInterface` / `WizardInterface`: a module declares
 * what it can detect, the registry collects them, and the evaluator runs
 * them. A definition never writes to the occurrences table itself.
 *
 * ## evaluate() is a pure read — this is a hard contract
 *
 * `evaluate()` MUST:
 *
 *   - perform no writes and have no side effects
 *   - be idempotent: two runs over unchanged data return the same set
 *   - return the FULL current truth for its scope, not a delta
 *
 * The third is the one that bites. The evaluator reconciles the returned
 * set against what is stored and stamps `resolved_at` on anything absent.
 * A definition that returns "only what changed" therefore tells the
 * evaluator that everything it omitted has been fixed, and the entire
 * backlog silently resolves itself.
 *
 * ## It must also be set-based
 *
 * One query returning every occurrence for the club. Never a loop issuing
 * a query per user, per team or per row: the sweep runs for every club on
 * an hourly heartbeat, and a per-row query is how one definition takes the
 * whole sweep down.
 *
 * ## And it must name its own recipients
 *
 * Per epic decision 5 the definition resolves its audience and returns one
 * occurrence per recipient. `capRequired()` is a second, independent gate
 * the evaluator applies on top — a definition that resolves the right
 * people is not excused from declaring the capability, because a person's
 * roles change without the definition being re-run.
 */
interface AlertInterface {

    /**
     * Stable machine key, `<module>.<condition>`, e.g.
     * `activities.past_still_planned`. Persisted in `alert_key` and used
     * in preference storage, so it is a vocabulary: never rename one.
     */
    public function key(): string;

    /**
     * Module slug this alert belongs to, e.g. `activities`. Groups the
     * definition in the settings matrix (#2632) — the equivalent of the
     * per-app grouping in a phone's notification settings, one level finer.
     */
    public function module(): string;

    /** Short translated label, shown on surfaces and in settings. */
    public function label(): string;

    /**
     * Translated one-line description of what the condition means and why
     * it matters. Shown in the settings matrix, where it is the only thing
     * telling a user what they are turning off.
     */
    public function description(): string;

    /**
     * The kind of record this alert's occurrences are about — `activity`,
     * `player`, `evaluation`, `team`, and so on. Written to `subject_type`
     * on every occurrence the definition produces.
     *
     * #2731 promoted this from a per-definition convention to part of the
     * contract. Event-driven invalidation dispatches on it: a save that
     * touched activity 41 re-runs the definitions whose subject is an
     * activity and leaves the rest alone. A definition whose evaluate()
     * writes one type here and another into its occurrences would have its
     * rows resolved by a run that never looked at them, so the two must
     * agree — see `AlertEvaluator::runForSubject()`.
     */
    public function subjectType(): string;

    /**
     * Severity when the condition first appears. A definition may return a
     * higher severity per occurrence from `evaluate()` — typically ageing
     * an occurrence up — and the reconcile recomputes it every run.
     */
    public function defaultSeverity(): string;

    /**
     * Capability a recipient must hold to receive an occurrence. Checked by
     * the evaluator against each resolved recipient, on every run, so a
     * coach who loses a role stops receiving the alert at the next sweep.
     */
    public function capRequired(): string;

    /**
     * Surfaces this alert renders on by default, e.g. `['badge','banner']`.
     * Never `interrupt` — that tier is assignable only by club policy
     * (epic decision 4), so a definition cannot make itself blocking.
     *
     * @return list<string>
     */
    public function defaultSurfaces(): array;

    /**
     * True when users may not mute this alert. Mirrors
     * `Comms\Domain\MessageType::isOperational()`. Wave 1 has no preference
     * layer, so nothing reads this yet — it is declared here so definitions
     * shipping before #2632 do not need revisiting.
     */
    public function isOperational(): bool;

    /**
     * Every occurrence currently true within `$context`.
     *
     * @return list<\TT\Modules\Alerts\Domain\AlertOccurrence>
     */
    public function evaluate( AlertContext $context ): array;
}
