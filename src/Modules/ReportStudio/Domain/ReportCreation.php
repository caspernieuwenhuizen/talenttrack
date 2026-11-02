<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportCreation (#4570) — create a report from its starting choices. The
 * one path `POST /reports` and the start wizard's last step both take, so
 * neither can store a combination the other would refuse.
 */
final class ReportCreation {

    /**
     * @param array<string,mixed> $in the starting choices, as `ReportStartRules::validate()` reads them
     * @return int|StudioError the new report's id, or why it was not made
     */
    public static function create( array $in, int $user_id ): int|StudioError {
        $choices = ReportStartRules::validate( $in, $user_id );
        if ( $choices instanceof StudioError ) return $choices;

        $id = ( new ReportsRepository() )->create( $choices, $user_id );
        if ( $id <= 0 ) {
            return new StudioError( 'db_error', __( 'The report could not be saved.', 'talenttrack' ), 500 );
        }
        return $id;
    }
}
