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
     * The frozen payload as this reader may read it about the document's
     * subject, or null when they may not read the block at all.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context the document's context: scope, player_id, team_id, from, to
     * @return array<string,mixed>|null
     */
    public function forReader( array $data, int $reader, array $context ): ?array;
}
