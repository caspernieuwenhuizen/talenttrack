<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TacticalThemeRule — Pass 4.
 *
 * Applies the coach's tactical_theme to each slot whose
 * `theme_filter = true`. Slots with `theme_filter = false`
 * (warm-up, cool-down) retain a NULL theme so the selection pass
 * doesn't filter exercises by theme for them.
 *
 * No dependencies — pure transformation per spec § Rules Engine.
 */
class TacticalThemeRule implements RulePass {

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        $theme = $ctx->tactical_theme;

        foreach ( $ctx->slots as $i => $slot ) {
            $ctx->slots[ $i ]['effective_theme'] = ( $theme !== null && ! empty( $slot['theme_filter'] ) )
                ? $theme
                : null;
        }
        return $ctx;
    }
}
