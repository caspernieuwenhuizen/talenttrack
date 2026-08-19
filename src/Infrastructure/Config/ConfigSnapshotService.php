<?php
namespace TT\Infrastructure\Config;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Modules\ModuleMetadata;
use TT\Shared\Tiles\TileRegistry;

/**
 * ConfigSnapshotService (#2540) — assembles the academy's current
 * configuration into one portable structure.
 *
 * Configuration is not one thing in this plugin: settings live in
 * `tt_config`, module on/off in `tt_module_state`, per-feature on/off in
 * `tt_feature_state`, and a handful of install-level values in
 * `wp_options`. Nothing read all four together before this class. The
 * export view composes; this service decides — per CLAUDE.md §4, so a
 * future non-WordPress front end gets the same answer the rendered
 * download does.
 *
 * The payload deliberately carries the human label and the owned
 * `?tt_view=` slugs alongside every module and feature. A consumer
 * asking "which surfaces does this academy actually have?" — the
 * originating use case, deciding what training material to write — cannot
 * answer that from a class name and a boolean.
 *
 * Player data is never included, in any form.
 */
final class ConfigSnapshotService {

    /**
     * Bumped when the payload shape changes in a way a consumer would
     * have to care about. Consumers should refuse a version they don't
     * understand rather than guess.
     */
    public const SCHEMA_VERSION = 1;

    /** Placeholder substituted for the value of any secret-bearing key. */
    public const REDACTED = '[redacted]';

    /**
     * Install-level values that live in `wp_options` rather than
     * `tt_config`. Curated rather than a `tt_%` wildcard scan: a wildcard
     * would sweep up transients, cursors and anything a future module
     * parks in options, including secrets nobody remembered to exclude.
     * Add a key here consciously.
     */
    private const OPTION_KEYS = [
        'tt_installed_version',
        'tt_dashboard_page_id',
        'tt_license_tier',
        'tt_license_plan',
    ];

    /**
     * Key fragments that mark a `tt_config` value as secret-bearing.
     * Matched case-insensitively as substrings, so a new integration that
     * follows the existing naming (`*.client_secret_enc`,
     * `*.password_enc`, `*_api_key`) is redacted the day it lands rather
     * than the day someone remembers to update a denylist.
     *
     * Belt and braces with SECRET_KEYS below: a pattern catches the
     * families, the explicit list catches anything named off-pattern.
     */
    private const SECRET_KEY_PATTERNS = [
        'secret',
        'password',
        'api_key',
        'apikey',
        'token',
        'credential',
        'private_key',
        'service_account',
        '_enc',
    ];

    /**
     * Secret-bearing keys whose names don't match any pattern above.
     * `spond.credentials.email` is the account identity for the club's
     * Spond login — not a credential itself, but half of one, and it is
     * personal data. It is listed rather than pattern-matched because
     * "email" is far too broad a fragment to redact wholesale.
     */
    private const SECRET_KEYS = [
        'spond.credentials.email',
    ];

    /**
     * Is this config key secret-bearing, and therefore redacted from any
     * export? Public so the policy is directly testable and so other
     * export surfaces can reuse it rather than re-deriving it.
     */
    public static function isSecretKey( string $key ): bool {
        if ( in_array( $key, self::SECRET_KEYS, true ) ) return true;
        $needle = strtolower( $key );
        foreach ( self::SECRET_KEY_PATTERNS as $pattern ) {
            if ( strpos( $needle, $pattern ) !== false ) return true;
        }
        return false;
    }

    /**
     * The full configuration snapshot for the current club.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array {
        $settings = $this->settings();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at'    => gmdate( 'c' ),
            'plugin_version' => defined( 'TT_VERSION' ) ? TT_VERSION : 'unknown',
            'club_id'        => CurrentClub::id(),
            'settings'       => $settings['values'],
            'redacted_keys'  => $settings['redacted'],
            'options'        => $this->options(),
            'modules'        => $this->modules(),
            'features'       => $this->features(),
        ];
    }

    /**
     * Every `tt_config` key for this club, with secret-bearing values
     * replaced by the redaction placeholder.
     *
     * The key itself is kept either way: "Strava is configured on this
     * install" is exactly the kind of thing the export exists to tell
     * you, and it is not sensitive — the value is.
     *
     * @return array{values: array<string,string>, redacted: list<string>}
     */
    private function settings(): array {
        $values   = [];
        $redacted = [];
        foreach ( ( new ConfigService() )->all() as $key => $value ) {
            if ( self::isSecretKey( $key ) ) {
                $values[ $key ] = self::REDACTED;
                $redacted[]     = $key;
                continue;
            }
            $values[ $key ] = $value;
        }
        return [ 'values' => $values, 'redacted' => $redacted ];
    }

    /**
     * Install-level options. Absent options are reported as null rather
     * than omitted, so a consumer can tell "not set" from "this export
     * predates the key".
     *
     * @return array<string,mixed>
     */
    private function options(): array {
        $out = [];
        foreach ( self::OPTION_KEYS as $key ) {
            $raw = get_option( $key, null );
            $out[ $key ] = ( $raw === null || $raw === false ) ? null : (string) $raw;
        }
        return $out;
    }

    /**
     * Every declared module with its state, human label, category, and
     * the view slugs it owns.
     *
     * @return list<array<string,mixed>>
     */
    private function modules(): array {
        $out = [];
        foreach ( ModuleRegistry::allWithState() as $row ) {
            $class = (string) $row['class'];
            $meta  = ModuleMetadata::for( $class );
            $out[] = [
                'class'             => $class,
                'label'             => (string) ( $meta['label'] ?? $class ),
                'description'       => (string) ( $meta['description'] ?? '' ),
                'category'          => (string) ( $meta['category'] ?? '' ),
                'enabled'           => (bool) $row['enabled'],
                'always_on'         => (bool) $row['always_on'],
                'under_development' => ! empty( $row['under_development'] ),
                'view_slugs'        => TileRegistry::viewSlugsForModule( $class ),
            ];
        }
        return $out;
    }

    /**
     * Every catalogued feature with its state and the view slugs it
     * gates.
     *
     * `FeatureRegistry::allWithState()` only returns features whose
     * parent module is enabled — a feature under a disabled module is
     * moot, and that is the same set the modules management page shows.
     *
     * @return list<array<string,mixed>>
     */
    private function features(): array {
        $out = [];
        foreach ( FeatureRegistry::allWithState() as $row ) {
            $out[] = [
                'key'               => (string) $row['key'],
                'label'             => (string) $row['label'],
                'description'       => (string) $row['description'],
                'module_class'      => (string) $row['module_class'],
                'enabled'           => (bool) $row['enabled'],
                'default_enabled'   => (bool) $row['default_enabled'],
                'under_development' => ! empty( $row['under_development'] ),
                'view_slugs'        => array_values( (array) ( $row['view_slugs'] ?? [] ) ),
            ];
        }
        return $out;
    }
}
