<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondClient;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * SpondGroupStep (#0062) — optional new-team wizard step.
 *
 * Only appears when the club has Spond credentials configured. Pulls
 * the live group list via `SpondClient::fetchGroups()` so the admin
 * picks from a dropdown rather than copy-pasting a UUID. The picked
 * value lands on `tt_teams.spond_group_id` in `ReviewStep`.
 *
 * Skipped automatically (`notApplicableFor`) when no credentials are
 * configured — those clubs can connect Spond later from the admin
 * page and pick a group from the team-edit form.
 */
final class SpondGroupStep implements WizardStepInterface {

    public function slug(): string  { return 'spond-group'; }
    public function label(): string { return __( 'Spond group', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ! CredentialsManager::hasCredentials();
    }

    public function render( array $state ): void {
        $current = (string) ( $state['spond_group_id'] ?? '' );
        $result  = SpondClient::fetchGroups();

        echo '<p style="color:#5b6e75; max-width:48em;">';
        esc_html_e( 'Pick the Spond group that matches this team, or leave on "Not connected" to skip Spond for now. You can always reconnect later from the team edit form.', 'talenttrack' );
        echo '</p>';

        if ( empty( $result['ok'] ) ) {
            $message = (string) ( $result['error_message'] ?? '' );
            echo '<p class="tt-notice" style="color:#b32d2e;">';
            esc_html_e( 'Could not load Spond groups.', 'talenttrack' );
            if ( $message !== '' ) echo ' (' . esc_html( $message ) . ')';
            echo '</p>';
            echo '<input type="hidden" name="spond_group_id" value="" />';
            return;
        }

        echo '<label><span>' . esc_html__( 'Spond group', 'talenttrack' ) . '</span><select name="spond_group_id">';
        echo '<option value="">' . esc_html__( '— Not connected —', 'talenttrack' ) . '</option>';
        foreach ( (array) $result['groups'] as $g ) {
            $gid = (string) ( $g['id']   ?? '' );
            $gnm = (string) ( $g['name'] ?? '' );
            if ( $gid === '' ) continue;
            echo '<option value="' . esc_attr( $gid ) . '" ' . selected( $current, $gid, false ) . '>'
                . esc_html( $gnm !== '' ? $gnm : $gid ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $gid = isset( $post['spond_group_id'] ) ? sanitize_text_field( wp_unslash( (string) $post['spond_group_id'] ) ) : '';
        return [ 'spond_group_id' => $gid ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
