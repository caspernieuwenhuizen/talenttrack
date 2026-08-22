<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * PlayerTurns18Alert (#2636, epic #2629) — a player is about to become an
 * adult.
 *
 * Which player question does this answer? *Where is this player going?*
 * Turning eighteen is one of the few dates in an academy career that
 * changes the paperwork rather than the football: parental consent stops
 * being the basis for holding their data, a youth agreement may need to
 * become a contract, and the parent account's visibility into their record
 * becomes a decision rather than a default. All of that is easy to do a
 * month early and awkward to do a month late.
 *
 * ## Eighteen is not configurable; the notice period is
 *
 * The age of majority is a fact about the jurisdiction the academy operates
 * in, not a preference — and this definition's own key says `turns_18`, so
 * a club that changed the number would have an alert whose name lied. The
 * lead time is the part academies genuinely differ on, and that lives in
 * `alerts_player_turns_18_days` (30 days by default).
 *
 * Recipients are the team's head coach, per epic decision 7 — the person
 * who knows the player and can start the conversation. This deliberately
 * does not fan out to every academy administrator; that oversight is the
 * roll-up in #2633.
 */
final class PlayerTurns18Alert extends AbstractPlayerAlert {

    /** tt_config key: how much notice before the birthday. */
    public const CONFIG_KEY_LEAD_DAYS = 'alerts_player_turns_18_days';

    private const DEFAULT_LEAD_DAYS = 30;
    private const MAJORITY_AGE      = 18;
    private const URGENT_WITHIN_DAYS = 7;

    public function key(): string {
        return 'people.player_turns_18';
    }

    public function module(): string {
        return 'people';
    }

    public function label(): string {
        return __( 'Player turns 18 soon', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A player is about to turn 18. Parental consent stops being the basis for holding their data, and the paperwork is easy to do a month early and awkward to do a month late.', 'talenttrack' );
    }

    /**
     * Everything this alert leads to is an edit of the player record —
     * consent, agreement status, parent visibility — so that is the
     * capability that gates receipt.
     */
    public function capRequired(): string {
        return 'tt_edit_players';
    }

    protected function severityFor( object $row ): string {
        return $this->daysUntil( (string) ( $row->majority_date ?? '' ) ) <= self::URGENT_WITHIN_DAYS
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = $this->playerName( $row );
        $days = $this->daysUntil( (string) ( $row->majority_date ?? '' ) );

        if ( $days <= 0 ) {
            return sprintf(
                /* translators: %s: player name */
                __( '%s turns 18 today. Consent and agreement records need updating.', 'talenttrack' ),
                $name
            );
        }

        return sprintf(
            /* translators: 1: player name, 2: number of days until the birthday */
            _n(
                '%1$s turns 18 in %2$d day. Consent and agreement records need updating.',
                '%1$s turns 18 in %2$d days. Consent and agreement records need updating.',
                $days,
                'talenttrack'
            ),
            $name,
            $days
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [ 'majority_date' => (string) ( $row->majority_date ?? '' ) ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $lead = $this->threshold( self::CONFIG_KEY_LEAD_DAYS, self::DEFAULT_LEAD_DAYS );

        // The eighteenth birthday is computed in SQL rather than by pulling
        // every player into PHP and filtering: this has to be one indexed
        // pass on an hourly, every-club sweep.
        //
        // The zero-date guard matters here more than anywhere else. Imports
        // write `0000-00-00`, which is neither NULL nor empty, and
        // `DATE_ADD` on it does not produce a date in the alert window — but
        // relying on that is relying on a quirk, so it is excluded outright.
        $sql = $wpdb->prepare(
            "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id,
                    DATE_ADD( p.date_of_birth, INTERVAL %d YEAR ) AS majority_date
               FROM {$p}tt_players p
              WHERE " . $this->activePlayerWhere( 'p' ) . "
                AND p.team_id > 0
                AND p.date_of_birth IS NOT NULL
                AND p.date_of_birth <> '0000-00-00'
                AND DATE_ADD( p.date_of_birth, INTERVAL %d YEAR )
                    BETWEEN CURDATE() AND DATE_ADD( CURDATE(), INTERVAL %d DAY )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
              ORDER BY majority_date ASC, p.id ASC",
            self::MAJORITY_AGE,
            self::MAJORITY_AGE,
            $lead
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
