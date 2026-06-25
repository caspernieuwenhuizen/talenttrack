<?php
namespace TT\Modules\TeamDevelopment\Chemistry\Scorers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\ComponentScore;
use TT\Modules\TeamDevelopment\Chemistry\PairContext;
use TT\Modules\TeamDevelopment\Chemistry\PlayerChemistryProfile;

/**
 * ComponentScorer (#1912 Phase 2) — one of the five weighted components of
 * a pair's chemistry. Each returns a 0–100 ComponentScore from two player
 * profiles + their shared-history context. Pure: no DB access (the loader
 * does that), so each is independently reviewable and tunable.
 *
 * The locked spec fixes WHICH attribute groups feed each component and the
 * top-level weights; the internal formula here is a documented v1, open to
 * pilot tuning.
 */
interface ComponentScorer {

    /** Stable key matching ChemistryConfig::COMPONENTS. */
    public function key(): string;

    public function score(
        PlayerChemistryProfile $a,
        PlayerChemistryProfile $b,
        PairContext $pair
    ): ComponentScore;
}
