<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctSessionTemplatesRepository;

/**
 * SessionCompositionRule — Pass 3.
 *
 * Loads the seeded template for (age_group, md_context) and stamps
 * the ordered slot list onto the context. The template carries each
 * slot's category, intensity band range, duration target + tolerance,
 * and `theme_filter` flag (whether the coach's tactical_theme applies).
 *
 * No template for the requested context → `block`-severity warning.
 * (The repo falls back to `NONE` automatically; a missing `NONE`
 * template is a seed-data hole that needs an operator fix.)
 */
class SessionCompositionRule implements RulePass {

    private VctSessionTemplatesRepository $templates;

    public function __construct( VctSessionTemplatesRepository $templates ) {
        $this->templates = $templates;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        $template = $this->templates->findFor( $ctx->age_group, $ctx->md_context );

        if ( $template === null ) {
            $ctx->addWarning( 'missing_session_template', 'block', [
                'age_group'  => $ctx->age_group,
                'md_context' => $ctx->md_context,
            ] );
            return $ctx;
        }

        $ctx->slots = $template['slots'];
        return $ctx;
    }
}
