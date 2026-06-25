<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\StaffPickerComponent;
use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;

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
 * manager-only; Inputs is visible to assigned staff (own input only)
 * and managers (everyone's, with release control).
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

        if ( ! TrialCaseAccessPolicy::canViewSynthesis( $user_id, $case_id ) ) {
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

        $tabs       = self::tabSet( $case, $is_manager );
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
            ?>

            <div class="tt-player-detail__rail">
                <?php self::renderKeyFacts( $case ); ?>
            </div>

            <div class="tt-player-detail__main">
                <nav class="tt-player-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Trial sections', 'talenttrack' ); ?>">
                    <?php foreach ( $tabs as $key => $label ) :
                        $url       = add_query_arg( [ 'tab' => $key ], $base_url );
                        $is_active = $key === $active_tab;
                        $classes   = 'tt-player-tab';
                        if ( $is_active ) $classes .= ' tt-player-tab--active';
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="<?php echo esc_attr( $classes ); ?>"
                           role="tab"
                           aria-current="<?php echo $is_active ? 'true' : 'false'; ?>"
                           aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                            <?php echo esc_html( $label ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <section class="tt-player-tab-panel">
                    <?php
                    switch ( $active_tab ) {
                        case self::TAB_EXECUTION: self::renderExecutionTab( $case ); break;
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
    private static function tabSet( object $case, bool $is_manager ): array {
        $tabs = [
            self::TAB_OVERVIEW  => __( 'Overview', 'talenttrack' ),
            self::TAB_EXECUTION => __( 'Execution', 'talenttrack' ),
            self::TAB_INPUTS    => __( 'Staff inputs', 'talenttrack' ),
        ];
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
            echo '<ul class="tt-trial-staff-list">';
            foreach ( $staff as $s ) {
                $u = get_userdata( (int) $s->user_id );
                $label = $u ? (string) $u->display_name : '#' . (int) $s->user_id;
                $role  = $s->role_label ? ' (' . esc_html( (string) $s->role_label ) . ')' : '';
                echo '<li>' . esc_html( $label ) . $role . '</li>';
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
        /** @var array<int,object> $activities */
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.activity_date, a.activity_type_key, a.notes, att.status AS attendance
               FROM {$wpdb->prefix}tt_activities a
          LEFT JOIN {$wpdb->prefix}tt_attendance att
                 ON att.activity_id = a.id AND att.player_id = %d AND att.club_id = a.club_id
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

        // Own input form (if assigned and case still open).
        if ( $assigned && in_array( $case->status, [ 'open', 'extended' ], true ) ) {
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

    private static function renderOwnInputForm( int $case_id, ?object $existing ): void {
        $is_submitted = $existing && $existing->submitted_at;
        self::cardOpen( __( 'Your input', 'talenttrack' ) );
        if ( $is_submitted ) {
            echo '<p>' . esc_html__( 'You submitted on:', 'talenttrack' ) . ' ' . esc_html( (string) $existing->submitted_at ) . '</p>';
            echo '<p><em>' . esc_html__( 'To edit after submit, ask the head of development.', 'talenttrack' ) . '</em></p>';
            self::cardClose();
            return;
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
        echo '<button type="submit" name="submit_action" value="draft" class="tt-btn tt-btn-secondary">' . esc_html__( 'Save draft', 'talenttrack' ) . '</button> ';
        echo '<button type="submit" name="submit_action" value="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Submit input', 'talenttrack' ) . '</button>';
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
        echo '<fieldset class="tt-decision-radios"><legend>' . esc_html__( 'Outcome', 'talenttrack' ) . '</legend>';
        foreach ( $opts as $val => $label ) {
            echo '<label><input type="radio" name="decision" value="' . esc_attr( $val ) . '" required> ' . esc_html( $label ) . '</label>';
        }
        echo '</fieldset>';

        echo '<label>' . esc_html__( 'Justification (internal record, ≥ 30 characters)', 'talenttrack' ) . ' <textarea name="decision_notes" rows="3" minlength="30" required></textarea></label>';
        echo '<label>' . esc_html__( 'Strengths (used in the encouragement letter)', 'talenttrack' ) . ' <textarea name="strengths_summary" rows="2"></textarea></label>';
        echo '<label>' . esc_html__( 'Growth areas (used in the encouragement letter)', 'talenttrack' ) . ' <textarea name="growth_areas" rows="2"></textarea></label>';

        echo '<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Record decision and generate letter', 'talenttrack' ) . '</button></div>';
        echo '</form>';
        self::cardClose();
    }

    private static function renderPostDecision( object $case ): void {
        self::cardOpen( __( 'Decision recorded', 'talenttrack' ) );
        echo '<dl class="tt-trial-decision-summary">';
        echo '<dt>' . esc_html__( 'Outcome', 'talenttrack' ) . '</dt><dd>' . esc_html( TrialCasesRepository::decisionLabel( (string) $case->decision ) ) . '</dd>';
        echo '<dt>' . esc_html__( 'Recorded at', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $case->decision_made_at ) . '</dd>';
        if ( $case->decision_notes ) {
            echo '<dt>' . esc_html__( 'Justification', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $case->decision_notes ) . '</dd>';
        }
        echo '</dl>';

        if ( $case->decision === TrialCasesRepository::DECISION_ADMIT && LetterTemplateEngine::acceptanceSlipEnabled() ) {
            if ( $case->acceptance_slip_returned_at ) {
                echo '<p>' . esc_html__( 'Acceptance slip received on:', 'talenttrack' ) . ' ' . esc_html( (string) $case->acceptance_slip_returned_at ) . '</p>';
            } else {
                echo '<form method="post" class="tt-trial-accept-form"><input type="hidden" name="tt_trial_action" value="accept_received">';
                wp_nonce_field( 'tt_trial_accept_' . (int) $case->id, 'tt_trial_accept_nonce' );
                echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Mark acceptance slip as received', 'talenttrack' ) . '</button></form>';
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
        $letter = $svc->findActiveForCase( (int) $case->id );

        self::cardOpen( __( 'Letter', 'talenttrack' ) );

        if ( ! $letter ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No letter generated yet. Record a decision on the Decision tab to produce one.', 'talenttrack' ) . '</p>';
        } else {
            $print_url = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $case->id, 'tab' => 'letter', 'print' => 1 ], home_url( '/' ) );
            echo '<p><a class="tt-btn tt-btn-secondary" target="_blank" rel="noopener" href="' . esc_url( $print_url ) . '">' . esc_html__( 'Open print-ready view', 'talenttrack' ) . '</a></p>';
            echo '<div class="tt-trial-letter-preview">' . wp_kses_post( (string) $letter->rendered_html ) . '</div>';
        }

        self::cardClose();

        // History
        $history = $svc->listForCase( (int) $case->id );
        if ( $history ) {
            self::cardOpen( __( 'Letter history', 'talenttrack' ) );
            echo '<div class="tt-table-wrap">';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Generated at', 'talenttrack' ) . '</th><th>' . esc_html__( 'Audience', 'talenttrack' ) . '</th><th>' . esc_html__( 'Status', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $history as $row ) {
                $status = $row->revoked_at ? __( 'Revoked', 'talenttrack' ) : __( 'Active', 'talenttrack' );
                echo '<tr><td>' . esc_html( \TT\Shared\Dates\TTDate::dateTime( (string) $row->created_at ) ) . '</td><td>' . esc_html( (string) $row->audience ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            self::cardClose();
        }
    }

    /* ===== Parent meeting tab — preview link to fullscreen ===== */

    private static function renderMeetingTab( object $case ): void {
        $url = add_query_arg( [ 'tt_view' => 'trial-parent-meeting', 'id' => (int) $case->id ], home_url( '/' ) );
        self::cardOpen( __( 'Parent meeting mode', 'talenttrack' ) );
        echo '<p>' . esc_html__( 'A sanitized fullscreen view for the conversation with the parents. No internal data is shown — only the decision, the player photo and basics, and the letter.', 'talenttrack' ) . '</p>';
        echo '<p><a class="tt-btn tt-btn-primary" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'Open meeting view', 'talenttrack' ) . '</a></p>';
        self::cardClose();
    }

    /* ===== POST handlers ===== */

    private static function handlePost( int $user_id, int $case_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
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
                if ( strlen( $notes ) < 30 ) return;
                $ok = $cases->recordDecision( $case_id, $decision, $user_id, $notes, $strengths, $growth );
                if ( $ok ) {
                    $case = $cases->find( $case_id );
                    if ( $case ) {
                        // Player status follows decision.
                        global $wpdb;
                        $new_player_status = $decision === TrialCasesRepository::DECISION_ADMIT ? 'active' : 'archived';
                        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => $new_player_status ], [ 'id' => (int) $case->player_id, 'club_id' => CurrentClub::id() ] );
                        // Letter
                        $audience = self::audienceForDecision( $decision );
                        $svc      = new TrialLetterService();
                        $letter_id = $svc->generate( $case, $audience, $user_id, $strengths ?: null, $growth ?: null );
                        $svc->revokePriorLetters( $case_id, $letter_id );
                    }
                }
                return;

            case 'regenerate_letter':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_regenerate_' . $case_id, 'tt_trial_regenerate_nonce' ) ) return;
                $case = $cases->find( $case_id );
                if ( ! $case || ! $case->decision ) return;
                $audience = self::audienceForDecision( (string) $case->decision );
                $svc      = new TrialLetterService();
                $new_id   = $svc->generate( $case, $audience, $user_id, $case->strengths_summary, $case->growth_areas );
                $svc->revokePriorLetters( $case_id, $new_id );
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

    private static function audienceForDecision( string $decision ): string {
        switch ( $decision ) {
            case TrialCasesRepository::DECISION_ADMIT:           return AudienceType::TRIAL_ADMITTANCE;
            case TrialCasesRepository::DECISION_DENY_FINAL:      return AudienceType::TRIAL_DENIAL_FINAL;
            case TrialCasesRepository::DECISION_DENY_ENCOURAGE:  return AudienceType::TRIAL_DENIAL_ENCOURAGE;
        }
        return AudienceType::TRIAL_DENIAL_FINAL;
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
