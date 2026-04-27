<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * PdpStubForm — Sprint 1 placeholder form for PDP workflow templates.
 *
 * Sprint 2 replaces this with the real conversation form + verdict
 * form. The stub satisfies the FormInterface contract so the templates
 * can register today without pinning the engine to half-baked UI.
 */
class PdpStubForm implements FormInterface {

    public function render( array $task ): string {
        return '<p>' . esc_html__(
            'The PDP workflow surface ships in the next release. For now, open the player\'s PDP file directly.',
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
