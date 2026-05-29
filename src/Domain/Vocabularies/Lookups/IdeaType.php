<?php
/**
 * IdeaType — typed constants for the four values stored on
 * `tt_dev_ideas.type`. Backs the operator-editable display labels via
 * `tt_translations` (`idea_type` row set); the stored keys themselves
 * are the canonical contract between the dev module and the GitHub
 * promoter that writes the `<!-- type: feat -->` marker into the
 * promoted spec file.
 *
 * Per #988's locked decisions (2026-05-28):
 *
 *   - `Vocabularies\Lookups\IdeaType` is the single source of truth
 *     for the four type values.
 *   - `TT\Modules\Development\IdeaType::*` constants alias the values
 *     here for one release as a backward-compatibility shim; they will
 *     be removed in the next minor. The legacy class retains its
 *     module-local `label()` / `isValid()` helpers — only the constant
 *     values delegate to the canonical vocabulary.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->type === IdeaType::FEAT ) { ... }
 *     [ 'type' => IdeaType::BUG ]
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class IdeaType {

    public const FEAT         = 'feat';
    public const BUG          = 'bug';
    public const EPIC         = 'epic';
    public const NEEDS_TRIAGE = 'needs-triage';

    /** @var list<string> */
    public const ALL = [
        self::FEAT,
        self::BUG,
        self::EPIC,
        self::NEEDS_TRIAGE,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
