<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class PdpVerdictsPending extends AbstractKpiDataSource {
    public function id(): string { return 'pdp_verdicts_pending'; }
    public function label(): string { return __( 'PDP verdicts pending', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

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
