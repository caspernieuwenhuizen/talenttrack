<?php
namespace TT\Shared\Mobile;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * MobileSettings — per-club tuning for the mobile classification gate
 * (#0084 Child 1).
 *
 * Single setting at the moment: `force_mobile_for_user_agents`. Default
 * `true` — desktop-only surfaces show the prompt page on phones. Clubs
 * that prefer their users see the cramped view can flip this to `false`,
 * which makes every classification effectively `viewable` for the gate's
 * decision (the registry classifications stay declared; the dispatcher
 * just doesn't enforce them).
 *
 * Backed by `tt_config` (per-club key-value via `ConfigService`) so the
 * value travels into the future SaaS migration unchanged.
 */
final class MobileSettings {

    public const KEY_FORCE_MOBILE_GATE = 'force_mobile_for_user_agents';

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /**
     * Whether the dispatcher should enforce the desktop-only redirect.
     * Default true — operators flip to false to disable the gate club-wide.
     */
    public function isMobileGateEnabled(): bool {
        return $this->config->getBool( self::KEY_FORCE_MOBILE_GATE, true );
    }

    public function setMobileGateEnabled( bool $enabled ): void {
        $this->config->set( self::KEY_FORCE_MOBILE_GATE, $enabled ? '1' : '0' );
    }
}
