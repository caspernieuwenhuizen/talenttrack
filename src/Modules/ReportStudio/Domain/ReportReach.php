<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportReach (#4571) — how many people a report would reach if it were
 * published to these personas: the accounts that hold one of them and can
 * read what the report is about, the same two questions
 * `ReportAccess::isRecipient()` asks when one of them opens it.
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
            $role = PersonaResolver::defaultWpRoleFor( $key );
            if ( $role !== null ) $roles[ $role ] = $role;
        }
        if ( $roles === [] ) return 0;

        $count = 0;
        foreach ( get_users( [ 'role__in' => array_values( $roles ), 'fields' => 'ID' ] ) as $id ) {
            $reader = ReportReader::for( (int) $id );
            if ( array_intersect( $reader->personas(), $persona_keys ) === [] ) continue;
            if ( ReportAccess::canReadSubject( $reader, $report ) ) $count++;
        }
        return $count;
    }
}
