<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureStatusService;
use TT\Shared\Frontend\Components\DevelopmentPill;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Icons\IconRenderer;

/**
 * FrontendFeaturesView (#1486) — the all-personas capability catalog at
 * `?tt_view=features`.
 *
 * Read-only by design. The management page (`?tt_view=modules`) stays the
 * only write surface, gated by `tt_manage_modules`; nothing on a card here
 * toggles anything, and no card links into the manage page. The one route
 * across is the header action, which only a user who can manage modules
 * ever sees.
 *
 * #2878 reorganised the page from a flat alphabetical list into a catalog:
 * a summary of how much of TalentTrack the academy is running, then the
 * capabilities in use, then the ones available to switch on — each grouped
 * by category. What is off is written as an offer rather than a failure,
 * because this is the one screen where a coach or a parent sees the whole
 * product at once.
 *
 * All shaping lives in FeatureStatusService (CLAUDE.md §4); the view only
 * composes.
 */
class FrontendFeaturesView extends FrontendViewBase {

    /** @var bool guards the per-request stylesheet enqueue */
    private static bool $features_css_enqueued = false;

    /** Wire the read-only REST endpoints. Called from Kernel::boot. */
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'registerRest' ] );
    }

    public static function registerRest(): void {
        register_rest_route( 'talenttrack/v1', '/feature-status', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restList' ],
                'permission_callback' => static fn() => is_user_logged_in(),
            ],
        ] );
        // #2878 — the reader-facing catalog. A separate resource rather
        // than a new shape on /feature-status, which is v1 and has its own
        // consumers (CLAUDE.md §4).
        register_rest_route( 'talenttrack/v1', '/feature-catalog', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restCatalog' ],
                'permission_callback' => static fn() => is_user_logged_in(),
            ],
        ] );
    }

    /** @return \WP_REST_Response */
    public static function restList() {
        return new \WP_REST_Response( FeatureStatusService::overview(), 200 );
    }

    /** @return \WP_REST_Response */
    public static function restCatalog() {
        return new \WP_REST_Response( FeatureStatusService::catalog(), 200 );
    }

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        if ( self::$features_css_enqueued ) return;
        wp_enqueue_style(
            'tt-frontend-features',
            TT_PLUGIN_URL . 'assets/css/frontend-features.css',
            [ 'tt-frontend-mobile', 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        self::$features_css_enqueued = true;
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Features', 'talenttrack' ) );

        $actions = '';
        if ( current_user_can( 'tt_manage_modules' ) ) {
            $manage_url = add_query_arg( [ 'tt_view' => 'modules' ], RecordLink::dashboardUrl() );
            $actions = '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $manage_url ) . '">'
                . esc_html__( 'Manage modules & features', 'talenttrack' ) . '</a>';
        }
        self::renderHeader( __( 'Features', 'talenttrack' ), $actions );

        $catalog = FeatureStatusService::catalog();
        if ( empty( $catalog ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Nothing to show yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $in_use    = 0;
        $available = 0;
        foreach ( $catalog as $group ) {
            $in_use    += count( $group['in_use'] );
            $available += count( $group['available'] );
        }

        echo '<div class="tt-feature-catalog">';
        self::renderHero( $in_use, $available );

        if ( $in_use > 0 ) {
            self::renderBand(
                'in-use',
                __( 'In use', 'talenttrack' ),
                __( 'What your academy is running today.', 'talenttrack' ),
                $catalog,
                'in_use'
            );
        }

        if ( $available > 0 ) {
            self::renderBand(
                'available',
                __( 'Available to switch on', 'talenttrack' ),
                __( 'Part of TalentTrack, not switched on for your academy yet. An administrator can turn these on.', 'talenttrack' ),
                $catalog,
                'available'
            );
        }

        echo '</div>';
    }

    /**
     * The summary strip: how much of the product this academy is running.
     * The bar is decorative — the sentence above it already carries the
     * numbers, so the bar itself is aria-hidden rather than a redundant
     * progressbar for a screen reader to announce.
     */
    private static function renderHero( int $in_use, int $available ): void {
        $total = $in_use + $available;
        if ( $total < 1 ) return;

        $pct = (int) round( ( $in_use / $total ) * 100 );

        echo '<section class="tt-feature-hero">';
        echo '<p class="tt-feature-hero__lead">' . esc_html__( 'Your academy is running', 'talenttrack' ) . '</p>';
        echo '<p class="tt-feature-hero__count">' . esc_html(
            sprintf(
                /* translators: 1: number of capabilities in use, 2: total number of capabilities. */
                __( '%1$d of %2$d', 'talenttrack' ),
                $in_use,
                $total
            )
        ) . '</p>';
        echo '<p class="tt-feature-hero__lead">' . esc_html__( 'TalentTrack capabilities', 'talenttrack' ) . '</p>';

        echo '<div class="tt-feature-hero__bar" aria-hidden="true">';
        echo '<span class="tt-feature-hero__fill" style="width:' . esc_attr( (string) $pct ) . '%;"></span>'; /* tt-inline-ok */
        echo '</div>';

        if ( $available > 0 ) {
            echo '<p class="tt-feature-hero__more">' . esc_html(
                sprintf(
                    /* translators: %d: number of capabilities not switched on. */
                    _n( '%d more available to switch on', '%d more available to switch on', $available, 'talenttrack' ),
                    $available
                )
            ) . '</p>';
        } else {
            echo '<p class="tt-feature-hero__more">' . esc_html__( 'Everything available is switched on.', 'talenttrack' ) . '</p>';
        }

        echo '</section>';
    }

    /**
     * One band (in use / available) across every category that has cards
     * in it. Categories with nothing in this band are skipped.
     *
     * @param list<array<string,mixed>> $catalog
     * @param 'in_use'|'available'      $band
     */
    private static function renderBand( string $modifier, string $title, string $intro, array $catalog, string $band ): void {
        echo '<section class="tt-feature-band tt-feature-band--' . esc_attr( $modifier ) . '">';
        echo '<h2 class="tt-feature-band__title">' . esc_html( $title ) . '</h2>';
        echo '<p class="tt-feature-band__intro">' . esc_html( $intro ) . '</p>';

        foreach ( $catalog as $group ) {
            $entries = $group[ $band ];
            if ( empty( $entries ) ) continue;

            echo '<h3 class="tt-feature-category">' . esc_html( (string) $group['category_label'] ) . '</h3>';
            echo '<div class="tt-feature-grid">';
            foreach ( $entries as $entry ) {
                self::renderCard( $entry, $band );
            }
            echo '</div>';
        }

        echo '</section>';
    }

    /**
     * @param array{label:string, description:string, icon:string, enabled:bool, under_development:bool, provides:list<string>, features:list<array{key:string,label:string,description:string,enabled:bool,under_development:bool}>} $entry
     * @param 'in_use'|'available' $band
     */
    private static function renderCard( array $entry, string $band ): void {
        $classes = 'tt-feature-card tt-feature-card--' . ( $band === 'in_use' ? 'on' : 'off' );

        echo '<article class="' . esc_attr( $classes ) . '">';

        echo '<div class="tt-feature-card__head">';
        $icon = IconRenderer::render( (string) $entry['icon'], [ 'class' => 'tt-icon tt-feature-card__icon' ] );
        if ( $icon !== '' ) {
            echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconRenderer returns sanitised SVG markup.
        }
        echo '<h4 class="tt-feature-card__title">' . esc_html( (string) $entry['label'] ) . '</h4>';
        echo '</div>';

        if ( (string) $entry['description'] !== '' ) {
            echo '<p class="tt-feature-card__desc">' . esc_html( (string) $entry['description'] ) . '</p>';
        }

        if ( ! empty( $entry['under_development'] ) ) {
            echo DevelopmentPill::badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its own strings.
        }

        if ( ! empty( $entry['provides'] ) ) {
            echo '<p class="tt-feature-card__provides">'
                . '<span class="tt-feature-card__provides-label">' . esc_html__( 'Includes', 'talenttrack' ) . '</span> '
                . esc_html( implode( ', ', $entry['provides'] ) )
                . '</p>';
        }

        if ( ! empty( $entry['features'] ) ) {
            echo '<ul class="tt-feature-card__features">';
            foreach ( $entry['features'] as $feature ) {
                echo '<li class="tt-feature-sub">';
                echo '<span class="tt-feature-sub__body">';
                echo '<strong class="tt-feature-sub__title">' . esc_html( (string) $feature['label'] ) . '</strong>';
                if ( (string) $feature['description'] !== '' ) {
                    echo '<span class="tt-feature-sub__desc">' . esc_html( (string) $feature['description'] ) . '</span>';
                }
                if ( ! empty( $feature['under_development'] ) ) {
                    echo DevelopmentPill::badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its own strings.
                }
                echo '</span>';
                echo self::badge( (bool) $feature['enabled'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() returns escaped HTML.
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</article>';
    }

    /**
     * On/Off pill for a sub-feature row. Carries the word as well as the
     * colour so it never relies on colour alone (CLAUDE.md §2), and an
     * aria-label so screen readers announce the state.
     *
     * Module cards carry no pill: which band a card sits in already says
     * whether it is on, and repeating that on every card is noise.
     */
    private static function badge( bool $enabled ): string {
        $text = $enabled ? __( 'On', 'talenttrack' ) : __( 'Off', 'talenttrack' );
        $mod  = $enabled ? 'on' : 'off';
        return '<span class="tt-feature-badge tt-feature-badge--' . esc_attr( $mod ) . '" '
            . 'aria-label="' . esc_attr( $text ) . '">' . esc_html( $text ) . '</span>';
    }
}
