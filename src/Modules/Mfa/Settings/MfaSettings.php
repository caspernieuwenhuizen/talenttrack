<?php
namespace TT\Modules\Mfa\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * MfaSettings — typed accessor for per-club MFA configuration
 * (#0086 Workstream B Child 1, sprint 3).
 *
 * Backed by `tt_config` (per-club key-value store) via `ConfigService`.
 * All writes go through the same store so the future SaaS migration
 * picks them up untouched.
 *
 * The defaults below are the values an install runs with before an
 * operator visits the MFA tab — academy_admin + head_of_development
 * personas are gated for MFA, 5 attempts in 5 minutes triggers a
 * 15-minute lockout, "remember this device" cookies live 30 days.
 *
 * Sprint 3 wires the read path on every login + every challenge attempt.
 * Operators tune the values from the Account-page MFA tab's operator-only
 * sub-section.
 */
final class MfaSettings {

    public const KEY_REQUIRED_PERSONAS    = 'mfa_required_personas';
    public const KEY_LOCKOUT_MINUTES      = 'mfa_lockout_minutes';
    public const KEY_MAX_ATTEMPTS         = 'mfa_max_attempts';
    public const KEY_REMEMBER_DEVICE_DAYS = 'mfa_remember_device_days';

    private const DEFAULT_REQUIRED_PERSONAS    = [ 'academy_admin', 'head_of_development' ];
    private const DEFAULT_LOCKOUT_MINUTES      = 15;
    private const DEFAULT_MAX_ATTEMPTS         = 5;
    private const DEFAULT_REMEMBER_DEVICE_DAYS = 30;

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /**
     * Personas that are required to verify MFA at login.
     *
     * @return string[]
     */
    public function requiredPersonas(): array {
        $stored = $this->config->getJson( self::KEY_REQUIRED_PERSONAS, [] );
        if ( empty( $stored ) ) return self::DEFAULT_REQUIRED_PERSONAS;
        // Defensive: stored value might be wrong-shaped on hand-edit; coerce to string list.
        $clean = array_values( array_unique( array_filter( array_map( 'strval', $stored ) ) ) );
        return $clean === [] ? self::DEFAULT_REQUIRED_PERSONAS : $clean;
    }

    /** @param string[] $personas */
    public function setRequiredPersonas( array $personas ): void {
        $clean = array_values( array_unique( array_filter( array_map( 'strval', $personas ) ) ) );
        $this->config->set( self::KEY_REQUIRED_PERSONAS, (string) wp_json_encode( $clean ) );
    }

    /** Minutes a user is locked out after `maxAttempts()` consecutive failures. */
    public function lockoutMinutes(): int {
        $val = $this->config->getInt( self::KEY_LOCKOUT_MINUTES, self::DEFAULT_LOCKOUT_MINUTES );
        return max( 1, $val );
    }

    /** Max consecutive failed attempts before lockout. */
    public function maxAttempts(): int {
        $val = $this->config->getInt( self::KEY_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS );
        return max( 1, $val );
    }

    /** Days a "remember this device" cookie is honoured. */
    public function rememberDeviceDays(): int {
        $val = $this->config->getInt( self::KEY_REMEMBER_DEVICE_DAYS, self::DEFAULT_REMEMBER_DEVICE_DAYS );
        return max( 1, $val );
    }

    /**
     * Catalogue of personas operators can pick in the require-MFA setting.
     * Pulled from `PersonaResolver::WP_ROLE_TO_PERSONA` plus the coach
     * splits so the operator UI lists every persona that exists at runtime.
     *
     * @return array<string,string>  persona key → human label
     */
    public static function operatorSelectablePersonas(): array {
        return [
            'academy_admin'       => __( 'Academy admin',          'talenttrack' ),
            'head_of_development' => __( 'Head of development',    'talenttrack' ),
            'head_coach'          => __( 'Head coach',             'talenttrack' ),
            'assistant_coach'     => __( 'Assistant coach',        'talenttrack' ),
            'scout'               => __( 'Scout',                  'talenttrack' ),
            'team_manager'        => __( 'Team manager',           'talenttrack' ),
            'parent'              => __( 'Parent',                 'talenttrack' ),
            'player'              => __( 'Player',                 'talenttrack' ),
            'readonly_observer'   => __( 'Read-only observer',     'talenttrack' ),
        ];
    }
}
