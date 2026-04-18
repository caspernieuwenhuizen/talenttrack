<?php
namespace TT\Infrastructure\FeatureToggles;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * FeatureToggleService — boolean feature flags backed by tt_config.
 *
 * Conventions:
 *   - All toggle keys are stored with prefix "feature." (e.g. "feature.audit_log")
 *   - Values are the strings "1" (enabled) or "0" (disabled)
 *   - Default values are declared in self::definitions() so the UI can render
 *     every known toggle even if the config row doesn't exist yet
 *
 * Adding a new toggle:
 *   1. Add an entry to self::definitions() with key, label, description, default.
 *   2. Anywhere in code: $toggles->isEnabled('my_feature').
 *   3. The Configuration UI automatically exposes the new toggle.
 */
class FeatureToggleService {

    public const PREFIX = 'feature.';

    /** @var ConfigService */
    private $config;

    public function __construct( ConfigService $config ) {
        $this->config = $config;
    }

    /**
     * Registry of known toggles.
     *
     * @return array<string, array{label:string, description:string, default:bool}>
     */
    public static function definitions(): array {
        return [
            'audit_log' => [
                'label'       => __( 'Audit log', 'talenttrack' ),
                'description' => __( 'Record player, evaluation, team, session, goal, and configuration changes to the audit trail.', 'talenttrack' ),
                'default'     => true,
            ],
            'verbose_logging' => [
                'label'       => __( 'Verbose logging', 'talenttrack' ),
                'description' => __( 'Write additional debug-level messages to the error log. Non-production environments only.', 'talenttrack' ),
                'default'     => false,
            ],
            'login_redirect' => [
                'label'       => __( 'Login redirect', 'talenttrack' ),
                'description' => __( 'After login, redirect players and coaches to the TalentTrack dashboard page instead of wp-admin.', 'talenttrack' ),
                'default'     => true,
            ],
        ];
    }

    public function isEnabled( string $toggle ): bool {
        $definitions = self::definitions();
        $default     = isset( $definitions[ $toggle ]['default'] ) ? (bool) $definitions[ $toggle ]['default'] : false;
        return $this->config->getBool( self::PREFIX . $toggle, $default );
    }

    public function setEnabled( string $toggle, bool $enabled ): void {
        $this->config->set( self::PREFIX . $toggle, $enabled ? '1' : '0' );
    }

    /**
     * @return array<string, bool> All known toggles with their effective (defaulted) state.
     */
    public function all(): array {
        $out = [];
        foreach ( self::definitions() as $key => $_def ) {
            $out[ $key ] = $this->isEnabled( $key );
        }
        return $out;
    }
}
