<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — first / last / DOB / current_club.
 *
 * Mirrors the LogProspectForm identity fields. Duplicate detection
 * runs at validate time via `ProspectsRepository::findDuplicateCandidates()`
 * — same logic the legacy form used. If matches exist and the user
 * hasn't ticked the override, validation fails with the candidate
 * names listed.
 */
final class IdentityStep implements WizardStepInterface {

    public function slug(): string { return 'identity'; }
    public function label(): string { return __( 'Identity', 'talenttrack' ); }

    public function render( array $state ): void {
        $first  = (string) ( $state['first_name']    ?? '' );
        $last   = (string) ( $state['last_name']     ?? '' );
        $dob    = (string) ( $state['date_of_birth'] ?? '' );
        $club   = (string) ( $state['current_club']  ?? '' );
        ?>
        <label>
            <span><?php esc_html_e( 'First name', 'talenttrack' ); ?> *</span>
            <input type="text" name="first_name" value="<?php echo esc_attr( $first ); ?>" required autocomplete="given-name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Last name', 'talenttrack' ); ?> *</span>
            <input type="text" name="last_name" value="<?php echo esc_attr( $last ); ?>" required autocomplete="family-name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></span>
            <input type="date" name="date_of_birth" value="<?php echo esc_attr( $dob ); ?>" />
        </label>
        <label>
            <span><?php esc_html_e( 'Current club', 'talenttrack' ); ?></span>
            <input type="text" name="current_club" value="<?php echo esc_attr( $club ); ?>" />
        </label>
        <label style="display:block; margin-top:12px;">
            <input type="checkbox" name="duplicate_override" value="1" <?php checked( ! empty( $state['duplicate_override'] ) ); ?> />
            <?php esc_html_e( 'I have checked the existing prospects list — this is a new entry', 'talenttrack' ); ?>
        </label>
        <?php
    }

    public function validate( array $post, array $state ) {
        $first  = isset( $post['first_name'] )    ? sanitize_text_field( (string) $post['first_name'] )    : '';
        $last   = isset( $post['last_name']  )    ? sanitize_text_field( (string) $post['last_name']  )    : '';
        $dob    = isset( $post['date_of_birth'] ) ? trim( (string) $post['date_of_birth'] )                : '';
        $club   = isset( $post['current_club']  ) ? sanitize_text_field( (string) $post['current_club']  ) : '';
        $override = ! empty( $post['duplicate_override'] );

        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'missing_name', __( 'First and last name are required.', 'talenttrack' ) );
        }
        if ( $dob !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return new \WP_Error( 'bad_dob', __( 'Use YYYY-MM-DD for the date of birth.', 'talenttrack' ) );
        }

        if ( ! $override ) {
            $repo = new ProspectsRepository();
            $candidates = $repo->findDuplicateCandidates( $first, $last, null, $club ?: null );
            if ( ! empty( $candidates ) ) {
                $names = array_filter( array_map(
                    static fn ( $c ) => trim( ( $c->first_name ?? '' ) . ' ' . ( $c->last_name ?? '' ) ),
                    array_slice( $candidates, 0, 5 )
                ) );
                return new \WP_Error( 'duplicate_candidates', sprintf(
                    /* translators: %s: comma-separated list of likely-duplicate prospect names. */
                    __( 'A prospect with this name already exists (%s). Tick "this is a new entry" if you have already checked.', 'talenttrack' ),
                    implode( ', ', $names )
                ) );
            }
        }

        return [
            'first_name'         => $first,
            'last_name'          => $last,
            'date_of_birth'      => $dob,
            'current_club'       => $club,
            'duplicate_override' => $override ? 1 : 0,
        ];
    }

    public function nextStep( array $state ): ?string { return 'discovery'; }
    public function submit( array $state ) { return null; }
}
