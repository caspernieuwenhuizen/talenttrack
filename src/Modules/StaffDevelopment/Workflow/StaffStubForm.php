<?php
namespace TT\Modules\StaffDevelopment\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * StaffStubForm — v1 placeholder for the four staff-development
 * workflow templates. Mirrors `PdpStubForm` in #0044: the templates
 * register so the engine sees them, but the form just points the user
 * at the real surface (My PDP / My evaluations / etc.) for now. Sprint
 * follow-ups will replace this with task-specific forms when usage
 * signal warrants the dedicated UI.
 */
class StaffStubForm implements FormInterface {

    public function render( array $task ): string {
        return '<p>' . esc_html__(
            'Open the staff-development tile group on your dashboard to complete this task.',
            'talenttrack'
        ) . '</p>';
    }

    public function validate( array $raw, array $task ): array {
        return [];
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [];
    }
}
