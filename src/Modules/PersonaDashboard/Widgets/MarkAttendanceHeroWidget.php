<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Repositories\UpcomingActivityRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardState;

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

    public function description(): string {
        return __( 'Head-coach landing card: reads the next un-processed activity on a team the coach owns, surfaces type / team / time, and exposes a one-tap CTA into the mark-attendance wizard with `activity_id` pre-seeded. Empty state ("no upcoming activity") shows a "Pick an activity" CTA into the wizard\'s activity picker. Reads upcoming activities via UpcomingActivityRepository.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'head_coach', 'assistant_coach' ];
    }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::COACH; }

    public function capRequired(): string { return 'tt_edit_evaluations'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        // #879 — if a live match-execution is active OR a prepped match
        // is scheduled today on one of the coach's teams, pivot the
        // hero to "Resume match" / "Start match". Otherwise fall
        // through to the existing mark-attendance flow below.
        $exec_inner = self::renderMatchExecutionBranch( $slot, $ctx );
        if ( $exec_inner !== null ) {
            return $this->wrap( $slot, $exec_inner, 'hero hero-match-execution' );
        }

        // v3.110.186 (#792) — was `nextForCoach()`, which returns the
        // next UPCOMING activity (not yet completed). The mark-attendance
        // wizard only acts on COMPLETED, not-yet-evaluated, rateable
        // activities, so a hero pre-seeding an upcoming activity_id put
        // the user on a roster page for a session that hadn't happened
        // yet — "player list with no activity context" symptom from #792.
        // `latestRateableForCoach` queries the same universe the wizard's
        // picker uses, so the hero and the wizard agree.
        $next = UpcomingActivityRepository::latestRateableForCoach( $ctx->user_id, $ctx->club_id );

        if ( $next === null ) {
            $eyebrow = __( 'Up next', 'talenttrack' );
            $title   = __( 'No upcoming activity', 'talenttrack' );
            $detail  = __( 'Schedule a training or match to populate this card.', 'talenttrack' );
            // #1350 — the CTA names the immediate action. The old shared
            // "Select completed activity to evaluate" label (v3.110.108)
            // was wrong on both branches: nothing here is "completed"
            // jargon a coach uses, and in the populated state there's
            // nothing left to select.
            $primary_label  = __( 'Pick an activity', 'talenttrack' );
            // v3.110.84 — `restart=1` forces a fresh wizard run.
            // Belt-and-suspenders alongside the autosave removal: even
            // if a stale `tt_wizard_drafts` row somehow lingered, the
            // hero entry CTA always nukes the wizard state before
            // first render. Coaches expect the hero to start a new
            // motion, not resume an abandoned one.
            $primary_url    = add_query_arg( [ 'restart' => 1 ], WizardEntryPoint::urlFor( 'mark-attendance', $ctx->viewUrl( 'activities' ) ) );
            $secondary_html = '';
        } else {
            $aid     = (int) $next->id;
            $eyebrow = UpcomingActivityRepository::eyebrowFor( (string) $next->session_date );
            // v3.110.78 — lead with the activity TYPE (Training /
            // Wedstrijd / etc.) so the coach reads "what's next" at a
            // glance, regardless of what they named the row. The
            // user-supplied title (e.g. "Dinsdag") demotes to the
            // detail line. Falls back to the user title if the type
            // lookup is missing.
            $type_key   = (string) ( $next->activity_type_key ?? '' );
            $type_label = UpcomingActivityRepository::activityTypeLabel( $type_key );
            $user_title = trim( (string) ( $next->title ?? '' ) );
            $title      = $type_label !== ''
                ? $type_label
                : ( $user_title !== '' ? $user_title : __( 'Activity', 'talenttrack' ) );
            $detail     = self::buildDetail( $next, $type_label !== '' ? $user_title : '' );

            $wizard_base   = WizardEntryPoint::urlFor( 'mark-attendance', $ctx->viewUrl( 'activities' ) );
            // #1350 — resume instead of restart when an in-flight run
            // for THIS activity exists: a coach who stepped away
            // mid-attendance and taps the hero again expects to land
            // where they were, not lose the run. A run for a different
            // activity (or none) keeps the v3.110.84 fresh-start nuke.
            $in_flight   = WizardState::load( $ctx->user_id, 'mark-attendance' );
            $resume_same = (int) ( $in_flight['activity_id'] ?? 0 ) === $aid;
            $url_args    = [ 'activity_id' => $aid ];
            if ( ! $resume_same ) {
                $url_args['restart'] = 1;
            }
            $primary_url = add_query_arg( $url_args, $wizard_base );

            // #1350 — name the action and the target: the coach's most-
            // tapped button should read "Mark attendance — Training ·
            // U14 · Today", not wizard jargon.
            $cta_context = implode( ' · ', array_filter( [
                $title,
                trim( (string) ( $next->team_name ?? '' ) ),
                UpcomingActivityRepository::dayLabelFor( (string) $next->session_date ),
            ], static fn( $part ) => $part !== '' ) );
            $primary_label = $resume_same
                /* translators: %s = activity type · team · day, e.g. "Training · U14 · Today" */
                ? sprintf( __( 'Continue attendance — %s', 'talenttrack' ), $cta_context )
                /* translators: %s = activity type · team · day, e.g. "Training · U14 · Today" */
                : sprintf( __( 'Mark attendance — %s', 'talenttrack' ), $cta_context );

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

    /**
     * Detail line: `<user title> · <team> · <location>`. The user
     * title slot is only filled when the activity TYPE was used as the
     * hero title — otherwise the user title is the hero title and we
     * shouldn't repeat it.
     */
    /**
     * #879 — match-execution pivot. Returns the inner HTML for the
     * hero when a live execution or a startable prepped match exists
     * on one of the coach's teams; returns null to fall through to
     * the default mark-attendance flow.
     *
     * Priority: live execution > startable prepped match > null.
     *
     * Tenancy + team scope are handled inside the repository helpers
     * (`findLiveForTeams` / `findStartableForTeams`); both consume the
     * coach's `team_ids` from `QueryHelpers::get_teams_for_coach()`,
     * which returns empty for users who don't actually coach a team
     * (HoD / admin without a personal assignment).
     */
    private static function renderMatchExecutionBranch( WidgetSlot $slot, RenderContext $ctx ): ?string {
        $teams = QueryHelpers::get_teams_for_coach( $ctx->user_id );
        if ( $teams === [] ) return null;
        $team_ids = [];
        foreach ( $teams as $t ) {
            $tid = isset( $t->id ) ? (int) $t->id : 0;
            if ( $tid > 0 ) $team_ids[] = $tid;
        }
        if ( $team_ids === [] ) return null;

        $repo = new MatchExecutionRepository();

        // 1) Live match — highest priority.
        $live = $repo->findLiveForTeams( $team_ids );
        if ( $live !== null ) {
            $aid = (int) $live->activity_id;
            $home_score = (int) ( $live->home_score ?? 0 );
            $away_score = (int) ( $live->away_score ?? 0 );
            $state = (string) ( $live->state ?? '' );
            $minute_label = self::liveMinuteLabel( $live );
            // Eyebrow: "Live · <minute_label>" (e.g. "Live · 1e 23'").
            $eyebrow = $minute_label !== ''
                ? sprintf(
                    /* translators: %s = live half + minute, e.g. "1e 23'" */
                    __( 'Live · %s', 'talenttrack' ),
                    $minute_label
                )
                : __( 'Live', 'talenttrack' );
            $team_name = UpcomingActivityRepository::teamName( (int) ( $live->team_id ?? 0 ) );
            $opponent  = trim( (string) ( $live->opponent ?? '' ) );
            $title = $team_name !== '' && $opponent !== ''
                ? $team_name . ' · ' . $opponent
                : ( $team_name !== '' ? $team_name : ( $opponent !== '' ? $opponent : __( 'Live match', 'talenttrack' ) ) );
            $detail = sprintf( '%d — %d', $home_score, $away_score );

            $resume_url = add_query_arg(
                [ 'tt_view' => 'match-execution', 'activity_id' => $aid ],
                $ctx->viewUrl( 'activities' )
            );
            return '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
                . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
                . '<div class="tt-pd-hero-detail">' . esc_html( $detail ) . '</div>'
                . '<div class="tt-pd-hero-cta-row">'
                . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $resume_url ) . '">'
                . esc_html__( 'Resume match', 'talenttrack' )
                . '</a>'
                . '</div>';
        }

        // 2) Startable prepped match today.
        $start = $repo->findStartableForTeams( $team_ids );
        if ( $start !== null ) {
            $aid = (int) $start->activity_id;
            $team_name = UpcomingActivityRepository::teamName( (int) ( $start->team_id ?? 0 ) );
            $opponent  = trim( (string) ( $start->opponent ?? '' ) );
            $title = $team_name !== '' && $opponent !== ''
                ? $team_name . ' · ' . $opponent
                : ( $team_name !== '' ? $team_name : ( $opponent !== '' ? $opponent : __( "Today's match", 'talenttrack' ) ) );

            $detail_bits = [];
            $start_time = (string) ( $start->start_time ?? '' );
            if ( $start_time !== '' ) {
                // Trim trailing :00 seconds for a cleaner kickoff label.
                if ( preg_match( '/^(\d{2}:\d{2})/', $start_time, $m ) ) {
                    $start_time = $m[1];
                }
                $detail_bits[] = sprintf(
                    /* translators: %s = kickoff time HH:MM */
                    __( 'Kickoff %s', 'talenttrack' ),
                    $start_time
                );
            }
            $location = (string) ( $start->location ?? '' );
            if ( $location !== '' ) $detail_bits[] = $location;

            $start_url = add_query_arg(
                [ 'tt_view' => 'match-execution', 'activity_id' => $aid ],
                $ctx->viewUrl( 'activities' )
            );
            $prep_url = add_query_arg(
                [ 'tt_view' => 'match-prep', 'activity_id' => $aid ],
                $ctx->viewUrl( 'activities' )
            );
            $prep_url = BackLink::appendTo( $prep_url, RecordLink::dashboardUrl() );

            return '<div class="tt-pd-hero-eyebrow">' . esc_html__( 'Today', 'talenttrack' ) . '</div>'
                . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
                . '<div class="tt-pd-hero-detail">' . esc_html( implode( ' · ', $detail_bits ) ) . '</div>'
                . '<div class="tt-pd-hero-cta-row">'
                . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $start_url ) . '">'
                . esc_html__( 'Start match', 'talenttrack' )
                . '</a>'
                . '<a class="tt-pd-cta tt-pd-cta-ghost" href="' . esc_url( $prep_url ) . '">'
                . esc_html__( 'Edit prep', 'talenttrack' )
                . '</a>'
                . '</div>';
        }

        return null;
    }

    /**
     * #879 — derive a "1e 23'" / "HT" / "2e 67'" label from the
     * execution row's timer columns. Returns "" when we can't compute
     * a minute (e.g. row exists but `state = not_started` — though
     * that's filtered out upstream).
     */
    private static function liveMinuteLabel( object $row ): string {
        $state = (string) ( $row->state ?? '' );
        $pause_first  = (int) ( $row->first_half_pause_seconds ?? 0 );
        $pause_second = (int) ( $row->second_half_pause_seconds ?? 0 );
        $now_ts = current_time( 'timestamp', true ); // UTC

        if ( $state === MatchExecutionState::FIRST_HALF ) {
            $start = strtotime( (string) ( $row->first_half_started_at ?? '' ) . ' UTC' );
            if ( $start === false ) return '';
            $elapsed = max( 0, $now_ts - $start - $pause_first );
            return self::formatMinute( 1, (int) floor( $elapsed / 60 ) );
        }
        if ( $state === MatchExecutionState::SECOND_HALF ) {
            $start = strtotime( (string) ( $row->second_half_started_at ?? '' ) . ' UTC' );
            if ( $start === false ) return '';
            $elapsed = max( 0, $now_ts - $start - $pause_second );
            return self::formatMinute( 2, (int) floor( $elapsed / 60 ) );
        }
        if ( $state === MatchExecutionState::HALF_TIME ) {
            return __( 'HT', 'talenttrack' );
        }
        return '';
    }

    private static function formatMinute( int $half, int $minute ): string {
        return sprintf(
            /* translators: 1: half number (1 or 2), 2: minute */
            __( '%1$de %2$d\'', 'talenttrack' ),
            $half,
            $minute
        );
    }

    private static function buildDetail( object $row, string $user_title = '' ): string {
        $bits = [];
        if ( $user_title !== '' ) $bits[] = $user_title;
        $team_name = UpcomingActivityRepository::teamName( (int) ( $row->team_id ?? 0 ) );
        if ( $team_name !== '' ) $bits[] = $team_name;
        $location = (string) ( $row->location ?? '' );
        if ( $location !== '' ) $bits[] = $location;
        return implode( ' · ', $bits );
    }
}
