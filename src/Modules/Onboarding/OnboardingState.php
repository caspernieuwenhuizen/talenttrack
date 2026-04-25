<?php
namespace TT\Modules\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OnboardingState — read/write the wizard state option.
 *
 * Stored in `tt_onboarding_state` (wp_options) as JSON:
 *   {
 *     step:       <slug>,         // current step the user is on
 *     dismissed:  <bool>,         // user clicked Skip-for-now or Try-with-sample-data
 *     payload:    <object>        // form values keyed by step slug
 *   }
 *
 * Completion is tracked separately in `tt_onboarding_completed_at` so
 * the menu visibility check + banner logic can answer "is this install
 * onboarded?" with a single get_option.
 */
class OnboardingState {

    public const STEPS = [ 'welcome', 'academy', 'first_team', 'first_admin', 'done' ];

    private const STATE_OPT     = 'tt_onboarding_state';
    private const COMPLETED_OPT = 'tt_onboarding_completed_at';

    /**
     * @return array{step:string, dismissed:bool, payload:array<string,array<string,mixed>>}
     */
    public static function get(): array {
        $raw = get_option( self::STATE_OPT, '' );
        if ( $raw === '' || $raw === false ) {
            return self::defaults();
        }
        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            return self::defaults();
        }
        $step      = isset( $decoded['step'] ) && in_array( $decoded['step'], self::STEPS, true )
            ? (string) $decoded['step']
            : self::STEPS[0];
        $dismissed = ! empty( $decoded['dismissed'] );
        $payload   = isset( $decoded['payload'] ) && is_array( $decoded['payload'] )
            ? $decoded['payload']
            : [];
        return [ 'step' => $step, 'dismissed' => $dismissed, 'payload' => $payload ];
    }

    public static function setStep( string $step ): void {
        if ( ! in_array( $step, self::STEPS, true ) ) return;
        $state         = self::get();
        $state['step'] = $step;
        self::save( $state );
    }

    public static function setDismissed( bool $dismissed ): void {
        $state              = self::get();
        $state['dismissed'] = $dismissed;
        self::save( $state );
    }

    /**
     * Merge a step's form values into the persisted payload.
     *
     * @param array<string,mixed> $values
     */
    public static function recordPayload( string $step, array $values ): void {
        $state                       = self::get();
        $state['payload'][ $step ]   = $values;
        self::save( $state );
    }

    /** @return array<string,mixed> */
    public static function payloadFor( string $step ): array {
        $state = self::get();
        $val   = $state['payload'][ $step ] ?? [];
        return is_array( $val ) ? $val : [];
    }

    public static function reset(): void {
        delete_option( self::STATE_OPT );
        delete_option( self::COMPLETED_OPT );
        do_action( 'tt_onboarding_reset' );
    }

    public static function isCompleted(): bool {
        return (int) get_option( self::COMPLETED_OPT, 0 ) > 0;
    }

    public static function markCompleted(): void {
        update_option( self::COMPLETED_OPT, time() );
        do_action( 'tt_onboarding_completed' );
    }

    /**
     * Should the welcome surface (banner + menu entry) be shown?
     * - Hidden once the wizard is completed.
     * - Hidden when the user explicitly dismissed (still reachable via the
     *   ?force_welcome=1 query param used by the reset link).
     */
    public static function shouldShowWelcome(): bool {
        if ( self::isCompleted() ) return false;
        $state = self::get();
        return ! $state['dismissed'];
    }

    public static function shouldShowBanner(): bool {
        return self::shouldShowWelcome();
    }

    /**
     * @return array{step:string, dismissed:bool, payload:array<string,array<string,mixed>>}
     */
    private static function defaults(): array {
        return [ 'step' => self::STEPS[0], 'dismissed' => false, 'payload' => [] ];
    }

    /** @param array{step:string, dismissed:bool, payload:array<string,array<string,mixed>>} $state */
    private static function save( array $state ): void {
        update_option( self::STATE_OPT, wp_json_encode( $state ), false );
    }
}
