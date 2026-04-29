<?php
namespace TT\Modules\PersonaDashboard\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\Widget;

/**
 * WidgetRegistry — closed enum of the 14 shipped widget types (#0060).
 *
 * Sprint 1 ships the catalog; sprint 2's editor reads from `all()` to
 * populate the palette. Widgets register themselves via the
 * CoreWidgets seed at module boot.
 */
final class WidgetRegistry {

    /** @var array<string, Widget> */
    private static array $widgets = [];

    public static function register( Widget $widget ): void {
        self::$widgets[ $widget->id() ] = $widget;
    }

    public static function get( string $id ): ?Widget {
        return self::$widgets[ $id ] ?? null;
    }

    /** @return array<string, Widget> */
    public static function all(): array {
        return self::$widgets;
    }

    /** Drop everything — tests use this between scenarios. */
    public static function clear(): void {
        self::$widgets = [];
    }
}
