<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

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
        if ( ! current_user_can( 'tt_view_prospects' ) && ! $is_admin ) {
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

        // Scope: a scout sees only their own; everyone else with cap sees all.
        $is_owner = (int) $visit->scout_user_id === $user_id;
        $is_scope_admin = current_user_can( 'tt_manage_prospects' ) || $is_admin;
        if ( ! $is_owner && ! $is_scope_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Scouting visit', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You can only view your own scouting visits.', 'talenttrack' ) . '</p>';
            return;
        }

        $title = sprintf(
            /* translators: 1: localised visit date, 2: location */
            __( 'Visit on %1$s — %2$s', 'talenttrack' ),
            mysql2date( get_option( 'date_format' ), (string) $visit->visit_date, true ),
            (string) $visit->location
        );
        FrontendBreadcrumbs::fromDashboard( $title, $parent_crumb );

        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $page_actions = [];
        if ( $is_owner || $is_scope_admin ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'scouting-visits', 'action' => 'edit', 'id' => (int) $visit->id ],
                $base_url
            );
            $page_actions[] = [
                'label' => __( 'Edit visit', 'talenttrack' ),
                'href'  => BackLink::appendTo( $edit_url ),
                'icon'  => '✎',
            ];
        }
        if ( current_user_can( 'tt_edit_prospects' ) ) {
            $wizard_url = WizardEntryPoint::urlFor(
                'new-prospect',
                add_query_arg( [ 'tt_view' => 'scouting-visit', 'id' => (int) $visit->id ], $base_url )
            );
            $wizard_url = add_query_arg( [ 'from_visit' => (int) $visit->id ], $wizard_url );
            $page_actions[] = [
                'label'   => __( 'Log scouting find', 'talenttrack' ),
                'href'    => $wizard_url,
                'primary' => true,
                'icon'    => '+',
            ];
        }
        self::renderHeader( $title, self::pageActionsHtml( $page_actions ) );

        self::renderFacts( $visit );
        self::renderProspects( $visit );
    }

    private static function renderFacts( object $visit ): void {
        $status_key   = (string) ( $visit->status ?? 'planned' );
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
        <div class="tt-card tt-detail-card">
            <table class="tt-detail-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                        <td><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $visit->visit_date, true ) ); ?></td>
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

    private static function renderProspects( object $visit ): void {
        $repo = new ScoutingVisitsRepository();
        $rows = $repo->prospectsForVisit( (int) $visit->id );

        echo '<section class="tt-section">';
        echo '<h2 class="tt-section-title">' . esc_html__( 'Prospects logged from this visit', 'talenttrack' ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p class="tt-empty">' . esc_html__( 'No prospects logged from this visit yet.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }
        ?>
        <div class="tt-table-wrap">
            <table class="tt-table tt-table-sortable">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Birth year', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Club', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Position', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Logged', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $p ) :
                        $name = trim( (string) $p->first_name . ' ' . (string) $p->last_name );
                        $birth_year = '';
                        $dob = (string) ( $p->dob ?? '' );
                        if ( $dob !== '' && preg_match( '/^(\d{4})/', $dob, $m ) ) {
                            $birth_year = $m[1];
                        }
                        $kanban_url = add_query_arg(
                            [ 'tt_view' => 'onboarding-pipeline', 'prospect_id' => (int) $p->id ],
                            remove_query_arg( [ 'action', 'id', 'tt_back' ] )
                        );
                        $kanban_url = BackLink::appendTo( $kanban_url );
                        $status = '';
                        if ( ! empty( $p->archived_at ) )                  $status = __( 'Archived', 'talenttrack' );
                        elseif ( ! empty( $p->promoted_to_player_id ) )    $status = __( 'Joined', 'talenttrack' );
                        elseif ( ! empty( $p->promoted_to_trial_case_id ) ) $status = __( 'In trial', 'talenttrack' );
                        else                                               $status = __( 'Active', 'talenttrack' );
                        ?>
                        <tr>
                            <td data-sort="<?php echo esc_attr( $p->last_name . ' ' . $p->first_name ); ?>">
                                <a href="<?php echo esc_url( $kanban_url ); ?>"><?php echo esc_html( $name ); ?></a>
                            </td>
                            <td><?php echo esc_html( $birth_year ); ?></td>
                            <td><?php echo esc_html( (string) ( $p->current_club ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $p->position ?? '' ) ); ?></td>
                            <td data-sort="<?php echo esc_attr( (string) ( $p->discovered_at ?? '' ) ); ?>">
                                <?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) ( $p->discovered_at ?? '' ), true ) ); ?>
                            </td>
                            <td><?php echo esc_html( $status ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        echo '</section>';
    }
}
