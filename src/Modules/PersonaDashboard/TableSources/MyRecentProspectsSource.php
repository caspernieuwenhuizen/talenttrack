<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Registry\TableRowSource;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MyRecentProspectsSource (v3.110.78) — `my_recent_prospects` preset.
 *
 * Scout's mental model: "the prospect I just logged IS my scout report"
 * (per pilot feedback on the v3.110.68 scout template). The legacy
 * `recent_scout_reports` source is wired to `ScoutReportsRepository` —
 * the PDF-export artifact, gated on `tt_generate_scout_report`. A
 * working scout doesn't have that cap and shouldn't need it; what they
 * want on the dashboard is "what prospects did I add lately?" and a
 * Show-all that lands on the kanban they own.
 *
 * This source pulls from `tt_prospects` scoped to
 * `discovered_by_user_id = current user`, ordered by `discovered_at
 * DESC, id DESC`. Status is derived purely from `tt_prospects` columns
 * (no workflow-task join) so the dashboard query stays cheap:
 *
 *   - `archived_at IS NOT NULL`           → Archived
 *   - `promoted_to_player_id IS NOT NULL` → Joined
 *   - `promoted_to_trial_case_id IS NOT NULL` → In trial
 *   - else                                → Active
 *
 * The Show-all link on `DataTableWidget` for this preset targets
 * `?tt_view=onboarding-pipeline` (cap `tt_view_prospects`, which every
 * scout has), not `?tt_view=scout-history`.
 */
final class MyRecentProspectsSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        if ( $user_id <= 0 ) return [];
        $limit = max( 1, min( 50, (int) ( $config['limit'] ?? 5 ) ) );

        $repo = new ProspectsRepository();
        $rows = $repo->search( [
            'discovered_by_user_id' => $user_id,
            'include_archived'      => true,
            'limit'                 => $limit,
        ] );
        if ( $rows === [] ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $when = '';
            if ( ! empty( $r->discovered_at ) ) {
                $ts = strtotime( (string) $r->discovered_at );
                if ( $ts !== false ) {
                    $when = wp_date( 'D j M', $ts );
                }
            }
            $name = trim( (string) ( $r->first_name ?? '' ) . ' ' . (string) ( $r->last_name ?? '' ) );
            $status = self::statusLabel( $r );
            $open_url = add_query_arg(
                [ 'tt_view' => 'onboarding-pipeline', 'prospect_id' => (int) $r->id ],
                RecordLink::dashboardUrl()
            );

            $out[] = [
                esc_html( $when !== '' ? $when : '—' ),
                esc_html( $name !== '' ? $name : '—' ),
                esc_html( $status ),
                '<a class="tt-pd-row-link" href="' . esc_url( $open_url ) . '">' . esc_html__( 'Open', 'talenttrack' ) . '</a>',
            ];
        }
        return $out;
    }

    private static function statusLabel( object $r ): string {
        if ( ! empty( $r->archived_at ) )                return __( 'Archived', 'talenttrack' );
        // v3.110.84 — trial-admit sets BOTH promoted_to_player_id AND
        // promoted_to_trial_case_id, with the player at status='trial'.
        // Check trial_case_id first so admit_to_trial prospects show
        // "In trial" instead of being mis-labelled "Joined". A separate
        // path that promotes to a non-trial status would need a player
        // lookup to surface "Joined" properly; for the scout-dashboard
        // table this approximation matches the kanban classifier well
        // enough without the extra join cost.
        if ( ! empty( $r->promoted_to_trial_case_id ) )  return __( 'In trial', 'talenttrack' );
        if ( ! empty( $r->promoted_to_player_id ) )      return __( 'Joined', 'talenttrack' );
        return __( 'Active', 'talenttrack' );
    }
}
