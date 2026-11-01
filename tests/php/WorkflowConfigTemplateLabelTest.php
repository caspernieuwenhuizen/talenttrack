<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4606 — the workflow configuration screen's two "Template" headers use
 * the same context-keyed msgid as the My tasks filter (#4555), so one
 * Dutch entry ("Sjabloon") covers them.
 */
final class WorkflowConfigTemplateLabelTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/tools/lib/gettext-calls.php';
    }

    public function test_both_headers_share_the_my_tasks_msgid(): void {
        $calls = tt_gettext_calls( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Workflow/Frontend/FrontendWorkflowConfigView.php' ) );

        $keyed = array_filter( $calls, static fn ( array $c ): bool => $c['msgid'] === 'Template' && $c['msgctxt'] === 'my tasks filter: the kind of task' );
        $bare  = array_filter( $calls, static fn ( array $c ): bool => $c['msgid'] === 'Template' && $c['msgctxt'] === '' );

        $this->assertCount( 2, $keyed );
        $this->assertSame( [], array_values( $bare ), 'the bare msgid ships untranslated' );
    }
}
