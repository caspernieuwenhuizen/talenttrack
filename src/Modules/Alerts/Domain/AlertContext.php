<?php
namespace TT\Modules\Alerts\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * AlertContext (#2631, epic #2629) — what an evaluation run is scoped to.
 *
 * Carries the club being swept and, optionally, a narrowed subject scope.
 *
 * **The scope is a seam, not a feature.** Epic decision 6 defers
 * event-driven invalidation: wave 1 is cron-only and nothing populates
 * `subjectIds`. It exists so a later wave can re-evaluate one activity
 * after `tt_activity_completed` fires, instead of sweeping the club, and
 * can do so without reshaping `AlertInterface::evaluate()` and every
 * definition that implements it.
 *
 * A definition MUST honour the scope when it is set — returning the full
 * club's occurrences from a narrowed run would make the reconcile resolve
 * everything the narrowed run didn't mention. `applyScope()` exists so
 * definitions express that in one line rather than each inventing it.
 */
final class AlertContext {

    /** @var int */
    public $clubId;

    /**
     * Subject type the run is narrowed to, or '' for a full sweep.
     * @var string
     */
    public $subjectType;

    /**
     * Subject ids the run is narrowed to. Empty for a full sweep.
     * @var list<int>
     */
    public $subjectIds;

    /**
     * @param list<int> $subjectIds
     */
    public function __construct( int $clubId = 0, string $subjectType = '', array $subjectIds = [] ) {
        $this->clubId      = $clubId > 0 ? $clubId : CurrentClub::id();
        $this->subjectType = $subjectType;
        $this->subjectIds  = array_values( array_filter( array_map( 'intval', $subjectIds ) ) );
    }

    /** True when this run covers the whole club rather than named subjects. */
    public function isFullSweep(): bool {
        return $this->subjectType === '' || empty( $this->subjectIds );
    }

    /**
     * True when a definition producing `$subjectType` subjects should
     * restrict itself to `subjectIds`.
     */
    public function narrowsTo( string $subjectType ): bool {
        return ! $this->isFullSweep() && $this->subjectType === $subjectType;
    }

    /**
     * SQL fragment restricting `$column` to the scoped ids, or '' for a
     * full sweep. Ids are cast to int on construction, so inlining them is
     * injection-safe.
     *
     *   $sql .= $ctx->applyScope( 'activity', 'a.id' );
     */
    public function applyScope( string $subjectType, string $column ): string {
        if ( ! $this->narrowsTo( $subjectType ) ) return '';
        return sprintf( ' AND %s IN (%s)', $column, implode( ',', $this->subjectIds ) );
    }
}
