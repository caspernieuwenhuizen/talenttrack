<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\PdpFormAuthor;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormEntriesRepository;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Forms\PdpFormSignoff;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Modules\Pdp\Workflow\PdpSelfReviewTasks;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PdpConversationFormRestController (#4977, epic #4975) — the PDP
 * conversation form of one conversation.
 *
 *   GET    /pdp-conversations/{id}/form                     the form as the caller may read it
 *   PATCH  /pdp-conversations/{id}/form                     `{ form_key }`: which form (staff)
 *   PATCH  /pdp-conversations/{id}/form/player              the player's scores and answers
 *   PUT    /pdp-conversations/{id}/form/player/preparation  the player marks the preparation finished
 *   DELETE /pdp-conversations/{id}/form/player/preparation  … and takes that back while unlocked
 *   PATCH  /pdp-conversations/{id}/form/coach               the trainer's scores, answers and SMART points
 *   POST   /pdp-conversations/{id}/signoff                  the irreversible commit
 *
 * The pre-gate is `tt_view_pdp`, which every legitimate actor holds and the
 * read-only observer does not. The per-record decision is
 * `PdpFormAccess`, the shape `PdpFormReader`, the commit `PdpFormSignoff`;
 * this controller checks bodies and maps answers.
 *
 * Both `PATCH` routes autosave (save model A): a key that is not sent is
 * left alone. "Klaar" and sign-off are their own resources, so no debounce
 * can send them.
 */
final class PdpConversationFormRestController extends BaseController {

    private const BASE = '/pdp-conversations/(?P<id>\d+)';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $id_arg = [ 'id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'The conversation, from the URL.' ] ];

        register_rest_route( self::NS, self::BASE . '/form', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'show' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'chooseForm' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
                'args'                => $id_arg + self::chooseArgs(),
            ],
        ] );
        register_rest_route( self::NS, self::BASE . '/form/player', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patchPlayer' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
                'args'                => $id_arg + self::playerArgs(),
            ],
        ] );
        register_rest_route( self::NS, self::BASE . '/form/player/preparation', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'markPrepared' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
                'args'                => $id_arg,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'unmarkPrepared' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
                'args'                => $id_arg,
            ],
        ] );
        register_rest_route( self::NS, self::BASE . '/form/coach', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patchCoach' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
                'args'                => $id_arg + self::coachArgs(),
            ],
        ] );
        register_rest_route( self::NS, self::BASE . '/signoff', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'signOff' ],
                'permission_callback' => [ __CLASS__, 'can_use' ],
                'args'                => $id_arg,
            ],
        ] );
    }

    /** @return array<string, array<string,mixed>> */
    private static function chooseArgs(): array {
        return [ 'form_key' => [ 'type' => 'string', 'required' => true, 'description' => 'A conversation form the academy uses.' ] ];
    }

    /** @return array<string, array<string,mixed>> */
    private static function playerArgs(): array {
        return [
            'scores'  => [ 'type' => 'object', 'description' => 'Item key => 1-5, or null to clear. Items not sent are left alone.' ],
            'answers' => [ 'type' => 'object', 'description' => 'Field key => text. Fields not sent are left alone.' ],
        ];
    }

    /** @return array<string, array<string,mixed>> */
    private static function coachArgs(): array {
        return self::playerArgs() + [
            'goals' => [ 'type' => 'object', 'description' => 'Slot => { action, support, due_date, item_key }, each field optional. Slots and fields not sent are left alone.' ],
        ];
    }

    /**
     * The pre-gate: a PDP right. The read-only observer holds none; who may
     * do what with THIS conversation is `PdpFormAccess::forConversation()`
     * in each callback.
     */
    public static function can_use(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_view_pdp' );
    }

    public static function show( WP_REST_Request $r ): WP_REST_Response {
        $access = PdpFormAccess::forConversation( get_current_user_id(), absint( $r['id'] ) );
        if ( $access === null ) return self::notFound();
        if ( ! $access->canRead() ) return self::forbidden();
        return RestResponse::success( ( new PdpFormReader() )->read( $access, $access->conversation() ) );
    }

    public static function chooseForm( WP_REST_Request $r ): WP_REST_Response {
        $refused = self::checkBody( $r, self::chooseArgs() );
        if ( $refused !== null ) return $refused;

        $access = PdpFormAccess::forConversation( get_current_user_id(), absint( $r['id'] ) );
        if ( $access === null ) return self::notFound();
        if ( ! $access->canRead() ) return self::forbidden();
        $conv = $access->conversation();
        if ( ! empty( ( (array) $conv )['coach_signoff_at'] ) ) return self::signedOffError();
        if ( ! $access->choosesForm() ) return self::forbidden();

        $form_key = sanitize_key( (string) $r->get_param( 'form_key' ) );
        $offered  = array_values( array_filter( PdpFormRegistry::formsForClub() ) );
        if ( ! in_array( $form_key, $offered, true ) ) {
            return RestResponse::error( 'invalid_field', __( 'That conversation form is not available at this academy.', 'talenttrack' ), 400, [ 'field' => 'form_key', 'allowed' => $offered ] );
        }

        $id      = (int) ( (array) $conv )['id'];
        $current = (string) ( ( (array) $conv )['form_key'] ?? '' );
        if ( $current !== $form_key ) {
            // Owner decision 6 on #4975: the form is fixed at the player's
            // first save. Before that the trainer may switch it.
            if ( $current !== '' && ( new PdpFormEntriesRepository() )->hasEntries( $id, PdpFormAuthor::PLAYER ) ) {
                return RestResponse::error( 'form_fixed', __( 'The player has started on this form, so it can no longer be switched.', 'talenttrack' ), 409 );
            }
            $ok = ( new PdpConversationsRepository() )->update( $id, [ 'form_key' => $form_key, 'form_version' => PdpFormRegistry::latestVersion( $form_key ) ] );
            if ( ! $ok ) return self::dbError();
        }
        return self::fresh( $id );
    }

    public static function patchPlayer( WP_REST_Request $r ): WP_REST_Response {
        $refused = self::checkBody( $r, self::playerArgs() );
        if ( $refused !== null ) return $refused;

        $access = PdpFormAccess::forConversation( get_current_user_id(), absint( $r['id'] ) );
        if ( $access === null ) return self::notFound();
        if ( ! $access->canRead() || ! $access->isPlayer() ) return self::forbidden();
        $conv       = $access->conversation();
        $definition = PdpFormReader::definitionFor( $conv );
        if ( $definition === null ) return self::noForm();
        if ( PdpCycleState::isNotHeld( $conv ) ) return self::notHeld();
        if ( ! $access->writesPlayerPart() ) return self::lockedError();

        $parsed = self::parseEntries( $r, $definition, PdpFormAuthor::PLAYER, false );
        if ( $parsed instanceof WP_REST_Response ) return $parsed;

        $written = self::writeEntries( (int) ( (array) $conv )['id'], $definition, PdpFormAuthor::PLAYER, $parsed );
        if ( ! $written ) return self::dbError();
        return self::fresh( (int) ( (array) $conv )['id'] );
    }

    public static function markPrepared( WP_REST_Request $r ): WP_REST_Response {
        return self::setPrepared( $r, true );
    }

    public static function unmarkPrepared( WP_REST_Request $r ): WP_REST_Response {
        return self::setPrepared( $r, false );
    }

    public static function patchCoach( WP_REST_Request $r ): WP_REST_Response {
        $refused = self::checkBody( $r, self::coachArgs() );
        if ( $refused !== null ) return $refused;

        $access = PdpFormAccess::forConversation( get_current_user_id(), absint( $r['id'] ) );
        if ( $access === null ) return self::notFound();
        if ( ! $access->canRead() ) return self::forbidden();
        $conv = $access->conversation();
        if ( ! empty( ( (array) $conv )['coach_signoff_at'] ) ) return self::signedOffError();
        if ( ! $access->writesCoachPart() ) return self::forbidden();
        $definition = PdpFormReader::definitionFor( $conv );
        if ( $definition === null ) return self::noForm();
        if ( PdpCycleState::isNotHeld( $conv ) ) return self::notHeld();

        $parsed = self::parseEntries( $r, $definition, PdpFormAuthor::COACH, true );
        if ( $parsed instanceof WP_REST_Response ) return $parsed;

        $written = self::writeEntries( (int) ( (array) $conv )['id'], $definition, PdpFormAuthor::COACH, $parsed );
        if ( ! $written ) return self::dbError();
        return self::fresh( (int) ( (array) $conv )['id'] );
    }

    public static function signOff( WP_REST_Request $r ): WP_REST_Response {
        $uid    = get_current_user_id();
        $access = PdpFormAccess::forConversation( $uid, absint( $r['id'] ) );
        if ( $access === null ) return self::notFound();
        if ( ! $access->canRead() ) return self::forbidden();
        $conv = $access->conversation();
        if ( ! empty( ( (array) $conv )['coach_signoff_at'] ) ) return self::signedOffError();
        if ( ! $access->signsOff() ) return self::forbidden();
        if ( PdpCycleState::isNotHeld( $conv ) ) return self::notHeld();
        // #2041 — talks are signed off in order, as on the conversation route.
        $row = (array) $conv;
        if ( (int) $row['id'] !== ( new PdpConversationsRepository() )->activeConversationId( (int) ( $row['pdp_file_id'] ?? 0 ) ) ) {
            return RestResponse::error( 'conversation_not_active', __( 'Only the current talk can be signed off. Earlier talks come first.', 'talenttrack' ), 409 );
        }

        $result = PdpFormSignoff::signOff( $uid, $conv, $access->playerId() );
        if ( $result instanceof \WP_Error ) {
            $data   = (array) $result->get_error_data();
            $status = (int) ( $data['status'] ?? 400 );
            unset( $data['status'] );
            return RestResponse::error( (string) $result->get_error_code(), $result->get_error_message(), $status, $data );
        }
        return RestResponse::success( $result, 201 );
    }

    private static function setPrepared( WP_REST_Request $r, bool $prepared ): WP_REST_Response {
        $refused = self::checkBody( $r, [] );
        if ( $refused !== null ) return $refused;

        $access = PdpFormAccess::forConversation( get_current_user_id(), absint( $r['id'] ) );
        if ( $access === null ) return self::notFound();
        if ( ! $access->canRead() || ! $access->isPlayer() ) return self::forbidden();
        $conv = $access->conversation();
        if ( PdpFormReader::definitionFor( $conv ) === null ) return self::noForm();
        if ( PdpCycleState::isNotHeld( $conv ) ) return self::notHeld();
        if ( ! $access->writesPlayerPart() ) return self::lockedError();

        $row  = (array) $conv;
        $id   = (int) $row['id'];
        $now  = (string) ( $row['player_prepared_at'] ?? '' );
        if ( $prepared === ( $now !== '' ) ) return self::fresh( $id );

        $patch = [ 'player_prepared_at' => $prepared ? current_time( 'mysql', true ) : null ];
        if ( ! ( new PdpConversationsRepository() )->update( $id, $patch ) ) return self::dbError();

        $file = ( new \TT\Modules\Pdp\Repositories\PdpFilesRepository() )->find( (int) ( $row['pdp_file_id'] ?? 0 ) );
        if ( $file !== null ) PdpSelfReviewTasks::syncAfterPatch( $conv, $file, $patch );

        return self::fresh( $id );
    }

    /**
     * Check the `scores`, `answers` and (for the trainer) `goals` of a body
     * against the pinned definition. A key the form does not have is
     * `400 unknown_field`; a value it cannot take is `400 invalid_field`.
     *
     * @param array<string,mixed> $definition
     * @return array{scores:array<string,int|null>, answers:array<string,string>, goals:array<int,array<string,string|null>>}|WP_REST_Response
     */
    private static function parseEntries( WP_REST_Request $r, array $definition, string $author, bool $with_goals ) {
        $out   = [ 'scores' => [], 'answers' => [], 'goals' => [] ];
        $items = array_column( PdpFormRegistry::items( $definition ), 'key' );

        $scores = $r->get_param( 'scores' );
        if ( $scores !== null ) {
            if ( ! is_array( $scores ) ) return self::invalid( 'scores', __( 'Send the scores as an object of item => score.', 'talenttrack' ) );
            $unknown = array_values( array_diff( array_map( 'strval', array_keys( $scores ) ), $items ) );
            if ( $unknown !== [] ) return self::unknown( 'scores', $unknown, $items );
            foreach ( $scores as $item => $value ) {
                if ( $value === null || $value === '' ) {
                    $out['scores'][ (string) $item ] = null;
                    continue;
                }
                $int = filter_var( $value, FILTER_VALIDATE_INT );
                if ( $int === false || $int < 1 || $int > 5 ) {
                    return RestResponse::error( 'invalid_field', __( 'A score is a whole number from 1 to 5.', 'talenttrack' ), 400, [ 'field' => 'scores.' . $item, 'min' => 1, 'max' => 5 ] );
                }
                $out['scores'][ (string) $item ] = $int;
            }
        }

        $answers = $r->get_param( 'answers' );
        if ( $answers !== null ) {
            if ( ! is_array( $answers ) ) return self::invalid( 'answers', __( 'Send the answers as an object of field => text.', 'talenttrack' ) );
            $fields  = PdpFormRegistry::answerFields( $definition, $author );
            $unknown = array_values( array_diff( array_map( 'strval', array_keys( $answers ) ), $fields ) );
            if ( $unknown !== [] ) return self::unknown( 'answers', $unknown, $fields );
            foreach ( $answers as $field => $value ) {
                if ( $value !== null && ! is_scalar( $value ) ) return self::invalid( 'answers.' . $field, __( 'An answer is text.', 'talenttrack' ) );
                $out['answers'][ (string) $field ] = sanitize_textarea_field( (string) ( $value ?? '' ) );
            }
        }

        if ( ! $with_goals ) return $out;

        $goals = $r->get_param( 'goals' );
        if ( $goals !== null ) {
            if ( ! is_array( $goals ) ) return self::invalid( 'goals', __( 'Send the development points as an object of slot => fields.', 'talenttrack' ) );
            $slots   = array_map( 'strval', array_keys( PdpFormRegistry::goalSlots( $definition ) ) );
            $unknown = array_values( array_diff( array_map( 'strval', array_keys( $goals ) ), $slots ) );
            if ( $unknown !== [] ) return self::unknown( 'goals', $unknown, $slots );
            $names = [ 'action' => 'action_text', 'support' => 'support_text', 'due_date' => 'due_date', 'item_key' => 'item_key' ];
            foreach ( $goals as $slot => $fields ) {
                if ( ! is_array( $fields ) ) return self::invalid( 'goals.' . $slot, __( 'A development point is an object of action, support, due_date and item_key.', 'talenttrack' ) );
                $unknown = array_values( array_diff( array_map( 'strval', array_keys( $fields ) ), array_keys( $names ) ) );
                if ( $unknown !== [] ) return self::unknown( 'goals.' . $slot, $unknown, array_keys( $names ) );
                $clean = [];
                foreach ( $fields as $name => $value ) {
                    if ( $value !== null && ! is_scalar( $value ) ) return self::invalid( "goals.{$slot}.{$name}", __( 'A development point field is text.', 'talenttrack' ) );
                    $value = trim( (string) ( $value ?? '' ) );
                    if ( $name === 'due_date' ) {
                        if ( $value !== '' && ! self::isDate( $value ) ) {
                            return RestResponse::error( 'invalid_field', __( 'A deadline is a date (YYYY-MM-DD).', 'talenttrack' ), 400, [ 'field' => "goals.{$slot}.due_date" ] );
                        }
                        $clean['due_date'] = $value !== '' ? $value : null;
                    } elseif ( $name === 'item_key' ) {
                        if ( $value !== '' && ! in_array( $value, $items, true ) ) {
                            return RestResponse::error( 'invalid_field', __( 'A development point refers to an item of this form.', 'talenttrack' ), 400, [ 'field' => "goals.{$slot}.item_key", 'allowed' => $items ] );
                        }
                        $clean['item_key'] = $value !== '' ? $value : null;
                    } else {
                        $clean[ $names[ $name ] ] = sanitize_textarea_field( $value );
                    }
                }
                $out['goals'][ (int) $slot ] = $clean;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $definition
     * @param array{scores:array<string,int|null>, answers:array<string,string>, goals:array<int,array<string,string|null>>} $parsed
     */
    private static function writeEntries( int $conversation_id, array $definition, string $author, array $parsed ): bool {
        $repo     = new PdpFormEntriesRepository();
        $form_key = (string) $definition['form_key'];
        $uid      = get_current_user_id();
        foreach ( $parsed['scores'] as $item => $score ) {
            if ( ! $repo->saveScore( $conversation_id, $form_key, $item, $author, $score, $uid ) ) return false;
        }
        foreach ( $parsed['answers'] as $field => $body ) {
            if ( ! $repo->saveAnswer( $conversation_id, $form_key, $field, $author, $body, $uid ) ) return false;
        }
        foreach ( $parsed['goals'] as $slot => $fields ) {
            if ( $fields === [] ) continue;
            if ( ! $repo->saveGoal( $conversation_id, $slot, $fields, $uid ) ) return false;
        }
        return true;
    }

    /** The form as the caller now reads it, after a write. */
    private static function fresh( int $conversation_id ): WP_REST_Response {
        $access = PdpFormAccess::forConversation( get_current_user_id(), $conversation_id );
        if ( $access === null ) return self::notFound();
        return RestResponse::success( ( new PdpFormReader() )->read( $access, $access->conversation() ) );
    }

    private static function isDate( string $value ): bool {
        $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
        return $d !== false && $d->format( 'Y-m-d' ) === $value;
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $allowed
     */
    private static function unknown( string $group, array $unknown, array $allowed ): WP_REST_Response {
        $fields = array_map( static fn( string $k ): string => $group . '.' . $k, $unknown );
        return RestResponse::error(
            'unknown_field',
            sprintf(
                /* translators: %s: comma-separated field names */
                __( 'This form does not have: %s.', 'talenttrack' ),
                implode( ', ', $fields )
            ),
            400,
            [ 'fields' => $fields, 'allowed' => $allowed ]
        );
    }

    private static function invalid( string $field, string $message ): WP_REST_Response {
        return RestResponse::error( 'invalid_field', $message, 400, [ 'field' => $field ] );
    }

    private static function notFound(): WP_REST_Response {
        return RestResponse::error( 'not_found', __( 'Conversation not found.', 'talenttrack' ), 404 );
    }

    private static function forbidden(): WP_REST_Response {
        return RestResponse::error( 'forbidden', __( 'You do not have permission to do this on this conversation form.', 'talenttrack' ), 403 );
    }

    private static function noForm(): WP_REST_Response {
        return RestResponse::error( 'no_form', __( 'This conversation does not use a conversation form yet.', 'talenttrack' ), 409 );
    }

    private static function notHeld(): WP_REST_Response {
        return RestResponse::error( 'conversation_not_held', __( 'This talk did not take place, so its form takes no entries.', 'talenttrack' ), 409 );
    }

    private static function lockedError(): WP_REST_Response {
        return RestResponse::error( 'form_locked', __( 'The conversation has started, so your part of the form can no longer be changed.', 'talenttrack' ), 409 );
    }

    private static function signedOffError(): WP_REST_Response {
        return RestResponse::error( 'already_signed_off', __( 'This conversation has already been signed off.', 'talenttrack' ), 409 );
    }

    private static function dbError(): WP_REST_Response {
        return RestResponse::error( 'db_error', __( 'The form could not be saved. Try again.', 'talenttrack' ), 500 );
    }
}
