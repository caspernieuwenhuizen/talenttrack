<?php
/**
 * IdeaStatus — typed constants for the nine values stored on
 * `tt_dev_ideas.status`. Backs the lookup row set seeded by migration
 * 0115 (`idea_status`), operator-editable display labels via
 * `tt_translations`.
 *
 * Lifecycle:
 *
 *     SUBMITTED -> REFINING -> READY_FOR_APPROVAL -> PROMOTING
 *                                                 -> PROMOTED -> IN_PROGRESS -> DONE
 *                                                 -> PROMOTION_FAILED
 *                                              -> REJECTED
 *
 * `PROMOTING` and `PROMOTION_FAILED` are transient-internal states the
 * GitHub-promoter writes while the API call is in flight. Author-facing
 * surfaces collapse those down to "In review" via the
 * `IdeaStatus::authorFacingLabel()` legacy helper.
 *
 * Per #988's locked decisions (2026-05-28):
 *
 *   - `Vocabularies\Lookups\IdeaStatus` is the single source of truth
 *     for the nine status values.
 *   - `TT\Modules\Development\IdeaStatus::*` constants alias the values
 *     here for one release as a backward-compatibility shim; they will
 *     be removed in the next minor. The legacy class retains its
 *     module-local `label()` / `authorFacingLabel()` / `boardColumns()`
 *     helpers — only the constant values delegate to the canonical
 *     vocabulary.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->status === IdeaStatus::PROMOTED ) { ... }
 *     in_array( $row->status, [ IdeaStatus::REFINING, IdeaStatus::READY_FOR_APPROVAL ], true );
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class IdeaStatus {

    public const SUBMITTED          = 'submitted';
    public const REFINING           = 'refining';
    public const READY_FOR_APPROVAL = 'ready-for-approval';
    public const REJECTED           = 'rejected';
    public const PROMOTING          = 'promoting';
    public const PROMOTED           = 'promoted';
    public const PROMOTION_FAILED   = 'promotion-failed';
    public const IN_PROGRESS        = 'in-progress';
    public const DONE               = 'done';

    /** @var list<string> */
    public const ALL = [
        self::SUBMITTED,
        self::REFINING,
        self::READY_FOR_APPROVAL,
        self::REJECTED,
        self::PROMOTING,
        self::PROMOTED,
        self::PROMOTION_FAILED,
        self::IN_PROGRESS,
        self::DONE,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
