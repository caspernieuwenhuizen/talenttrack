<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;

/**
 * ExportGate (#4718) — may this account receive a file the server produces?
 *
 * One rule in front of every download and every mailed attachment: the
 * export pipeline, the report and explorer CSV and PDF streams, and the
 * scheduled report deliveries. It answers the matrix entity `exports`,
 * which every persona holds except the read-only observer, so the observer
 * reads what it may read on screen and prints it from the browser, but
 * takes no file away.
 *
 * It is a gate in front of, not instead of, each file's own rule: an
 * exporter still checks the record or the teams it covers, and a report
 * still checks who may read it. A WordPress administrator always passes,
 * the same line `ExportScope` draws, because an administrator does not
 * always resolve to a matrix persona.
 */
final class ExportGate {

    public const ENTITY = 'exports';

    public static function mayExport( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( user_can( $user_id, 'administrator' ) ) return true;

        return MatrixGate::canAnyScope( $user_id, self::ENTITY, 'read' );
    }

    /** The refusal a download route answers with when `mayExport()` says no. */
    public static function refusalMessage(): string {
        return __( 'Your account cannot download files.', 'talenttrack' );
    }
}
