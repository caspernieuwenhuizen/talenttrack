<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SharedReads — per-request memo for the shared reads blocks call (#4568).
 *
 * Keyed by the read and by (subject, window), as
 * `TeamMonthlyReport::attendanceRowsIn()` does, so ten blocks on one team
 * read the team's minutes once.
 */
final class SharedReads {

    /** @var array<string,array<mixed>> */
    private static array $memo = [];

    /**
     * @param callable():array<mixed> $read_fn
     * @return array<mixed>
     */
    public static function remember( string $read, ReportContext $context, callable $read_fn ): array {
        $key = $read . '|' . $context->userId . '|' . $context->subjectKey();
        if ( ! array_key_exists( $key, self::$memo ) ) {
            self::$memo[ $key ] = $read_fn();
        }
        return self::$memo[ $key ];
    }

    public static function flush(): void {
        self::$memo = [];
    }
}
