<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchQuery;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Staff and other people (#4671). The rule `GET /people` applies: the
 * people read right (`tt_view_people` or `tt_edit_people`), live records in
 * the club. Only the name is searched and returned, never an e-mail or a
 * phone number.
 */
final class PersonSearchProvider extends RecordSearchProvider {

    public function kind(): string { return 'person'; }

    public function patterns(): array { return []; }

    protected function alias(): string { return 'pe'; }

    protected function tieBreak(): string { return 'pe.last_name ASC, pe.first_name ASC, pe.id ASC'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_people pe";
    }

    protected function columns(): string {
        return 'pe.id, pe.first_name, pe.last_name, pe.role_type';
    }

    protected function scope( int $user_id ): ?array {
        if ( ! self::readsPeople( $user_id ) ) return null;
        return [ 'sql' => '1=1', 'params' => [], 'own_sql' => '', 'own_params' => [] ];
    }

    protected function conditions( SearchQuery $query ): array {
        if ( $query->team_id === null ) return [ 'sql' => '1=1', 'params' => [] ];
        global $wpdb;
        return [
            'sql'    => "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_team_people tp WHERE tp.person_id = pe.id AND tp.club_id = pe.club_id AND tp.team_id = %d )",
            'params' => [ $query->team_id ],
        ];
    }

    /** @return array<string,mixed> */
    protected function toHit( object $row, int $user_id ): array {
        $r  = (array) $row;
        $id = (int) ( $r['id'] ?? 0 );
        return [
            'type'     => 'person',
            'id'       => $id,
            'label'    => trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) ),
            'sublabel' => \TT\Infrastructure\Query\LabelTranslator::roleType( (string) ( $r['role_type'] ?? 'other' ) ),
            'url'      => $this->detailUrl( 'people', $id ),
            'score'    => 0,
            'actions'  => [],
        ];
    }

    public function confirm( int $user_id, array $hits ): array {
        return self::readsPeople( $user_id ) ? $hits : [];
    }

    private static function readsPeople( int $user_id ): bool {
        return $user_id > 0 && ( user_can( $user_id, 'tt_view_people' ) || user_can( $user_id, 'tt_edit_people' ) );
    }
}
