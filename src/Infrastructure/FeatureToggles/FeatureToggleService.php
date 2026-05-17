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
                'description' => __( 'Record player, evaluation, team, activity, goal, and configuration changes to the audit trail.', 'talenttrack' ),
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
            // #0071 child 4 — controls whether players and parents see the
            // player-status colour dot. Staff always see it. Default off
            // on fresh installs (HoD opts in); migration 0055 sets to true
            // for upgrade installs that already had it visible.
            'player_status_visible_to_player_parent' => [
                'label'       => __( 'Show player status to players and parents', 'talenttrack' ),
                'description' => __( 'When enabled, players see their own status colour and parents see their child\'s. When disabled, the status dot is staff-only (coaches, scouts, Head of Development, Academy Admin). The detailed breakdown remains staff-only regardless. Note: a status change can still be inferred indirectly if other surfaces (e.g. evaluations) reveal it; this toggle hides the explicit dot, not the underlying judgement.', 'talenttrack' ),
                'default'     => false,
            ],
            'allow_registration' => [
                'label'       => __( 'Allow new user registration', 'talenttrack' ),
                'description' => __( 'When enabled, staff can send invitations and invitees can create accounts. Disable to stop all new user creation — new invitations cannot be issued and pending invitations cannot be accepted while disabled. Linking an already-existing WordPress user to a TalentTrack record (silent link when an invitee is already logged in with the matching email) is unaffected.', 'talenttrack' ),
                'default'     => true,
            ],
            // v3.110.156 — operator flag for "this install only ever
            // holds demo data" (pilot installs, training instances,
            // ephemeral demos). When enabled, DemoMode::effective()
            // returns ON regardless of the runtime mode toggle, so
            // demo-scoped reads always show tagged rows and every
            // write through `tagIfActive` tags the new row. Closes the
            // 0096 → 0099 → 0102 → 0103 backfill loop by giving the
            // operator an explicit "always tag, never hide" signal
            // that's independent of the runtime mode flip.
            //
            // Only flip this ON on installs where you are CERTAIN no
            // real production data will ever live. With this on, a
            // demo-tagged row in `tt_demo_tags` is the visibility
            // gate for every read path, and an untagged row stays
            // invisible to every reader. Untagged rows on disk are
            // still preserved — flipping this OFF later restores the
            // standard mixed-mode behaviour (mode toggle in charge).
            'demo_only_install' => [
                'label'       => __( 'This is a demo-only install', 'talenttrack' ),
                'description' => __( 'Enable on pilot / training / ephemeral demo installs where every record is demo data. With this on, demo mode is permanently active: all newly created records auto-tag as demo, demo filtering always shows the tagged set, and the runtime mode toggle is overridden. Disable for production installs where some records are real club data.', 'talenttrack' ),
                'default'     => false,
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
