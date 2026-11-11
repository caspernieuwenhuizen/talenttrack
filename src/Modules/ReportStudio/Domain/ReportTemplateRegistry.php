<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReportTemplateRegistry (#5022, epic #4564) — the templates a Studio report
 * can start from, by key. Each is registered as a factory, as the persona
 * dashboard defaults are, so its labels are translated when it is read.
 *
 * Which templates a builder may start follows `ReportStartRules`: a template
 * is offered only when every starting choice it fixes is one the rules allow
 * this builder, in the order the rules decide them.
 */
final class ReportTemplateRegistry {

    /** @var array<string,callable():ReportTemplate> */
    private static array $factories = [];

    /** @param callable():ReportTemplate $factory */
    public static function register( string $key, callable $factory ): void {
        self::$factories[ $key ] = $factory;
    }

    public static function find( string $key ): ?ReportTemplate {
        $factory = self::$factories[ $key ] ?? null;
        return $factory !== null ? $factory() : null;
    }

    /** @return list<ReportTemplate> */
    public static function all(): array {
        $out = [];
        foreach ( self::$factories as $factory ) $out[] = $factory();
        return $out;
    }

    /**
     * The templates this user may start a report from.
     *
     * @return list<ReportTemplate>
     */
    public static function startableBy( int $user_id ): array {
        if ( $user_id <= 0 || ! user_can( $user_id, 'tt_build_reports' ) ) return [];
        $reader = ReportReader::for( $user_id );
        return array_values( array_filter( self::all(), static fn( ReportTemplate $t ): bool => self::refusal( $t, $reader ) === null ) );
    }

    /**
     * Why this reader may not start this template, or null when they may:
     * the first starting choice it fixes that the rules switch off for them.
     */
    public static function refusal( ReportTemplate $template, ReportReader $reader ): ?string {
        $before = [];
        foreach ( ReportStartRules::FIELDS as $field ) {
            $value = $template->choices[ $field ] ?? null;
            if ( $value === null ) continue;
            $reason = ReportStartRules::reason( $field, $value, $before, $reader );
            if ( $reason !== null ) return $reason;
            $before[ $field ] = $value;
        }
        return null;
    }

    /** For tests that register their own templates. */
    public static function clear(): void {
        self::$factories = [];
    }
}
