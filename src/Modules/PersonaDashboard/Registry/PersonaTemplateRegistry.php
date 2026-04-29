<?php
namespace TT\Modules\PersonaDashboard\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;

/**
 * PersonaTemplateRegistry — resolves the active template for a
 * (persona, club_id) pair (#0060).
 *
 * Resolution order:
 *   1. Override row in tt_config keyed by `persona_dashboard.<persona>`
 *      with status=published. Sprint 2's editor writes here.
 *   2. Ship default seeded by CoreTemplates::register().
 *   3. Empty template (renders the legacy tile fallback).
 *
 * Defaults register via `registerDefault()`; overrides come from
 * tt_config and are read live, no caching beyond the underlying
 * ConfigService cache.
 */
final class PersonaTemplateRegistry {

    /** @var array<string, callable(int): PersonaTemplate> */
    private static array $defaults = [];

    /**
     * @param callable(int): PersonaTemplate $factory  takes club_id, returns PersonaTemplate
     */
    public static function registerDefault( string $persona_slug, callable $factory ): void {
        self::$defaults[ $persona_slug ] = $factory;
    }

    public static function resolve( string $persona_slug, int $club_id ): PersonaTemplate {
        // Step 1 — published override?
        $override = self::loadOverride( $persona_slug, PersonaTemplate::STATUS_PUBLISHED );
        if ( $override !== null ) return $override;

        // Step 2 — ship default.
        if ( isset( self::$defaults[ $persona_slug ] ) ) {
            $factory = self::$defaults[ $persona_slug ];
            return $factory( $club_id );
        }

        // Step 3 — empty layout, GridRenderer falls back to legacy tile grid.
        return new PersonaTemplate(
            $persona_slug,
            $club_id,
            null,
            null,
            new \TT\Modules\PersonaDashboard\Domain\GridLayout( [] )
        );
    }

    public static function loadOverride( string $persona_slug, string $status ): ?PersonaTemplate {
        $key = self::configKey( $persona_slug, $status );
        $raw = QueryHelpers::get_config( $key, '' );
        if ( ! is_string( $raw ) || $raw === '' ) return null;
        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) return null;
        $payload['status'] = $status;
        return PersonaTemplate::fromArray( $persona_slug, self::currentClubId(), $payload );
    }

    public static function saveOverride( PersonaTemplate $template, string $status ): bool {
        $key = self::configKey( $template->persona_slug, $status );
        $payload = $template->toArray();
        $payload['status'] = $status;
        $json = wp_json_encode( $payload );
        if ( ! is_string( $json ) ) return false;
        QueryHelpers::set_config( $key, $json );
        return true;
    }

    public static function deleteOverride( string $persona_slug, string $status ): bool {
        $key = self::configKey( $persona_slug, $status );
        QueryHelpers::set_config( $key, '' );
        return true;
    }

    /** @return list<string> */
    public static function defaultPersonas(): array {
        return array_keys( self::$defaults );
    }

    public static function clear(): void {
        self::$defaults = [];
    }

    private static function configKey( string $persona_slug, string $status ): string {
        $persona = sanitize_key( $persona_slug );
        $status  = sanitize_key( $status );
        return 'persona_dashboard.' . $persona . '.' . $status;
    }

    private static function currentClubId(): int {
        if ( class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' ) ) {
            return (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
        }
        return 1;
    }
}
