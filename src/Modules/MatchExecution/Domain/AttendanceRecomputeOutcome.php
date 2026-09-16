<?php
namespace TT\Modules\MatchExecution\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AttendanceRecomputeOutcome (#3445) — what happened when
 * {@see \TT\Modules\MatchExecution\Repositories\MatchExecutionRepository::recomputeAttendanceAndMinutes}
 * ran.
 *
 * The recompute used to answer `bool`, and every one of its four call
 * sites discarded the answer. A match could therefore reach `completed`
 * while the step that writes the register had given up on its second
 * line, and nothing anywhere said so — the activity read "Completed" on
 * every screen with no attendance and no minutes behind it.
 *
 * A reason is the difference between "it worked" and "it did not, and
 * here is the sentence a coach can act on". The reasons are stable
 * strings because they travel out over REST as well as into the log.
 */
final class AttendanceRecomputeOutcome {

    /** Rows were written from the availability list + the sub log. */
    public const REASON_OK = 'ok';

    /** The execution row is gone (deleted, or another club's). */
    public const REASON_NO_EXECUTION = 'no_execution';

    /** No `tt_match_prep` row — no availability list, no line-up. */
    public const REASON_NO_PREP = 'no_prep';

    /** Prep exists but nobody is on the availability list. */
    public const REASON_NO_AVAILABILITY = 'no_availability';

    /** The write threw; the exception message is in the log. */
    public const REASON_DB_ERROR = 'db_error';

    private string $reason;

    private int $rows_written;

    private function __construct( string $reason, int $rows_written ) {
        $this->reason       = $reason;
        $this->rows_written = $rows_written;
    }

    public static function recorded( int $rows_written ): self {
        return new self( self::REASON_OK, max( 0, $rows_written ) );
    }

    public static function failed( string $reason ): self {
        return new self( $reason, 0 );
    }

    /**
     * True only when the recompute actually left a register behind.
     * A pass that wrote zero rows is not a success from the only angle
     * that matters — the coach still has no attendance.
     */
    public function isRecorded(): bool {
        return $this->reason === self::REASON_OK && $this->rows_written > 0;
    }

    public function reason(): string {
        return $this->reason;
    }

    public function rowsWritten(): int {
        return $this->rows_written;
    }
}
