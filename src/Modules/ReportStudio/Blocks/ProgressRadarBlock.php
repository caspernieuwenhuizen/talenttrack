<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerRadarQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Progress radar (#4572) — one player's last five evaluations over the main
 * categories, read through `PlayerRadarQuery::progressForPlayer()`, the read
 * of the "Player · Progress & radar" report and `GET /reports/player-radar`.
 *
 * It shows "now", not the period: the read takes the latest evaluations,
 * whenever they were, and the label says so. A category an evaluation did
 * not rate comes back from the read as 0, which is below every rating scale;
 * the block sends it as no figure, so it reads as a dash, never as a 0.
 *
 * Staff only, like the report it comes from.
 */
final class ProgressRadarBlock implements ReportBlock {

    /** The evaluations the report overlays. */
    public const LIMIT = 5;

    public function key(): string {
        return 'progress_radar';
    }

    public function label(): string {
        return __( 'Progress over the last five evaluations', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    /** The evaluations read on the player, as the averages block asks it. */
    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_PLAYER && CategoryAveragesBlock::readsEvaluations( $context );
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $radar = SharedReads::remember(
            'player_progress_radar',
            $context,
            static fn(): array => ( new PlayerRadarQuery() )->progressForPlayer( $context->playerId, self::LIMIT )
        );

        // One row per evaluation, oldest first as the read gives them, so the
        // page estimate counts them like any table.
        $labels = array_values( array_map( 'strval', (array) ( $radar['labels'] ?? [] ) ) );
        $rows   = [];
        foreach ( (array) ( $radar['datasets'] ?? [] ) as $set ) {
            if ( ! is_array( $set ) ) continue;
            $values = [];
            foreach ( array_values( (array) ( $set['values'] ?? [] ) ) as $v ) {
                $n        = is_numeric( $v ) ? (float) $v : 0.0;
                $values[] = $n > 0 ? $n : null;
            }
            $rows[] = [ 'eval_date' => (string) ( $set['label'] ?? '' ), 'values' => $values ];
        }

        return [
            'breakdown'  => 'evaluation',
            'categories' => $labels,
            'rows'       => $rows,
        ];
    }
}
