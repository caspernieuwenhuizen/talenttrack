<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ProposeTestTrainingService;
use TT\Modules\Prospects\Domain\ProspectStageClassifier;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\ScoutingVisitsAccess;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
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

    /** #3710 — nonce action and field for the "propose a test training" post. */
    private const PROPOSE_ACTION = 'tt_propose_test_training';
    private const PROPOSE_NONCE  = 'tt_propose_nonce';

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
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        FrontendBreadcrumbs::fromDashboard( __( 'Onboarding pipeline', 'talenttrack' ) );
        self::renderHeader( __( 'Onboarding pipeline', 'talenttrack' ) );

        // #3710 — handled before the board is computed, so the proposal the
        // scout just made is already the prospect's next action by the time
        // the focus panel renders.
        echo self::handleProposal( $user_id );

        $can_edit = AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' );
        if ( $can_edit ) {
            $wizard_url = WizardEntryPoint::urlFor(
                'new-prospect',
                add_query_arg( [ 'tt_view' => 'onboarding-pipeline' ], RecordLink::dashboardUrl() )
            );
            echo '<p class="tt-pipeline-cta">'
                . '<a class="tt-btn tt-btn-primary" href="' . esc_url( $wizard_url ) . '">'
                . esc_html__( 'Add prospect', 'talenttrack' )
                . '</a></p>';
        }

        $stages = self::computeStages( $user_id );

        // #1763 — focus a single prospect on the board when
        // ?prospect_id=N is present (the card fallback for a prospect with
        // no "next action", and dashboard "open prospect" links). So a
        // pipeline card is never a dead end.
        $focus_pid = isset( $_GET['prospect_id'] ) ? absint( wp_unslash( $_GET['prospect_id'] ) ) : 0;
        if ( $focus_pid > 0 ) {
            echo self::renderProspectFocus( $stages, $focus_pid );
        }

        echo self::renderKanban( $stages );
    }

    /**
     * #3710 — the scout's proposal, posted from the focus panel.
     *
     * A plain form post rather than a fetch: the panel is server-rendered,
     * the action is a single idempotent write, and a scout on a phone at a
     * pitch should not need JavaScript to put a player forward. There is no
     * redirect afterwards because the page is already streaming by the time
     * a view runs; the service's idempotency guard is what makes a re-post
     * harmless.
     */
    private static function handleProposal( int $user_id ): string {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return '';
        if ( ! isset( $_POST[ self::PROPOSE_NONCE ], $_POST['tt_propose_prospect_id'] ) ) return '';

        $prospect_id = absint( wp_unslash( $_POST['tt_propose_prospect_id'] ) );
        $nonce       = sanitize_text_field( wp_unslash( (string) $_POST[ self::PROPOSE_NONCE ] ) );
        if ( $prospect_id <= 0 || ! wp_verify_nonce( $nonce, self::PROPOSE_ACTION . '_' . $prospect_id ) ) {
            return '';
        }

        $result = ProposeTestTrainingService::propose( $user_id, $prospect_id );
        if ( is_wp_error( $result ) ) {
            return '<div class="tt-notice tt-notice-error">' . esc_html( $result->get_error_message() ) . '</div>';
        }

        return '<div class="tt-notice tt-notice-success">'
            . esc_html__( 'Put forward. The head of development has been asked to arrange a test training.', 'talenttrack' )
            . '</div>';
    }

    /**
     * #1763 — focused prospect panel above the board. Reuses the card data
     * already computed in `computeStages()`, so it shows the same identity,
     * sub-label, context line and (smart) next-action link. Renders nothing
     * when the prospect isn't visible to this user.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private static function renderProspectFocus( array $stages, int $focus_pid ): string {
        $found = null; $stage_label = '';
        foreach ( $stages as $stage ) {
            foreach ( $stage['cards'] as $card ) {
                if ( (int) ( $card['id'] ?? 0 ) === $focus_pid ) {
                    $found = $card; $stage_label = (string) $stage['label']; break 2;
                }
            }
        }
        if ( $found === null ) return '';

        $name = (string) ( $found['name'] ?? '' );
        $sub  = (string) ( $found['sub_label'] ?? '' );
        $ctx  = (string) ( $found['context_line'] ?? '' );
        $url  = (string) ( $found['url'] ?? '' );
        // A non-self URL means there's a real "next action" to deep-link to.
        $has_action = $url !== '' && strpos( $url, 'prospect_id=' ) === false;
        $close_url  = remove_query_arg( 'prospect_id' );

        // #2838 — the correction path. Until this, a prospect logged with
        // a mistyped email or a consent that arrived late had nowhere to
        // be fixed, and the focus panel was the closest thing to a record
        // view. tt_back carries the pipeline URL so Cancel comes back here.
        $can_edit = AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_edit_prospects' );
        $edit_url = $can_edit
            ? RecordLink::detailUrlForWithBack( 'prospect-edit', $focus_pid )
            : '';

        // #3710 — a prospect whose chain never produced the invite task had
        // no way forward at all: the panel showed "Edit contact" and
        // nothing else, and the invite task is the only thing that links a
        // prospect to a test training. The service decides whether this
        // viewer may put this prospect forward; the panel only asks.
        $viewer      = get_current_user_id();
        $stuck       = ! $has_action && ProposeTestTrainingService::canPropose( $viewer, $focus_pid );
        $can_invite  = AuthorizationService::userCanOrMatrix( $viewer, 'tt_invite_prospects' );
        // Somebody who may issue the invitation themselves does not need to
        // ask for it: they go straight to the surface where a test training
        // is arranged, rather than addressing a task to themselves.
        $invite_url  = $stuck && $can_invite
            ? BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'test-trainings', 'action' => 'new' ],
                RecordLink::dashboardUrl()
            ) )
            : '';
        $can_propose = $stuck && ! $can_invite;

        ob_start(); ?>
        <section class="tt-pipeline-focus" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: prospect name */ __( 'Prospect: %s', 'talenttrack' ), $name ) ); ?>">
            <div class="tt-pipeline-focus-head">
                <span class="tt-pipeline-focus-av" aria-hidden="true"><?php echo esc_html( (string) ( $found['initials'] ?? '' ) ); ?></span>
                <div class="tt-pipeline-focus-id">
                    <span class="tt-pipeline-focus-name"><?php echo esc_html( $name !== '' ? $name : __( '(unnamed prospect)', 'talenttrack' ) ); ?></span>
                    <?php if ( $sub !== '' ) : ?><span class="tt-pipeline-focus-sub"><?php echo esc_html( $sub ); ?></span><?php endif; ?>
                </div>
                <span class="tt-pipeline-focus-stage"><?php echo esc_html( $stage_label ); ?></span>
                <a class="tt-pipeline-focus-close" href="<?php echo esc_url( $close_url ); ?>" aria-label="<?php esc_attr_e( 'Back to pipeline', 'talenttrack' ); ?>">&times;</a>
            </div>
            <?php if ( $ctx !== '' ) : ?>
                <p class="tt-pipeline-focus-ctx"><?php echo esc_html( $ctx ); ?></p>
            <?php endif; ?>
            <?php
            // #3677 — the scouting record behind the prospect.
            echo self::renderFocusScouting( $focus_pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped at source.
            // #3812 — and the consent trail, which is the question the
            // scout was asked five times over six weeks.
            echo self::renderFocusConsent( $focus_pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped at source.
            ?>
            <?php if ( $has_action || $can_edit || $can_propose || $invite_url !== '' ) : ?>
                <p class="tt-pipeline-focus-actions">
                    <?php if ( $has_action ) : ?>
                        <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open next action', 'talenttrack' ); ?></a>
                    <?php endif; ?>
                    <?php if ( $invite_url !== '' ) : ?>
                        <?php // long label: "Arrange" alone reads as arranging the prospect, not the training. ?>
                        <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $invite_url ); ?>"><?php esc_html_e( 'Arrange test training', 'talenttrack' ); ?></a>
                    <?php elseif ( $can_propose ) : ?>
                        <?php // long label: "Propose" alone does not say what is being proposed. ?>
                        <button type="submit" form="tt-propose-<?php echo (int) $focus_pid; ?>" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Propose test training', 'talenttrack' ); ?></button>
                    <?php endif; ?>
                    <?php if ( $can_edit ) : ?>
                        <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit contact', 'talenttrack' ); ?></a>
                    <?php endif; ?>
                </p>
                <?php if ( $can_propose ) : ?>
                    <form id="tt-propose-<?php echo (int) $focus_pid; ?>" class="tt-pipeline-focus-propose" method="post" action="<?php echo esc_url( self::prospectFocusUrl( $focus_pid ) ); ?>">
                        <?php wp_nonce_field( self::PROPOSE_ACTION . '_' . $focus_pid, self::PROPOSE_NONCE ); ?>
                        <input type="hidden" name="tt_propose_prospect_id" value="<?php echo (int) $focus_pid; ?>">
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * #3812 — the consent trail behind a focused prospect: when the
     * academy asked, who it asked, and what came back.
     *
     * This is the answer to the question that started the issue — "did the
     * consent emails go out?" — which a scout could previously only answer
     * from memory. Read-only here: entries are written by the
     * `request_consent` task and by `POST /prospects/{id}/consent-requests`,
     * so the panel composes and does not decide (CLAUDE.md §4).
     *
     * Renders nothing when the academy has not asked, rather than an empty
     * heading: a prospect whose family approached the club directly has no
     * consent request to show and never will.
     */
    private static function renderFocusConsent( int $prospect_id ): string {
        if ( ! ProspectConsentRequestsRepository::tableExists() ) return '';

        $entries = ( new ProspectConsentRequestsRepository() )->forProspect( $prospect_id );
        if ( $entries === [] ) return '';

        ob_start(); ?>
        <div class="tt-pipeline-focus-consent">
            <h3 class="tt-pipeline-focus-subhead"><?php esc_html_e( 'Consent requests', 'talenttrack' ); ?></h3>
            <ul class="tt-pipeline-focus-consent-list">
                <?php foreach ( $entries as $entry ) :
                    $asked_at = (string) ( $entry['asked_at'] ?? '' );
                    $asked_of = (string) ( $entry['asked_of'] ?? '' );
                    $outcome  = (string) ( $entry['outcome'] ?? '' );
                    $notes    = trim( (string) ( $entry['notes'] ?? '' ) );
                    ?>
                    <li class="tt-pipeline-focus-consent-row">
                        <span class="tt-pipeline-focus-consent-when">
                            <?php echo esc_html( $asked_at !== '' ? TTDate::date( $asked_at ) : '' ); ?>
                        </span>
                        <span class="tt-pipeline-focus-consent-who"><?php echo esc_html( $asked_of ); ?></span>
                        <span class="tt-chip"><?php echo esc_html( ConsentOutcome::label( $outcome ) ); ?></span>
                        <?php if ( $notes !== '' ) : ?>
                            <span class="tt-pipeline-focus-consent-note"><?php echo esc_html( $notes ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * #3677 — the scouting record behind a focused prospect: the notes the
     * scout wrote when they logged the find, and the visit they were found
     * at. Both already sit on `tt_prospects` and both already come back over
     * `GET prospects/{id}`; the panel just never read them, so a scout about
     * to propose a trial had to leave the board to answer "where did this
     * player come from".
     *
     * Read-only on purpose (see the shaping comment on #3677): no observation
     * log, no stage buttons. The stage keeps following the workflow tasks.
     *
     * The caller only reaches this for a prospect already on the viewer's
     * board, so the prospect row is inside their `ProspectScope`. The visit
     * is a separate surface with its own gate and gets its own check.
     */
    private static function renderFocusScouting( int $prospect_id ): string {
        $prospect = ( new ProspectsRepository() )->find( $prospect_id );
        if ( $prospect === null ) return '';
        $row = (array) $prospect;

        $notes      = trim( (string) ( $row['scouting_notes'] ?? '' ) );
        $visit_html = self::renderFocusVisit(
            (int) ( $row['scouting_visit_id'] ?? 0 ),
            // #3711 — sightings after the first one. The count goes next to
            // the discovery visit rather than replacing it: "found here, and
            // watched twice more" is the sentence a head of development is
            // reading this panel for.
            ( new ProspectVisitObservationsRepository() )->countForProspect( $prospect_id )
        );
        if ( $notes === '' && $visit_html === '' ) return '';

        ob_start(); ?>
        <div class="tt-pipeline-focus-scouting">
            <?php if ( $notes !== '' ) : ?>
                <div class="tt-pipeline-focus-notes">
                    <h2 class="tt-pipeline-focus-label"><?php esc_html_e( 'Scouting notes', 'talenttrack' ); ?></h2>
                    <p class="tt-pipeline-focus-notes-body"><?php echo nl2br( esc_html( $notes ) ); ?></p>
                </div>
            <?php endif; ?>
            <?php echo $visit_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped at source. ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * #3677 — the "Found at" block: date, event and scout of the visit the
     * prospect was linked to by #3600, linking through to the visit itself.
     *
     * Renders nothing unless the viewer holds the scouting-visit surface
     * (#2007's `scouting_visits_panel`) AND may read this particular row. A
     * head coach reads their age group's funnel on purpose but must not reach
     * a scout's visit planner, and a scout reaches only their own visits —
     * linking past either check would hand them a refusal page instead of a
     * visit. An archived or deleted visit shows nothing.
     */
    private static function renderFocusVisit( int $visit_id, int $observation_count = 0 ): string {
        if ( $visit_id <= 0 ) return '';

        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        if ( ! ScoutingVisitsAccess::allows( $user_id, $is_admin ) ) return '';

        $visit = ( new ScoutingVisitsRepository() )->findLinkable( $visit_id );
        if ( $visit === null ) return '';
        $row = (array) $visit;

        $scout_id = (int) ( $row['scout_user_id'] ?? 0 );
        $is_owner = $scout_id > 0 && $scout_id === $user_id;
        if ( ! $is_owner && ! $is_admin && ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_prospects' ) ) {
            return '';
        }

        $date  = TTDate::date( (string) ( $row['visit_date'] ?? '' ) );
        $event = trim( (string) ( $row['event_description'] ?? '' ) );
        if ( $event === '' ) $event = trim( (string) ( $row['location'] ?? '' ) );

        $scout      = $scout_id > 0 ? get_userdata( $scout_id ) : false;
        $scout_name = $scout ? (string) $scout->display_name : '';

        $link_parts = array_values( array_filter( [ $date, $event ], static fn( string $p ): bool => $p !== '' ) );
        if ( $link_parts === [] ) return '';

        $url = RecordLink::detailUrlForWithBack( 'scouting-visit', $visit_id );

        ob_start(); ?>
        <div class="tt-pipeline-focus-visit">
            <h2 class="tt-pipeline-focus-label"><?php echo esc_html( _x( 'Found at', 'the scouting visit a prospect was discovered at', 'talenttrack' ) ); ?></h2>
            <a class="tt-pipeline-focus-visit-link" href="<?php echo esc_url( $url ); ?>">
                <?php foreach ( $link_parts as $part ) : ?>
                    <span><?php echo esc_html( $part ); ?></span>
                <?php endforeach; ?>
            </a>
            <?php if ( $scout_name !== '' ) : ?>
                <p class="tt-pipeline-focus-visit-by"><?php
                    /* translators: %s: name of the scout who made the visit. */
                    echo esc_html( sprintf( __( 'Scout: %s', 'talenttrack' ), $scout_name ) );
                ?></p>
            <?php endif; ?>
            <?php
            // #3711 — the discovery visit is one observation; anything past
            // it is a re-sighting, which is what the count reports.
            $later = max( 0, $observation_count - 1 );
            if ( $later > 0 ) : ?>
                <p class="tt-pipeline-focus-visit-more"><?php
                    echo esc_html( sprintf(
                        /* translators: %s: number of scouting visits after the one the prospect was discovered at. */
                        _n( 'Seen at %s later visit', 'Seen at %s later visits', $later, 'talenttrack' ),
                        number_format_i18n( $later )
                    ) );
                ?></p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array{key:string,label:string,count:int,cards:array<int, array<string,mixed>>}> $stages
     */
    private static function renderKanban( array $stages ): string {
        ob_start();
        ?>
        <div class="tt-pipeline-kanban" role="list">
            <?php foreach ( $stages as $stage ) : ?>
                <section class="tt-pipeline-col" id="stage-<?php echo esc_attr( $stage['key'] ); ?>" data-stage="<?php echo esc_attr( $stage['key'] ); ?>" role="listitem">
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
        $joined    = ! empty( $card['joined'] );
        $initials  = (string) ( $card['initials']     ?? '' );
        $url       = (string) ( $card['url']          ?? '' );
        $tag       = $url !== '' ? 'a' : 'div';
        $url_attr  = $url !== '' ? ' href="' . esc_url( $url ) . '"' : '';

        // Flag accent — amber for a stale (overdue) early-funnel card,
        // green for a card that has reached the joined column. The accent
        // is a left border (CSS) plus a matching footer chip.
        $flag_cls = '';
        if ( $joined ) {
            $flag_cls = ' tt-pipeline-card--flag-g';
        } elseif ( $stale ) {
            $flag_cls = ' tt-pipeline-card--flag-a';
        }

        ob_start();
        ?>
        <<?php echo $tag; ?> class="tt-pipeline-card<?php echo esc_attr( $flag_cls ); ?>"<?php
            echo $url_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>>
            <span class="tt-pipeline-card-who">
                <span class="tt-pipeline-card-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
                <span class="tt-pipeline-card-id">
                    <span class="tt-pipeline-card-name"><?php echo esc_html( $name ); ?></span>
                    <?php if ( $sub !== '' ) : ?>
                        <span class="tt-pipeline-card-sub"><?php echo esc_html( $sub ); ?></span>
                    <?php endif; ?>
                </span>
            </span>
            <?php if ( $context !== '' ) : ?>
                <span class="tt-pipeline-card-ctx"><?php echo esc_html( $context ); ?></span>
            <?php endif; ?>
            <?php if ( $joined ) : ?>
                <span class="tt-pipeline-card-foot">
                    <span class="tt-chip tt-chip--green"><?php esc_html_e( 'Player', 'talenttrack' ); ?></span>
                </span>
            <?php elseif ( $stale ) : ?>
                <span class="tt-pipeline-card-foot">
                    <span class="tt-chip tt-chip--amber"><?php esc_html_e( 'Action needed', 'talenttrack' ); ?></span>
                </span>
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
     * #3160 — the row set is narrowed to the viewer's scope in the WHERE,
     * via `ProspectScope`: a global `prospects` read (scout, Head of
     * Development, Academy Admin) sees the whole funnel; a team-scoped head
     * coach sees their own age groups, anyone promoted into their squads,
     * and their own discoveries. Narrowing in SQL rather than in PHP is
     * deliberate — the KPI counts are derived from this same row set, so a
     * post-filter would leave them club-wide while the columns narrowed.
     *
     * @return array<int, array{key:string,label:string,count:int,cards:array<int, array<string,mixed>>}>
     */
    public static function computeStages( int $user_id ): array {
        global $wpdb;
        $club_id = CurrentClub::id();
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
        // #3160 — the viewer's visibility scope, resolved in one place and
        // applied in the WHERE rather than after the fact. Filtering in PHP
        // would leave the KPI counts above the board club-wide while the
        // columns below it narrowed, which is worse than either.
        $where_scope = ProspectScope::sqlClause( $user_id, 'p' );

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
                MAX(CASE WHEN wt.template_key = 'request_consent'              AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_consent,
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
              {$where_scope}
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
            // #3812 — between Prospects and Invited: the academy has asked
            // the child's own club to pass a consent request to the family
            // and is waiting for an answer.
            'consent'   => [ 'key' => 'consent',   'label' => __( 'Consent requested', 'talenttrack' ), 'cards' => [] ],
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

        // Reduce to indexed list with counts. Spelled out field by field
        // rather than spreading `$s`: writing into `$stages_init[$stage]`
        // above, where `$stage` is a union of the stage keys, leaves the
        // analyser unable to prove `key` and `label` are still there, and
        // the declared return type stops being provable the moment a
        // seventh stage joins the six.
        $out = [];
        foreach ( $stages_init as $s ) {
            $cards = $s['cards'];
            $out[] = [
                'key'   => (string) ( $s['key'] ?? '' ),
                'label' => (string) ( $s['label'] ?? '' ),
                'count' => count( $cards ),
                'cards' => $cards,
            ];
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
            // v3.110.99 — show birth year, not derived age. Operator
            // feedback: a scout scans for "the '08 striker" or "the
            // '07 kid", not for age. Year is also stable; age would
            // flip mid-season and confuse the kanban scan.
            $year = self::birthYearFromDob( $dob );
            if ( $year !== '' ) $sub_parts[] = sprintf( /* translators: %s: 4-digit birth year. */ __( 'born %s', 'talenttrack' ), $year );
        }

        $context_line = self::contextLine( $row, $stage );

        $stale = false;
        $due = (string) ( $row->soonest_due_at ?? '' );
        if ( $due !== '' ) {
            $due_ts = strtotime( $due );
            if ( $due_ts !== false && $due_ts < $stale_cutoff ) $stale = true;
        }

        return [
            'id'           => (int) ( $row->id ?? 0 ),
            'name'         => trim( $first . ' ' . $last ),
            'initials'     => self::initials( $first, $last ),
            'sub_label'    => implode( ' · ', $sub_parts ),
            'context_line' => $context_line,
            'stale'        => $stale,
            'joined'       => $stage === 'joined',
            'url'          => self::cardUrl( $row, $stage ),
        ];
    }

    /**
     * Two-letter initials for the card avatar. Falls back to the first
     * two letters of whichever name part exists so a single-name
     * prospect still renders a non-empty avatar.
     */
    private static function initials( string $first, string $last ): string {
        $f = trim( $first );
        $l = trim( $last );
        if ( $f !== '' && $l !== '' ) {
            return strtoupper( mb_substr( $f, 0, 1 ) . mb_substr( $l, 0, 1 ) );
        }
        $one = $f !== '' ? $f : $l;
        return strtoupper( mb_substr( $one, 0, 2 ) );
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
            case 'consent':
                // #3812 — the academy has asked the child's club and is
                // waiting. Nothing else can move until the family answers.
                return __( 'Consent request out, awaiting the family', 'talenttrack' );
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
     *
     * v3.110.98 — every returned URL is wrapped in `BackLink::appendTo()`
     * so the destination view's `← Back to Onboarding pipeline` pill
     * renders (CLAUDE.md §5's second affordance). The pipeline view
     * was missing this contract on its outgoing card-click URLs.
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
            // #3812 — the open consent request is the card's next action:
            // the scout opens it to record what the club came back with.
            case 'consent':
                $task_id = (int) ( $row->open_consent ?? 0 );
                break;
            case 'joined':
                $player_id = (int) ( $row->promoted_to_player_id ?? 0 );
                if ( $player_id > 0 ) {
                    return BackLink::appendTo( add_query_arg(
                        [ 'tt_view' => 'players', 'id' => $player_id ],
                        RecordLink::dashboardUrl()
                    ) );
                }
                return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
            case 'trial':
                $case_id = (int) ( $row->promoted_to_trial_case_id ?? 0 );
                if ( $case_id > 0 ) {
                    return BackLink::appendTo( add_query_arg(
                        [ 'tt_view' => 'trial-case', 'id' => $case_id ],
                        RecordLink::dashboardUrl()
                    ) );
                }
                return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
        }
        if ( $task_id > 0 ) {
            return BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'my-tasks', 'task_id' => $task_id ],
                RecordLink::dashboardUrl()
            ) );
        }
        // #1763 — no "next action": fall back to focusing the prospect on
        // the board so a card is never a dead end.
        return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
    }

    /**
     * #1763 — URL that focuses a prospect on the pipeline board
     * (`?tt_view=onboarding-pipeline&prospect_id=N`). Used as the card
     * fallback and by dashboard "open prospect" links.
     */
    private static function prospectFocusUrl( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        return BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'onboarding-pipeline', 'prospect_id' => $prospect_id ],
            RecordLink::dashboardUrl()
        ) );
    }

    private static function birthYearFromDob( string $dob ): string {
        $ts = strtotime( $dob );
        if ( $ts === false ) return '';
        $year = (int) date( 'Y', $ts );
        // Guard against obviously-bad inputs (year 0 / pre-1900 / future).
        if ( $year < 1900 || $year > (int) date( 'Y' ) ) return '';
        return (string) $year;
    }

    // #3160 — `isScoutOnly()` lived here and in OnboardingPipelineWidget.
    // It meant "holds tt_view_prospects but not tt_manage_prospects", which
    // was the scout's shape until v3.110.154 moved them to a global grant.
    // After that it stopped catching scouts and started catching the head
    // coach — the only remaining persona without `create_delete` — narrowing
    // their board to their own discoveries. `ProspectScope` replaces both
    // copies with the question the seed actually asks.
}
