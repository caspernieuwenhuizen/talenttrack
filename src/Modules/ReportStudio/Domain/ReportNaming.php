<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportNaming (#4737) — a report's two names. A rename waits for publish,
 * like every other edit: readers see the report's own name, the one its
 * published version carries, and each builder works on their draft's name.
 * A report never published has no readers, so the two are one.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportNaming {

    /**
     * Does this draft name differ from the name readers see?
     *
     * @param Report $report
     */
    public static function changed( array $report, string $draft_name ): bool {
        return $report['published_version_id'] !== null && $draft_name !== $report['name'];
    }
}
