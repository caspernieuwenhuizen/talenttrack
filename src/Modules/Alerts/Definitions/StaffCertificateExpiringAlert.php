<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * StaffCertificateExpiringAlert (#2636, epic #2629) — a coaching or
 * safeguarding certificate is about to run out.
 *
 * Which player question does this answer? *What does this player need
 * next?* — answered from the other side. Every player in the squad needs
 * the person running their session to be qualified to run it, and a lapsed
 * safeguarding certificate is a player-protection problem wearing an
 * administrative disguise. It is the one alert in this wave whose subject
 * is not a player and whose justification still is one.
 *
 * ## The only definition in the wave that does not extend AbstractPlayerAlert
 *
 * A certificate belongs to a person, not to a player or a team, so there is
 * no `player_id` to carry and no team head coach to resolve. Bending it into
 * the player-shaped base would mean inventing a relationship that is not
 * there.
 *
 * ## Audience: the certificate holder, and only them
 *
 * The person whose certificate it is, via `tt_people.wp_user_id`. Not their
 * team's players' coaches, not every administrator — this is somebody's own
 * professional record, and epic decision 7 sends an occurrence to whoever
 * can fix the thing. A staff member with no linked account produces no
 * occurrence: there is genuinely nobody to tell, and the Head of
 * Development already has the org-wide roll-up at
 * `tt_view_staff_certifications_expiry`.
 *
 * ## The window is symmetric
 *
 * `alerts_staff_cert_expiring_days` (60 by default) reaches both forwards
 * and backwards. Dropping already-expired certificates would make the alert
 * vanish at exactly the moment the problem becomes real. Reaching back
 * forever would keep certificates from three jobs ago on the list; past the
 * window it is not "expiring", it is lapsed, and that is a different
 * conversation.
 */
final class StaffCertificateExpiringAlert implements AlertInterface {

    public const SUBJECT_TYPE = 'staff_certification';

    /** tt_config key: the symmetric window around today, in days. */
    public const CONFIG_KEY_WINDOW_DAYS = 'alerts_staff_cert_expiring_days';

    private const DEFAULT_WINDOW_DAYS = 60;
    private const URGENT_WITHIN_DAYS  = 14;

    /** @var ConfigService|null */
    private $config = null;

    public function key(): string {
        return 'people.staff_certificate_expiring';
    }

    public function module(): string {
        return 'people';
    }

    public function label(): string {
        return __( 'Certificate expiring', 'talenttrack' );
    }

    public function description(): string {
        return __( 'One of your certificates is about to expire or has just expired. Every player in the squad needs the person running their session to be qualified to run it.', 'talenttrack' );
    }

    public function defaultSeverity(): string {
        return Severity::ATTENTION;
    }

    /**
     * The staff-development read capability — the one that opens a staff
     * member's own record. Not the org-wide
     * `tt_view_staff_certifications_expiry`, which is the Head of
     * Development's roll-up and would exclude the very people this alert
     * is for.
     */
    public function capRequired(): string {
        return 'tt_view_staff_development';
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ 'badge', 'banner' ];
    }

    /**
     * Not operational. Safeguarding certificates are close to the line, but
     * the person who can least afford to mute this is the person it is
     * about, and the club-policy layer (#2632) is where an academy that
     * wants it non-mutable should say so — that is a club's call, not a
     * definition's.
     */
    public function isOperational(): bool {
        return false;
    }

    /** @return list<AlertOccurrence> */
    public function evaluate( AlertContext $context ): array {
        $out = [];
        foreach ( $this->rows( $context ) as $row ) {
            $cert_id = (int) ( $row->subject_id ?? 0 );
            $user_id = (int) ( $row->wp_user_id ?? 0 );
            if ( $cert_id <= 0 || $user_id <= 0 ) continue;

            $out[] = new AlertOccurrence(
                $this->key(),
                $user_id,
                self::SUBJECT_TYPE,
                $cert_id,
                $this->severityFor( $row ),
                [
                    'title'      => $this->titleFor( $row ),
                    'url'        => (string) add_query_arg(
                        'tt_view',
                        'my-staff-certifications',
                        RecordLink::dashboardUrl()
                    ),
                    'cert_name'  => (string) ( $row->cert_name ?? '' ),
                    'expires_on' => (string) ( $row->expires_on ?? '' ),
                ]
            );
        }
        return $out;
    }

    /** @return list<object> */
    private function rows( AlertContext $context ): array {
        global $wpdb;
        $p      = $wpdb->prefix;
        $window = $this->windowDays();

        // The certificate type is a lookup row, LEFT-joined: a certificate
        // whose type was deleted still expires, and losing the alert
        // because the vocabulary changed would be the wrong failure.
        $sql = $wpdb->prepare(
            "SELECT c.id AS subject_id, c.expires_on, c.person_id,
                    pe.wp_user_id, pe.first_name, pe.last_name,
                    l.name AS cert_name
               FROM {$p}tt_staff_certifications c
         INNER JOIN {$p}tt_people pe ON pe.id = c.person_id AND pe.club_id = c.club_id
          LEFT JOIN {$p}tt_lookups l ON l.id = c.cert_type_lookup_id
              WHERE " . QueryHelpers::clubScopeWhere( 'c' ) . "
                AND c.archived_at IS NULL
                AND c.expires_on IS NOT NULL
                AND c.expires_on <> '0000-00-00'
                AND c.expires_on BETWEEN DATE_SUB( CURDATE(), INTERVAL %d DAY )
                                     AND DATE_ADD( CURDATE(), INTERVAL %d DAY )
                AND pe.archived_at IS NULL
                AND pe.trashed_at IS NULL
                AND pe.status = 'active'
                AND pe.wp_user_id > 0"
            . $context->applyScope( self::SUBJECT_TYPE, 'c.id' ) . "
              ORDER BY c.expires_on ASC, c.id ASC",
            $window,
            $window
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /** Already expired, or expiring inside a fortnight, is urgent. */
    private function severityFor( object $row ): string {
        $expires = (string) ( $row->expires_on ?? '' );
        $today   = current_time( 'Y-m-d' );
        if ( $expires !== '' && $expires <= $today ) return Severity::URGENT;

        $ts = strtotime( $expires );
        if ( $ts === false ) return Severity::ATTENTION;
        $days = (int) ceil( ( $ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
        return $days <= self::URGENT_WITHIN_DAYS ? Severity::URGENT : Severity::ATTENTION;
    }

    private function titleFor( object $row ): string {
        $cert = trim( (string) ( $row->cert_name ?? '' ) );
        if ( $cert === '' ) $cert = __( 'A certificate', 'talenttrack' );

        $expires = (string) ( $row->expires_on ?? '' );
        if ( $expires !== '' && $expires <= current_time( 'Y-m-d' ) ) {
            return sprintf(
                /* translators: %s: certificate name */
                __( 'Your %s certificate has expired.', 'talenttrack' ),
                $cert
            );
        }

        $ts   = strtotime( $expires );
        $days = $ts === false ? 0 : max( 0, (int) ceil( ( $ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );

        return sprintf(
            /* translators: 1: certificate name, 2: number of days until it expires */
            _n(
                'Your %1$s certificate expires in %2$d day.',
                'Your %1$s certificate expires in %2$d days.',
                $days,
                'talenttrack'
            ),
            $cert,
            $days
        );
    }

    private function windowDays(): int {
        if ( $this->config === null ) {
            $this->config = new ConfigService();
        }
        $value = $this->config->getInt( self::CONFIG_KEY_WINDOW_DAYS, self::DEFAULT_WINDOW_DAYS );
        return $value > 0 ? $value : self::DEFAULT_WINDOW_DAYS;
    }
}
