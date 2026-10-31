<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ProposeTestTrainingService;
use TT\Modules\Prospects\Domain\ProspectStages;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\ScoutingVisitsAccess;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
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

        $stages = ProspectStages::computeStages( $user_id );

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
     * already computed in `ProspectStages::computeStages()`, so it shows the same identity,
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
        // #3932 — carrying the prospect through. Without it the deep link
        // opened a form with nothing on it about the child whose card the
        // button was on, and the session it created was linked to nobody.
        $invite_url  = $stuck && $can_invite
            ? BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'test-trainings', 'action' => 'new', 'prospect_id' => $focus_pid ],
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
            <?php // #4257 — "Open record" is offered for every focused prospect. ?>
            <?php if ( $focus_pid > 0 ) : ?>
                <p class="tt-pipeline-focus-actions">
                    <?php if ( $has_action ) : ?>
                        <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( BackLink::appendTo( $url ) ); ?>"><?php esc_html_e( 'Open next action', 'talenttrack' ); ?></a>
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
                    <?php // #4257 — the full record: every consent request and visit. ?>
                    <?php
                    CrossViewLink::render( 'prospect', static function () use ( $focus_pid ): void {
                        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( RecordLink::detailUrlForWithBack( 'prospect', $focus_pid ) ) . '">' /* tt-xview-ok — gated by CrossViewLink::render() */
                            . esc_html__( 'Open record', 'talenttrack' ) . '</a>';
                    }, [ 'ctx' => [ 'prospect_id' => $focus_pid ] ] );
                    ?>
                </p>
                <?php if ( $can_propose ) : ?>
                    <form id="tt-propose-<?php echo (int) $focus_pid; ?>" class="tt-pipeline-focus-propose" method="post" action="<?php echo esc_url( BackLink::appendTo( ProspectStages::prospectFocusUrl( $focus_pid ) ) ); ?>">
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
        $url_attr  = $url !== '' ? ' href="' . esc_url( BackLink::appendTo( $url ) ) . '"' : '';

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

    // #3160 — `isScoutOnly()` lived here and in OnboardingPipelineWidget.
    // It meant "holds tt_view_prospects but not tt_manage_prospects", which
    // was the scout's shape until v3.110.154 moved them to a global grant.
    // After that it stopped catching scouts and started catching the head
    // coach — the only remaining persona without `create_delete` — narrowing
    // their board to their own discoveries. `ProspectScope` replaces both
    // copies with the question the seed actually asks.
}
