<?php
/**
 * PdpStatus — typed constants for the values stored in `tt_pdp_files.status`.
 * Tracks the lifecycle of a single PDP file: a coach opens a file at the
 * start of a season, marks it completed at end-of-cycle signoff, and
 * archives it once the season is closed out.
 *
 * Backs the `pdp_status` lookup pill rendered via
 * `LookupPill::render( 'pdp_status', $status )`; the column is VARCHAR(20)
 * with `DEFAULT 'open'` defined by migration 0031, so admins may extend
 * the vocabulary via the lookups admin but the three values below are
 * the canonical set every status filter and KPI expects.
 *
 * `PdpFilesRepository::setStatus()` is the gate: it rejects any value not
 * in `[ open, completed, archived ]`, so these three remain the contract
 * regardless of operator edits.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $file->status === PdpStatus::OPEN ) { ... }
 *     in_array( $file->status, [ PdpStatus::COMPLETED, PdpStatus::ARCHIVED ], true );
 *
 * SQL string literals (`status NOT IN ('completed','archived')` in the
 * season-carryover query) stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PdpStatus {

    public const OPEN      = 'open';
    public const COMPLETED = 'completed';
    public const ARCHIVED  = 'archived';

    /** @var list<string> */
    public const ALL = [
        self::OPEN,
        self::COMPLETED,
        self::ARCHIVED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
