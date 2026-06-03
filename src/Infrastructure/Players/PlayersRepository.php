<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * PlayersRepository — read-only repository for player records.
 *
 * #1079 — module-by-module rollout of #806's architectural sweep.
 * Pattern established in v4.17.2 / #1081 (Evaluations); follow-ups
 * #1077 (Goals, v4.20.18) and #1078 (Activities, v4.20.19) shipped
 * the same shape with their respective hydration mechanisms.
 *
 * Per-row shape (additive to whatever `SELECT *` returned from
 * `tt_players`):
 *
 *   `status`                       raw code (back-compat — KPI joins,
 *                                  filter dropdowns)
 *   `status_localised`             user-facing label via
 *                                  `LabelTranslator::playerStatus()`
 *   `preferred_foot`               raw code (back-compat)
 *   `preferred_foot_localised`     user-facing label via
 *                                  `LookupTranslator::byTypeAndName(
 *                                  'foot_options', $code )`. Two
 *                                  callsites historically disagreed
 *                                  on the lookup_type slug
 *                                  (`foot_option` vs `foot_options`);
 *                                  the repository normalises to the
 *                                  seeded plural `foot_options` per
 *                                  the lookup admin's canonical
 *                                  rows.
 *
 * Position labels (`preferred_positions` is a JSON array) are
 * deliberately not hydrated here — they need per-element translation
 * which is more naturally surfaced via a helper than a row-level
 * field. Same scoping discipline as the #1077 slice that left other
 * Goals callsites untouched.
 */
class PlayersRepository {

    /**
     * Active player by id. Returns null when the row doesn't exist
     * or is archived. Filters via the standard demo-scope helper —
     * does NOT filter by `CurrentClub::id()` because the precedent
     * site (`FrontendPlayersManageView::loadPlayer`) deliberately
     * does not, per the #1149 family of bugs where stricter scope
     * on a print router caused valid links to 404.
     */
    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 'p', 'player' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.* FROM {$p}tt_players p WHERE p.id = %d AND p.archived_at IS NULL {$scope}",
            $id
        ) );

        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /**
     * Decorate a `tt_players` row in place with `status_localised`
     * + `preferred_foot_localised`. Raw fields stay for back-compat.
     */
    private static function hydrate( object $row ): void {
        $row->status_localised = LabelTranslator::playerStatus( (string) ( $row->status ?? '' ) );

        $foot_raw = (string) ( $row->preferred_foot ?? '' );
        if ( $foot_raw === '' ) {
            $row->preferred_foot_localised = '';
            return;
        }
        $label = LookupTranslator::byTypeAndName( 'foot_options', $foot_raw );
        // Back-compat fallback: a prior site (FrontendMyProfileView at
        // line 108 before refactor) used the singular `foot_option`
        // slug. Try that too if the plural didn't land a translation.
        if ( ! is_string( $label ) || $label === '' || $label === $foot_raw ) {
            $alt = LookupTranslator::byTypeAndName( 'foot_option', $foot_raw );
            if ( is_string( $alt ) && $alt !== '' && $alt !== $foot_raw ) {
                $label = $alt;
            }
        }
        $row->preferred_foot_localised = is_string( $label ) && $label !== '' ? $label : $foot_raw;
    }
}
