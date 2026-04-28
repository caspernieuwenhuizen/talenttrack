<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Central registry of available wizards. Modules register at boot.
 *
 * Two layers of gating:
 *   - capability: the wizard's own `requiredCap()`.
 *   - config: `tt_wizards_enabled` lookup decides which wizard slugs
 *             surface entry-points. `'all'` = every registered wizard;
 *             `'off'` = none; comma-separated list = explicit allowlist.
 *
 * Default config value is `'all'` so a fresh install gets the wizards
 * out of the box. Clubs that prefer flat forms switch to `'off'`.
 */
final class WizardRegistry {

    /** @var array<string, WizardInterface> */
    private static array $wizards = [];

    public static function register( WizardInterface $wizard ): void {
        self::$wizards[ $wizard->slug() ] = $wizard;
    }

    public static function find( string $slug ): ?WizardInterface {
        return self::$wizards[ $slug ] ?? null;
    }

    /** @return array<string, WizardInterface> */
    public static function all(): array {
        return self::$wizards;
    }

    /**
     * Is wizard `$slug` reachable for the current user? Checks both
     * the capability gate and the `tt_wizards_enabled` config.
     */
    public static function isAvailable( string $slug, int $user_id = 0 ): bool {
        $w = self::find( $slug );
        if ( ! $w ) return false;
        if ( ! self::isEnabled( $slug ) ) return false;
        if ( $user_id <= 0 ) $user_id = get_current_user_id();
        return user_can( $user_id, $w->requiredCap() );
    }

    /**
     * Config check, no cap. Used by the entry-point gating on the
     * existing manage views (so the "+ New player" button knows
     * whether to point at the wizard or the flat form).
     */
    public static function isEnabled( string $slug ): bool {
        $cfg = strtolower( trim( (string) \TT\Infrastructure\Query\QueryHelpers::get_config( 'tt_wizards_enabled', 'all' ) ) );
        if ( $cfg === '' || $cfg === 'off' || $cfg === '0' || $cfg === 'false' ) return false;
        if ( $cfg === 'all' || $cfg === '1' || $cfg === 'true' ) return true;
        $list = array_filter( array_map( 'trim', explode( ',', $cfg ) ) );
        return in_array( $slug, $list, true );
    }
}
