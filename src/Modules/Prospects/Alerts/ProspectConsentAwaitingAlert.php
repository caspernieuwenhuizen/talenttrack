<?php
namespace TT\Modules\Prospects\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\AbstractDataQualityAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ProspectConsentAwaitingAlert (#4017, epic #2629) — the child's club never
 * came back, and nothing in the product said so.
 *
 * Which player question does this answer? *Where are they going?* — for a
 * prospect who cannot go anywhere until somebody answers. A scout logged a
 * consent request as `awaiting` and it stayed that way for a week; no test
 * training can be planned until the family agrees, and the only thing
 * chasing it was the scout's own notebook.
 *
 * ## What happens today is worse than silence
 *
 * The one place an ageing `awaiting` row is read is
 * `ProspectRetentionCron`, where it holds the retention clock — and its own
 * comment records the hole: "a request nobody ever followed up on still
 * ages out on the normal rule". So an unchased request does not sit there
 * visibly waiting. It ends in a **silent purge** of the child's record.
 *
 * That is why the threshold matters more than the wording. The default is
 * five days against a no-progress retention window of ninety, so the alert
 * fires with the best part of three months of slack. An academy that
 * lengthens the threshold should keep that relationship: a threshold set
 * past the retention window would be a reminder that arrives after the
 * record it was about.
 *
 * ## State-derived, so there is no reminder to manage
 *
 * There is no "reminded at" column and no sent flag, deliberately. The
 * condition is "a request has been `awaiting` longer than the threshold",
 * and it stops being true the moment somebody records an outcome — agreed,
 * declined or no reply. `ProspectsModule` maps the outcome hook into the
 * invalidation table so that resolution is immediate rather than up to an
 * hour later, because telling a scout to chase something they have just
 * finished chasing is how a catalogue teaches people to stop reading it.
 *
 * ## One occurrence per prospect, not per request
 *
 * The subject is the prospect. A club asked twice about the same child is
 * still one thing to chase, and the sentence names the oldest wait, which
 * is the one that matters. Consent already on record — an `agreed` entry,
 * or `consent_given_at` from the family direct — settles the question, so
 * a stale `awaiting` row beside it raises nothing.
 *
 * ## Audience is a capability
 *
 * Whoever holds `tt_edit_prospects`, resolved by
 * `AbstractDataQualityAlert`. The scout who logged the request holds it, and
 * so does whoever covers for them the week they are away — which is the
 * case the reporter's notebook does not cover.
 */
final class ProspectConsentAwaitingAlert extends AbstractDataQualityAlert {

    /** tt_config key holding how long a wait may run before it is said out loud. */
    public const CONFIG_KEY_WAITING_DAYS = 'alerts_prospect_consent_awaiting_days';

    /** Five days, as asked for: a week's chase, caught before the weekend. */
    public const DEFAULT_WAITING_DAYS = 5;

    /** A wait this long is not a wait any more. */
    private const URGENT_MULTIPLIER = 4;

    public function key(): string {
        return 'prospects.consent_awaiting';
    }

    public function module(): string {
        return 'prospects';
    }

    public function subjectType(): string {
        return 'prospect';
    }

    public function label(): string {
        return __( 'Consent request still waiting', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A consent request logged for a prospect is still waiting for an answer. Nothing can be arranged for the child until the family agrees, and a request nobody chases eventually ages out of the system altogether — so this says how long the wait has been running.', 'talenttrack' );
    }

    public function capRequired(): string {
        return 'tt_edit_prospects';
    }

    public function defaultSeverity(): string {
        return Severity::ATTENTION;
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ Surface::BADGE, Surface::BANNER ];
    }

    /**
     * Louder once the wait is four times the threshold. At that point the
     * chase has not happened and the retention clock is the next thing to
     * think about.
     */
    protected function severityFor( object $row ): string {
        $days = (int) ( $row->waiting_days ?? 0 );
        return $days >= $this->waitingDays() * self::URGENT_MULTIPLIER
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
        if ( $name === '' ) $name = __( 'A prospect', 'talenttrack' );

        $days   = (int) ( $row->waiting_days ?? 0 );
        $asked  = trim( (string) ( $row->asked_of ?? '' ) );

        if ( $asked !== '' ) {
            return sprintf(
                /* translators: 1: the club or coordinator that was asked, 2: prospect name, 3: number of days the request has been waiting */
                _n(
                    '%1$s has not answered the consent request for %2$s — %3$d day waiting.',
                    '%1$s has not answered the consent request for %2$s — %3$d days waiting.',
                    $days,
                    'talenttrack'
                ),
                $asked,
                $name,
                $days
            );
        }

        return sprintf(
            /* translators: 1: prospect name, 2: number of days the request has been waiting */
            _n(
                'The consent request for %1$s has been waiting %2$d day.',
                'The consent request for %1$s has been waiting %2$d days.',
                $days,
                'talenttrack'
            ),
            $name,
            $days
        );
    }

    /** The prospect's own screen, where the consent log and its outcomes live. */
    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'prospect-edit', (int) ( $row->subject_id ?? 0 ) );
    }

    /** A prospect is not a player yet, so there is no player to roll up to. */
    protected function playerIdFor( object $row ): ?int {
        return null;
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'prospect_name' => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
            'asked_of'      => (string) ( $row->asked_of ?? '' ),
            'asked_at'      => (string) ( $row->asked_at ?? '' ),
            'waiting_days'  => (int) ( $row->waiting_days ?? 0 ),
        ];
    }

    /** The configured wait, in days, floored at 1. */
    public function waitingDays(): int {
        return $this->threshold( self::CONFIG_KEY_WAITING_DAYS, self::DEFAULT_WAITING_DAYS );
    }

    /**
     * Prospects with a consent request that has been `awaiting` longer than
     * the threshold, oldest wait first.
     *
     * One query. The repository had no "awaiting older than N days" read —
     * `forProspect()` and `hasAgreed()` are both per-prospect, and a sweep
     * that asked per prospect would be a query per row on an hourly cron.
     * The table-existence guard is the same one `ProspectRetentionCron`
     * uses: installs that predate migration 0284 have no log to read, and
     * must answer "nothing to chase" rather than a database error on every
     * sweep.
     *
     * @return list<object>
     */
    protected function rows( AlertContext $context ): array {
        if ( ! ProspectConsentRequestsRepository::tableExists() ) return [];

        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->waitingDays();

        $sql = $wpdb->prepare(
            "SELECT pr.id AS subject_id,
                    pr.first_name,
                    pr.last_name,
                    MIN( cr.asked_at ) AS asked_at,
                    DATEDIFF( CURDATE(), MIN( cr.asked_at ) ) AS waiting_days,
                    -- The club behind the oldest wait. A correlated read
                    -- rather than GROUP_CONCAT + SUBSTRING_INDEX, which
                    -- would split a club name that contains a comma.
                    ( SELECT oldest.asked_of
                        FROM {$p}tt_prospect_consent_requests oldest
                       WHERE oldest.prospect_id = pr.id
                         AND oldest.club_id = pr.club_id
                         AND oldest.outcome = %s
                    ORDER BY oldest.asked_at ASC, oldest.id ASC
                       LIMIT 1 ) AS asked_of
               FROM {$p}tt_prospect_consent_requests cr
               JOIN {$p}tt_prospects pr
                 ON pr.id = cr.prospect_id AND pr.club_id = cr.club_id
              WHERE cr.club_id = %d
                AND cr.outcome = %s
                AND pr.archived_at IS NULL
                AND pr.promoted_to_player_id IS NULL
                AND ( pr.consent_given_at IS NULL OR pr.consent_given_at = '0000-00-00 00:00:00' )
                AND NOT EXISTS (
                        SELECT 1 FROM {$p}tt_prospect_consent_requests agreed
                         WHERE agreed.prospect_id = pr.id
                           AND agreed.club_id = pr.club_id
                           AND agreed.outcome = %s
                    )"
            . $context->applyScope( $this->subjectType(), 'pr.id' ) . "
           GROUP BY pr.id, pr.first_name, pr.last_name
             HAVING MIN( cr.asked_at ) <= DATE_SUB( CURDATE(), INTERVAL %d DAY )
           ORDER BY waiting_days DESC, pr.id ASC",
            ConsentOutcome::AWAITING,
            CurrentClub::id(),
            ConsentOutcome::AWAITING,
            ConsentOutcome::AGREED,
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->get_results( $sql );
        if ( ! is_array( $result ) ) return [];

        return array_values( array_filter( $result, 'is_object' ) );
    }
}
