<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wizard state — accumulated answers across steps.
 *
 * Keyed on `(user_id, wizard_slug)` so two wizards in flight don't
 * step on each other. Stored in WP transients with a short TTL (an
 * hour) — long enough for a real user, short enough that abandoned
 * sessions clean up themselves.
 *
 * Each successful step writes its values via `merge()`; the state
 * carries `_step` (current slug) and `_started_at` (timestamp) for
 * the framework's own bookkeeping.
 *
 * The Phase-4 analytics layer reads `_started_at` + `_completed_at`
 * + `_skipped_steps` from this same store, so the schema is stable.
 */
final class WizardState {

    private const TTL = HOUR_IN_SECONDS;

    public static function key( int $user_id, string $wizard_slug ): string {
        return 'tt_wizard_' . $user_id . '_' . $wizard_slug;
    }

    /**
     * @return array<string,mixed>
     */
    public static function load( int $user_id, string $wizard_slug ): array {
        $row = get_transient( self::key( $user_id, $wizard_slug ) );
        return is_array( $row ) ? $row : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function save( int $user_id, string $wizard_slug, array $state ): void {
        set_transient( self::key( $user_id, $wizard_slug ), $state, self::TTL );
    }

    public static function clear( int $user_id, string $wizard_slug ): void {
        delete_transient( self::key( $user_id, $wizard_slug ) );
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function merge( int $user_id, string $wizard_slug, array $patch ): array {
        $current = self::load( $user_id, $wizard_slug );
        $merged  = array_merge( $current, $patch );
        self::save( $user_id, $wizard_slug, $merged );
        return $merged;
    }

    public static function start( int $user_id, string $wizard_slug, string $first_step ): array {
        $state = [
            '_step'        => $first_step,
            '_started_at'  => time(),
            '_skipped'     => [],
        ];
        self::save( $user_id, $wizard_slug, $state );
        return $state;
    }

    public static function setStep( int $user_id, string $wizard_slug, string $step_slug ): array {
        return self::merge( $user_id, $wizard_slug, [ '_step' => $step_slug ] );
    }

    public static function recordSkip( int $user_id, string $wizard_slug, string $step_slug ): void {
        $state = self::load( $user_id, $wizard_slug );
        $skipped = (array) ( $state['_skipped'] ?? [] );
        if ( ! in_array( $step_slug, $skipped, true ) ) $skipped[] = $step_slug;
        $state['_skipped'] = $skipped;
        self::save( $user_id, $wizard_slug, $state );
    }

    /**
     * Push the step the user just left onto a history stack so Back
     * can pop it (#0063). Conditional branching (e.g. NewPlayer's
     * trial path) means we can't compute "previous step" purely from
     * the static step list — we have to track what was actually
     * visited.
     */
    public static function pushHistory( int $user_id, string $wizard_slug, string $step_slug ): void {
        $state = self::load( $user_id, $wizard_slug );
        $history = (array) ( $state['_history'] ?? [] );
        // Drop trailing duplicate so a Back→Next round-trip doesn't
        // accumulate the same slug twice.
        if ( end( $history ) !== $step_slug ) $history[] = $step_slug;
        $state['_history'] = $history;
        self::save( $user_id, $wizard_slug, $state );
    }

    /**
     * Pop the previous step off the history. Returns null when there
     * is no prior step (i.e. user is on step 1) so the framework can
     * gracefully suppress the Back button.
     */
    public static function popHistory( int $user_id, string $wizard_slug ): ?string {
        $state = self::load( $user_id, $wizard_slug );
        $history = (array) ( $state['_history'] ?? [] );
        $prev = array_pop( $history );
        $state['_history'] = $history;
        self::save( $user_id, $wizard_slug, $state );
        return is_string( $prev ) && $prev !== '' ? $prev : null;
    }

    public static function hasHistory( int $user_id, string $wizard_slug ): bool {
        $state = self::load( $user_id, $wizard_slug );
        return ! empty( $state['_history'] );
    }
}
