<?php
namespace TT\Modules\Wizards\TeamAnnouncement;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Send\MassAnnouncementSender;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — who this reaches.
 *
 * One required select carrying both halves of the answer (`team:12`,
 * `age:O13`, `academy`), so a team scope can never be submitted with no
 * team attached. Nothing is pre-selected: the form does not get to
 * choose "everyone" on the sender's behalf.
 *
 * The options are what this sender may actually reach — a team manager
 * sees their own squads and nothing else. That is a courtesy, not the
 * rule: `validate()` asks `canSend()` again, and so does the REST route,
 * because a hidden option is not a refused one.
 */
final class AudienceStep implements WizardStepInterface {

    public function slug(): string { return 'audience'; }

    public function label(): string { return __( 'Audience', 'talenttrack' ); }

    public function render( array $state ): void {
        $user_id = get_current_user_id();
        $sender  = new MassAnnouncementSender();
        $teams   = $sender->teamsFor( $user_id );
        $current = (string) ( $state['audience'] ?? '' );

        if ( $teams === [] && ! MassAnnouncementSender::holdsAcademyTier( $user_id ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You are not assigned to a team yet, so there is nobody to announce to. Ask an administrator to add you to one.', 'talenttrack' ) . '</p>';
            echo '<input type="hidden" name="audience" value="" required />';
            return;
        }

        ?>
        <div class="tt-field">
            <label class="tt-field-label tt-field-required" for="tt-announce-audience">
                <?php esc_html_e( 'Who this reaches', 'talenttrack' ); ?>
            </label>
            <select id="tt-announce-audience" class="tt-input" name="audience" required>
                <option value=""><?php esc_html_e( '— Choose —', 'talenttrack' ); ?></option>
                <?php foreach ( $teams as $team ) :
                    $value = MassAnnouncementSender::audienceValue( MassAnnouncementSender::SCOPE_TEAM, $team['id'] ); ?>
                    <option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>>
                        <?php
                        printf(
                            /* translators: %s: the team's name */
                            esc_html__( 'The families of %s', 'talenttrack' ),
                            esc_html( $team['name'] )
                        );
                        ?>
                    </option>
                <?php endforeach; ?>

                <?php if ( MassAnnouncementSender::holdsAcademyTier( $user_id ) ) : ?>
                    <?php foreach ( $sender->ageGroups() as $group ) :
                        $value = MassAnnouncementSender::audienceValue( MassAnnouncementSender::SCOPE_AGE_GROUP, 0, $group ); ?>
                        <option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>>
                            <?php
                            printf(
                                /* translators: %s: an age group, for example O13 */
                                esc_html__( 'Every family in %s', 'talenttrack' ),
                                esc_html( $group )
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="<?php echo esc_attr( MassAnnouncementSender::SCOPE_ACADEMY ); ?>"
                        <?php selected( $current, MassAnnouncementSender::SCOPE_ACADEMY ); ?>>
                        <?php esc_html_e( 'Every family in the academy', 'talenttrack' ); ?>
                    </option>
                <?php endif; ?>
            </select>
            <small class="tt-field-hint">
                <?php esc_html_e( 'Send to the smallest group the news actually concerns. Families stop reading announcements that were not for them.', 'talenttrack' ); ?>
            </small>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $raw      = isset( $post['audience'] ) ? sanitize_text_field( wp_unslash( (string) $post['audience'] ) ) : '';
        $audience = MassAnnouncementSender::parseAudience( $raw );

        if ( $audience['scope'] === '' ) {
            return new \WP_Error( 'no_audience', __( 'Choose who this announcement reaches.', 'talenttrack' ) );
        }

        $sender = new MassAnnouncementSender();
        if ( ! $sender->canSend( get_current_user_id(), $audience['scope'], $audience['team_id'], $audience['age_group'] ) ) {
            return new \WP_Error(
                'audience_denied',
                __( 'You cannot send an announcement to that audience.', 'talenttrack' )
            );
        }

        return [
            'audience'  => MassAnnouncementSender::audienceValue( $audience['scope'], $audience['team_id'], $audience['age_group'] ),
            'scope'     => $audience['scope'],
            'team_id'   => $audience['team_id'],
            'age_group' => $audience['age_group'],
        ];
    }

    /** Always names the next step, so the framework never submits from here. */
    public function nextStep( array $state ): string { return 'compose'; }

    /**
     * Unreachable: `submit()` runs only on the step whose `nextStep()`
     * answered null, and this one never does. Empty rather than null
     * because the contract is a result array, and a step that cannot
     * commit has nothing to put in it.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ): array { return []; }
}
