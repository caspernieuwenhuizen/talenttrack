<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * AddProspectHeroWidget (v3.110.68) — scout-persona dashboard hero.
 *
 * Scout's most frequent action by a long way is "log a new prospect"
 * (5–15× per week during a season, peaking Sunday after weekend
 * matches; see `docs/scout-actions.md` action #1). Before this widget,
 * the scout persona dashboard's hero was `assigned_players_grid` —
 * a legacy surface from before the prospects funnel existed (#0081).
 * Scouts had to navigate to `?tt_view=onboarding-pipeline` first,
 * then click `+ New prospect`. Two taps from the dashboard for the
 * #1 action.
 *
 * This widget is the one-tap path:
 *
 *   - Single giant `+ New prospect` CTA that launches the
 *     `new-prospect` wizard (v3.110.59).
 *   - A one-line context strip below the CTA: "X logged this month ·
 *     Y still active in your funnel". Glance-info paired with the
 *     action so the scout knows their rhythm without leaving the
 *     hero.
 *
 * The CTA goes through `WizardEntryPoint::urlFor()` so installs that
 * disabled the `new-prospect` wizard slug fall back to whatever
 * surface that helper resolves to (no broken hero).
 *
 * Mobile: the hero collapses to one column; the CTA stays full-width
 * touch-target (≥ 48px) per CLAUDE.md §2.
 */
class AddProspectHeroWidget extends AbstractWidget {

    public function id(): string { return 'add_prospect_hero'; }

    public function label(): string { return __( 'Add prospect hero', 'talenttrack' ); }

    public function description(): string {
        return __( 'Scout hero card with a one-tap CTA to the new-prospect wizard. Includes a glance strip — "X logged this month · Y still active in your funnel" — scoped to the viewer\'s own discovered_by_user_id, sourced directly from tt_prospects.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'scout', 'head_of_development' ];
    }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function capRequired(): string { return 'tt_edit_prospects'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $wizard_url = WizardEntryPoint::urlFor(
            'new-prospect',
            $ctx->viewUrl( 'onboarding-pipeline' )
        );

        $stats = self::quickStats( $ctx->user_id, $ctx->club_id );

        $eyebrow = __( 'Spot someone new', 'talenttrack' );
        $title   = __( 'Log a new prospect', 'talenttrack' );
        $detail  = self::detailLine( $stats );

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
            . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-hero-detail">' . esc_html( $detail ) . '</div>'
            . '<div class="tt-pd-hero-cta-row">'
            . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $wizard_url ) . '">'
            . esc_html__( '+ New prospect', 'talenttrack' )
            . '</a>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-add-prospect' );
    }

    /**
     * Two counts the scout sees at a glance, scoped to their own
     * portfolio (`discovered_by_user_id = $user_id`):
     *
     *   - logged_this_month — discovery rhythm; flags a quiet month.
     *   - active_in_funnel — open portfolio size; the scout's WIP.
     *
     * Both are derived from `tt_prospects` directly to avoid having
     * to load the full pipeline classifier on every hero render. The
     * full classifier still runs in `OnboardingPipelineWidget` /
     * `FrontendOnboardingPipelineView`; this is just a glance line.
     *
     * @return array{logged_this_month:int, active_in_funnel:int}
     */
    private static function quickStats( int $user_id, int $club_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [ 'logged_this_month' => 0, 'active_in_funnel' => 0 ];
        }
        $month_start = gmdate( 'Y-m-01' );

        $logged = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE discovered_by_user_id = %d
                AND club_id = %d
                AND discovered_at >= %s",
            $user_id, $club_id, $month_start
        ) );
        $active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE discovered_by_user_id = %d
                AND club_id = %d
                AND archived_at IS NULL
                AND promoted_to_player_id IS NULL",
            $user_id, $club_id
        ) );
        return [ 'logged_this_month' => $logged, 'active_in_funnel' => $active ];
    }

    /**
     * @param array{logged_this_month:int, active_in_funnel:int} $stats
     */
    private static function detailLine( array $stats ): string {
        $logged = (int) ( $stats['logged_this_month'] ?? 0 );
        $active = (int) ( $stats['active_in_funnel'] ?? 0 );
        $logged_part = sprintf(
            /* translators: %d: number of prospects the scout logged so far this month. */
            _n( '%d logged this month', '%d logged this month', $logged, 'talenttrack' ),
            $logged
        );
        $active_part = sprintf(
            /* translators: %d: number of prospects in non-terminal stages discovered by the scout. */
            _n( '%d still active in your funnel', '%d still active in your funnel', $active, 'talenttrack' ),
            $active
        );
        return $logged_part . ' · ' . $active_part;
    }
}
