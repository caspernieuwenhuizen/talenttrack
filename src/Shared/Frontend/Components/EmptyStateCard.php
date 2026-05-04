<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EmptyStateCard — reusable empty-state block.
 *
 * Renders an icon + headline + explainer + optional CTA button when
 * a list / tab / panel has no content yet. Permission-aware: when
 * `cta_cap` is set and the current user lacks it, the CTA is dropped
 * but the headline + explainer still render so a read-only viewer
 * sees the framing without a button that would just bounce.
 *
 * Markup is mobile-first; styles live in the consuming view's CSS.
 * The component emits `.tt-empty-state` (block) + `.tt-empty-state__*`
 * BEM-shaped children so themes / surface styles can target predictably.
 *
 * @phpstan-type Args array{
 *     icon?: string,
 *     headline: string,
 *     explainer?: string,
 *     cta_label?: string,
 *     cta_url?: string,
 *     cta_cap?: string,
 * }
 */
final class EmptyStateCard {

    public static function render( array $args ): void {
        $headline = (string) ( $args['headline'] ?? '' );
        if ( $headline === '' ) return;

        $explainer = (string) ( $args['explainer'] ?? '' );
        $icon      = (string) ( $args['icon'] ?? '' );
        $cta_label = (string) ( $args['cta_label'] ?? '' );
        $cta_url   = (string) ( $args['cta_url'] ?? '' );
        $cta_cap   = (string) ( $args['cta_cap'] ?? '' );

        $show_cta = $cta_label !== '' && $cta_url !== ''
            && ( $cta_cap === '' || current_user_can( $cta_cap ) );

        ?>
        <div class="tt-empty-state" role="status">
            <?php if ( $icon !== '' ) : ?>
                <div class="tt-empty-state__icon" aria-hidden="true"><?php echo self::iconSvg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static svg ?></div>
            <?php endif; ?>
            <h3 class="tt-empty-state__headline"><?php echo esc_html( $headline ); ?></h3>
            <?php if ( $explainer !== '' ) : ?>
                <p class="tt-empty-state__explainer"><?php echo esc_html( $explainer ); ?></p>
            <?php endif; ?>
            <?php if ( $show_cta ) : ?>
                <p class="tt-empty-state__action">
                    <a class="tt-btn tt-btn-primary tt-empty-state__cta" href="<?php echo esc_url( $cta_url ); ?>">
                        <?php echo esc_html( $cta_label ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Static SVG glyphs. Stroke-only line icons matching the dashboard's
     * existing visual register. Kept inline to avoid an icon-system
     * dependency for a five-tab consumer.
     */
    private static function iconSvg( string $key ): string {
        $glyphs = [
            'goals' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></svg>',
            'evaluations' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 7-7"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>',
            'activities' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3v9l5 3"/></svg>',
            'pdp' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>',
            'trials' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 5 5.5.8-4 3.9.9 5.5L12 14.6 7.1 17.2 8 11.7 4 7.8l5.5-.8z"/></svg>',
        ];
        return $glyphs[ $key ] ?? '';
    }
}
