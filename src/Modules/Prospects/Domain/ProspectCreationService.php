<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * ProspectCreationService (#4015) — the one way a prospect comes into
 * existence.
 *
 * Which player question does this answer? *Where has this player come from?*
 * — the very first entry in it. A scout back from a visit records the child
 * they saw, and until this there were two hand-written copies of how that
 * happens and no way to do it over the API at all.
 *
 * ## Why this class exists
 *
 * Three surfaces create a prospect: the `new-prospect` wizard, the legacy
 * `LogProspectForm` workflow task, and now `POST /prospects`. Before this,
 * the first two each carried their own field map and their own duplicate
 * check — the same `findDuplicateCandidates()` call, the same sentence about
 * ticking "this is a new entry", written out twice. A third copy behind a
 * REST route would have guaranteed the three drifted, and a route that
 * accepted a duplicate the wizard refuses is not the same product from two
 * doors.
 *
 * So the field map, the validation, the duplicate rule and the follow-on
 * task live here, and the three surfaces are three ways of collecting the
 * same input.
 *
 * ## The duplicate rule is a question, not a refusal
 *
 * A likely duplicate comes back as a `WP_Error` carrying the candidate
 * names, and the caller may re-submit with `duplicate_override` set. That is
 * the wizard's behaviour, deliberately mirrored rather than replaced: two
 * children genuinely do share a name, and the check exists to make somebody
 * look, not to make the second one unrecordable.
 *
 * ## The follow-on task
 *
 * Creating a prospect dispatches `InviteToTestTrainingTemplate` for the head
 * of development, as the wizard's review step did. The legacy workflow form
 * passes `false`, because there the chain it is part of spawns the next step
 * itself and a second task would be a duplicate in somebody's inbox.
 */
final class ProspectCreationService {

    public const ERR_MISSING_NAME  = 'missing_name';
    public const ERR_BAD_DOB       = 'bad_dob';
    public const ERR_BAD_EMAIL     = 'invalid_parent_email';
    public const ERR_DUPLICATE     = 'duplicate_candidates';
    public const ERR_CREATE_FAILED = 'prospect_create_failed';

    /** Candidate names printed in the refusal before it elides. */
    private const MAX_NAMED = 5;

    /**
     * Record the prospect.
     *
     * @param array<string,mixed> $input Raw-ish field values; every key is
     *        optional except the two names. `consent_given` (truthy) is
     *        accepted as well as an explicit `consent_given_at`.
     * @param bool $dispatch_invite Whether to open the head of development's
     *        invite task. False only for the legacy workflow chain, which
     *        spawns its own next step.
     * @return int|\WP_Error The new prospect's id, or the refusal.
     */
    public function create( array $input, bool $dispatch_invite = true ) {
        $fields = self::normalise( $input );

        $invalid = self::validate( $fields );
        if ( $invalid instanceof \WP_Error ) return $invalid;

        // Checked against what arrived, not against what survived
        // `sanitize_email()`. An address that sanitising emptied was not an
        // address, and the scout needs telling now rather than finding the
        // contact silently blank a fortnight later.
        $raw_email = trim( (string) ( $input['parent_email'] ?? '' ) );
        if ( $raw_email !== '' && ! is_email( $raw_email ) ) {
            return new \WP_Error(
                self::ERR_BAD_EMAIL,
                __( 'Enter a valid parent email or leave it blank.', 'talenttrack' )
            );
        }

        if ( empty( $input['duplicate_override'] ) ) {
            $candidates = self::findDuplicates(
                (string) $fields['first_name'],
                (string) $fields['last_name'],
                isset( $fields['current_club'] ) ? (string) $fields['current_club'] : null
            );
            if ( $candidates !== [] ) {
                return self::duplicateError( $candidates );
            }
        }

        $prospect_id = ( new ProspectsRepository() )->create( $fields );
        if ( $prospect_id <= 0 ) {
            return new \WP_Error(
                self::ERR_CREATE_FAILED,
                __( 'Could not create the prospect record.', 'talenttrack' )
            );
        }

        if ( $dispatch_invite ) {
            self::dispatchInvite( $prospect_id );
        }

        return $prospect_id;
    }

    /**
     * The repository field map, sanitised.
     *
     * One copy of this existed in `ReviewStep::submit()` and another in
     * `LogProspectForm::serializeResponse()`, and they had already diverged:
     * only the wizard's passed `scouting_visit_id`, so a prospect logged
     * through the workflow task counted on no visit.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function normalise( array $input ): array {
        $consent_at = null;
        if ( ! empty( $input['consent_given_at'] ) ) {
            $consent_at = (string) $input['consent_given_at'];
        } elseif ( ! empty( $input['consent_given'] ) ) {
            $consent_at = (string) current_time( 'mysql', true );
        }

        $discovered_by = (int) ( $input['discovered_by_user_id'] ?? 0 );
        if ( $discovered_by <= 0 ) $discovered_by = (int) get_current_user_id();

        return [
            'first_name'            => sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ),
            'last_name'             => sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ),
            'date_of_birth'         => trim( (string) ( $input['date_of_birth'] ?? '' ) ) ?: null,
            'discovered_at'         => trim( (string) ( $input['discovered_at'] ?? '' ) ) ?: gmdate( 'Y-m-d' ),
            'discovered_by_user_id' => $discovered_by,
            'discovered_at_event'   => sanitize_text_field( (string) ( $input['discovered_at_event'] ?? '' ) ) ?: null,
            'current_club'          => sanitize_text_field( (string) ( $input['current_club'] ?? '' ) ) ?: null,
            'scouting_notes'        => sanitize_textarea_field( (string) ( $input['scouting_notes'] ?? '' ) ) ?: null,
            // #3600 — the visit the prospect was found at, so the visit's
            // own page lists who came out of it.
            'scouting_visit_id'     => (int) ( $input['scouting_visit_id'] ?? 0 ),
            'parent_name'           => sanitize_text_field( (string) ( $input['parent_name'] ?? '' ) ) ?: null,
            'parent_email'          => sanitize_email( (string) ( $input['parent_email'] ?? '' ) ) ?: null,
            'parent_phone'          => sanitize_text_field( (string) ( $input['parent_phone'] ?? '' ) ) ?: null,
            'consent_given_at'      => $consent_at,
        ];
    }

    /**
     * Everything that makes a field map unusable, independent of what else
     * is in the database.
     *
     * @param array<string,mixed> $fields A map from `normalise()`.
     * @return true|\WP_Error
     */
    public static function validate( array $fields ) {
        if ( (string) $fields['first_name'] === '' || (string) $fields['last_name'] === '' ) {
            return new \WP_Error(
                self::ERR_MISSING_NAME,
                __( 'First and last name are required.', 'talenttrack' )
            );
        }

        $dob = (string) ( $fields['date_of_birth'] ?? '' );
        if ( $dob !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return new \WP_Error(
                self::ERR_BAD_DOB,
                __( 'Use YYYY-MM-DD for the date of birth.', 'talenttrack' )
            );
        }

        return true;
    }

    /**
     * Prospects already on file who look like this one.
     *
     * @return object[]
     */
    public static function findDuplicates( string $first_name, string $last_name, ?string $current_club = null ): array {
        if ( trim( $first_name ) === '' || trim( $last_name ) === '' ) return [];

        return ( new ProspectsRepository() )->findDuplicateCandidates(
            $first_name,
            $last_name,
            // Not captured by any of the three create surfaces yet; passed
            // explicitly so a reader does not have to wonder whether the
            // omission is deliberate.
            null,
            ( $current_club !== null && trim( $current_club ) !== '' ) ? $current_club : null
        );
    }

    /**
     * The one sentence all three surfaces say about a likely duplicate.
     *
     * @param object[] $candidates
     */
    public static function duplicateMessage( array $candidates ): string {
        $names = array_values( array_filter( array_map(
            static fn ( $row ): string => trim(
                (string) ( ( (array) $row )['first_name'] ?? '' ) . ' ' .
                (string) ( ( (array) $row )['last_name'] ?? '' )
            ),
            array_slice( $candidates, 0, self::MAX_NAMED )
        ) ) );

        return sprintf(
            /* translators: %s: comma-separated list of likely-duplicate prospect names. */
            __( 'A prospect with this name already exists (%s). Tick "this is a new entry" if you have already checked.', 'talenttrack' ),
            implode( ', ', $names )
        );
    }

    /**
     * The refusal, carrying the candidates so an API consumer can show the
     * same choice the wizard shows rather than guessing what it collided
     * with.
     *
     * @param object[] $candidates
     */
    public static function duplicateError( array $candidates ): \WP_Error {
        $shaped = [];
        foreach ( array_slice( $candidates, 0, self::MAX_NAMED ) as $row ) {
            $r        = (array) $row;
            $shaped[] = [
                'id'           => (int) ( $r['id'] ?? 0 ),
                'first_name'   => (string) ( $r['first_name'] ?? '' ),
                'last_name'    => (string) ( $r['last_name'] ?? '' ),
                'current_club' => (string) ( $r['current_club'] ?? '' ),
            ];
        }

        return new \WP_Error(
            self::ERR_DUPLICATE,
            self::duplicateMessage( $candidates ),
            [
                'candidates'         => $shaped,
                'duplicate_override' => false,
            ]
        );
    }

    /**
     * Open the head of development's invite task for a fresh prospect.
     *
     * Guarded on both classes: the workflow module is switchable, and a
     * prospect that exists without its follow-up task is a worse outcome
     * than a fatal on a module that is off.
     */
    private static function dispatchInvite( int $prospect_id ): void {
        if ( ! class_exists( WorkflowModule::class ) || ! class_exists( InviteToTestTrainingTemplate::class ) ) {
            return;
        }

        $context = new TaskContext( null, null, null, null, null, null, null, $prospect_id );
        WorkflowModule::engine()->dispatch( InviteToTestTrainingTemplate::KEY, $context );
    }
}
