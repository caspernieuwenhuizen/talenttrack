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
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        $person = ( new PeopleRepository() )->find( $person_id );
        $back_url = remove_query_arg( [ 'id' ] );
        FrontendBackButton::render( $back_url );

        if ( ! $person ) {
            self::renderHeader( __( 'Person not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That person is no longer on file.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        $name = trim( ( (string) ( $person->first_name ?? '' ) ) . ' ' . ( (string) ( $person->last_name ?? '' ) ) );
        if ( $name === '' ) $name = __( 'Person', 'talenttrack' );
        self::renderHeader( $name );

        $teams = ( new PeopleRepository() )->getPersonTeams( $person_id );
        $email = (string) ( $person->email ?? '' );
        $phone = (string) ( $person->phone ?? '' );
        $role  = (string) ( $person->role_type ?? '' );
        ?>
        <article class="tt-person-detail">
            <dl class="tt-profile-dl">
                <?php if ( $role !== '' ) : ?>
                    <dt><?php esc_html_e( 'Role', 'talenttrack' ); ?></dt>
                    <dd><?php echo esc_html( LabelTranslator::roleType( $role ) ); ?></dd>
                <?php endif; ?>
                <?php if ( $email !== '' ) : ?>
                    <dt><?php esc_html_e( 'Email', 'talenttrack' ); ?></dt>
                    <dd>
                        <?php
                        $compose_url = add_query_arg(
                            [ 'tt_view' => 'mail-compose', 'person_id' => $person_id ],
                            home_url( '/' )
                        );
                        ?>
                        <a class="tt-record-link" href="<?php echo esc_url( $compose_url ); ?>">
                            <?php echo esc_html( $email ); ?>
                        </a>
                    </dd>
                <?php endif; ?>
                <?php if ( $phone !== '' ) : ?>
                    <dt><?php esc_html_e( 'Phone', 'talenttrack' ); ?></dt>
                    <dd><a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a></dd>
                <?php endif; ?>
                <?php if ( ! empty( $person->status ) ) : ?>
                    <dt><?php esc_html_e( 'Status', 'talenttrack' ); ?></dt>
                    <dd><?php echo esc_html( LabelTranslator::personStatus( (string) $person->status ) ); ?></dd>
                <?php endif; ?>
            </dl>

            <?php if ( ! empty( $teams ) ) : ?>
                <section class="tt-pde-section">
                    <h3><?php esc_html_e( 'Teams', 'talenttrack' ); ?></h3>
                    <ul class="tt-stack">
                        <?php foreach ( $teams as $t ) :
                            $team_id   = (int) ( $t->team_id ?? $t->id ?? 0 );
                            $team_name = (string) ( $t->team_name ?? $t->name ?? '' );
                            if ( $team_id <= 0 || $team_name === '' ) continue;
                            $url = add_query_arg(
                                [ 'tt_view' => 'teams', 'id' => $team_id ],
                                home_url( '/' )
                            );
                            ?>
                            <li>
                                <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>">
                                    <?php echo esc_html( $team_name ); ?>
                                </a>
                                <?php if ( ! empty( $t->functional_role_label ) ) : ?>
                                    <span class="tt-muted"> &middot; <?php echo esc_html( (string) $t->functional_role_label ); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
        <?php
    }
}
