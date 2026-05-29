<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Configuration\LookupCanonicalSeeds;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;
use WP_REST_Request;

/**
 * LookupNormalisationRestController (#987 v4.12.0) — operator actions
 * for the canonical-language drift review tool.
 *
 *   POST /lookup-normalisation/{audit_id}/accept   accept the rewrite
 *   POST /lookup-normalisation/{audit_id}/skip     skip without renaming
 *
 * "Accept" rewrites `tt_lookups.name` to the canonical English value,
 * preserves the drifted value as a `tt_translations` row in the
 * detected locale (so dashboards still render the operator-visible
 * label in that language), and writes a second `tt_audit_log` entry
 * with `action = 'lookup.normalisation.applied'` for traceability.
 *
 * "Skip" writes `action = 'lookup.normalisation.skipped'` so the
 * pending list stops surfacing the row — the operator has consciously
 * left the drifted value in place.
 *
 * Both flows write a follow-up entry rather than mutating the original
 * `lookup.needs_review` row — audit log is append-only by design. The
 * frontend filters review rows by joining against the latest action
 * for each entity_id.
 *
 * Cap gate: `tt_access_frontend_admin`. Same gate as the host view.
 * Mirrors how `FrontendConfigurationView` itself locks down.
 */
final class LookupNormalisationRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/lookup-normalisation/(?P<audit_id>\d+)/accept', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'accept' ],
                'permission_callback' => self::permCan( 'tt_access_frontend_admin' ),
                'args'                => self::auditIdArg(),
            ],
        ] );

        register_rest_route( self::NS, '/lookup-normalisation/(?P<audit_id>\d+)/skip', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'skip' ],
                'permission_callback' => self::permCan( 'tt_access_frontend_admin' ),
                'args'                => self::auditIdArg(),
            ],
        ] );
    }

    /** @return array<string,array<string,mixed>> */
    private static function auditIdArg(): array {
        return [
            'audit_id' => [
                'sanitize_callback' => 'absint',
                'validate_callback' => [ self::class, 'isPositiveInt' ],
            ],
        ];
    }

    /**
     * Accept the rewrite. Body may carry:
     *   - canonical: string (override the migration's suggestion;
     *     operator may pick a different canonical from the list)
     *   - locale:    string (override the heuristic-detected locale
     *     for the preserved translation)
     */
    public static function accept( WP_REST_Request $req ): \WP_REST_Response {
        $audit_id = (int) $req->get_param( 'audit_id' );
        $audit    = self::fetchAuditRow( $audit_id );
        if ( $audit === null ) {
            return RestResponse::error( 'audit_not_found', __( 'Audit row not found.', 'talenttrack' ), 404 );
        }
        if ( self::isResolved( (int) $audit->entity_id ) ) {
            return RestResponse::error( 'already_resolved', __( 'This row has already been reviewed.', 'talenttrack' ), 409 );
        }

        $payload = self::decodePayload( (string) $audit->payload );
        $lookup_type  = (string) ( $payload['lookup_type'] ?? '' );
        $current_name = (string) ( $payload['current_name'] ?? '' );
        $suggested    = (string) ( $payload['suggested'] ?? '' );
        $detected     = (string) ( $payload['detected_locale'] ?? '' );
        $row_id       = (int) $audit->entity_id;

        $body = $req->get_json_params();
        if ( ! is_array( $body ) ) $body = [];
        $canonical_override = isset( $body['canonical'] ) ? trim( (string) $body['canonical'] ) : '';
        $locale_override    = isset( $body['locale'] ) ? trim( (string) $body['locale'] ) : '';

        $canonical = $canonical_override !== '' ? $canonical_override : $suggested;
        $locale    = $locale_override    !== '' ? $locale_override    : $detected;

        if ( $canonical === '' ) {
            return RestResponse::error( 'canonical_required', __( 'No canonical value supplied or suggested for this row.', 'talenttrack' ), 400 );
        }
        // Defensive: only accept canonicals that are in the known list,
        // so an operator can't typo a fresh drift back into the column.
        $allowed = LookupCanonicalSeeds::canonicalFor( $lookup_type );
        if ( ! empty( $allowed ) && ! in_array( $canonical, $allowed, true ) ) {
            return RestResponse::error( 'canonical_not_in_list', __( 'Canonical value must come from the list for this lookup type.', 'talenttrack' ), 400 );
        }

        global $wpdb;
        $lookups_table = $wpdb->prefix . 'tt_lookups';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, lookup_type, name FROM {$lookups_table}
              WHERE id = %d AND club_id = %d",
            $row_id, CurrentClub::id()
        ) );
        if ( ! $existing ) {
            return RestResponse::error( 'row_vanished', __( 'The lookup row no longer exists.', 'talenttrack' ), 404 );
        }
        if ( (string) $existing->lookup_type !== $lookup_type ) {
            return RestResponse::error( 'lookup_type_mismatch', __( 'Lookup type changed since this drift was flagged.', 'talenttrack' ), 409 );
        }
        $stored_name = (string) $existing->name;

        // 1. Rewrite the canonical column.
        if ( $stored_name !== $canonical ) {
            $wpdb->update(
                $lookups_table,
                [
                    'name'       => $canonical,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $row_id, 'club_id' => CurrentClub::id() ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );
        }

        // 2. Preserve the drifted operator-visible value as a
        //    translation row for the detected locale. The drifted
        //    value is what the operator was used to seeing on
        //    dashboards; the translation chain will keep showing
        //    it post-rename.
        if ( $locale !== '' && $stored_name !== '' && $stored_name !== $canonical ) {
            ( new TranslationsRepository() )->upsert(
                TranslatableFieldRegistry::ENTITY_LOOKUP,
                $row_id,
                'name',
                $locale,
                $stored_name,
                (int) get_current_user_id()
            );
        }

        // 3. Log the resolution.
        self::recordResolution( $row_id, 'lookup.normalisation.applied', [
            'audit_id'        => $audit_id,
            'lookup_type'     => $lookup_type,
            'from'            => $stored_name,
            'to'              => $canonical,
            'preserved_as'    => $locale !== '' ? [ $locale => $stored_name ] : null,
        ] );

        return RestResponse::success( [
            'id'        => $row_id,
            'name'      => $canonical,
            'preserved' => $locale !== '' ? $locale : null,
        ] );
    }

    /**
     * Skip the row — record the operator's deliberate "leave as-is"
     * decision so the review queue stops surfacing it.
     */
    public static function skip( WP_REST_Request $req ): \WP_REST_Response {
        $audit_id = (int) $req->get_param( 'audit_id' );
        $audit    = self::fetchAuditRow( $audit_id );
        if ( $audit === null ) {
            return RestResponse::error( 'audit_not_found', __( 'Audit row not found.', 'talenttrack' ), 404 );
        }
        if ( self::isResolved( (int) $audit->entity_id ) ) {
            return RestResponse::error( 'already_resolved', __( 'This row has already been reviewed.', 'talenttrack' ), 409 );
        }

        self::recordResolution( (int) $audit->entity_id, 'lookup.normalisation.skipped', [
            'audit_id' => $audit_id,
        ] );

        return RestResponse::success( [ 'id' => (int) $audit->entity_id ] );
    }

    /**
     * Append a resolution row to the audit log. Append-only by design.
     *
     * @param array<string,mixed> $payload
     */
    private static function recordResolution( int $entity_id, string $action, array $payload ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_audit_log', [
            'club_id'     => CurrentClub::id(),
            'user_id'     => (int) get_current_user_id(),
            'action'      => $action,
            'entity_type' => 'lookup',
            'entity_id'   => $entity_id,
            'payload'     => (string) wp_json_encode( $payload ),
            'ip_address'  => '',
            'created_at'  => current_time( 'mysql' ),
        ] );
    }

    /**
     * Has this entity_id been resolved (accepted OR skipped) since the
     * `lookup.needs_review` entry was written?
     */
    private static function isResolved( int $entity_id ): bool {
        global $wpdb;
        $resolved = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log
              WHERE entity_type = 'lookup'
                AND entity_id   = %d
                AND action IN ( 'lookup.normalisation.applied', 'lookup.normalisation.skipped' )
                AND club_id     = %d",
            $entity_id, CurrentClub::id()
        ) );
        return $resolved > 0;
    }

    private static function fetchAuditRow( int $audit_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, action, entity_type, entity_id, payload, club_id
               FROM {$wpdb->prefix}tt_audit_log
              WHERE id = %d AND club_id = %d AND action = %s",
            $audit_id, CurrentClub::id(), 'lookup.needs_review'
        ) );
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodePayload( string $raw ): array {
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
