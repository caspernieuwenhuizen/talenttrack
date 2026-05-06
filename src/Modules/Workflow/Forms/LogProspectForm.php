<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * LogProspectForm (#0081 child 2) — the scout's quick-capture form.
 *
 * Fields: identity (first/last name, DOB, current club, age group,
 * preferred position), discovery context (event, scouting notes), and
 * parent contact (name, email, phone, consent date). Two-screen UX is
 * intended for child 3's UI polish; for now this renders as a single
 * scrollable form because that's what `FrontendTaskDetailView` knows
 * how to render — the response payload shape is final, only the
 * presentation lifts in the next pass.
 *
 * Duplicate detection runs at validate time. `ProspectsRepository::
 * findDuplicateCandidates()` returns up-to-5 matches on first/last
 * name + age group + current club. If matches exist and the user
 * hasn't ticked the "I've checked, this is a new prospect" override,
 * validation fails with a `__form` error listing the candidates.
 * False positives are preferable to false negatives in this domain.
 *
 * On `serializeResponse()` (called after validate has passed), the
 * form writes the `tt_prospects` row directly via the repository and
 * embeds `prospect_id` in the returned payload. The template's
 * `onComplete()` stamps the ID onto the task's `prospect_id` column
 * so the pipeline widget can join. This is a deliberate choice over
 * a separate "create prospect" REST call — the form IS the entity-
 * creation flow; splitting it would invite race conditions where the
 * task completes but the prospect doesn't exist (or vice-versa).
 */
class LogProspectForm implements FormInterface {

    public function render( array $task ): string {
        $existing  = self::decodeResponse( $task );
        $disabled  = self::completedAttr( $task );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <h3 style="margin:0 0 12px; font-size:1rem;"><?php esc_html_e( 'Identity', 'talenttrack' ); ?></h3>

            <p style="margin: 0 0 6px;">
                <label for="tt-lp-first" style="font-weight:600;"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="text" id="tt-lp-first" name="first_name" required
                       value="<?php echo esc_attr( (string) ( $existing['first_name'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-lp-last" style="font-weight:600;"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="text" id="tt-lp-last" name="last_name" required
                       value="<?php echo esc_attr( (string) ( $existing['last_name'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-lp-dob"><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="date" id="tt-lp-dob" name="date_of_birth"
                       value="<?php echo esc_attr( (string) ( $existing['date_of_birth'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-lp-club"><?php esc_html_e( 'Current club', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="text" id="tt-lp-club" name="current_club"
                       value="<?php echo esc_attr( (string) ( $existing['current_club'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <h3 style="margin:18px 0 12px; font-size:1rem;"><?php esc_html_e( 'Discovery', 'talenttrack' ); ?></h3>

            <p style="margin: 0 0 6px;">
                <label for="tt-lp-event"><?php esc_html_e( 'Discovered at (event / match)', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="text" id="tt-lp-event" name="discovered_at_event"
                       value="<?php echo esc_attr( (string) ( $existing['discovered_at_event'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-lp-notes"><?php esc_html_e( 'Scouting notes', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-lp-notes" name="scouting_notes" rows="4" style="width:100%;"
                          <?php echo $disabled; ?>><?php
                    echo esc_textarea( (string) ( $existing['scouting_notes'] ?? '' ) );
                ?></textarea>
            </p>

            <h3 style="margin:18px 0 12px; font-size:1rem;"><?php esc_html_e( 'Parent contact', 'talenttrack' ); ?></h3>

            <p style="margin: 0 0 6px;">
                <label for="tt-lp-pname"><?php esc_html_e( 'Parent name', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="text" id="tt-lp-pname" name="parent_name"
                       value="<?php echo esc_attr( (string) ( $existing['parent_name'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-lp-pemail"><?php esc_html_e( 'Parent email', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="email" id="tt-lp-pemail" name="parent_email"
                       inputmode="email" autocomplete="email"
                       value="<?php echo esc_attr( (string) ( $existing['parent_email'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <p style="margin: 12px 0 6px;">
                <label for="tt-lp-pphone"><?php esc_html_e( 'Parent phone', 'talenttrack' ); ?></label>
            </p>
            <p>
                <input type="tel" id="tt-lp-pphone" name="parent_phone"
                       inputmode="tel" autocomplete="tel"
                       value="<?php echo esc_attr( (string) ( $existing['parent_phone'] ?? '' ) ); ?>"
                       <?php echo $disabled; ?> style="width:100%;" />
            </p>

            <p style="margin: 16px 0 6px;">
                <label>
                    <input type="checkbox" name="consent_given" value="1"
                           <?php echo ! empty( $existing['consent_given_at'] ) ? 'checked' : ''; ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Parent has given consent for the academy to hold this contact information', 'talenttrack' ); ?>
                </label>
            </p>

            <p style="margin: 18px 0 6px;">
                <label>
                    <input type="checkbox" name="duplicate_override" value="1" <?php echo $disabled; ?> />
                    <?php esc_html_e( 'I have checked the existing prospects list — this is a new entry', 'talenttrack' ); ?>
                </label>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];

        $first = trim( (string) ( $raw['first_name'] ?? '' ) );
        $last  = trim( (string) ( $raw['last_name']  ?? '' ) );
        if ( $first === '' ) $errors['first_name'] = __( 'First name is required.', 'talenttrack' );
        if ( $last  === '' ) $errors['last_name']  = __( 'Last name is required.', 'talenttrack' );

        $dob = (string) ( $raw['date_of_birth'] ?? '' );
        if ( $dob !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            $errors['date_of_birth'] = __( 'Use YYYY-MM-DD for the date of birth.', 'talenttrack' );
        }

        $email = (string) ( $raw['parent_email'] ?? '' );
        if ( $email !== '' && ! is_email( $email ) ) {
            $errors['parent_email'] = __( 'Enter a valid parent email or leave it blank.', 'talenttrack' );
        }

        if ( empty( $errors ) && empty( $raw['duplicate_override'] ) ) {
            $repo = new ProspectsRepository();
            $candidates = $repo->findDuplicateCandidates(
                $first,
                $last,
                null, // age_group_lookup_id not yet captured by this form
                trim( (string) ( $raw['current_club'] ?? '' ) ) ?: null
            );
            if ( ! empty( $candidates ) ) {
                $names = array_map(
                    static fn ( $c ) => trim( ( $c->first_name ?? '' ) . ' ' . ( $c->last_name ?? '' ) ),
                    array_slice( $candidates, 0, 5 )
                );
                $errors['__form'] = sprintf(
                    /* translators: %s: comma-separated list of likely-duplicate prospect names. */
                    __( 'A prospect with this name already exists (%s). Tick "this is a new entry" if you have already checked.', 'talenttrack' ),
                    implode( ', ', array_filter( $names ) )
                );
            }
        }

        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $repo = new ProspectsRepository();

        $consent_at = ! empty( $raw['consent_given'] ) ? current_time( 'mysql', true ) : null;

        $prospect_id = $repo->create( [
            'first_name'                  => sanitize_text_field( (string) ( $raw['first_name'] ?? '' ) ),
            'last_name'                   => sanitize_text_field( (string) ( $raw['last_name']  ?? '' ) ),
            'date_of_birth'               => trim( (string) ( $raw['date_of_birth'] ?? '' ) ) ?: null,
            'discovered_at'               => gmdate( 'Y-m-d' ),
            'discovered_by_user_id'       => (int) ( $task['assignee_user_id'] ?? get_current_user_id() ),
            'discovered_at_event'         => sanitize_text_field( (string) ( $raw['discovered_at_event'] ?? '' ) ) ?: null,
            'current_club'                => sanitize_text_field( (string) ( $raw['current_club'] ?? '' ) ) ?: null,
            'scouting_notes'              => sanitize_textarea_field( (string) ( $raw['scouting_notes'] ?? '' ) ) ?: null,
            'parent_name'                 => sanitize_text_field( (string) ( $raw['parent_name'] ?? '' ) ) ?: null,
            'parent_email'                => sanitize_email( (string) ( $raw['parent_email'] ?? '' ) ) ?: null,
            'parent_phone'                => sanitize_text_field( (string) ( $raw['parent_phone'] ?? '' ) ) ?: null,
            'consent_given_at'            => $consent_at,
        ] );

        return [
            'prospect_id'         => $prospect_id,
            'first_name'          => sanitize_text_field( (string) ( $raw['first_name'] ?? '' ) ),
            'last_name'           => sanitize_text_field( (string) ( $raw['last_name']  ?? '' ) ),
            'date_of_birth'       => trim( (string) ( $raw['date_of_birth'] ?? '' ) ) ?: null,
            'current_club'        => sanitize_text_field( (string) ( $raw['current_club'] ?? '' ) ),
            'discovered_at_event' => sanitize_text_field( (string) ( $raw['discovered_at_event'] ?? '' ) ),
            'scouting_notes'      => sanitize_textarea_field( (string) ( $raw['scouting_notes'] ?? '' ) ),
            'parent_name'         => sanitize_text_field( (string) ( $raw['parent_name'] ?? '' ) ),
            'parent_email'        => sanitize_email( (string) ( $raw['parent_email'] ?? '' ) ),
            'parent_phone'        => sanitize_text_field( (string) ( $raw['parent_phone'] ?? '' ) ),
            'consent_given_at'    => $consent_at,
        ];
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }
}
