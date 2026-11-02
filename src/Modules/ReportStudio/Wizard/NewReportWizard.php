<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Shared\Wizards\WizardInterface;

/**
 * NewReportWizard (#4570, epic #4564) — the create flow for a Report Studio
 * report or dashboard (CLAUDE.md §3), at `?tt_view=wizard&slug=new-report`.
 *
 * Six steps, one or two questions each, decided in the order the rules
 * depend on them: what and about whom, the subject, who reads it, when and
 * how, sharing, name and review. `ReportStartRules` answers every switched-off
 * option and moves a later choice an earlier one ruled out.
 *
 * Wizard plan: the wizard is the only screen that creates a report; the
 * fallback for integrations is `POST /reports`, which takes the same path.
 * Switching `new-report` off hides the "+ New report" button.
 */
final class NewReportWizard implements WizardInterface {

    public const SLUG = 'new-report';

    public function slug(): string { return self::SLUG; }

    public function label(): string { return __( 'New report', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_build_reports'; }

    public function firstStepSlug(): string { return 'what'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [
            new WhatStep(),
            new SubjectStep(),
            new ReadersStep(),
            new WhenStep(),
            new SharingStep(),
            new ReviewStep(),
        ];
    }

    /**
     * The Studio is a switchable feature and a plan feature as well as a
     * capability, and the wizard is offered only where all three hold.
     */
    public function isAvailableFor( int $user_id ): bool {
        return FeatureRegistry::isEnabled( 'report_studio' )
            && LicenseGate::allows( 'report_studio' )
            && user_can( $user_id, $this->requiredCap() );
    }

    /**
     * Opened from a player's or a team's page, the report starts about that
     * subject and the subject step is skipped, but only for a subject this
     * user builds reports about: an edited URL does not skip the check.
     *
     * @param array<string,mixed> $get
     * @return array<string,mixed>
     */
    public function initialState( array $get ): array {
        $reader    = ReportReader::for( get_current_user_id() );
        $player_id = isset( $get['player_id'] ) ? absint( $get['player_id'] ) : 0;
        if ( $player_id > 0 && ReportAccess::buildRefusal( $reader, ReportContext::SCOPE_PLAYER, $player_id, null, [] ) === null ) {
            return [ 'scope' => ReportContext::SCOPE_PLAYER, 'player_id' => $player_id, 'subject_from_page' => 1 ];
        }
        $team_id = isset( $get['team_id'] ) ? absint( $get['team_id'] ) : 0;
        if ( $team_id > 0 && ReportAccess::buildRefusal( $reader, ReportContext::SCOPE_TEAM, null, $team_id, [] ) === null ) {
            return [ 'scope' => ReportContext::SCOPE_TEAM, 'team_id' => $team_id, 'subject_from_page' => 1 ];
        }
        return [];
    }
}
