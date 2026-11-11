<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CarriesSensitiveReads (#4963, epic #4564) — a block whose payload can show
 * a player's injuries or medical and safeguarding journey entries, and says
 * which: the reads a document holding it is of, per player, by the rule
 * `InjuryReads` owns (`player.injuries_viewed`,
 * `player.sensitive_timeline_viewed`).
 *
 * A composition shown to one signed-in reader records its reads itself. One
 * that is stored or sent is composed quietly, so whoever sends it records
 * the reads for the people it reached: a scheduled PDF, once per recipient
 * (`ScheduledReportsRunner::runPdf()`).
 *
 * Every block that can carry injury or sensitive journey data implements it,
 * so the next one is classified when it is written.
 */
interface CarriesSensitiveReads {

    /**
     * The reads this payload is of.
     *
     * @param array<string,mixed> $data    the block's payload as it was sent
     * @param array<string,mixed> $context the document's context: scope, player_id, team_id, from, to
     * @param int                 $reader  a reader the payload was composed for, to read back what it shows
     * @return array<string, array<int,int>> action => player id => entries
     */
    public function sensitiveReads( array $data, array $context, int $reader ): array;
}
