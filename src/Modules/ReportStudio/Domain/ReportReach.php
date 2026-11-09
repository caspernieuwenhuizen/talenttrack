<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportReach (#4571) — how many people a report would reach if it were
 * published to these personas, by the rule a recipient is held to when
 * they open it (`ReportAccess::readsAs()`, which `isRecipient()` asks): the
 * account holds one of the personas and can read the subject. A team read
 * that comes from a functional role counts, because the subject check is
 * the one every route asks.
 *
 * The holders are found through every WordPress role a persona comes from,
 * and counted with `ReportAccess::readersAmong()`, which reads what the rule
 * rests on once for all of them (#4742): the count costs the same number of
 * statements for three holders as for sixty.
 *
 * The publish confirm shows this number, so it is counted here and never in
 * the browser.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportReach {

    /**
     * @param Report       $report
     * @param list<string> $persona_keys
     */
    public static function count( array $report, array $persona_keys ): int {
        if ( $persona_keys === [] ) return 0;

        $roles = [];
        foreach ( $persona_keys as $key ) {
            foreach ( PersonaResolver::wpRolesFor( $key ) as $role ) $roles[ $role ] = $role;
        }
        if ( $roles === [] ) return 0;

        $holders = get_users( [ 'role__in' => array_values( $roles ), 'fields' => 'ID' ] );
        $ids     = array_values( array_map( 'intval', $holders ) );
        return count( ReportAccess::readersAmong( $ids, $report, $persona_keys ) );
    }
}
