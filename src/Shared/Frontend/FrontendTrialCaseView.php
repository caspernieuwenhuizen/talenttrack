<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\StaffPickerComponent;
use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Domain\TrialDecisionMotivation;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Modules\Trials\Services\TrialDecisionDeadline;
use TT\Modules\Trials\Services\TrialDecisionService;

/**
 * FrontendTrialCaseView — the trial case working surface at
 * `?tt_view=trial-case&id=N` (#0017, redesigned in #1646).
 *
 * The layout mirrors the player profile and team detail
 * (FrontendPlayerDetailView / FrontendTeamDetailView): a paper hero
 * carrying the player identity (photo + name anchor the page — a trial
 * is a key transition in the player's journey), an action row, a
 * key-facts strip, then the content in `tt-player-card`-style panels.
 *
 * Navigation is tab-based (Overview · Execution · Inputs · Decision ·
 * Letter · Parent meeting). Per-tab visibility is enforced via
 * `TrialCaseAccessPolicy`: the Decision and Letter tabs are
 * manager-only; Execution needs `canViewSynthesis()` because it
 * aggregates other coaches' input; Inputs is visible to assigned staff
 * (own input, plus released ones) and managers (everyone's, with release
 * control).
 *
 * #3222 — entry is gated on `canOpenCase()`, the union of "may read the
 * synthesis" and "may write an input". It used to be the synthesis alone,
 * which locked out an assigned assistant coach: the seed grants that
 * persona `trial_inputs: change` and no `trial_synthesis`, so they were
 * entitled to write an input and could not open the screen holding the
 * field. Widening entry does not widen what anybody reads — Execution
 * still checks the synthesis gate, in the tab list and again when the
 * body renders, since `?tab=execution` is a URL anyone can type.
 *
 * Composition only — all data comes from repositories / QueryHelpers,
 * all visibility/decision logic lives in the domain layer (CLAUDE.md §4).
 */
class FrontendTrialCaseView extends FrontendViewBase {

    private const TAB_OVERVIEW  = 'overview';
    private const TAB_EXECUTION = 'execution';
    private const TAB_INPUTS    = 'inputs';
    private const TAB_DECISION  = 'decision';
    private const TAB_LETTER    = 'letter';
    private const TAB_MEETING   = 'meeting';

    private static bool $detail_css_enqueued = false;

    /**
     * #3786 — a refused decide, carried from `handlePost()` to the
     * Decision tab in the same request.
     *
     * The decide action is **not** POST-redirect-GET: `handlePost()` runs
     * inside `render()`, which itself runs inside `the_content`, long after
     * headers could be sent. So the refusal and the user's draft live in a
     * property for the rest of this request and nowhere else — no
     * transient, and therefore no stale error to survive into somebody's
     * next page load.
     *
     * Carries the `refusal()` shape plus the four values the user typed.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $decide_error = null;

    private static function enqueueDetailAssets(): void {
        if ( self::$detail_css_enqueued ) return;
        // Reuse the player-detail card system + tokens (1:1 shapes), then
        // layer the trial-specific tweaks on top.
        wp_enqueue_style(
            'tt-frontend-player-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-player-detail.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        self::$detail_css_enqueued = true;
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $trials_label = __( 'Trials', 'talenttrack' );
        $parent_crumb = [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'trials', $trials_label ) ];

        // v3.85.5 — Trials is Pro-tier; the case detail view inherits
        // the same gate as the manage view.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'trial_module' )
        ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Trial case', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Trial case', 'talenttrack' ) );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Trial cases', 'talenttrack' ), 'pro' );
            return;
        }

        $case_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $case_id <= 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Trial case not found', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Trial case not found', 'talenttrack' ) );
            return;
        }

        // #3222 — the union of "may read the synthesis" and "may write an
        // input", both of which already require assignment. Gating entry on
        // the synthesis alone locked out the one persona whose only job on
        // a case is to submit input.
        if ( ! TrialCaseAccessPolicy::canOpenCase( $user_id, $case_id ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Trial case', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You are not assigned to this case.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueDetailAssets();
        self::handlePost( $user_id, $case_id );

        $cases  = new TrialCasesRepository();
        $case   = $cases->find( $case_id );
        if ( ! $case ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Trial case not found', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Trial case not found', 'talenttrack' ) );
            return;
        }

        $player = QueryHelpers::get_player( (int) $case->player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '#' . (int) $case->player_id;
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( [
            [ 'label' => __( 'Dashboard', 'talenttrack' ), 'url' => RecordLink::dashboardUrl() ],
            [ 'label' => $trials_label, 'url' => add_query_arg( [ 'tt_view' => 'trials' ], RecordLink::dashboardUrl() ) ],
            [ 'label' => sprintf( __( 'Trial: %s', 'talenttrack' ), $name ) ],
        ] );

        $is_manager = TrialCaseAccessPolicy::isManager( $user_id );
        // #3222 — Execution aggregates other coaches' input, so it follows
        // the synthesis gate rather than mere entry to the case. An
        // input-only assistant coach gets Overview and Staff inputs.
        $sees_synthesis = TrialCaseAccessPolicy::canViewSynthesis( $user_id, $case_id );

        $tabs       = self::tabSet( $case, $is_manager, $sees_synthesis );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_OVERVIEW;
        if ( ! array_key_exists( $active_tab, $tabs ) ) {
            $active_tab = self::TAB_OVERVIEW;
        }
        $base_url = add_query_arg(
            [ 'tt_view' => 'trial-case', 'id' => (int) $case->id ],
            RecordLink::dashboardUrl()
        );
        ?>
        <article class="tt-player-detail tt-trial-detail" data-tab="<?php echo esc_attr( $active_tab ); ?>">
            <?php
            self::renderHero( $case, $player, $name );
            self::renderActionRow( $case, $user_id, $is_manager );
            self::renderEndingSoonBanner( $case );
            ?>

            <div class="tt-player-detail__rail">
                <?php self::renderKeyFacts( $case ); ?>
            </div>

            <div class="tt-player-detail__main">
                <?php
                // #2822 — the section strip comes from the shared spine
                // (CLAUDE.md §5c): these tabs move between facets of one
                // trial case without leaving its subject. `tabs_always`
                // because they are the only route to those sections, so
                // they must survive the `classic` shell.
                \TT\Shared\Frontend\Components\RecordSpine::render( [
                    'name'        => $name,
                    'photo_url'   => $player ? (string) ( $player->photo_url ?? '' ) : '',
                    'meta'        => $trials_label,
                    'tabs_always' => true,
                    'tabs'        => array_values( array_map(
                        static fn( string $key, string $label ): array => [
                            'label'  => $label,
                            'url'    => add_query_arg( [ 'tab' => $key ], $base_url ),
                            'active' => $key === $active_tab,
                        ],
                        array_keys( $tabs ),
                        array_values( $tabs )
                    ) ),
                ] );
                ?>

                <section class="tt-player-tab-panel">
                    <?php
                    switch ( $active_tab ) {
                        case self::TAB_EXECUTION:
                            // #3222 — re-checked rather than trusted to the
                            // tab list: `?tab=execution` is a URL anybody
                            // who reached the case can type.
                            if ( $sees_synthesis ) { self::renderExecutionTab( $case ); }
                            break;
                        case self::TAB_INPUTS:    self::renderInputsTab( $case, $user_id ); break;
                        case self::TAB_DECISION:
                            if ( $is_manager ) { self::renderDecisionTab( $case ); }
                            break;
                        case self::TAB_LETTER:
                            if ( $is_manager ) { self::renderLetterTab( $case ); }
                            break;
                        case self::TAB_MEETING:
                            if ( $is_manager ) { self::renderMeetingTab( $case ); }
                            break;
                        case self::TAB_OVERVIEW:
                        default:                  self::renderOverviewTab( $case, $user_id ); break;
                    }
                    ?>
                </section>
            </div>

            <?php
            // #1646 — nonce-bearing archive form, submitted in one click by
            // the "Archive case" action in the row above (requestSubmit).
            // No visible mid-body control; the form is `hidden`.
            if ( $is_manager && $case->archived_at === null ) {
                echo '<form method="post" id="tt-trial-archive-form" class="tt-trial-archive" hidden>';
                wp_nonce_field( 'tt_trial_archive_' . (int) $case->id, 'tt_trial_archive_nonce' );
                echo '<input type="hidden" name="tt_trial_action" value="archive">';
                echo '</form>';
            }
            ?>
        </article>
        <?php
    }

    /**
     * Tab labels in display order. Manager-only tabs (Decision, Letter)
     * and the post-decision Parent-meeting tab are folded in here.
     *
     * @return array<string,string>
     */
    private static function tabSet( object $case, bool $is_manager, bool $sees_synthesis = true ): array {
        $tabs = [ self::TAB_OVERVIEW => __( 'Overview', 'talenttrack' ) ];
        if ( $sees_synthesis ) {
            $tabs[ self::TAB_EXECUTION ] = __( 'Execution', 'talenttrack' );
        }
        $tabs[ self::TAB_INPUTS ] = __( 'Staff inputs', 'talenttrack' );
        if ( $is_manager ) {
            $tabs[ self::TAB_DECISION ] = __( 'Decision', 'talenttrack' );
            $tabs[ self::TAB_LETTER ]   = __( 'Letter', 'talenttrack' );
            if ( $case->status === TrialCasesRepository::STATUS_DECIDED ) {
                $tabs[ self::TAB_MEETING ] = __( 'Parent meeting', 'talenttrack' );
            }
        }
        return $tabs;
    }

