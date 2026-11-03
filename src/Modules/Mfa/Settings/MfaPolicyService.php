<?php
namespace TT\Modules\Mfa\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\ClubMembership;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\MfaSecretsRepository;

/**
 * MfaPolicyService (#4707) — the academy-wide rule for which personas must
 * enrol a second factor.
 *
 * The one write path for `mfa_required_personas`: the wp-admin operator
 * form, `PUT /settings/mfa-policy` and the Configuration form all save
 * through {@see save()}, so the same submission stores the same value and
 * writes the same audit row whichever surface it came from.
 */
final class MfaPolicyService {

    /** @var MfaSettings */
    private $settings;

    public function __construct( ?MfaSettings $settings = null ) {
        $this->settings = $settings ?? new MfaSettings();
    }

    /**
     * Keep the selectable persona keys from a submission and drop the rest.
     *
     * @param array<int|string,mixed> $raw
     * @return list<string>
     */
    public static function clean( array $raw ): array {
        $submitted = array_map( static fn ( $v ): string => sanitize_key( is_scalar( $v ) ? (string) $v : '' ), $raw );
        return array_values( array_intersect( array_keys( MfaSettings::operatorSelectablePersonas() ), $submitted ) );
    }

    /**
     * Store the required personas and audit the change.
     *
     * @param array<int|string,mixed> $raw submitted persona keys
     * @return list<string> what was stored
     */
    public function save( array $raw, int $actor_user_id ): array {
        $clean  = self::clean( $raw );
        $before = $this->settings->requiredPersonas();
        $this->settings->setRequiredPersonas( $clean );

        MfaAuditEvents::record(
            MfaAuditEvents::REQUIRED_PERSONAS_CHANGED,
            $actor_user_id,
            [ 'before' => $before, 'after' => $clean ]
        );
        return $clean;
    }

    /**
     * Every selectable persona with whether it is required, how many
     * accounts hold it and how many of those have not enrolled. Counts only:
     * the policy screen shows how many people a change reaches, never who.
     *
     * @return list<array{key:string, label:string, required:bool, total:int, unenrolled:int}>
     */
    public function overview(): array {
        $required = $this->settings->requiredPersonas();
        $counts   = self::counts();
        $out      = [];
        foreach ( MfaSettings::operatorSelectablePersonas() as $key => $label ) {
            $out[] = [
                'key'        => (string) $key,
                'label'      => (string) $label,
                'required'   => in_array( $key, $required, true ),
                'total'      => $counts[ $key ]['total'] ?? 0,
                'unenrolled' => $counts[ $key ]['unenrolled'] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Accounts per persona and how many of them have not enrolled. A user
     * holding two personas counts under both, the way the login gate
     * treats them.
     *
     * @return array<string, array{total:int, unenrolled:int}>
     */
    private static function counts(): array {
        $enrolled = [];
        foreach ( ( new MfaSecretsRepository() )->listEnrollments() as $row ) {
            if ( $row['enrolled_at'] !== null ) $enrolled[ $row['wp_user_id'] ] = true;
        }

        $out = [];
        // #4787 — the accounts this club's policy governs, by the gate's own rule.
        $ids = ClubMembership::userIdsGovernedBy( CurrentClub::id() );
        if ( $ids === [] ) return [];
        // One query for every user and their roles, instead of two per account in personasFor().
        cache_users( $ids );
        foreach ( $ids as $id ) {
            $uid = (int) $id;
            foreach ( PersonaResolver::personasFor( $uid ) as $persona ) {
                if ( ! isset( $out[ $persona ] ) ) $out[ $persona ] = [ 'total' => 0, 'unenrolled' => 0 ];
                $out[ $persona ]['total']++;
                if ( ! isset( $enrolled[ $uid ] ) ) $out[ $persona ]['unenrolled']++;
            }
        }
        return $out;
    }
}
