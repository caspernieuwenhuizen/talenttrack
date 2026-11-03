<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Tiles\TileRegistry;

/**
 * Sections (#4671): the destinations of the navigation, from
 * `TileRegistry::tilesForUserGrouped()`, which is already capability- and
 * persona-filtered and honours the hidden marker. The kind key stays `view`,
 * the type the header box has always shown them under.
 */
final class SectionSearchProvider extends MemorySearchProvider {

    public function kind(): string { return 'view'; }

    protected function entries( int $user_id ): array {
        $out = [];
        foreach ( TileRegistry::tilesForUserGrouped( $user_id ) as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                $label = (string) ( $tile['label'] ?? '' );
                $url   = (string) ( $tile['url'] ?? '' );
                if ( $label === '' || $url === '' ) continue;
                $out[] = [
                    'key'      => (string) ( $tile['slug'] ?? $label ),
                    'title'    => $label,
                    'text'     => (string) ( $tile['desc'] ?? '' ),
                    'label'    => $label,
                    'sublabel' => (string) $group['label'],
                    'url'      => $url,
                ];
            }
        }
        return $out;
    }

    /**
     * Every section the viewer reaches, unfiltered, in navigation order: the
     * header box shows them before anything is typed.
     *
     * @return list<array<string,mixed>>
     */
    public function all( int $user_id ): array {
        $out = [];
        foreach ( $this->entries( $user_id ) as $entry ) {
            $out[] = [
                'type'     => 'view',
                'id'       => 0,
                'label'    => $entry['label'],
                'sublabel' => $entry['sublabel'],
                'url'      => $entry['url'],
                'score'    => 0,
                'actions'  => [],
            ];
        }
        return $out;
    }
}
