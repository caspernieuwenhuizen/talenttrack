<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Contracts\AudienceAwareAlert;
use TT\Modules\Alerts\Domain\AlertAudience;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * EvaluationSharedWithFamilyAlert (#3795) — something has been released for
 * the family to read.
 *
 * Which player question does this answer? *Where is this player now?* — for
 * the two people who were checking by hand every Sunday because nothing ever
 * told them anything had changed.
 *
 * ## It fires on the share, never on the save
 *
 * This is the reason the alert is safe to ship, not an implementation
 * detail. An evaluation is written days or weeks before anybody decides the
 * player and their family should read it. An alert that fired when the row
 * was saved would tell a family that an assessment of their child exists
 * before staff chose to release it — a side channel straight around the
 * share gate, and the opposite of what a share gate is for.
 *
 * The condition is therefore the *shared state itself*, not a save event:
 * `player_feedback` has content. That field is the share (#1386) — it is the
 * only part of an evaluation written for the player to read, and the ratings
 * and the internal `notes` stay with staff. An evaluation saved and never
 * shared does not match this query at any point in its life, so there is no
 * ordering, no race and no hook to get wrong.
 *
 * ## Why it is recency-bounded rather than event-driven
 *
 * Alerts are state-derived and self-resolving (epic #2629): a definition
 * returns everything currently true and the evaluator resolves whatever it
 * stopped returning. "Was shared at some point" is true forever, which would
 * make an alert that never clears. So the condition is "was shared
 * recently" — `alerts_eval_shared_recent_days`, default 14 — and the
 * occurrence resolves itself once the window passes, the way an unread
 * notification stops being news.
 *
 * ## Family audience only
 *
 * Staff do not need telling that they shared something. Declaring the
 * parent audience alone keeps the definition off every staff matrix, and
 * `AbstractPlayerAlert` skips head-coach resolution entirely for it.
 */
final class EvaluationSharedWithFamilyAlert extends AbstractPlayerAlert implements AudienceAwareAlert {

    use FamilyAudienceTrait;

    public const SUBJECT_TYPE = 'evaluation';

    /** tt_config key: how long a share stays news. */
    public const CONFIG_KEY_RECENT_DAYS = 'alerts_eval_shared_recent_days';

    private const DEFAULT_RECENT_DAYS = 14;

    public function key(): string {
        return 'evaluations.shared_with_family';
    }

    public function module(): string {
        return 'evaluations';
    }

    public function label(): string {
        return __( 'New evaluation shared with the family', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Feedback on an evaluation of your child has been shared with you. It appears only once staff have released it, never when the evaluation is first written.', 'talenttrack' );
    }

    /**
     * Family-only. See the class docblock: staff do not need telling that
     * they shared something.
     *
     * @return list<string>
     */
    public function audiences(): array {
        return [ AlertAudience::PARENT ];
    }

    /**
     * Empty on purpose. The capability gate answers "may this person act on
     * this?", and there is nothing to act on — this is news, not work. The
     * gate that matters for a family occurrence is the guardian link, which
     * `AlertEvaluator` re-checks against `tt_player_parents` on every run.
     */
    public function capRequired(): string {
        return '';
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /** News, not a problem. */
    public function defaultSeverity(): string {
        return Severity::INFO;
    }

    protected function titleFor( object $row ): string {
        return $this->familyTitleFor( $row );
    }

    protected function familyTitleFor( object $row ): string {
        return sprintf(
            /* translators: %s: player name */
            __( 'New feedback has been shared about %s.', 'talenttrack' ),
            $this->playerName( $row )
        );
    }

    /** @return array<string,mixed> */
    protected function familyPayloadFor( object $row ): array {
        return [ 'eval_date' => (string) ( $row->eval_date ?? '' ) ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->threshold( self::CONFIG_KEY_RECENT_DAYS, self::DEFAULT_RECENT_DAYS );

        // `player_feedback` with content IS the share. An evaluation that was
        // saved and never shared has NULL or '' here and never appears,
        // whatever else happens to the row — which is the whole privacy
        // argument for this definition, expressed as a WHERE clause.
        //
        // `updated_at` is when the row last changed, which for a row that has
        // feedback is the closest thing the schema has to "when it was
        // released". It is used only to bound the window, never shown.
        //
        // `club_id IS NULL` is tolerated on the evaluations side because rows
        // predating the #0052 tenancy backfill can still carry it.
        $sql = $wpdb->prepare(
            "SELECT e.id AS subject_id, e.player_id, e.eval_date,
                    p.first_name, p.last_name, p.team_id
               FROM {$p}tt_evaluations e
         INNER JOIN {$p}tt_players p ON p.id = e.player_id
              WHERE e.archived_at IS NULL
                AND e.trashed_at IS NULL
                AND ( e.club_id = %d OR e.club_id IS NULL )
                AND e.player_feedback IS NOT NULL
                AND TRIM( e.player_feedback ) <> ''
                AND e.updated_at >= DATE_SUB( NOW(), INTERVAL %d DAY )
                AND " . $this->activePlayerWhere( 'p' )
            . $context->applyScope( self::SUBJECT_TYPE, 'e.id' ) . "
              ORDER BY e.updated_at DESC, e.id ASC",
            CurrentClub::id(),
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = $row;
        }
        return $out;
    }
}
