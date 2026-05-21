<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TaskStatus — canonical status values for tt_workflow_tasks.status.
 *
 * Stored as plain strings (the schema uses VARCHAR(32) rather than ENUM
 * to keep migrations cheap when new statuses are added). The constants
 * here are the contract; never type a status string by hand elsewhere.
 *
 * State transitions:
 *   open         → in_progress (assignee opened a draft)
 *   open         → completed   (assignee submitted directly)
 *   open         → overdue     (deadline passed, set by overdue sweeper)
 *   open         → skipped     (admin marked the task irrelevant)
 *   open         → cancelled   (template disabled / trigger reverted)
 *   in_progress  → completed
 *   in_progress  → overdue
 *   overdue      → completed   (late submission allowed)
 */
final class TaskStatus {
    public const OPEN        = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';
    public const OVERDUE     = 'overdue';
    public const SKIPPED     = 'skipped';
    public const CANCELLED   = 'cancelled';

    /** @return string[] */
    public static function all(): array {
        return [
            self::OPEN,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::OVERDUE,
            self::SKIPPED,
            self::CANCELLED,
        ];
    }

    /** True when the status counts as "still actionable" by the assignee. */
    public static function isActionable( string $status ): bool {
        return $status === self::OPEN
            || $status === self::IN_PROGRESS
            || $status === self::OVERDUE;
    }

    /**
     * Operator-editable label for a stored status value. Resolves
     * through `tt_translations` for the current locale via
     * `LookupTranslator::byTypeAndName('task_status', $value)`;
     * pre-migration installs fall back to the canonical English label.
     * Stored values stay sacred (constants above).
     */
    public static function label( string $status ): string {
        if ( $status === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'task_status', $status );
            if ( $label !== '' ) return $label;
        }
        switch ( $status ) {
            case self::OPEN:        return __( 'Open',        'talenttrack' );
            case self::IN_PROGRESS: return __( 'In progress', 'talenttrack' );
            case self::COMPLETED:   return __( 'Completed',   'talenttrack' );
            case self::OVERDUE:     return __( 'Overdue',     'talenttrack' );
            case self::SKIPPED:     return __( 'Skipped',     'talenttrack' );
            case self::CANCELLED:   return __( 'Cancelled',   'talenttrack' );
        }
        return $status;
    }
}
