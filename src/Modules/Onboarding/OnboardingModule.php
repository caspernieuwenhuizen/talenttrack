<?php
namespace TT\Modules\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * OnboardingModule — first-install Setup Wizard (#0024).
 *
 * Adds a `TalentTrack → Welcome` admin page with a five-step wizard:
 *   1. welcome      — explanation + "Try with sample data" / "Set up my academy"
 *   2. academy      — academy name + primary color + season label + date format
 *   3. first_team   — first team name + age group
 *   4. first_admin  — link current WP user to a tt_people record + role grant
 *   5. done         — summary + "Recommended next steps" deep-link cards
 *
 * Decisions locked in specs/0024-feat-setup-wizard-and-onboarding.md.
 *
 * Self-contained: this module hides everything (banner, menu entry) once
 * `tt_onboarding_completed_at` is written. A reset link returns to step 1.
 */
class OnboardingModule implements ModuleInterface {

    public function getName(): string { return 'onboarding'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        if ( ! is_admin() ) return;
        Admin\OnboardingPage::init();
        Admin\OnboardingHandlers::init();
        Admin\OnboardingBanner::init();
    }
}
