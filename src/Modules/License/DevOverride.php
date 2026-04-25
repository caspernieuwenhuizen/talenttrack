<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DevOverride — developer/owner tier override for demos.
 *
 * Two-factor by accident of wp-config.php access:
 *
 *   1. **TT_DEV_OVERRIDE_SECRET** (wp-config.php constant) — bcrypt hash
 *      of a password Casper memorizes. Only present on Casper's own
 *      demo / dev installs; absent on customer installs.
 *
 *   2. **Password entry** at the hidden admin page registered by
 *      Admin\DevOverridePage when the constant is defined. Submitted
 *      password is hashed and compared via password_verify().
 *
 * On match, a 24-hour transient `tt_license_dev_override` is written
 * with `{ tier, set_at, set_by }`. LicenseGate::tier() consults the
 * override before Freemius. After 24h the transient expires; Casper
 * re-enters the password to extend.
 *
 * The override never reaches customer installs because the constant
 * isn't defined — DevOverridePage refuses to register its admin page,
 * and DevOverride::active() returns false.
 *
 * Threat model: anyone with wp-config.php access already owns the
 * site. Adding a password makes accidental override (e.g. by another
 * developer with shell access) require an extra step. Adding TOTP
 * would be overkill; bcrypt password is sufficient at this layer.
 */
class DevOverride {

    public const TRANSIENT      = 'tt_license_dev_override';
    public const TRANSIENT_TTL  = DAY_IN_SECONDS;
    public const SECRET_CONST   = 'TT_DEV_OVERRIDE_SECRET';

    /**
     * Whether the override mechanism is even available on this install.
     */
    public static function isAvailable(): bool {
        return defined( self::SECRET_CONST ) && (string) constant( self::SECRET_CONST ) !== '';
    }

    /**
     * Whether an override is currently active. Returns the override
     * payload or null.
     *
     * @return array{tier:string, set_at:int, set_by:int}|null
     */
    public static function active(): ?array {
        if ( ! self::isAvailable() ) return null;
        $value = get_transient( self::TRANSIENT );
        if ( ! is_array( $value ) ) return null;
        if ( ! isset( $value['tier'] ) ) return null;
        return [
            'tier'   => FeatureMap::normalizeTier( (string) $value['tier'] ),
            'set_at' => (int) ( $value['set_at'] ?? 0 ),
            'set_by' => (int) ( $value['set_by'] ?? 0 ),
        ];
    }

    /**
     * Verify the submitted password against the stored bcrypt hash and
     * write the override transient on success. Returns true on success.
     */
    public static function activate( string $password, string $tier ): bool {
        if ( ! self::isAvailable() ) return false;
        $hash = (string) constant( self::SECRET_CONST );
        if ( ! password_verify( $password, $hash ) ) return false;

        $tier = FeatureMap::normalizeTier( $tier );
        set_transient( self::TRANSIENT, [
            'tier'   => $tier,
            'set_at' => time(),
            'set_by' => get_current_user_id(),
        ], self::TRANSIENT_TTL );
        return true;
    }

    public static function deactivate(): void {
        delete_transient( self::TRANSIENT );
    }
}