    /**
     * Paper hero — the player's photo (or initials) and name anchor the
     * trial case, with pills for trial status, decision and track. A
     * trial is a key transition in the player's journey, so the player
     * stays the subject of the page.
     */
    private static function renderHero( object $case, ?object $player, string $name ): void {
        $photo  = $player ? (string) ( $player->photo_url ?? '' ) : '';
        $status = (string) $case->status;
        $player_url = $player
            ? RecordLink::detailUrlForWithBack( 'players', (int) $case->player_id )
            : '';
        ?>
        <header class="tt-player-detail__hero" aria-label="<?php esc_attr_e( 'Trial case', 'talenttrack' ); ?>">
            <div class="tt-player-hero__row">
                <div class="tt-player-hero__avatar" data-status="<?php echo esc_attr( $status ); ?>" aria-hidden="true">
                    <?php if ( $photo !== '' ) : ?>
                        <img class="tt-player-hero__photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
                    <?php else : ?>
                        <?php echo esc_html( self::initialsFor( $name ) ); ?>
                    <?php endif; ?>
                </div>
                <div class="tt-player-hero__main">
                    <h1 class="tt-player-hero__name"><?php echo esc_html( $name ); ?></h1>
                    <p class="tt-player-hero__sub">
                        <?php if ( $player_url !== '' ) : ?>
                            <a href="<?php echo esc_url( $player_url ); ?>"><?php esc_html_e( 'Player profile', 'talenttrack' ); ?></a>
                            <span> · <?php esc_html_e( 'Trial case', 'talenttrack' ); ?></span>
                        <?php else : ?>
                            <?php esc_html_e( 'Trial case', 'talenttrack' ); ?>
                        <?php endif; ?>
                    </p>
                    <p class="tt-player-hero__pills">
                        <span class="tt-player-pill" data-status="<?php echo esc_attr( $status ); ?>">
                            <?php echo esc_html( TrialCasesRepository::statusLabel( (string) $case->status ) ); ?>
                        </span>
                        <?php if ( $case->decision ) : ?>
                            <span class="tt-player-pill tt-player-pill--pos">
                                <?php echo esc_html( TrialCasesRepository::decisionLabel( (string) $case->decision ) ); ?>
                            </span>
                        <?php endif; ?>
                        <?php
                        $track = ( new TrialTracksRepository() )->find( (int) $case->track_id );
                        if ( $track ) :
                            ?>
                            <span class="tt-player-pill">
                                <?php echo esc_html( \TT\Infrastructure\Query\LabelTranslator::trialTrackName( (string) $track->name ) ); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </header>
        <?php
    }

