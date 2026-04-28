<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Reports\PlayerReportRenderer;

/**
 * PlayerReportView — legacy entry point preserved as a thin shim.
 *
 * #0014 Sprint 3: the body is now produced by
 * {@see \TT\Modules\Reports\PlayerReportRenderer}, which consumes a
 * {@see \TT\Modules\Reports\ReportConfig}. This class stays as a thin
 * adapter so existing callers (most notably PrintRouter and any
 * out-of-tree integrations) keep working.
 *
 * Echoes the body. The new renderer returns a string; callers that
 * want the body inline should use the renderer directly.
 */
class PlayerReportView {

    /**
     * @param array<string, mixed> $filters Raw $_GET-shaped filters; see
     *                                       PlayerStatsService::sanitizeFilters.
     */
    public static function render( int $player_id, array $filters ): void {
        echo PlayerReportRenderer::renderStandard(
            $player_id,
            $filters,
            get_current_user_id()
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped HTML.
    }
}
