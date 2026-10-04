<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4236 — one script drives the overflow menus, and "Archive case" confirms
 * through the shared archive modal instead of an inline handler.
 */
final class OverflowMenuCleanupTest extends WP_UnitTestCase {

    private function source( string $relative ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
    }

    public function test_the_trial_case_view_has_no_inline_handler(): void {
        $view = $this->source( 'src/Shared/Frontend/FrontendTrialCaseView.php' );

        $this->assertStringNotContainsString( 'onclick=', $view );
        $this->assertStringContainsString( 'data-tt-archive-form="tt-trial-archive-form"', $view );
        $this->assertStringContainsString( 'id="tt-trial-archive-form"', $view );
    }

    public function test_the_archive_script_handles_form_actions(): void {
        $script = $this->source( 'assets/js/frontend-archive-button.js' );

        $this->assertStringContainsString( '[data-tt-archive-form]', $script );
        $this->assertStringContainsString( 'requestSubmit', $script );
    }

    public function test_only_one_script_drives_the_overflow_menus(): void {
        $this->assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/assets/js/frontend-page-actions.js' );
        $this->assertStringNotContainsString(
            'frontend-page-actions',
            $this->source( 'src/Shared/Frontend/FrontendViewBase.php' )
        );
    }
}
