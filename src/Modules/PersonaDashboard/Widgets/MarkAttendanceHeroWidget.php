<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Repositories\UpcomingActivityRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * MarkAttendanceHeroWidget (#0092, v3.110.69) — head-coach dashboard hero.
 *
 * Replaces `today_up_next_hero` as the default coach-template hero
 * (the old widget stays registered so any operator-customized
 * template that pinned it keeps working).
 *
 * Coach's most frequent action is recording attendance — 4× / week
 * during a regular football season (3 trainings + 1 match). The
 * previous hero's "Attendance" CTA dropped the coach on the
 * activities list, not the activity, costing 6–8 taps before the
 * roster was on screen. This hero deep-links into the new
 * `mark-attendance` wizard with the next activity preselected so the
 * coach lands on the roster in one tap.
 *
 * Behaviour:
 *
 *   - Reads the soonest upcoming activity on a team the coach owns
 *     via `UpcomingActivityRepository::nextForCoach()`.
 *   - Primary CTA: **Mark attendance** → opens the wizard with
 *     `activity_id` pre-seeded.
 *   - Secondary link: **Edit activity** → the activity's edit form,
 *     the post-hoc attendance-correction surface.
 *   - Empty state (no upcoming activity): primary CTA becomes
 *     **Pick a session** which opens the wizard at the
 *     activity-picker step.
 *
 * Mobile-first: single column, CTA buttons hit the 48px tap-target
 * floor, no hover-only state.
 */
class MarkAttendanceHeroWidget extends AbstractWidget {

    public function id(): string { return 'mark_attendance_hero'; }

    public function label(): string { return __( 'Mark attendance hero', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::COACH; }

    public function capRequired(): string { return 'tt_edit_evaluations'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $teams    = QueryHelpers::get_teams_for_coach( $ctx->user_id );
        $team_ids = array_map( static fn( $t ): int => (int) $t->id, is_array( $teams ) ? $teams : [] );
        $next     = UpcomingActivityRepository::nextForCoach( $team_ids, $ctx->club_id );

        if ( $next === null ) {
            $eyebrow = __( 'Up next', 'talenttrack' );
            $title   = __( 'No upcoming activity', 'talenttrack' );
            $detail  = __( 'Schedule a training or match to populate this card.', 'talenttrack' );
            $primary_label  = __( 'Pick an activity', 'talenttrack' );
            $primary_url    = WizardEntryPoint::urlFor( 'mark-attendance', $ctx->viewUrl( 'activities' ) );
            $secondary_html = '';
        } else {
            $aid     = (int) $next->id;
            $eyebrow = UpcomingActivityRepository::eyebrowFor( (string) $next->session_date );
            $title   = (string) ( $next->title ?? __( 'Activity', 'talenttrack' ) );
            $detail  = self::buildDetail( $next );

            $wizard_base   = WizardEntryPoint::urlFor( 'mark-attendance', $ctx->viewUrl( 'activities' ) );
            $primary_url   = add_query_arg( [ 'activity_id' => $aid ], $wizard_base );
            $primary_label = __( 'Mark attendance', 'talenttrack' );

            // v3.110.73 — attach `tt_back` pointing to the dashboard so
            // the activity edit form's Cancel button returns the coach
            // where they came from (the hero), not to the activities
            // list. `BackLink::appendTo()` is the canonical way to wire
            // cross-surface back-targets per CLAUDE.md §5.
            $edit_url = add_query_arg(
                [ 'tt_view' => 'activities', 'id' => $aid ],
                $ctx->viewUrl( 'activities' )
            );
            $edit_url = BackLink::appendTo( $edit_url, RecordLink::dashboardUrl() );
            $secondary_html = '<a class="tt-pd-cta tt-pd-cta-ghost" href="' . esc_url( $edit_url ) . '">'
                . esc_html__( 'Edit activity', 'talenttrack' )
                . '</a>';
        }

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
            . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-hero-detail">' . esc_html( $detail ) . '</div>'
            . '<div class="tt-pd-hero-cta-row">'
            . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $primary_url ) . '">'
            . esc_html( $primary_label )
            . '</a>'
            . $secondary_html
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-mark-attendance' );
    }

    private static function buildDetail( object $row ): string {
        $bits = [];
        $team_name = UpcomingActivityRepository::teamName( (int) ( $row->team_id ?? 0 ) );
        if ( $team_name !== '' ) $bits[] = $team_name;
        $location = (string) ( $row->location ?? '' );
        if ( $location !== '' ) $bits[] = $location;
        return implode( ' · ', $bits );
    }
}
