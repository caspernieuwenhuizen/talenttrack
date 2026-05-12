<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\Domain\ProspectStageClassifier;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendOnboardingPipelineView (#0081 child 3, v3.110.48 redesign).
 *
 * Standalone onboarding-pipeline page at `?tt_view=onboarding-pipeline`.
 *
 * The original v3.97/8 implementation rendered the dashboard widget at
 * XL size (a count strip) and a JS-driven "+ New prospect" button that
 * POSTed to `/prospects/log` and dispatched a `LogProspectTemplate`
 * task as a side-effect of the click — surprising the user (a task
 * appears out of nowhere) and dumping them under "My tasks" in the
 * breadcrumb. v3.110.48 replaces both:
 *
 *   - The CTA points at the new `new-prospect` wizard. No task is
 *     created until the wizard's review step submits, and on submit
 *     the chain skips `LogProspectTemplate` (the wizard IS the form
 *     that template's task used to wrap) and dispatches
 *     `InviteToTestTrainingTemplate` for the HoD directly.
 *   - The view renders its own kanban — six columns, one per stage,
 *     each with a count and a stack of prospect cards (name, age
 *     group / DOB, current club, discovered date, plus a status
 *     sub-line and a click-through to whatever's actionable for that
 *     prospect right now). The dashboard widget keeps the compact
 *     count-strip rendering for tile placement.
 *
 * Mobile (≤720px) collapses the six columns into a vertical stack, one
 * column per row, scrollable.
 */
class FrontendOnboardingPipelineView extends FrontendViewBase {

    public static function render( int $user_id ): void {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'Onboarding pipeline', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the onboarding pipeline.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-onboarding-pipeline',
            TT_PLUGIN_URL . 'assets/css/components/onboarding-pipeline.css',
            [],
            TT_VERSION
        );
        FrontendBreadcrumbs::fromDashboard( __( 'Onboarding pipeline', 'talenttrack' ) );
        self::renderHeader( __( 'Onboarding pipeline', 'talenttrack' ) );

        $can_edit = AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' );
        if ( $can_edit ) {
            $wizard_url = WizardEntryPoint::urlFor(
                'new-prospect',
                add_query_arg( [ 'tt_view' => 'onboarding-pipeline' ], RecordLink::dashboardUrl() )
            );
            echo '<p class="tt-pipeline-cta">'
                . '<a class="tt-btn tt-btn-primary" href="' . esc_url( $wizard_url ) . '">'
                . esc_html__( '+ New prospect', 'talenttrack' )
                . '</a></p>';
        }

        $stages = self::computeStages( $user_id );
        echo self::renderKanban( $stages );
    }

    /**
     * @param array<int, array{key:string,label:string,count:int,cards:array<int, array<string,mixed>>}> $stages
     */
    private static function renderKanban( array $stages ): string {
        ob_start();
        ?>
        <div class="tt-pipeline-kanban" role="list">
            <?php foreach ( $stages as $stage ) : ?>
                <section class="tt-pipeline-col" data-stage="<?php echo esc_attr( $stage['key'] ); ?>" role="listitem">
                    <header class="tt-pipeline-col-head">
                        <span class="tt-pipeline-col-label"><?php echo esc_html( $stage['label'] ); ?></span>
                        <span class="tt-pipeline-col-count"><?php echo esc_html( (string) $stage['count'] ); ?></span>
                    </header>
                    <div class="tt-pipeline-col-body">
                        <?php if ( empty( $stage['cards'] ) ) : ?>
                            <p class="tt-pipeline-empty">&mdash;</p>
                        <?php else : ?>
                            <?php foreach ( $stage['cards'] as $card ) : ?>
                                <?php echo self::renderCard( $card ); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $card */
    private static function renderCard( array $card ): string {
        $name      = (string) ( $card['name']         ?? '' );
        $sub       = (string) ( $card['sub_label']    ?? '' );
        $context   = (string) ( $card['context_line'] ?? '' );
        $stale     = ! empty( $card['stale'] );
        $url       = (string) ( $card['url']          ?? '' );
        $tag       = $url !== '' ? 'a' : 'div';
        $url_attr  = $url !== '' ? ' href="' . esc_url( $url ) . '"' : '';
        $stale_cls = $stale ? ' tt-pipeline-card-stale' : '';

        ob_start();
        ?>
        <<?php echo $tag; ?> class="tt-pipeline-card<?php echo esc_attr( $stale_cls ); ?>"<?php
            echo $url_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>>
            <span class="tt-pipeline-card-name"><?php echo esc_html( $name ); ?></span>
            <?php if ( $sub !== '' ) : ?>
                <span class="tt-pipeline-card-sub"><?php echo esc_html( $sub ); ?></span>
            <?php endif; ?>
            <?php if ( $context !== '' ) : ?>
                <span class="tt-pipeline-card-ctx"><?php echo esc_html( $context ); ?></span>
            <?php endif; ?>
            <?php if ( $stale ) : ?>
                <span class="tt-pipeline-card-stale-badge"><?php esc_html_e( 'stale', 'talenttrack' ); ?></span>
            <?php endif; ?>
        </<?php echo $tag; ?>>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Resolve every visible (non-archived) prospect into exactly one
     * stage. Stage rules are mutually exclusive — a prospect is either
     * Joined, Team offer, Trial group, Test training, Invited, or
     * still in the Prospects column (drafted but not yet handed to
     * the HoD).
     *
     * Scout users see only their own prospects (`discovered_by_user_id`);
     * everyone else with the `tt_view_prospects` cap sees the whole
     * club.
     *
     * @return array<int, array{key:string,label:string,count:int,cards:array<int, array<string,mixed>>}>
     */
    public static function computeStages( int $user_id ): array {
        global $wpdb;
        $club_id = CurrentClub::id();
        $scout_only = self::isScoutOnly( $user_id );
        $prospects = $wpdb->prefix . 'tt_prospects';
        $tasks     = $wpdb->prefix . 'tt_workflow_tasks';
        $players   = $wpdb->prefix . 'tt_players';

        // Pull every visible prospect with its most-relevant open task
        // (one row per prospect). MAX(CASE WHEN…) collapses parallel
        // open tasks across templates into one column per template so
        // we can decide stage in PHP without a second query.
        // v3.110.84: also LEFT JOIN tt_players to expose player_status
        // so the classifier can distinguish Trial group (player still
        // at status='trial') from Joined (player graduated).
        $where_scout = $scout_only
            ? $wpdb->prepare( ' AND p.discovered_by_user_id = %d', $user_id )
            : '';

        $sql = "
            SELECT
                p.id                       AS id,
                p.first_name               AS first_name,
                p.last_name                AS last_name,
                p.date_of_birth            AS date_of_birth,
                p.current_club             AS current_club,
                p.discovered_at            AS discovered_at,
                p.discovered_at_event      AS discovered_at_event,
                p.promoted_to_player_id    AS promoted_to_player_id,
                p.promoted_to_trial_case_id AS promoted_to_trial_case_id,
                p.created_at               AS created_at,
                MAX(pl.status)             AS player_status,
                MAX(CASE WHEN wt.template_key = 'log_prospect'                 AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_log,
                MAX(CASE WHEN wt.template_key = 'invite_to_test_training'      AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_invite,
                MAX(CASE WHEN wt.template_key = 'confirm_test_training'        AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_confirm,
                MAX(CASE WHEN wt.template_key = 'record_test_training_outcome' AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_outcome,
                MAX(CASE WHEN wt.template_key = 'await_team_offer_decision'    AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_offer,
                MAX(CASE WHEN wt.template_key = 'invite_to_test_training'      AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_invite,
                MAX(CASE WHEN wt.template_key = 'confirm_test_training'        AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_confirm,
                MAX(CASE WHEN wt.template_key = 'record_test_training_outcome' AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_outcome,
                MIN(CASE WHEN wt.status IN ('open','in_progress','overdue') THEN wt.due_at ELSE NULL END) AS soonest_due_at
            FROM {$prospects} p
            LEFT JOIN {$tasks}   wt ON wt.prospect_id = p.id AND wt.club_id = %d
            LEFT JOIN {$players} pl ON pl.id = p.promoted_to_player_id AND pl.club_id = %d
            WHERE p.club_id = %d
              AND p.archived_at IS NULL
              {$where_scout}
            GROUP BY p.id
            ORDER BY p.discovered_at DESC, p.id DESC
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $club_id, $club_id, $club_id ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $today = time();
        $joined_cutoff = $today - 90 * DAY_IN_SECONDS;
        $stale_cutoff  = $today - 30 * DAY_IN_SECONDS;

        $stages_init = [
            'prospects' => [ 'key' => 'prospects', 'label' => __( 'Prospects',     'talenttrack' ), 'cards' => [] ],
            'invited'   => [ 'key' => 'invited',   'label' => __( 'Invited',       'talenttrack' ), 'cards' => [] ],
            'test'      => [ 'key' => 'test',      'label' => __( 'Test training', 'talenttrack' ), 'cards' => [] ],
            'trial'     => [ 'key' => 'trial',     'label' => __( 'Trial group',   'talenttrack' ), 'cards' => [] ],
            'offer'     => [ 'key' => 'offer',     'label' => __( 'Team offer',    'talenttrack' ), 'cards' => [] ],
            'joined'    => [ 'key' => 'joined',    'label' => __( 'Joined',        'talenttrack' ), 'cards' => [] ],
        ];

        foreach ( $rows as $row ) {
            $stage = ProspectStageClassifier::classify( $row, $joined_cutoff );
            if ( $stage === null ) continue; // not visible (e.g. promoted >90d ago)
            $stages_init[ $stage ]['cards'][] = self::buildCard( $row, $stage, $stale_cutoff );
        }

        // Reduce to indexed list with counts.
        $out = [];
        foreach ( $stages_init as $s ) {
            $s['count'] = count( $s['cards'] );
            $out[] = $s;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function buildCard( object $row, string $stage, int $stale_cutoff ): array {
        $first   = (string) ( $row->first_name ?? '' );
        $last    = (string) ( $row->last_name  ?? '' );
        $club    = (string) ( $row->current_club ?? '' );
        $dob     = (string) ( $row->date_of_birth ?? '' );
        $event   = (string) ( $row->discovered_at_event ?? '' );

        $sub_parts = [];
        if ( $club !== '' ) $sub_parts[] = $club;
        if ( $dob !== '' ) {
            $age = self::ageFromDob( $dob );
            if ( $age !== null ) $sub_parts[] = sprintf( /* translators: %d: age in years. */ __( 'age %d', 'talenttrack' ), $age );
        }

        $context_line = self::contextLine( $row, $stage );

        $stale = false;
        $due = (string) ( $row->soonest_due_at ?? '' );
        if ( $due !== '' ) {
            $due_ts = strtotime( $due );
            if ( $due_ts !== false && $due_ts < $stale_cutoff ) $stale = true;
        }

        return [
            'name'         => trim( $first . ' ' . $last ),
            'sub_label'    => implode( ' · ', $sub_parts ),
            'context_line' => $context_line,
            'stale'        => $stale,
            'url'          => self::cardUrl( $row, $stage ),
        ];
    }

    private static function contextLine( object $row, string $stage ): string {
        $event = (string) ( $row->discovered_at_event ?? '' );
        switch ( $stage ) {
            case 'prospects':
                // v3.110.81 — with the new "invite-completed = Invited"
                // semantics, the Prospects column now ALSO holds
                // prospects whose invite task is open (email not yet
                // sent). Surface that distinction in the context line
                // so the HoD knows what's blocked on them.
                if ( ! empty( $row->open_invite ) ) {
                    return __( 'Awaiting HoD to send the invite', 'talenttrack' );
                }
                return $event !== ''
                    ? sprintf( /* translators: %s: discovery event / match label. */ __( 'Discovered: %s', 'talenttrack' ), $event )
                    : __( 'Drafted, not yet handed to HoD', 'talenttrack' );
            case 'invited':
                // The email has gone out; awaiting parent confirmation.
                return __( 'Invitation sent, awaiting parent', 'talenttrack' );
            case 'test':
                return __( 'Awaiting outcome', 'talenttrack' );
            case 'trial':
                return __( 'In trial group', 'talenttrack' );
            case 'offer':
                return __( 'Team offer pending', 'talenttrack' );
            case 'joined':
                return __( 'Promoted to academy', 'talenttrack' );
            default:
                return '';
        }
    }

    /**
     * Card click target. Routes to whichever surface is most useful for
     * the prospect's current stage — the open task form when there is
     * one, the player profile when joined, or nothing (a static card)
     * when the prospect has no actionable surface yet.
     */
    private static function cardUrl( object $row, string $stage ): string {
        $task_id = 0;
        switch ( $stage ) {
            case 'offer':   $task_id = (int) ( $row->open_offer   ?? 0 ); break;
            case 'test':    $task_id = (int) ( $row->open_outcome ?? 0 ); break;
            case 'invited':
                // After v3.110.81 a prospect lands in Invited because
                // (a) the invite task was completed (no open_invite to
                // link to), OR (b) the confirm task is open. Deep-link
                // to confirm first; fall back to invite for the legacy
                // case where the chain stopped after invite without
                // spawning confirm.
                $task_id = (int) ( $row->open_confirm ?? 0 );
                if ( $task_id === 0 ) $task_id = (int) ( $row->open_invite ?? 0 );
                break;
            case 'prospects':
                $task_id = (int) ( $row->open_log ?? 0 );
                if ( $task_id === 0 ) $task_id = (int) ( $row->open_invite ?? 0 );
                break;
            case 'joined':
                $player_id = (int) ( $row->promoted_to_player_id ?? 0 );
                if ( $player_id > 0 ) {
                    return add_query_arg(
                        [ 'tt_view' => 'players', 'id' => $player_id ],
                        RecordLink::dashboardUrl()
                    );
                }
                return '';
            case 'trial':
                $case_id = (int) ( $row->promoted_to_trial_case_id ?? 0 );
                if ( $case_id > 0 ) {
                    return add_query_arg(
                        [ 'tt_view' => 'trial-case', 'id' => $case_id ],
                        RecordLink::dashboardUrl()
                    );
                }
                return '';
        }
        if ( $task_id > 0 ) {
            return add_query_arg(
                [ 'tt_view' => 'my-tasks', 'task_id' => $task_id ],
                RecordLink::dashboardUrl()
            );
        }
        return '';
    }

    private static function ageFromDob( string $dob ): ?int {
        $ts = strtotime( $dob );
        if ( $ts === false ) return null;
        $now = time();
        if ( $ts >= $now ) return null;
        $diff_secs = $now - $ts;
        $age = (int) floor( $diff_secs / ( 365.25 * DAY_IN_SECONDS ) );
        return $age >= 0 && $age < 100 ? $age : null;
    }

    private static function isScoutOnly( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        $roles = (array) ( $user->roles ?? [] );
        if ( in_array( 'tt_head_dev', $roles, true ) ) return false;
        if ( in_array( 'tt_club_admin', $roles, true ) ) return false;
        if ( in_array( 'administrator', $roles, true ) ) return false;
        return in_array( 'tt_scout', $roles, true );
    }
}
