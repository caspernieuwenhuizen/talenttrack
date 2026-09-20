<?php
namespace TT\Modules\Wizards\TeamAnnouncement;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsOutcomeSummary;
use TT\Modules\Comms\Rest\CommsRestController;
use TT\Modules\Comms\Send\MassAnnouncementSender;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — the commit.
 *
 * Everything before this was a draft. This step is the only place the
 * wizard writes anything, and what it writes cannot be recalled, so it
 * states the blast radius in numbers and in words before the button
 * that does it, and asks for an explicit acknowledgement of the count.
 *
 * Quiet hours are shown, never overridden. A message composed at 22:40
 * is held until morning and says so here, because "it did not arrive"
 * and "it arrives at seven" are different facts and the sender should
 * leave knowing which one applies.
 */
final class ConfirmStep implements WizardStepInterface {

    public function slug(): string { return 'confirm'; }

    public function label(): string { return __( 'Confirm', 'talenttrack' ); }

    public function render( array $state ): void {
        wp_enqueue_style(
            'tt-frontend-team-announcement',
            TT_PLUGIN_URL . 'assets/css/frontend-team-announcement.css',
            [],
            TT_VERSION
        );

        $sender    = new MassAnnouncementSender();
        $scope     = (string) ( $state['scope'] ?? '' );
        $team_id   = (int) ( $state['team_id'] ?? 0 );
        $age_group = (string) ( $state['age_group'] ?? '' );
        $count     = $sender->recipientCount( $scope, $team_id, $age_group );

        echo '<div class="tt-announce-confirm">';

        echo '<p class="tt-announce-count">' . esc_html( sprintf(
            /* translators: %d: number of people the announcement will be sent to */
            _n( 'This announcement will be sent to %d person.', 'This announcement will be sent to %d people.', $count, 'talenttrack' ),
            $count
        ) ) . '</p>';
        echo '<p class="tt-announce-audience">' . esc_html( $sender->audienceLabel( $scope, $team_id, $age_group ) ) . '</p>';

        echo '<ul class="tt-announce-consequences">';
        echo '<li>' . esc_html__( 'Anybody who has switched announcements off will not receive it.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'Outside your academy\'s messaging hours it is held and delivered the next morning.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'It cannot be recalled, and nobody can reply to it. Every send is recorded in the message log.', 'talenttrack' ) . '</li>';
        echo '</ul>';

        echo '<h3 class="tt-announce-preview-title">' . esc_html( (string) ( $state['subject'] ?? '' ) ) . '</h3>';
        echo '<p class="tt-announce-preview-body">' . nl2br( esc_html( (string) ( $state['body'] ?? '' ) ) ) . '</p>';

        if ( $count === 0 ) {
            echo '<p class="tt-notice tt-notice-warning">' . esc_html__( 'Nobody in this audience can be reached, so there is nothing to send. Check the contact details on the player records first.', 'talenttrack' ) . '</p>';
        }

        foreach ( CommsOutcomeSummary::warnings( $sender->preflight( $scope, $team_id, $age_group ) ) as $warning ) {
            echo '<div class="tt-notice tt-notice-warning">' . esc_html( $warning ) . '</div>';
        }

        echo '</div>';
        ?>
        <div class="tt-field tt-announce-ack">
            <label class="tt-check">
                <input type="checkbox" name="acknowledged" value="1" required />
                <span><?php echo esc_html( sprintf(
                    /* translators: %d: number of people the announcement will be sent to */
                    _n(
                        'I have read it back and it is ready to go to %d person.',
                        'I have read it back and it is ready to go to %d people.',
                        $count,
                        'talenttrack'
                    ),
                    $count
                ) ); ?></span>
            </label>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        if ( empty( $post['acknowledged'] ) ) {
            return new \WP_Error(
                'not_acknowledged',
                __( 'Confirm the announcement is ready before sending it.', 'talenttrack' )
            );
        }
        return [ 'acknowledged' => true ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $scope     = (string) ( $state['scope'] ?? '' );
        $team_id   = (int) ( $state['team_id'] ?? 0 );
        $age_group = (string) ( $state['age_group'] ?? '' );
        $subject   = trim( (string) ( $state['subject'] ?? '' ) );
        $body      = trim( (string) ( $state['body'] ?? '' ) );

        if ( $subject === '' || $body === '' ) {
            return new \WP_Error( 'bad_draft', __( 'A subject and a message are both required.', 'talenttrack' ) );
        }

        // Asked again at the commit, not only when the audience was
        // picked: a draft survives an hour in a transient and longer in
        // the draft table, and a coach can lose a team in between.
        $sender = new MassAnnouncementSender();
        if ( ! $sender->canSend( get_current_user_id(), $scope, $team_id, $age_group ) ) {
            return new \WP_Error( 'audience_denied', __( 'You cannot send an announcement to that audience.', 'talenttrack' ) );
        }

        $results = $sender->send( $subject, $body, $scope, $team_id, $age_group );
        if ( $results === [] ) {
            return new \WP_Error( 'no_recipients', __( 'Nobody in this audience could be reached, so nothing was sent.', 'talenttrack' ) );
        }

        return [ 'redirect_url' => self::landing() ];
    }

    /**
     * Where the sender lands afterwards: the message log, which is the
     * record of what just went out. Sending an announcement and reading
     * the log are separate permissions, so somebody who holds only the
     * first goes back to the dashboard rather than to a screen that
     * would refuse them.
     */
    private static function landing(): string {
        $dashboard = RecordLink::dashboardUrl();
        if ( ! current_user_can( CommsRestController::CAP_READ_LOG ) ) return $dashboard;
        return add_query_arg( [ 'tt_view' => 'messages' ], $dashboard ); /* tt-xview-ok */
    }
}
