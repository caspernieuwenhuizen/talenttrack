<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contract for a single step in a record-creation wizard.
 *
 * Each step is a thin class — it renders its own form, validates the
 * submitted values, and decides which step comes next. Branching
 * happens by returning a non-default slug from `nextStep()`. The
 * final step's `submit()` is what actually creates the record.
 *
 * State across steps lives in `WizardState` (transients). Steps
 * should treat it as read-mostly: they pull what they need at render
 * time and write only their own answers via `validate()`'s return.
 */
interface WizardStepInterface {

    /**
     * Stable slug — appears in the URL and in `WizardState`.
     */
    public function slug(): string;

    /**
     * Short title shown in the step header / progress dots.
     */
    public function label(): string;

    /**
     * Render the form fields for this step. Echoes HTML. Should NOT
     * render the surrounding `<form>` element — the wizard view
     * provides that.
     *
     * @param array<string,mixed> $state  Current wizard state (read-mostly).
     */
    public function render( array $state ): void;

    /**
     * Validate the submitted POST. Return either:
     *   - an array of values to merge into state (success), or
     *   - a `\WP_Error` (failure — the wizard re-renders with the message).
     *
     * @param array<string,mixed> $post   Sanitised $_POST snapshot.
     * @param array<string,mixed> $state  Current wizard state.
     * @return array<string,mixed>|\WP_Error
     */
    public function validate( array $post, array $state );

    /**
     * Return the slug of the next step, or `null` to indicate this is
     * the final step (and `submit()` should run).
     *
     * @param array<string,mixed> $state  Updated state including this
     *                                    step's validated answers.
     */
    public function nextStep( array $state ): ?string;

    /**
     * Final step only — create the record from accumulated state.
     * Other steps may return `null` here and the framework won't call
     * it.
     *
     * @param array<string,mixed> $state  Full accumulated state.
     * @return array<string,mixed>|\WP_Error  e.g. `[ 'redirect_url' => '…' ]` or an error.
     */
    public function submit( array $state );
}
