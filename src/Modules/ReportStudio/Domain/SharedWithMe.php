<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * SharedWithMe (#4591) — the published reports a reader may open: published
 * to a persona they hold, not archived, about a subject they read. The same
 * query `GET /reports?owner=published` answers, so the Reports launcher's
 * "Shared with me" group and the REST list never disagree.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class SharedWithMe {

    /** The most reports the launcher lists; the REST list pages past it. */
    public const LIMIT = 50;

    /** @return list<Report> */
    public static function forReader( ReportReader $reader ): array {
        if ( $reader->userId <= 0 || $reader->personas() === [] ) return [];
        $repo     = new ReportsRepository();
        $subjects = ReportAccess::listedSubjects( $reader, $repo->publishedPlayerSubjects( $reader->userId, $reader->personas() ) );
        $list     = $repo->listFor(
            $reader->userId,
            $reader->personas(),
            [ 'kind' => '', 'scope' => '', 'owner' => 'published', 'archived' => 'active' ],
            $subjects,
            false,
            1,
            self::LIMIT
        );
        return $list['rows'];
    }
}
