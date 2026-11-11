<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CutsForReader (#4961, epic #4564) — a block whose payload holds records a
 * reader may or may not see, by rights the block's composer asks inside the
 * payload rather than per row. A snapshot frozen for its taker is shown to
 * another reader through this, so each reader sees only what they may read
 * now: the answer the live block would give them.
 */
interface CutsForReader {

    /**
     * The frozen payload as this reader may read it about this player.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function forReader( array $data, int $reader, int $player_id ): array;
}
