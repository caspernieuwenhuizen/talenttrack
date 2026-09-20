<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ScoutingVisitStatus;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ProspectOutcome;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\ScoutingVisitsAccess;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Dates\TTDate;

/**
 * FrontendScoutingVisitDetailView (v3.110.119) — single scouting
 * visit at `?tt_view=scouting-visit&id=N` (singular slug).
 *
 * Shows the visit's facts (date / location / event / age groups /
 * status / notes), the list of prospects logged from this visit
 * (`tt_prospects.scouting_visit_id`), and an Edit + Log-find CTA.
 *
 * Log-find CTA passes `from_visit=N` to the new-prospect wizard.
 * The wizard's ScoutingVisitStep (when shipped) pre-fills the link;
 * for the v3.110.119 release the step is deferred — the visit is
 * still recorded onto the prospect via the wizard's hidden field
 * passthrough.
 */
class FrontendScoutingVisitDetailView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        // #2007 — two questions, and both have to pass. The cap decides
        // whether this user may read prospect data at all; the panel entity
        // decides whether the scout's visit surfaces are theirs. A head
        // coach holds the first on purpose (their own age group's funnel,
        // #0081) and must not hold the second. This view has no tile, so
        // the dashboard's dispatch gate has no entity to read for it —
        // without the check here it stays reachable by URL.
        $allowed = AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' )
            && ScoutingVisitsAccess::allows( $user_id, $is_admin );

        if ( ! $allowed && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'Scouting visit', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to scouting visits.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $repo  = new ScoutingVisitsRepository();
        $visit = $id > 0 ? $repo->find( $id ) : null;

        $parent_crumb = [ FrontendBreadcrumbs::viewCrumb( 'scouting-visits', __( 'Scouting visits', 'talenttrack' ) ) ];

        if ( ! $visit ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Scouting visit not found', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Scouting visit not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This scouting visit does not exist or you do not have access.', 'talenttrack' ) . '</p>';
            return;
        }

        // Scope: a scout sees only their own; everyone else with cap sees
        // all. The rule lives in ScoutingVisitsAccess (#3604) so this view,
        // the list and the REST read routes give one answer.
        if ( ! ScoutingVisitsAccess::canReadVisit( $user_id, $visit, $is_admin ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Scouting visit', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You can only view your own scouting visits.', 'talenttrack' ) . '</p>';
            return;
        }

        $title = sprintf(
            /* translators: 1: localised visit date, 2: location */
            __( 'Visit on %1$s — %2$s', 'talenttrack' ),
            TTDate::date( (string) $visit->visit_date ),
            (string) $visit->location
        );
        FrontendBreadcrumbs::fromDashboard( $title, $parent_crumb );

        $visit_id = (int) $visit->id;
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $page_actions = [];
        // Editing and archiving a visit ask the same question as reading it
        // (owner, or the head of development), and the refusal above has
        // already answered it — one rule, one answer, asked once.
        $edit_url = add_query_arg(
            [ 'tt_view' => 'scouting-visits', 'action' => 'edit', 'id' => $visit_id ],
            $base_url
        );
        $page_actions[] = [
            'label' => __( 'Edit visit', 'talenttrack' ),
            'href'  => BackLink::appendTo( $edit_url ),
            'icon'  => \TT\Shared\Icons\IconRenderer::render( 'edit', [ 'width' => 16, 'height' => 16 ] ), // #1365 — inline SVG edit icon.
        ];
        if ( AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' ) ) {
            $wizard_url = WizardEntryPoint::urlFor(
                'new-prospect',
                add_query_arg( [ 'tt_view' => 'scouting-visit', 'id' => $visit_id ], $base_url ) /* tt-xview-ok */
            );
            $wizard_url = add_query_arg( [ 'from_visit' => $visit_id ], $wizard_url );
            $page_actions[] = [
                'label'   => __( 'Log scouting find', 'talenttrack' ),
                'href'    => $wizard_url,
                'primary' => true,
                'icon'    => '+',
            ];
        }

        // #1764 — surface the existing archive (soft-delete) endpoint in
        // the UI. The DELETE /scouting-visits/{id} route already enforces
        // its own capability + row-ownership check; this button is gated
        // the same way the Edit action above is (owner or scope admin),
        // and the JS layer fires the REST call with a nonce + confirm.
        $page_actions[] = [
            'label'      => __( 'Archive visit', 'talenttrack' ),
            'variant'    => 'danger',
            'data_attrs' => [ 'tt-archive-visit' => $visit_id ],
        ];

        wp_enqueue_script(
            'tt-scouting-visit-archive',
            TT_PLUGIN_URL . 'assets/js/components/scouting-visit-archive.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-scouting-visit-archive', 'TT_SCOUTING_VISIT_ARCHIVE', [
            'rest_url'     => esc_url_raw( rest_url( 'talenttrack/v1/scouting-visits/' ) ),
            'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
            // Soft-delete redirects back to the list, which excludes
            // archived rows; `tt_archived=1` triggers the success notice.
            'redirect_url' => esc_url_raw( add_query_arg(
                [ 'tt_view' => 'scouting-visits', 'tt_archived' => 1 ],
                $base_url
            ) ),
            'i18n' => [
                'confirm'       => __( 'Archive this scouting visit? It will be removed from the list.', 'talenttrack' ),
                'error_generic' => __( 'Could not archive the visit. Please try again.', 'talenttrack' ),
                'network_error' => __( 'Network error. Please try again.', 'talenttrack' ),
            ],
        ] );

        // #3711 — linking an existing prospect is the write half of this
        // screen, so it is enqueued under the same capability the
        // "Log scouting find" action above uses.
        $can_link = AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' )
            && empty( $visit->archived_at );
        if ( $can_link ) {
            self::enqueueObservationAssets( $visit_id );
        }

        self::renderHeader( $title, self::pageActionsHtml( $page_actions ) );

        self::renderFacts( $visit );
        self::renderProspects( $visit, $can_link );
        if ( $can_link ) {
            self::renderLinkExisting();
        }
    }

    /**
     * Boot data for the link-an-existing-prospect search. The prospects
     * list route applies `ProspectScope` server-side, so the typeahead
     * cannot surface a child the viewer may not already see.
     */
    private static function enqueueObservationAssets( int $visit_id ): void {
        wp_enqueue_script(
            'tt-scouting-visit-observations',
            TT_PLUGIN_URL . 'assets/js/components/scouting-visit-observations.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-scouting-visit-observations', 'TT_VISIT_OBSERVATIONS', [
            'rest_nonce'       => wp_create_nonce( 'wp_rest' ),
            'prospects_url'    => esc_url_raw( rest_url( 'talenttrack/v1/prospects' ) ),
            'observations_url' => esc_url_raw( rest_url( 'talenttrack/v1/scouting-visits/' . $visit_id . '/observations' ) ),
            'i18n' => [
                'searching'      => __( 'Searching…', 'talenttrack' ),
                'no_results'     => __( 'No prospects match that name.', 'talenttrack' ),
                'error_generic'  => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'error_link'     => __( 'Could not link that prospect. Please try again.', 'talenttrack' ),
                'error_unlink'   => __( 'Could not remove that link. Please try again.', 'talenttrack' ),
                'network_error'  => __( 'Network error. Please try again.', 'talenttrack' ),
                'confirm_unlink' => __( 'Remove this prospect from the visit?', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * The search box. Deliberately below the list: the question it
     * answers is "who else was here", which only makes sense once you
     * have read who is already on it.
     */
    private static function renderLinkExisting(): void {
        ?>
        <section class="tt-section tt-observation-link" data-tt-observations>
            <h2 class="tt-section-title"><?php esc_html_e( 'Link an existing prospect', 'talenttrack' ); ?></h2>
            <p class="tt-observation-hint">
                <?php esc_html_e( 'Saw somebody you have already logged? Find them here instead of logging them twice.', 'talenttrack' ); ?>
            </p>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-observation-search">
                    <?php esc_html_e( 'Search prospects by name', 'talenttrack' ); ?>
                </label>
                <input
                    type="search"
                    id="tt-observation-search"
                    class="tt-input"
                    inputmode="search"
                    autocomplete="off"
                    data-tt-observation-search
                    placeholder="<?php esc_attr_e( 'Start typing a name…', 'talenttrack' ); ?>"
                >
            </div>
            <p class="tt-observation-status" role="status" aria-live="polite" data-tt-observation-status></p>
            <ul class="tt-observation-results" data-tt-observation-results></ul>
        </section>
        <?php
    }

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        // Shared with the list view (FrontendScoutingPlanView): the
        // 2026 facts-card + status-chip styling lives in this sheet.
        wp_enqueue_style(
            'tt-scouting-visits',
            TT_PLUGIN_URL . 'assets/css/components/scouting-visits.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    private static function renderFacts( object $visit ): void {
        $status_key   = (string) ( $visit->status ?? ScoutingVisitStatus::PLANNED );
        $status_label = FrontendScoutingPlanView::statusLabel( $status_key );
        $age_groups   = (string) ( $visit->age_groups_csv ?? '' );
        $event        = (string) ( $visit->event_description ?? '' );
        $time_part    = (string) ( $visit->visit_time ?? '' );
        $time_label   = ( $time_part !== '' && $time_part !== '00:00:00' ) ? substr( $time_part, 0, 5 ) : '';
        $scout_label  = '';
        $scout        = get_userdata( (int) $visit->scout_user_id );
        if ( $scout ) $scout_label = (string) $scout->display_name;
        $notes = (string) ( $visit->notes ?? '' );
        ?>
        <div class="tt-svisit-detail-card">
            <table class="tt-svisit-detail-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                        <td><?php echo esc_html( \TT\Shared\Dates\TTDate::date( (string) $visit->visit_date ) ); ?></td>
                    </tr>
                    <?php if ( $time_label !== '' ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Time', 'talenttrack' ); ?></th>
                            <td><?php echo esc_html( $time_label ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Location', 'talenttrack' ); ?></th>
                        <td><?php echo esc_html( (string) $visit->location ); ?></td>
                    </tr>
                    <?php if ( $event !== '' ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Event', 'talenttrack' ); ?></th>
                            <td><?php echo esc_html( $event ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( $age_groups !== '' ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Age groups expected', 'talenttrack' ); ?></th>
                            <td><?php echo esc_html( $age_groups ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <td><?php echo FrontendScoutingPlanView::statusPillHtml( $status_key, $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Scout', 'talenttrack' ); ?></th>
                        <td><?php echo esc_html( $scout_label ); ?></td>
                    </tr>
                    <?php if ( $notes !== '' ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                            <td><?php echo wp_kses_post( wpautop( $notes ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderProspects( object $visit, bool $can_link = false ): void {
        $repo     = new ScoutingVisitsRepository();
        $visit_id = (int) $visit->id;
        $rows     = $repo->prospectsForVisit( $visit_id );

        echo '<section class="tt-section tt-svisit-prospects" data-tt-observations>';
        echo '<h2 class="tt-section-title">' . esc_html__( 'Prospects watched at this visit', 'talenttrack' ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p class="tt-empty">' . esc_html__( 'No prospects logged from this visit yet.', 'talenttrack' ) . '</p>';
            echo '<p class="tt-observation-status" role="status" aria-live="polite" data-tt-observation-status></p>';
            echo '</section>';
            return;
        }
        ?>
        <p class="tt-observation-status" role="status" aria-live="polite" data-tt-observation-status></p>
        <div class="tt-table-wrap">
            <table class="tt-table tt-table-sortable">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Birth year', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Club', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Position', 'talenttrack' ); ?></th>
                        <th><?php echo esc_html( _x( 'Seen', 'date a prospect was watched at this scouting visit', 'talenttrack' ) ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <?php if ( $can_link ) : ?>
                            <th><span class="tt-sr-only"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $p ) :
                        $name = trim( (string) $p->first_name . ' ' . (string) $p->last_name );
                        $birth_year = '';
                        $dob = (string) ( $p->date_of_birth ?? '' );
                        if ( $dob !== '' && preg_match( '/^(\d{4})/', $dob, $m ) ) {
                            $birth_year = $m[1];
                        }
                        $kanban_url = add_query_arg(
                            [ 'tt_view' => 'onboarding-pipeline', 'prospect_id' => (int) $p->id ],
                            remove_query_arg( [ 'action', 'id', 'tt_back' ] )
                        );
                        $kanban_url = BackLink::appendTo( $kanban_url );
                        // #3604 — the same derivation the REST read uses.
                        $status = ProspectOutcome::label( ProspectOutcome::forRow( (array) $p ) );
                        ?>
                        <tr>
                            <td data-sort="<?php echo esc_attr( $p->last_name . ' ' . $p->first_name ); ?>">
                                <a href="<?php echo esc_url( $kanban_url ); ?>"><?php echo esc_html( $name ); ?></a>
                            </td>
                            <td><?php echo esc_html( $birth_year ); ?></td>
                            <td><?php echo esc_html( (string) ( $p->current_club ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $p->position ?? '' ) ); ?></td>
                            <?php
                            // #3711 — the date of THIS sighting, not the
                            // prospect's discovery date. They are the same
                            // for a first sighting and different for every
                            // one after it, which is the whole point.
                            $seen_at = (string) ( $p->observed_at ?? '' );
                            if ( $seen_at === '' ) $seen_at = (string) ( $p->discovered_at ?? '' );
                            $observation_id = (int) ( $p->observation_id ?? 0 );
                            ?>
                            <td data-sort="<?php echo esc_attr( $seen_at ); ?>">
                                <?php echo esc_html( \TT\Shared\Dates\TTDate::date( $seen_at ) ); ?>
                                <?php if ( (int) ( $p->scouting_visit_id ?? 0 ) === $visit_id ) : ?>
                                    <span class="tt-observation-badge"><?php echo esc_html_x( 'Discovered here', 'scouting visit', 'talenttrack' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $status ); ?></td>
                            <?php if ( $can_link ) : ?>
                                <td>
                                    <?php if ( $observation_id > 0 ) : ?>
                                        <button type="button" class="tt-btn tt-btn-secondary tt-observation-unlink"
                                            data-tt-observation-unlink="<?php echo esc_attr( (string) $observation_id ); ?>">
                                            <?php esc_html_e( 'Remove from visit', 'talenttrack' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        echo '</section>';
    }
}
