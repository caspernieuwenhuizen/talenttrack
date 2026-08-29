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
 *
 * ## Account mail is outside the switch (#3110)
 *
 * A template marked `AccountMailTemplate` is not switchable at all: it
 * exists to make an account work rather than to tell a family something,
 * so `isEnabled()` answers `true` for it whatever is stored, and it is
 * absent from the settings screen. An install that already has such a
 * key in its stored disabled set keeps the row — it is simply inert —
 * and it is dropped the next time the set is saved, because
 * `normaliseStored()` only keeps switchable keys.
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
        // #3110 — account mail sends because someone asked for an account,
        // and that is its only condition. Checked before the stored set so
        // a legacy row naming it cannot suppress an invitation.
        if ( ! self::isSwitchable( $templateKey ) ) return true;

        return ! in_array( $templateKey, self::disabledKeys(), true );
    }

    /**
     * Whether this template is the academy's decision to make (#3110).
     *
     * An unregistered key is switchable — the stored set is the disabled
     * one, so an unknown key has to keep behaving as it did before.
     */
    public static function isSwitchable( string $templateKey ): bool {
        $template = TemplateRegistry::get( $templateKey );
        return ! ( $template instanceof AccountMailTemplate );
    }

    /**
     * Registered templates an academy can switch, in registration order.
     * The Messages settings screen and the setup wizard both build their
     * list from this, so account mail never appears on either.
     *
     * @return array<string, TemplateInterface>
     */
    public static function switchableTemplates(): array {
        return array_filter(
            TemplateRegistry::all(),
            static fn ( TemplateInterface $t ): bool => ! ( $t instanceof AccountMailTemplate )
        );
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
     * switchable template keys. Accepts a JSON array or a comma-separated
     * list, and discards anything that isn't a registered switchable
     * template — a malformed or stale payload can't poison the stored
     * value, can't silently switch off a template that no longer exists,
     * and (#3110) can't name account mail, which is not the academy's
     * decision to make.
     */
    public static function normaliseStored( string $raw ): string {
        $known = array_keys( self::switchableTemplates() );
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
