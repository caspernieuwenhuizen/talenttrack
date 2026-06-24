<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DeleteBlockedException — thrown by GenericCascadeDeleter when a hard
 * delete is refused because referencing rows exist that the entity's
 * cascade plan does not own (and therefore must not silently orphan or
 * delete). Carries the per-table dependency report so the caller can
 * surface a clear "can't delete — these still reference it" message.
 *
 * Fail-closed by design: anything the plan doesn't explicitly cascade or
 * set-null blocks the delete rather than risk deleting too much. The
 * worst case for an incomplete plan is a refused delete, never data loss.
 */
class DeleteBlockedException extends \RuntimeException {

    /** @var array<string,int>  bare table name => count of blocking rows */
    private array $report;

    /** @param array<string,int> $report */
    public function __construct( array $report ) {
        $this->report = $report;
        parent::__construct( self::summarize( $report ) );
    }

    /** @return array<string,int> */
    public function report(): array {
        return $this->report;
    }

    /** Human-readable one-line summary of the blocking dependents. */
    public static function summarize( array $report ): string {
        $parts = [];
        foreach ( $report as $table => $count ) {
            $parts[] = sprintf( '%d %s', (int) $count, self::humanize( (string) $table ) );
        }
        return sprintf(
            /* translators: %s is a comma-separated list like "3 evaluation ratings, 2 attendance". */
            __( 'Cannot delete: still referenced by %s. Archive or remove these first.', 'talenttrack' ),
            implode( ', ', $parts )
        );
    }

    /** tt_eval_ratings => "eval ratings" */
    private static function humanize( string $bare_table ): string {
        $name = preg_replace( '/^tt_/', '', $bare_table );
        return str_replace( '_', ' ', (string) $name );
    }
}
