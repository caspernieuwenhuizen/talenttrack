<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TemplateRegistry (#0066) — central lookup keyed on template key.
 *
 * Same registration pattern as `WidgetRegistry` /
 * `KpiDataSourceRegistry` / `ChannelAdapterRegistry`: static,
 * in-memory, idempotent. Each use case (eventually 15 in #0066's
 * scope) registers its template at boot from its owning module.
 */
final class TemplateRegistry {

    /** @var array<string, TemplateInterface> */
    private static array $templates = [];

    public static function register( TemplateInterface $template ): void {
        self::$templates[ $template->key() ] = $template;
    }

    public static function get( string $key ): ?TemplateInterface {
        return self::$templates[ $key ] ?? null;
    }

    /** @return array<string, TemplateInterface> */
    public static function all(): array {
        return self::$templates;
    }

    public static function clear(): void {
        self::$templates = [];
    }
}
