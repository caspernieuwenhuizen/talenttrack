<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * TemplateSwitch (#2603) — per-template on/off, per club.
 *
 * An academy that doesn't want goal nudges previously had to disable the
 * whole `comms_scheduled_sends` feature and lose attendance flags,
 * onboarding nudges and staff-development reminders with it. This is the
 * finer-grained control: one switch per registered template.
 *
 * ## Stored as the DISABLED set, deliberately
 *
 * The config value is the list of templates that are switched *off*, so
 * an empty value means "everything on". A template that ships in a later
 * release is therefore enabled on upgrade without touching stored config,
 * and an install that has never opened the settings screen behaves
 * exactly as it did before. Storing the enabled set would have inverted
 * both of those.
 *
 * Mirrors the `profile_cards_hidden` pattern (#2207) — one JSON config
 * row rather than one row per key.
 *
 * ## Enforced in the orchestrator, not at the call site
 *
 * `CommsService::send()` consults this before any per-recipient policy,
 * so no caller — action hook, sync facade, cron — can route around it.
 * A disabled send writes a `template_disabled` audit row (#2602): the
 * switch suppresses the message, never the evidence.
 *
 * Club-scoped for free: `QueryHelpers::get_config` namespaces per club.
 */
final class TemplateSwitch {

    public const CONFIG_KEY = 'comms_templates_disabled';

    /**
     * Template keys currently switched off for this club.
     *
     * @return string[]
     */
    public static function disabledKeys(): array {
        $raw = QueryHelpers::get_config( self::CONFIG_KEY, '' );
        return self::decode( $raw );
    }

    public static function isEnabled( string $templateKey ): bool {
        return ! in_array( $templateKey, self::disabledKeys(), true );
    }

    /**
     * Replace the disabled set. Keys that match no registered template
     * are dropped, so a stale payload cannot accumulate.
     *
     * @param string[] $templateKeys
     */
    public static function setDisabled( array $templateKeys ): void {
        QueryHelpers::set_config( self::CONFIG_KEY, self::normaliseStored( wp_json_encode( array_values( $templateKeys ) ) ) );
    }

    /**
     * Normalise a stored / submitted value to canonical JSON of known
     * template keys. Accepts a JSON array or a comma-separated list, and
     * discards anything that isn't a registered template — a malformed or
     * stale payload can't poison the stored value, and can't silently
     * switch off a template that no longer exists.
     */
    public static function normaliseStored( string $raw ): string {
        $known = array_keys( TemplateRegistry::all() );
        $clean = array_values( array_intersect( self::decode( $raw ), $known ) );
        return (string) wp_json_encode( $clean );
    }

    /** @return string[] */
    private static function decode( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) return [];

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            // Tolerate a CSV form so a hand-edited config row still works.
            $decoded = array_map( 'trim', explode( ',', $raw ) );
        }

        $out = [];
        foreach ( $decoded as $key ) {
            if ( ! is_string( $key ) ) continue;
            $key = sanitize_key( $key );
            if ( $key !== '' ) $out[] = $key;
        }
        return array_values( array_unique( $out ) );
    }
}
