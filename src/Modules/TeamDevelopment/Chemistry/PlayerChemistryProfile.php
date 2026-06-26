<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerChemistryProfile (#1912 Phase 2) — the immutable per-player input
 * the chemistry sub-engines read. Built by ChemistryProfileLoader from the
 * attribute model (groups physical/technical/tactical/mental/behaviour/
 * development) plus derived facts (age, footedness) the engine needs but
 * doesn't store as scored attributes.
 *
 * A NULL attribute value means "not recorded" — the scorers treat an empty
 * group as neutral rather than zero, so an un-populated player doesn't drag
 * the whole lineup to 0.
 */
final class PlayerChemistryProfile {

    /**
     * @param int                                   $player_id
     * @param array<string, array<string, ?int>>    $attributes group → attr_key → value|null
     * @param float|null                            $age        years (from DOB), or null
     * @param string                                $foot       'left' | 'right' | 'both' | ''
     */
    public function __construct(
        public readonly int $player_id,
        public readonly array $attributes,
        public readonly ?float $age,
        public readonly string $foot
    ) {}

    /**
     * Mean of the recorded values in a group (0–100), or null when the group
     * has no recorded value.
     */
    public function groupAverage( string $group ): ?float {
        $vals = [];
        foreach ( $this->attributes[ $group ] ?? [] as $v ) {
            if ( $v !== null ) $vals[] = (int) $v;
        }
        if ( empty( $vals ) ) return null;
        return array_sum( $vals ) / count( $vals );
    }

    /**
     * Mean across several groups (recorded values only), or null when none
     * are recorded.
     *
     * @param list<string> $groups
     */
    public function meanOfGroups( array $groups ): ?float {
        $vals = [];
        foreach ( $groups as $g ) {
            $avg = $this->groupAverage( $g );
            if ( $avg !== null ) $vals[] = $avg;
        }
        if ( empty( $vals ) ) return null;
        return array_sum( $vals ) / count( $vals );
    }

    /** A single attribute value (0–100) or null. */
    public function attr( string $group, string $key ): ?int {
        $v = $this->attributes[ $group ][ $key ] ?? null;
        return $v === null ? null : (int) $v;
    }
}
