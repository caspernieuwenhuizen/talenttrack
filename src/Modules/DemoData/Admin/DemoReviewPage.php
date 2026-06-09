<?php
namespace TT\Modules\DemoData\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DemoReviewPage (#1272 PR1) — read-only inventory of demo-tagged rows
 * per entity, split by `batch_id` provenance.
 *
 * Lands at `?page=tt-demo-review` under TalentTrack → Demo data
 * review. Zero mutation; the pilot uses this to decide whether to
 * proceed with the conversion wizard (#1272 PR2).
 *
 * Split shape per entity:
 *   - `batch_id = 'user-created'` → recommended keep (the operator
 *     created the row themselves mid-demo).
 *   - any other batch_id → recommended delete (seeded by
 *     DemoGenerator / DemoBatchRegistry).
 *
 * Total per-entity counts come straight from `tt_demo_tags` so the
 * inventory matches what's actually scoped, not what the seed shipped.
 */
final class DemoReviewPage {

    private const CAP = 'tt_edit_settings';

    /** Entity types tracked by `tt_demo_tags` per DemoMode::TRACKED_ENTITY_TYPES. */
    private const ENTITIES = [
        'team', 'player', 'person', 'activity', 'evaluation', 'goal',
    ];

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to review demo data.', 'talenttrack' ) );
        }

        $breakdown = self::breakdown();
        $totals    = self::totalsFromBreakdown( $breakdown );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Demo data review', 'talenttrack' ); ?></h1>

            <p style="max-width:720px; color:#5b6e75;">
                <?php esc_html_e(
                    'Per-entity inventory of demo-tagged rows split by their origin. User-created rows (you created them mid-demo) are recommended to keep; seeded rows are recommended to delete when you convert this install to production.',
                    'talenttrack'
                ); ?>
            </p>

            <?php if ( $totals['all'] === 0 ) : ?>
                <p><em><?php esc_html_e( 'No demo-tagged rows on this install. Nothing to convert.', 'talenttrack' ); ?></em></p>
                <?php return; ?>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:900px; margin-top:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Entity',         'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Total',         'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'User-created',  'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Seeded',        'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Seed batches',   'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( self::ENTITIES as $entity ) :
                    $row     = $breakdown[ $entity ] ?? [ 'total' => 0, 'user' => 0, 'seeded' => 0, 'batches' => [] ];
                    $batches = (array) $row['batches'];
                    $batch_list = '';
                    foreach ( $batches as $batch_id => $cnt ) {
                        $batch_list .= sprintf(
                            '<div><code>%s</code> · %d</div>',
                            esc_html( (string) $batch_id ),
                            (int) $cnt
                        );
                    }
                    if ( $batch_list === '' ) {
                        $batch_list = '<span style="color:#5b6e75;">—</span>';
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $entity ); ?></strong></td>
                        <td style="text-align:right;"><?php echo (int) $row['total']; ?></td>
                        <td style="text-align:right; color:#2e7d4f;"><?php echo (int) $row['user']; ?></td>
                        <td style="text-align:right; color:#c75c1f;"><?php echo (int) $row['seeded']; ?></td>
                        <td><?php echo $batch_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php esc_html_e( 'Totals', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php echo (int) $totals['all']; ?></th>
                        <th style="text-align:right; color:#2e7d4f;"><?php echo (int) $totals['user']; ?></th>
                        <th style="text-align:right; color:#c75c1f;"><?php echo (int) $totals['seeded']; ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

            <p style="margin-top:20px; max-width:720px; color:#5b6e75;">
                <?php esc_html_e(
                    'Conversion to production (per-record curation + transactional delete) ships in #1272 PR2. This page lets you confirm the inventory matches what you expect before that wizard becomes available.',
                    'talenttrack'
                ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Returns per-entity breakdown:
     *
     *   [
     *     'team' => [
     *       'total'   => int,
     *       'user'    => int,
     *       'seeded'  => int,
     *       'batches' => [ '<batch_id>' => int, ... ], // excludes 'user-created'
     *     ],
     *     ...
     *   ]
     *
     * Filtered to the current club. Uses one query per entity so the
     * batches breakdown comes back grouped without an in-PHP partition.
     *
     * @return array<string, array{total:int, user:int, seeded:int, batches:array<string,int>}>
     */
    private static function breakdown(): array {
        global $wpdb;
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        $club_id   = (int) CurrentClub::id();

        $out = [];
        foreach ( self::ENTITIES as $entity ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT batch_id, COUNT(*) AS cnt
                   FROM {$tag_table}
                  WHERE club_id = %d AND entity_type = %s
                  GROUP BY batch_id",
                $club_id, $entity
            ) );
            $user    = 0;
            $batches = [];
            $total   = 0;
            foreach ( (array) $rows as $r ) {
                $batch = (string) ( $r->batch_id ?? '' );
                $cnt   = (int) $r->cnt;
                $total += $cnt;
                if ( $batch === 'user-created' ) {
                    $user += $cnt;
                    continue;
                }
                $batches[ $batch ] = ( $batches[ $batch ] ?? 0 ) + $cnt;
            }
            $out[ $entity ] = [
                'total'   => $total,
                'user'    => $user,
                'seeded'  => $total - $user,
                'batches' => $batches,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array{total:int, user:int, seeded:int, batches:array<string,int>}> $breakdown
     * @return array{all:int, user:int, seeded:int}
     */
    private static function totalsFromBreakdown( array $breakdown ): array {
        $all = 0; $user = 0; $seeded = 0;
        foreach ( $breakdown as $row ) {
            $all    += (int) $row['total'];
            $user   += (int) $row['user'];
            $seeded += (int) $row['seeded'];
        }
        return [ 'all' => $all, 'user' => $user, 'seeded' => $seeded ];
    }
}
