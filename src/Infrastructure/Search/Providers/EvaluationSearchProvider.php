<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchViewer;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * Evaluations (#4673), for the family: the viewer's own and their
 * children's, where the record's own read allows it
 * (`SearchViewer::playerIdsReading( …, 'evaluations' )`). Found by the
 * evaluation type in the viewer's language (`LookupTranslator`) or as
 * stored, by the opponent, and by date. Staff are served by the
 * `staff_evaluation` kind (#4936).
 */
final class EvaluationSearchProvider extends FamilyRecordSearchProvider {

    /** @var array<string, array<int, array{0:string, 1:string}>> locale => lookup id => [shown name, stored name when it differs] */
    private static array $types = [];

    public function kind(): string { return 'evaluation'; }

    protected function scopeIds( int $user_id ): array {
        return SearchViewer::playerIdsReading( $user_id, 'evaluations' );
    }

    protected function rows( array $ids, SearchQuery $query, int $limit ): array {
        global $wpdb;
        $dates = self::dateSql( 'e.eval_date', $query );
        $sql   = "SELECT e.id, e.player_id, e.eval_date, e.opponent, e.eval_type_id
                    FROM {$wpdb->prefix}tt_evaluations e
                   WHERE ( e.club_id = %d OR e.club_id IS NULL )
                     AND e.archived_at IS NULL
                     AND e.player_id IN (" . self::placeholders( $ids ) . ")
                     AND {$dates['sql']}
                ORDER BY e.eval_date DESC, e.id DESC
                   LIMIT " . (int) $limit;
        $params = array_merge( [ (int) CurrentClub::id() ], $ids, $dates['params'] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return array_values( array_filter( is_array( $rows ) ? $rows : [], 'is_object' ) );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r         = (array) $row;
        $player_id = (int) ( $r['player_id'] ?? 0 );
        $names     = self::typeNames()[ (int) ( $r['eval_type_id'] ?? 0 ) ] ?? [ '', '' ];
        $label     = $names[0] !== '' ? $names[0] : __( 'Evaluation', 'talenttrack' );
        $date      = (string) ( $r['eval_date'] ?? '' );
        $opponent  = (string) ( $r['opponent'] ?? '' );
        // A parent of more than one child sees whose evaluation it is.
        $child     = SearchViewer::childNamesToShow( $user_id )[ $player_id ] ?? '';
        return [
            'type'      => 'evaluation',
            'id'        => (int) ( $r['id'] ?? 0 ),
            'label'     => $label,
            'sublabel'  => trim( implode( ' · ', array_filter( [ $child, $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '', $opponent ] ) ) ),
            'url'       => $this->meUrl( 'my-evaluations', [ 'player_id' => $player_id ] ),
            'score'     => 0,
            'date'      => $date,
            'player_id' => $player_id,
            'actions'   => [],
            'tt_title'  => trim( $names[0] . ' ' . $names[1] ),
            'tt_text'   => $opponent,
        ];
    }

    protected function scopeKey( array $hit ): int {
        return (int) ( $hit['player_id'] ?? 0 );
    }

    /**
     * The evaluation types by lookup id: the name in the viewer's language,
     * and the stored one, so "wedstrijdevaluatie" and "match evaluation"
     * both find a match evaluation on a Dutch install. The staff kind
     * (`StaffEvaluationSearchProvider`) matches on the same names.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public static function typeNames(): array {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( isset( self::$types[ $locale ] ) ) return self::$types[ $locale ];
        $out = [];
        foreach ( QueryHelpers::get_lookups( 'eval_type' ) as $lookup ) {
            $stored = (string) ( ( (array) $lookup )['name'] ?? '' );
            $shown  = LookupTranslator::name( $lookup );
            $out[ (int) ( ( (array) $lookup )['id'] ?? 0 ) ] = [ $shown !== '' ? $shown : $stored, $shown === $stored ? '' : $stored ];
        }
        return self::$types[ $locale ] = $out;
    }

    /** Tests switch the locale between calls. */
    public static function flush(): void {
        self::$types = [];
    }
}
