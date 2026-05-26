<?php
namespace TT\Modules\Vct\Rules\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * VctPhvFlagsProvider — narrow read interface over the per-player
 * Peak Height Velocity flag table. Injected into WorkloadCapRule so
 * the rule can be unit-tested with an in-memory fake (no DB required).
 *
 * Production implementation is VctPhvFlagsRepository.
 */
interface VctPhvFlagsProvider {

    /**
     * Given a list of roster player_ids, return the subset that
     * currently have an active PHV flag.
     *
     * @param list<int> $roster_player_ids
     * @return list<int>
     */
    public function activeForRoster( array $roster_player_ids ): array;
}
