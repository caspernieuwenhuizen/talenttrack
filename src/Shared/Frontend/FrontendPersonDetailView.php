<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\LabelTranslator;

/**
 * FrontendPersonDetailView — read-only display of a single person
 * (#0063), reachable via `?tt_view=people&id=N`.
 *
 * Cap-gated on `tt_view_people`. Composition only.
 *
 * Email links to the in-product mail composer (#0063 Sprint 3) when
 * the recipient has an email; falls through to a `mailto:` if the
 * composer module isn't enabled.
 */
final class FrontendPersonDetailView extends FrontendViewBase {

    public static function render( int $person_id, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_people' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        $person = ( new PeopleRepository() )->find( $person_id );

        // v3.92.1 — breadcrumb chain replaces the standalone back link.
        $people_label = __( 'People', 'talenttrack' );
        if ( ! $person ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Person not found', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'people', $people_label ) ]
            );
            self::renderHeader( __( 'Person not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That person is no longer on file.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        // v4.0.6 (#876) — the person profile + teams blocks now render
        // via .tt-profile-table; pull in frontend-player-detail.css
        // which carries the canonical styling for that class.
        wp_enqueue_style(
            'tt-frontend-player-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-player-detail.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        $name = trim( ( (string) ( $person->first_name ?? '' ) ) . ' ' . ( (string) ( $person->last_name ?? '' ) ) );
        if ( $name === '' ) $name = __( 'Person', 'talenttrack' );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            $name,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'people', $people_label ) ]
        );

        // v3.110.53 — Edit + Archive page-header actions.
        $actions    = [];
        $people_url = add_query_arg( [ 'tt_view' => 'people' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        if ( current_user_can( 'tt_edit_people' ) ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'people', 'id' => $person_id, 'action' => 'edit' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            );
            $actions[] = [
                'label'   => __( 'Edit', 'talenttrack' ),
                'href'    => $edit_url,
                'primary' => true,
                'icon'    => '✎',
            ];
            $actions[] = [
                'label'   => __( 'Archive', 'talenttrack' ),
                'variant' => 'danger',
                'data_attrs' => [
                    'tt-archive-rest-path' => 'people/' . $person_id,
                    'tt-archive-confirm'   => __( 'Archive this person? They can be restored later by a site admin.', 'talenttrack' ),
                    'tt-archive-redirect'  => $people_url,
                ],
            ];
        }
        self::renderHeader( $name, self::pageActionsHtml( $actions ) );

        $teams = ( new PeopleRepository() )->getPersonTeams( $person_id );
        $email = (string) ( $person->email ?? '' );
        $phone = (string) ( $person->phone ?? '' );
        $role  = (string) ( $person->role_type ?? '' );
        ?>
        <article class="tt-person-detail">
            <table class="tt-profile-table">
                <tbody>
                    <?php if ( $role !== '' ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                            <td><?php echo esc_html( LabelTranslator::roleType( $role ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( $email !== '' ) :
                        $compose_url = add_query_arg(
                            [ 'tt_view' => 'mail-compose', 'person_id' => $person_id ],
                            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                        );
                        ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Email', 'talenttrack' ); ?></th>
                            <td><a class="tt-record-link" href="<?php echo esc_url( $compose_url ); ?>"><?php echo esc_html( $email ); ?></a></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( $phone !== '' ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Phone', 'talenttrack' ); ?></th>
                            <td><a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( ! empty( $person->status ) ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                            <td><?php echo esc_html( LabelTranslator::personStatus( (string) $person->status ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( ! empty( $teams ) ) :
                $team_rows = [];
                foreach ( $teams as $t ) {
                    $team_id   = (int) ( $t->team_id ?? $t->id ?? 0 );
                    $team_name = (string) ( $t->team_name ?? $t->name ?? '' );
                    if ( $team_id <= 0 || $team_name === '' ) continue;
                    $team_rows[] = [
                        'id'   => $team_id,
                        'name' => $team_name,
                        'role' => (string) ( $t->functional_role_label ?? '' ),
                    ];
                }
                if ( ! empty( $team_rows ) ) : ?>
                    <section class="tt-pde-section">
                        <h3><?php esc_html_e( 'Teams', 'talenttrack' ); ?></h3>
                        <table class="tt-profile-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Functional role', 'talenttrack' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $team_rows as $row ) :
                                    $url = add_query_arg(
                                        [ 'tt_view' => 'teams', 'id' => $row['id'] ],
                                        \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
                                        </td>
                                        <td><?php echo $row['role'] !== '' ? esc_html( $row['role'] ) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </article>
        <?php
    }
}
