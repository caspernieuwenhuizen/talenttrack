<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PairContext (#1912 Phase 2) — the shared-history facts between two
 * players that the Familiarity and Performance sub-engines read. Loaded
 * once per team by ChemistryProfileLoader (attendance + tenure + shared
 * games) and looked up per pair.
 */
final class PairContext {

    public function __construct(
        public readonly int $shared_sessions,      // completed activities both attended
        public readonly int $tenure_overlap_days,  // days both on the same team
        public readonly int $shared_games          // completed games both attended
    ) {}

    public static function empty(): self {
        return new self( 0, 0, 0 );
    }
}
