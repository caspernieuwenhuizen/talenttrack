<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

class ReportsModule implements ModuleInterface {
    public function getName(): string { return 'reports'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\ReportsPage::init();
        // #0014 Sprint 5 — chrome-free emailed-link viewer. Intercepts
        // ?tt_scout_token=… on template_redirect, validates, emits the
        // stored rendered HTML (with photos already base64-inlined),
        // and exits. No theme HTML, no nav.
        ScoutLinkRouter::init();
        // #3955 — the retired report wizard's links go on to the player
        // report, or to the family's Reports tab.
        Frontend\ReportWizardRedirect::init();
        // #3955 — the scout's emailed link, sent from the player report now
        // the wizard it lived in is gone.
        Frontend\PlayerReportScoutSend::init();
    }
}