    /**
     * Action row — Record decision (when undecided) · Archive case ·
     * Delete permanently (admin). Mirrors the player/team detail action
     * band. Cap-gated identically to the legacy header actions.
     */
    private static function renderActionRow( object $case, int $user_id, bool $is_manager ): void {
        $trials_url = add_query_arg( [ 'tt_view' => 'trials' ], RecordLink::dashboardUrl() );
        $can_delete = $is_manager && current_user_can( 'tt_edit_settings' );
        $can_archive = $is_manager && $case->archived_at === null;
        ?>
        <div class="tt-player-detail__actions" aria-label="<?php esc_attr_e( 'Actions', 'talenttrack' ); ?>">
            <?php if ( $is_manager && $case->archived_at === null && empty( $case->decision ) ) :
                $decision_url = add_query_arg(
                    [ 'tt_view' => 'trial-case', 'id' => (int) $case->id, 'tab' => self::TAB_DECISION ],
                    RecordLink::dashboardUrl()
                );
                ?>
                <a class="tt-player-action tt-player-action--primary" href="<?php echo esc_url( $decision_url ); ?>">
                    <?php esc_html_e( 'Record decision', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>

            <?php if ( $can_archive ) :
                $archive_label   = __( 'Archive case', 'talenttrack' );
                $archive_confirm = __( 'Archive this case?', 'talenttrack' );
                ?>
                <button type="button"
                        class="tt-player-action"
                        onclick="if(confirm(<?php echo esc_attr( wp_json_encode( $archive_confirm ) ); ?>)){document.getElementById('tt-trial-archive-form').requestSubmit();}return false;">
                    <?php echo esc_html( $archive_label ); ?>
                </button>
            <?php endif; ?>

            <?php if ( $can_delete ) :
                $delete_redirect = add_query_arg( [ 'tt_view' => 'trials' ], $trials_url );
                ?>
                <div class="tt-player-action tt-player-action--more"
                     role="button"
                     tabindex="0"
                     aria-haspopup="true"
                     aria-expanded="false"
                     aria-label="<?php esc_attr_e( 'More actions', 'talenttrack' ); ?>"
                     onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');}">
                    ⋯
                    <div class="tt-player-action__menu" role="menu">
                        <button type="button"
                                class="tt-player-action tt-player-action--danger"
                                role="menuitem"
                                data-tt-archive-rest-path="<?php echo esc_attr( 'trial-cases/' . (int) $case->id . '/permanent' ); ?>"
                                data-tt-archive-confirm="<?php echo esc_attr__( 'Permanently delete this trial case? This removes its staff, inputs and extensions and cannot be undone.', 'talenttrack' ); ?>"
                                data-tt-archive-redirect="<?php echo esc_attr( $delete_redirect ); ?>">
                            <?php esc_html_e( 'Delete permanently', 'talenttrack' ); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Key-facts strip — Track · Window · Status · Decision. The same
     * 3/4-up cells the player and team profiles use.
     */
    /**
     * #3801 — the trial ends in days and nobody has decided.
     *
     * Nothing on this page used to compare `end_date` against
     * `decision IS NULL`: the key-facts strip printed the window and the
     * status and left the reader to do the arithmetic, which is how a
     * case came to end on a Monday with the family still waiting.
     *
     * Whether it is close enough to say so is
     * `TrialDecisionDeadline`'s answer, shared with the REST read and the
     * `trials.decision_due_soon` alert, so the three cannot disagree about
     * the same date. Not colour-only: the banner leads with the word
     * "Deadline" and states the count in words, and it is a `role="status"`
     * region so a screen reader announces it on load.
     */
    private static function renderEndingSoonBanner( object $case ): void {
        $end     = (string) ( $case->end_date ?? '' );
        $decided = $case->decision ?? null;
        if ( ! TrialDecisionDeadline::isDueSoon( $end, $decided === null ? null : (string) $decided ) ) return;

        $days = TrialDecisionDeadline::daysRemaining( $end );
        if ( $days === null ) return;

        if ( $days < 0 ) {
            $message = sprintf(
                /* translators: %d: number of days since the trial window closed. */
                _n(
                    'This trial ended %d day ago and no decision has been recorded.',
                    'This trial ended %d days ago and no decision has been recorded.',
                    abs( $days ),
                    'talenttrack'
                ),
                abs( $days )
            );
        } elseif ( $days === 0 ) {
            $message = __( 'This trial ends today and no decision has been recorded.', 'talenttrack' );
        } else {
            $message = sprintf(
                /* translators: %d: number of days until the trial window closes. */
                _n(
                    'This trial ends in %d day and no decision has been recorded.',
                    'This trial ends in %d days and no decision has been recorded.',
                    $days,
                    'talenttrack'
                ),
                $days
            );
        }

        echo '<p class="tt-trial-deadline" role="status" data-overdue="' . esc_attr( $days < 0 ? '1' : '0' ) . '">';
        echo '<strong class="tt-trial-deadline__label">' . esc_html( _x( 'Deadline', 'trial case ending-soon banner', 'talenttrack' ) ) . '</strong> ';
        echo '<span class="tt-trial-deadline__text">' . esc_html( $message ) . '</span>';
        echo '</p>';
    }

    private static function renderKeyFacts( object $case ): void {
        $track = ( new TrialTracksRepository() )->find( (int) $case->track_id );
        $track_label = $track
            ? \TT\Infrastructure\Query\LabelTranslator::trialTrackName( (string) $track->name )
            : '—';
        $window = trim( (string) $case->start_date . ' → ' . (string) $case->end_date );
        ?>
        <section class="tt-player-facts" aria-label="<?php esc_attr_e( 'Key facts', 'talenttrack' ); ?>">
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Track', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( $track_label ); ?></p>
            </div>
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Trial window', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( $window !== '→' ? $window : '—' ); ?></p>
            </div>
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Status', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( TrialCasesRepository::statusLabel( (string) $case->status ) ); ?></p>
            </div>
            <?php if ( $case->decision ) : ?>
                <div class="tt-player-facts__cell">
                    <span class="tt-player-facts__label"><?php esc_html_e( 'Decision', 'talenttrack' ); ?></span>
                    <p class="tt-player-facts__value"><?php echo esc_html( TrialCasesRepository::decisionLabel( (string) $case->decision ) ); ?></p>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /* ===== Panel helpers (player-card house pattern) ===== */

    private static function cardOpen( string $title ): void {
        echo '<div class="tt-player-card tt-trial-card">';
        echo '<div class="tt-player-card__head">';
        echo '<h2 class="tt-player-card__title">' . esc_html( $title ) . '</h2>';
        echo '</div>';
        echo '<div class="tt-player-card__body">';
    }

    private static function cardClose(): void {
        echo '</div></div>';
    }

    /* ===== Overview tab ===== */

    private static function renderOverviewTab( object $case, int $user_id ): void {
        $is_manager = TrialCaseAccessPolicy::isManager( $user_id );
        $staff_repo = new TrialCaseStaffRepository();
        $ext_repo   = new TrialExtensionsRepository();
        $staff      = $staff_repo->listForCase( (int) $case->id );
        $extensions = $ext_repo->listForCase( (int) $case->id );

        self::cardOpen( __( 'Summary', 'talenttrack' ) );
        if ( $case->notes ) {
            echo '<p>' . esc_html( (string) $case->notes ) . '</p>';
        } else {
            echo '<p class="tt-player-empty">' . esc_html__( 'No summary notes on this case yet.', 'talenttrack' ) . '</p>';
        }
        self::cardClose();

        self::cardOpen( __( 'Assigned staff', 'talenttrack' ) );
        if ( ! $staff ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No staff assigned yet.', 'talenttrack' ) . '</p>';
        } else {
            // #3801 — who has handed in and who has not. The panel roster
            // used to be a bare list of names, so "has Sanne submitted?"
            // was a question you answered by texting Sanne. Only for a
            // reader who may already read the case's aggregation: the
            // *fact* of a submission is not the judgement inside it, but it
            // is still more than an input-only assistant coach is here for.
            $case_id        = (int) ( $case->id ?? 0 );
            $sees_synthesis = TrialCaseAccessPolicy::canViewSynthesis( $user_id, $case_id );
            $submitted      = [];
            if ( $sees_synthesis ) {
                foreach ( ( new TrialStaffInputsRepository() )->listForCase( $case_id, true ) as $row ) {
                    $submitted[ (int) ( $row->user_id ?? 0 ) ] = (string) ( $row->submitted_at ?? '' );
                }
            }

            echo '<ul class="tt-trial-staff-list">';
            foreach ( $staff as $s ) {
                $uid   = (int) $s->user_id;
                $u     = get_userdata( $uid );
                $label = $u ? (string) $u->display_name : '#' . $uid;
                $role  = $s->role_label ? ' (' . esc_html( (string) $s->role_label ) . ')' : '';
                echo '<li>' . esc_html( $label ) . $role;
                if ( $sees_synthesis ) {
                    $has = isset( $submitted[ $uid ] );
                    echo ' <span class="tt-trial-staff-state" data-submitted="' . esc_attr( $has ? '1' : '0' ) . '">';
                    echo esc_html( $has
                        ? sprintf(
                            /* translators: %s: date and time the input was submitted. */
                            __( 'Submitted %s', 'talenttrack' ),
                            $submitted[ $uid ]
                        )
                        : __( 'No input yet', 'talenttrack' )
                    );
                    echo '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        if ( $is_manager ) {
            self::renderAssignStaffForm( (int) $case->id );
        }
        self::cardClose();

        self::cardOpen( __( 'Extension history', 'talenttrack' ) );
        if ( ! $extensions ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No extensions yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-table-wrap">';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Extended at', 'talenttrack' ) . '</th><th>' . esc_html__( 'Previous end', 'talenttrack' ) . '</th><th>' . esc_html__( 'New end', 'talenttrack' ) . '</th><th>' . esc_html__( 'Justification', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $extensions as $e ) {
                echo '<tr><td>' . esc_html( (string) $e->extended_at ) . '</td><td>' . esc_html( (string) $e->previous_end_date ) . '</td><td>' . esc_html( (string) $e->new_end_date ) . '</td><td>' . esc_html( (string) $e->justification ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        if ( $is_manager && in_array( $case->status, [ 'open', 'extended' ], true ) ) {
            self::renderExtensionForm( (int) $case->id, (string) $case->end_date );
        }
        self::cardClose();
    }

    private static function renderAssignStaffForm( int $case_id ): void {
        echo '<form method="post" class="tt-trial-assign-form">';
        wp_nonce_field( 'tt_trial_assign_' . $case_id, 'tt_trial_assign_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="assign_staff">';
        echo StaffPickerComponent::render( [
            'name'        => 'staff_user_id',
            'label'       => __( 'Staff member', 'talenttrack' ),
            'required'    => true,
            'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
        ] );
        echo '<label>' . esc_html__( 'Role label (optional)', 'talenttrack' ) . ' <input type="text" name="role_label" class="tt-input" placeholder="' . esc_attr__( 'e.g. Goalkeeping coach', 'talenttrack' ) . '"></label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Assign', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    private static function renderExtensionForm( int $case_id, string $current_end ): void {
        $next = gmdate( 'Y-m-d', strtotime( $current_end . ' +14 days' ) ?: time() + 14 * 86400 );
        echo '<form method="post" class="tt-trial-extend-form">';
        wp_nonce_field( 'tt_trial_extend_' . $case_id, 'tt_trial_extend_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="extend">';
        echo '<label>' . esc_html__( 'New end date', 'talenttrack' ) . ' <input type="date" name="new_end_date" value="' . esc_attr( $next ) . '" required></label>';
        echo '<label>' . esc_html__( 'Justification (required)', 'talenttrack' ) . ' <textarea name="justification" rows="2" required></textarea></label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Extend trial', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /* ===== Execution tab ===== */

    private static function renderExecutionTab( object $case ): void {
        global $wpdb;
        $pid    = (int) $case->player_id;
        $start  = (string) $case->start_date;
        $end    = (string) $case->end_date;

        self::cardOpen( __( 'Synthesis', 'talenttrack' ) );
        $svc      = new PlayerStatsService();
        $headline = $svc->getHeadlineNumbers( $pid, [ 'date_from' => $start, 'date_to' => $end ], 5 );
        if ( (int) $headline['eval_count'] === 0 ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No evaluations during the trial window yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-trial-headline">';
            echo '<li>' . esc_html__( 'Rolling rating', 'talenttrack' ) . ' <strong>' . esc_html( (string) $headline['rolling'] ) . '</strong></li>';
            echo '<li>' . esc_html__( 'Evaluations in window', 'talenttrack' ) . ' <strong>' . (int) $headline['eval_count'] . '</strong></li>';
            echo '</ul>';
        }
        self::cardClose();

        // Activities — schema names: tt_activities + tt_attendance.
        //
        // #3451 — the join is scoped to the RECORDED register. A planned
        // squad stores Expected as `Present`, so the trial case's Attendance
        // column claimed the player had turned up to a session nobody had
        // registered, and a player holding both kinds of row listed the
        // activity twice.
        /** @var array<int,object> $activities */
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.activity_date, a.activity_type_key, a.notes, att.status AS attendance
               FROM {$wpdb->prefix}tt_activities a
          LEFT JOIN {$wpdb->prefix}tt_attendance att
                 ON att.activity_id = a.id AND att.player_id = %d AND att.club_id = a.club_id
                AND att.record_type = 'actual'
              WHERE a.activity_date BETWEEN %s AND %s
                AND a.club_id = %d
              ORDER BY a.activity_date DESC LIMIT 500", $pid, $start, $end, CurrentClub::id()
        ) );
        self::cardOpen( __( 'Activities', 'talenttrack' ) );
        if ( ! $activities ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No activities yet during this trial period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-table-wrap">';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Type', 'talenttrack' ) . '</th><th>' . esc_html__( 'Attendance', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $activities as $a ) {
                echo '<tr><td>' . esc_html( (string) $a->activity_date ) . '</td><td>' . esc_html( (string) $a->activity_type_key ) . '</td><td>' . esc_html( (string) ( $a->attendance ?? '—' ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        self::cardClose();

        // Evaluations.
        /** @var array<int,object> $evals */
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date, evaluator_user_id
               FROM {$wpdb->prefix}tt_evaluations
              WHERE player_id = %d AND eval_date BETWEEN %s AND %s AND club_id = %d
              ORDER BY eval_date DESC LIMIT 500", $pid, $start, $end, CurrentClub::id()
        ) );
        self::cardOpen( __( 'Evaluations', 'talenttrack' ) );
        if ( ! $evals ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No evaluations yet during this trial period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-table-wrap">';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Evaluator', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $evals as $e ) {
                $u = get_userdata( (int) $e->evaluator_user_id );
                echo '<tr><td>' . esc_html( \TT\Shared\Dates\TTDate::date( (string) $e->eval_date ) ) . '</td><td>' . esc_html( $u ? (string) $u->display_name : '#' . (int) $e->evaluator_user_id ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        self::cardClose();

        // Goals.
        /** @var array<int,object> $goals */
        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, priority, target_date, updated_at
               FROM {$wpdb->prefix}tt_goals
              WHERE player_id = %d
                AND ( ( created_at >= %s AND created_at <= %s )
                   OR ( updated_at >= %s AND updated_at <= %s ) )
                AND archived_at IS NULL
                AND club_id = %d
              ORDER BY updated_at DESC LIMIT 500",
            $pid, $start . ' 00:00:00', $end . ' 23:59:59', $start . ' 00:00:00', $end . ' 23:59:59', CurrentClub::id()
        ) );
        self::cardOpen( __( 'Goals', 'talenttrack' ) );
        if ( ! $goals ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No goals yet during this trial period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-table-wrap">';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Title', 'talenttrack' ) . '</th><th>' . esc_html__( 'Status', 'talenttrack' ) . '</th><th>' . esc_html__( 'Priority', 'talenttrack' ) . '</th><th>' . esc_html__( 'Updated', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $goals as $g ) {
                echo '<tr><td>' . esc_html( (string) $g->title ) . '</td><td>' . esc_html( LookupTranslator::byTypeAndName( 'goal_status', (string) ( $g->status ?? '' ) ) ) . '</td><td>' . esc_html( LookupTranslator::byTypeAndName( 'goal_priority', (string) ( $g->priority ?? '' ) ) ) . '</td><td>' . esc_html( (string) $g->updated_at ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        self::cardClose();
    }

    /* ===== Inputs tab ===== */

    private static function renderInputsTab( object $case, int $user_id ): void {
        $is_manager = TrialCaseAccessPolicy::isManager( $user_id );
        $inputs_repo = new TrialStaffInputsRepository();
        $staff_repo  = new TrialCaseStaffRepository();
        $assigned    = $staff_repo->isAssigned( (int) $case->id, $user_id );

        // Own input form (if assigned and the case is still gathering
        // input). #3238 — the status literal used to live here and nowhere
        // else, which is how the API came to have no rule at all. The
        // predicate is now shared, so the screen and the endpoint cannot
        // drift again. `statusAcceptsInput()` rather than the case-id form
        // because this method already holds the row.
        if ( $assigned && TrialCaseAccessPolicy::statusAcceptsInput( (string) $case->status ) ) {
            $own = $inputs_repo->findForCaseUser( (int) $case->id, $user_id );
            self::renderOwnInputForm( (int) $case->id, $own );
        }

        // Aggregation for manager + assigned staff who can see released inputs.
        $visible = $inputs_repo->listVisibleForUser( (int) $case->id, $user_id, $is_manager );
        self::cardOpen( __( 'Submitted inputs', 'talenttrack' ) );

        if ( $is_manager ) {
            $assigned_count  = count( $staff_repo->listForCase( (int) $case->id ) );
            $submitted_count = count( $inputs_repo->listForCase( (int) $case->id, true ) );
            echo '<p class="tt-trial-input-count">' . sprintf( esc_html__( '%1$d of %2$d assigned staff have submitted.', 'talenttrack' ), $submitted_count, $assigned_count ) . '</p>';

            // #3801 — and the count says nothing about *who*. That gap is
            // what sent a head of development looking for a phone number.
            $handed_in = [];
            foreach ( $inputs_repo->listForCase( (int) ( $case->id ?? 0 ), true ) as $row ) {
                $handed_in[ (int) ( $row->user_id ?? 0 ) ] = true;
            }
            $awaiting = [];
            foreach ( $staff_repo->listForCase( (int) ( $case->id ?? 0 ) ) as $member ) {
                $uid = (int) ( $member->user_id ?? 0 );
                if ( $uid <= 0 || isset( $handed_in[ $uid ] ) ) continue;
                $u          = get_userdata( $uid );
                $awaiting[] = $u ? (string) $u->display_name : '#' . $uid;
            }
            if ( $awaiting ) {
                echo '<p class="tt-trial-input-awaiting">' . esc_html( sprintf(
                    /* translators: %s: comma-separated list of panellist names. */
                    __( 'Still waiting on: %s', 'talenttrack' ),
                    implode( ', ', $awaiting )
                ) ) . '</p>';
            }

            if ( $submitted_count > 0 && empty( $case->inputs_released_at ) ) {
                echo '<form method="post" class="tt-trial-release-form"><input type="hidden" name="tt_trial_action" value="release_inputs">';
                wp_nonce_field( 'tt_trial_release_' . (int) $case->id, 'tt_trial_release_nonce' );
                echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Release submitted inputs to assigned staff', 'talenttrack' ) . '</button></form>';
            } elseif ( ! empty( $case->inputs_released_at ) ) {
                echo '<p>' . esc_html__( 'Inputs released on:', 'talenttrack' ) . ' ' . esc_html( (string) $case->inputs_released_at ) . '</p>';
            }
        }

        if ( ! $visible ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No submitted inputs visible to you yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-trial-input-grid">';
            foreach ( $visible as $row ) {
                if ( ! $row->submitted_at ) continue; // drafts are own-only and shown above
                $u = get_userdata( (int) $row->user_id );
                echo '<article class="tt-trial-input-card">';
                echo '<header><strong>' . esc_html( $u ? (string) $u->display_name : '#' . (int) $row->user_id ) . '</strong>';
                echo '<div class="tt-meta">' . esc_html( (string) $row->submitted_at ) . '</div></header>';
                if ( $row->overall_rating !== null ) {
                    echo '<div class="tt-trial-input-rating">' . esc_html__( 'Overall', 'talenttrack' ) . ' <strong>' . esc_html( (string) $row->overall_rating ) . '</strong></div>';
                }
                if ( $row->free_text_notes ) {
                    echo '<details><summary>' . esc_html__( 'Notes', 'talenttrack' ) . '</summary><p>' . esc_html( (string) $row->free_text_notes ) . '</p></details>';
                }
                echo '</article>';
            }
            echo '</div>';
        }

        self::cardClose();
    }

    /**
     * The author's own input form.
     *
     * Only ever reached while `statusAcceptsInput()` holds — see the caller
     * in `renderInputsTab()` — so the case is `open` or `extended` and the
     * policy still allows a write. #3649: this method used to lock the card
     * the moment `submitted_at` was set, print "ask the head of
     * development", and return. That contradicted
     * `TrialCaseAccessPolicy` ("the decision, not the submission, is the
     * line"), which the REST route has always followed, and it pointed at a
     * reopen action the Trials module has never had — including when the
     * reader *was* the head of development.
     *
     * A submitted input therefore renders the same form, prefilled, with a
     * single **Save changes** button that posts `submit_action=draft`.
     * `upsertDraft()` leaves `submitted_at` alone, so an edit does not
     * un-submit the input: the "N of M submitted" count and the release
     * button are unaffected, and the original submission time survives.
     */
    private static function renderOwnInputForm( int $case_id, ?object $existing ): void {
        $submitted_at = $existing ? (string) ( $existing->submitted_at ?? '' ) : '';
        $is_submitted = $submitted_at !== '';
        self::cardOpen( __( 'Your input', 'talenttrack' ) );
        if ( $is_submitted ) {
            echo '<p class="tt-trial-input-submitted">' . esc_html( sprintf(
                /* translators: %s: date and time the input was submitted. */
                __( 'Submitted on %s. You can still edit this until the trial is decided.', 'talenttrack' ),
                $submitted_at
            ) ) . '</p>';
        }

        echo '<form method="post" class="tt-trial-input-form">';
        wp_nonce_field( 'tt_trial_input_' . $case_id, 'tt_trial_input_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="save_input">';

        // v3.110.116 — bounds + label follow `tt_config` rating scale.
        // v3.110.206 (#423) — adds inputmode="decimal" so mobile keyboards
        // pop up the decimal keypad on this rating input.
        $tt_rmin = (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_min', '5' );
        $tt_rmax = (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '10' );
        $tt_label = sprintf(
            /* translators: 1: rating min, 2: rating max */
            __( 'Overall rating (%1$s–%2$s)', 'talenttrack' ),
            (string) $tt_rmin,
            (string) $tt_rmax
        );
        echo '<label>' . esc_html( $tt_label ) . ' <input type="number" step="0.1" min="' . esc_attr( (string) $tt_rmin ) . '" max="' . esc_attr( (string) $tt_rmax ) . '" inputmode="decimal" name="overall_rating" value="' . esc_attr( $existing && $existing->overall_rating !== null ? (string) $existing->overall_rating : '' ) . '"></label>';
        echo '<label>' . esc_html__( 'Notes', 'talenttrack' ) . ' <textarea name="free_text_notes" rows="4">' . esc_textarea( $existing ? (string) $existing->free_text_notes : '' ) . '</textarea></label>';
        echo '<div class="tt-form-actions">';
        if ( $is_submitted ) {
            // One button, and it saves a draft: re-running `submit()` would
            // move `submitted_at` to now and lose when the input was
            // actually handed in.
            echo '<button type="submit" name="submit_action" value="draft" class="tt-btn tt-btn-primary">' . esc_html__( 'Save changes', 'talenttrack' ) . '</button>';
        } else {
            echo '<button type="submit" name="submit_action" value="draft" class="tt-btn tt-btn-secondary">' . esc_html__( 'Save draft', 'talenttrack' ) . '</button> ';
            echo '<button type="submit" name="submit_action" value="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Submit input', 'talenttrack' ) . '</button>';
        }
        echo '</div>';
        echo '</form>';
        self::cardClose();
    }

    /* ===== Decision tab ===== */

    private static function renderDecisionTab( object $case ): void {
        if ( $case->status === TrialCasesRepository::STATUS_DECIDED ) {
            self::renderPostDecision( $case );
            return;
        }

        // #3786 — the draft a refused submit is carrying, if any. The
        // fields are prefilled from it rather than emptied: on a
        // thirty-character floor, handing back an empty box is exactly the
        // wrong failure — the user typed a paragraph about a child.
        $draft = self::$decide_error ?? [];
        $error = isset( $draft['min_length'] )
            ? TrialDecisionMotivation::refusalMessage( (int) $draft['min_length'], (int) $draft['length'] )
            : '';

        self::cardOpen( __( 'Record decision', 'talenttrack' ) );
        echo '<p class="tt-trial-card__intro">' . esc_html__( 'Recording a decision sets the player status and generates the parent letter. Decisions are final for the season.', 'talenttrack' ) . '</p>';
        echo '<form method="post" class="tt-trial-decision-form">';
        wp_nonce_field( 'tt_trial_decide_' . (int) $case->id, 'tt_trial_decide_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="decide">';

        $opts = [
            TrialCasesRepository::DECISION_ADMIT          => TrialCasesRepository::decisionLabel( TrialCasesRepository::DECISION_ADMIT ),
            TrialCasesRepository::DECISION_DENY_FINAL     => TrialCasesRepository::decisionLabel( TrialCasesRepository::DECISION_DENY_FINAL ),
            TrialCasesRepository::DECISION_DENY_ENCOURAGE => TrialCasesRepository::decisionLabel( TrialCasesRepository::DECISION_DENY_ENCOURAGE ),
        ];
        $chosen = (string) ( $draft['decision'] ?? '' );
        echo '<fieldset class="tt-decision-radios"><legend>' . esc_html__( 'Outcome', 'talenttrack' ) . '</legend>';
        foreach ( $opts as $val => $label ) {
            echo '<label><input type="radio" name="decision" value="' . esc_attr( $val ) . '"'
                . checked( $chosen, (string) $val, false ) . ' required> ' . esc_html( $label ) . '</label>';
        }
        echo '</fieldset>';

        $notes_label = sprintf(
            /* translators: %d: minimum number of characters. */
            __( 'Justification (internal record, at least %d characters)', 'talenttrack' ),
            TrialDecisionMotivation::MIN_CHARS
        );
        echo '<label for="tt-trial-decision-notes">' . esc_html( $notes_label ) . '</label>';
        if ( $error !== '' ) {
            // Announced, named by `aria-describedby` below, and prefixed
            // with a word rather than signalled by colour alone.
            echo '<p class="tt-trial-decision-error" id="tt-trial-decision-notes-error" role="alert">';
            echo '<strong>' . esc_html( _x( 'Not saved', 'form field refusal', 'talenttrack' ) ) . '</strong> ';
            echo esc_html( $error ) . '</p>';
        }
        echo '<textarea id="tt-trial-decision-notes" name="decision_notes" rows="3" minlength="' . esc_attr( (string) TrialDecisionMotivation::MIN_CHARS ) . '" required'
            . ( $error !== '' ? ' aria-invalid="true" aria-describedby="tt-trial-decision-notes-error"' : '' )
            . '>' . esc_textarea( (string) ( $draft['notes'] ?? '' ) ) . '</textarea>';
        echo '<label>' . esc_html__( 'Strengths (used in the encouragement letter)', 'talenttrack' ) . ' <textarea name="strengths_summary" rows="2">' . esc_textarea( (string) ( $draft['strengths_summary'] ?? '' ) ) . '</textarea></label>';
        echo '<label>' . esc_html__( 'Growth areas (used in the encouragement letter)', 'talenttrack' ) . ' <textarea name="growth_areas" rows="2">' . esc_textarea( (string) ( $draft['growth_areas'] ?? '' ) ) . '</textarea></label>';

        echo '<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Record decision and generate letter', 'talenttrack' ) . '</button></div>';
        echo '</form>';
        self::cardClose();
    }

    /**
     * The decision, read back.
     *
     * #3787 — the motivation was already here, and two things about it
     * were not. It said *when* the decision was recorded and never *by
     * whom*, so the one entry a family may ask about a season later
     * carried no author; and a long motivation, which is what a
     * thirty-character floor is asking for, had nothing telling it to
     * wrap inside the summary grid.
     *
     * Read-only, and deliberately only here. The motivation is free text
     * about a child written for an internal panel decision; the player
     * file is read by considerably more people, and a decline-flavoured
     * sentence would follow an admitted child around their record for
     * years. Whoever is entitled to the trial case can read it on the
     * trial case.
     */
    private static function renderPostDecision( object $case ): void {
        self::cardOpen( __( 'Decision recorded', 'talenttrack' ) );
        echo '<dl class="tt-trial-decision-summary">';
        echo '<dt>' . esc_html__( 'Outcome', 'talenttrack' ) . '</dt><dd>' . esc_html( TrialCasesRepository::decisionLabel( (string) $case->decision ) ) . '</dd>';
        echo '<dt>' . esc_html__( 'Recorded at', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $case->decision_made_at ) . '</dd>';

        $recorded_by = (int) ( $case->decision_made_by ?? 0 );
        if ( $recorded_by > 0 ) {
            $user = get_userdata( $recorded_by );
            echo '<dt>' . esc_html__( 'Recorded by', 'talenttrack' ) . '</dt><dd>'
                . esc_html( $user ? (string) $user->display_name : '#' . $recorded_by ) . '</dd>';
        }

        $motivation = trim( (string) $case->decision_notes );
        if ( $motivation !== '' ) {
            echo '<dt>' . esc_html( _x( 'Motivation', 'trial decision summary', 'talenttrack' ) ) . '</dt>'
                . '<dd class="tt-trial-decision-motivation">' . esc_html( $motivation ) . '</dd>';
        }
        echo '</dl>';

        if ( $case->decision === TrialCasesRepository::DECISION_ADMIT && LetterTemplateEngine::acceptanceSlipEnabled() ) {
            if ( $case->acceptance_slip_returned_at ) {
                echo '<p>' . esc_html__( 'Acceptance slip received on:', 'talenttrack' ) . ' ' . esc_html( (string) $case->acceptance_slip_returned_at ) . '</p>';
            } else {
                echo '<form method="post" class="tt-trial-accept-form"><input type="hidden" name="tt_trial_action" value="accept_received">';
                wp_nonce_field( 'tt_trial_accept_' . (int) $case->id, 'tt_trial_accept_nonce' );
                echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Mark received', 'talenttrack' ) . '</button></form>';
            }
        }

        echo '<form method="post" class="tt-trial-regenerate"><input type="hidden" name="tt_trial_action" value="regenerate_letter">';
        wp_nonce_field( 'tt_trial_regenerate_' . (int) $case->id, 'tt_trial_regenerate_nonce' );
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Regenerate letter', 'talenttrack' ) . '</button></form>';

        self::cardClose();
    }

    /* ===== Letter tab ===== */

    private static function renderLetterTab( object $case ): void {
        $svc = new TrialLetterService();
        $case_id = (int) $case->id;
        $letter = $svc->findActiveForCase( $case_id );

        self::cardOpen( __( 'Letter', 'talenttrack' ) );

        if ( ! $letter ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No letter generated yet. Record a decision on the Decision tab to produce one.', 'talenttrack' ) . '</p>';
        } else {
            // #3661 — the standalone print document, not this page again.
            $print_url = \TT\Modules\Trials\Print\TrialLetterPrintRouter::urlFor( $case_id );
            echo '<p><a class="tt-btn tt-btn-secondary" target="_blank" rel="noopener" href="' . esc_url( $print_url ) . '">' . esc_html__( 'Print view', 'talenttrack' ) . '</a></p>';
            // #3683 — said once, next to the button that makes people
            // think otherwise. Generating has never sent anything.
            echo '<p class="tt-trial-card__intro">' . esc_html__( 'Generating a letter does not send it. Print or email it, then record the delivery below.', 'talenttrack' ) . '</p>';
            // Read through the engine: letters stored before #3661 carry
            // their stylesheet inlined ahead of them, which kses turns into
            // a block of CSS text above the letter.
            echo '<div class="tt-trial-letter-preview">'
                . wp_kses_post( LetterTemplateEngine::displayHtml( (string) $letter->rendered_html ) )
                . '</div>';
        }

        self::cardClose();

        if ( $letter ) {
            self::renderDeliveryCard( $case_id, $letter );
        }

        // History
        $history = $svc->listForCase( $case_id );
        if ( $history ) {
            self::cardOpen( __( 'Letter history', 'talenttrack' ) );
            echo '<div class="tt-table-wrap">';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Generated at', 'talenttrack' ) . '</th><th>' . esc_html__( 'Audience', 'talenttrack' ) . '</th><th>' . esc_html__( 'Status', 'talenttrack' ) . '</th><th>' . esc_html( _x( 'Delivered', 'trial letter', 'talenttrack' ) ) . '</th></tr></thead><tbody>';
            foreach ( $history as $row ) {
                $status = $row->revoked_at ? __( 'Revoked', 'talenttrack' ) : __( 'Active', 'talenttrack' );
                // `delivered_at` is written in UTC, like `revoked_at` beside
                // it, so it converts before it is formatted.
                $row_delivered = (string) ( $row->delivered_at ?? '' );
                $delivered = $row_delivered !== ''
                    ? \TT\Shared\Dates\TTDate::date( get_date_from_gmt( $row_delivered ) )
                    : __( 'Not recorded', 'talenttrack' );
                echo '<tr><td>' . esc_html( \TT\Shared\Dates\TTDate::dateTime( (string) $row->created_at ) ) . '</td><td>' . esc_html( (string) $row->audience ) . '</td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $delivered ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            self::cardClose();
        }
    }

    /**
     * #3683 — "does this family have the letter?"
     *
     * Model B (CLAUDE.md §6): an explicit Save with a real Cancel. There
     * is nothing being composed here — one radio and a commit — and a
     * debounce firing between "printed" and the method the HoD actually
     * meant would record a delivery nobody chose.
     *
     * Cancel returns to this tab rather than honouring `tt_back`: this is
     * a micro-form inside a record, not a create or edit screen, and
     * abandoning it should leave you looking at the letter you were
     * looking at.
     */
    private static function renderDeliveryCard( int $case_id, object $letter ): void {
        $letter_id    = (int) ( $letter->id ?? 0 );
        $delivered_at = (string) ( $letter->delivered_at ?? '' );
        $method       = (string) ( $letter->delivery_method ?? '' );
        $delivered_by = (int) ( $letter->delivered_by ?? 0 );

        $tab_url = add_query_arg(
            [ 'tt_view' => 'trial-case', 'id' => $case_id, 'tab' => self::TAB_LETTER ],
            RecordLink::dashboardUrl()
        );

        self::cardOpen( _x( 'Delivery', 'trial letter', 'talenttrack' ) );

        if ( $delivered_at !== '' ) {
            $user = $delivered_by > 0 ? get_userdata( $delivered_by ) : null;
            $who  = $user ? (string) $user->display_name : __( 'someone no longer on the staff list', 'talenttrack' );
            echo '<p class="tt-trial-delivery__state">' . esc_html( sprintf(
                /* translators: 1: date, 2: staff member name, 3: delivery method */
                __( 'Delivered on %1$s by %2$s (%3$s).', 'talenttrack' ),
                \TT\Shared\Dates\TTDate::date( get_date_from_gmt( $delivered_at ) ),
                $who,
                TrialLetterService::methodLabel( $method )
            ) ) . '</p>';

            echo '<form method="post" class="tt-trial-delivery-form">';
            wp_nonce_field( 'tt_trial_delivery_' . $case_id, 'tt_trial_delivery_nonce' );
            echo '<input type="hidden" name="tt_trial_action" value="clear_delivery">';
            echo '<input type="hidden" name="letter_id" value="' . esc_attr( (string) $letter_id ) . '">';
            echo \TT\Shared\Frontend\Components\FormSaveButton::render( [
                'label'      => __( 'Clear delivery record', 'talenttrack' ),
                'variant'    => 'secondary',
                'cancel_url' => $tab_url,
                'ignore_back' => true,
            ] );
            echo '</form>';
        } else {
            echo '<p class="tt-trial-delivery__state">' . esc_html__( 'Not recorded as delivered yet.', 'talenttrack' ) . '</p>';

            echo '<form method="post" class="tt-trial-delivery-form">';
            wp_nonce_field( 'tt_trial_delivery_' . $case_id, 'tt_trial_delivery_nonce' );
            echo '<input type="hidden" name="tt_trial_action" value="record_delivery">';
            echo '<input type="hidden" name="letter_id" value="' . esc_attr( (string) $letter_id ) . '">';
            echo '<fieldset class="tt-decision-radios"><legend>' . esc_html__( 'How did the family get it?', 'talenttrack' ) . '</legend>';
            foreach ( TrialLetterService::DELIVERY_METHODS as $key ) {
                echo '<label><input type="radio" name="delivery_method" value="' . esc_attr( $key ) . '" required> '
                    . esc_html( TrialLetterService::methodLabel( $key ) ) . '</label>';
            }
            echo '</fieldset>';
            echo \TT\Shared\Frontend\Components\FormSaveButton::render( [
                'label'       => __( 'Record delivery', 'talenttrack' ),
                'cancel_url'  => $tab_url,
                'ignore_back' => true,
            ] );
            echo '</form>';
        }

        self::cardClose();
    }

    /* ===== Parent meeting tab — preview link to fullscreen ===== */

    private static function renderMeetingTab( object $case ): void {
        $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'trial-parent-meeting', (int) $case->id );
        self::cardOpen( __( 'Parent meeting mode', 'talenttrack' ) );
        echo '<p>' . esc_html__( 'A sanitized fullscreen view for the conversation with the parents. No internal data is shown — only the decision, the player photo and basics, and the letter.', 'talenttrack' ) . '</p>';
        echo '<p><a class="tt-btn tt-btn-primary" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'Open meeting', 'talenttrack' ) . '</a></p>';
        self::cardClose();
    }

    /* ===== POST handlers ===== */

    private static function handlePost( int $user_id, int $case_id ): void {
        // `?? ''` because the key is not guaranteed: WP-CLI, cron and the
        // test runner all reach a render without one, and an undefined-key
        // notice inside `the_content` is a warning printed into the page.
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        $action = isset( $_POST['tt_trial_action'] ) ? sanitize_key( (string) $_POST['tt_trial_action'] ) : '';
        if ( $action === '' ) return;

        $cases = new TrialCasesRepository();

        switch ( $action ) {
            case 'assign_staff':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_assign_' . $case_id, 'tt_trial_assign_nonce' ) ) return;
                $u = isset( $_POST['staff_user_id'] ) ? absint( $_POST['staff_user_id'] ) : 0;
                $label = isset( $_POST['role_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['role_label'] ) ) : null;
                if ( $u > 0 ) ( new TrialCaseStaffRepository() )->assign( $case_id, $u, $label ?: null );
                return;

            case 'extend':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_extend_' . $case_id, 'tt_trial_extend_nonce' ) ) return;
                $new_end = isset( $_POST['new_end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_end_date'] ) ) : '';
                $just    = isset( $_POST['justification'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['justification'] ) ) : '';
                $case    = $cases->find( $case_id );
                if ( ! $case || $new_end === '' || trim( $just ) === '' ) return;
                if ( $new_end <= $case->end_date ) return;
                ( new TrialExtensionsRepository() )->record( $case_id, (string) $case->end_date, $new_end, $just, $user_id );
                $cases->update( $case_id, [
                    'end_date'        => $new_end,
                    'extension_count' => (int) $case->extension_count + 1,
                    'status'          => TrialCasesRepository::STATUS_EXTENDED,
                ] );
                return;

            case 'archive':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_archive_' . $case_id, 'tt_trial_archive_nonce' ) ) return;
                $cases->archive( $case_id, $user_id );
                return;

            case 'save_input':
                if ( ! TrialCaseAccessPolicy::canSubmitInput( $user_id, $case_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_input_' . $case_id, 'tt_trial_input_nonce' ) ) return;
                $inputs = new TrialStaffInputsRepository();
                $overall = isset( $_POST['overall_rating'] ) && $_POST['overall_rating'] !== '' ? (float) $_POST['overall_rating'] : null;
                $notes   = isset( $_POST['free_text_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['free_text_notes'] ) ) : '';
                $inputs->upsertDraft( $case_id, $user_id, [ 'overall_rating' => $overall, 'free_text_notes' => $notes ] );
                if ( ( $_POST['submit_action'] ?? '' ) === 'submit' ) {
                    $inputs->submit( $case_id, $user_id );
                }
                return;

            case 'release_inputs':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_release_' . $case_id, 'tt_trial_release_nonce' ) ) return;
                ( new TrialStaffInputsRepository() )->release( $case_id, $user_id );
                $cases->releaseInputs( $case_id, $user_id );
                return;

            case 'decide':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_decide_' . $case_id, 'tt_trial_decide_nonce' ) ) return;
                $decision = isset( $_POST['decision'] ) ? sanitize_key( (string) $_POST['decision'] ) : '';
                $notes    = isset( $_POST['decision_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['decision_notes'] ) ) : '';
                $strengths = isset( $_POST['strengths_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['strengths_summary'] ) ) : '';
                $growth    = isset( $_POST['growth_areas'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['growth_areas'] ) ) : '';

                // #3786 — the shared validator, the one the REST route
                // calls. This branch used to keep its own copy counting
                // `strlen()`, so a Dutch motivation with accents cleared a
                // floor the message describes in characters, and the screen
                // and the API disagreed about the same text.
                $refusal = TrialDecisionMotivation::refusal( $notes );
                if ( $refusal !== null ) {
                    // And it used to `return` in silence: the page reloaded,
                    // nothing was recorded, and nothing said why. The draft
                    // is carried back so the user does not lose a paragraph
                    // they wrote about a child.
                    self::$decide_error = $refusal + [
                        'decision'          => $decision,
                        'notes'             => $notes,
                        'strengths_summary' => $strengths,
                        'growth_areas'      => $growth,
                    ];
                    return;
                }

                // #3786 — the player status is NOT written here.
                // `TrialDecisionPlayerStatusSubscriber` owns that transition,
                // off the `tt_trial_decision_recorded` hook
                // `recordDecision()` fires. This branch used to write it too,
                // a second writer to one state, and it wrote `archived` — not
                // a value `PlayerStatus` recognises — over the subscriber's
                // `inactive` for a decline with encouragement. The docs have
                // always said that player stays Inactive.
                //
                // #4042 — record-then-generate is one domain call now, the
                // same one `POST trial-cases/{id}/decision` makes. This
                // branch used to own the decision → audience mapping, so
                // deciding over REST recorded the decision and produced no
                // letter at all.
                ( new TrialDecisionService() )->record(
                    $case_id, $decision, $user_id, $notes, $strengths ?: null, $growth ?: null
                );
                return;

            case 'regenerate_letter':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_regenerate_' . $case_id, 'tt_trial_regenerate_nonce' ) ) return;
                $case = $cases->find( $case_id );
                if ( ! $case || ! $case->decision ) return;
                // #3223 — superseding is the letter service's job, so there
                // is no revoke to follow this with. #4042 — and the audience
                // a decision warrants is the domain's, so this branch and the
                // decide branch cannot drift apart on it.
                ( new TrialDecisionService() )->generateFor(
                    $case_id,
                    (string) $case->decision,
                    $user_id,
                    $case->strengths_summary,
                    $case->growth_areas
                );
                return;

            case 'record_delivery':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_delivery_' . $case_id, 'tt_trial_delivery_nonce' ) ) return;
                $letter_id = isset( $_POST['letter_id'] ) ? absint( $_POST['letter_id'] ) : 0;
                $method    = isset( $_POST['delivery_method'] ) ? sanitize_key( (string) wp_unslash( $_POST['delivery_method'] ) ) : '';
                if ( $letter_id <= 0 ) return;
                // Ownership + the allowed-method check both live in the
                // service, so the form and the REST route refuse the same
                // things for the same reasons (CLAUDE.md §4).
                ( new TrialLetterService() )->recordDelivery( $letter_id, $case_id, $method, $user_id );
                return;

            case 'clear_delivery':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_delivery_' . $case_id, 'tt_trial_delivery_nonce' ) ) return;
                $letter_id = isset( $_POST['letter_id'] ) ? absint( $_POST['letter_id'] ) : 0;
                if ( $letter_id <= 0 ) return;
                ( new TrialLetterService() )->clearDelivery( $letter_id, $case_id );
                return;

            case 'accept_received':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_accept_' . $case_id, 'tt_trial_accept_nonce' ) ) return;
                $cases->markAcceptanceReceived( $case_id, $user_id );
                return;
        }
    }

    private static function nonceOk( string $action, string $field ): bool {
        return isset( $_POST[ $field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ), $action );
    }

    /** Two-letter initials from a player name; '?' when empty. */
    private static function initialsFor( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name ) ?: [ $name ];
        $first = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 );
        $last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';
        return mb_strtoupper( $first . $last );
    }
}
