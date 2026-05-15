<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class PdpVerdictsPending extends AbstractKpiDataSource {
    public function id(): string { return 'pdp_verdicts_pending'; }
    public function label(): string { return __( 'PDP verdicts pending', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /**
     * v3.110.112 — pilot ask: "goes to POP for players that I coach but
     * for a HoD that should not be the list. Should be the list of all
     * the POPs and in this case prefiltered on those who are open."
     *
     * Two changes work together to satisfy this:
     *   (1) This `linkUrl()` adds `filter[status]=open` to the PDP list
     *       URL so the destination lands prefiltered to open files.
     *   (2) `PdpFilesRestController::hasGlobalPdpAccess()` was extended
     *       in this ship to recognise the HoD persona (and other
     *       global-scope readers) so the list endpoint returns every
     *       file in the season instead of coach-scoping to zero.
     */
    public function linkUrl( RenderContext $ctx ): string {
        return add_query_arg( [ 'filter' => [ 'status' => 'open' ] ], $ctx->viewUrl( 'pdp' ) );
    }

    /**
     * Counts PDP cycles that have had every conversation completed
     * but no signed-off verdict yet — i.e. the rows that need the
     * Head of Development to sit down and write the end-of-season
     * decision.
     *
     * Hardcoded `unavailable` stub through v3.108.4 — implemented in
     * v3.108.5 so the HoD KPI strip stops rendering "—" for this
     * slot.
     */
    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $files = $wpdb->prefix . 'tt_pdp_files';
        $verds = $wpdb->prefix . 'tt_pdp_verdicts';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $files ) ) !== $files ) {
            return KpiValue::unavailable();
        }

        // A cycle is "pending verdict" when:
        // - the file is open (not archived)
        // - it belongs to the calling club
        // - either no verdict row exists, or the verdict isn't
        //   signed off yet
        $verdicts_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $verds ) ) === $verds;
        if ( $verdicts_table_exists ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*)
                   FROM {$files} f
              LEFT JOIN {$verds} v ON v.pdp_file_id = f.id
                  WHERE f.club_id = %d
                    AND f.archived_at IS NULL
                    AND ( v.id IS NULL OR v.signed_off_at IS NULL )",
                $club_id
            ) );
        } else {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$files} WHERE club_id = %d AND archived_at IS NULL",
                $club_id
            ) );
        }
        return KpiValue::of( (string) $count );
    }
}
