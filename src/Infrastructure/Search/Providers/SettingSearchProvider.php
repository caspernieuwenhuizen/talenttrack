<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Configuration\SettingsRegistry;
use TT\Shared\Tiles\NavigationGroups;

/**
 * Settings and modules (#4671): the entries `SettingsRegistry` returns for
 * the viewer (the same list as `GET /settings-entries`), narrowed to the
 * screens their navigation offers them
 * (`SettingsRegistry::navigableEntriesFor()`). Matched on the label, and on
 * the description, the keywords and the section as secondary text.
 */
final class SettingSearchProvider extends MemorySearchProvider {

    public function kind(): string { return 'setting'; }

    protected function entries( int $user_id ): array {
        // #4673 — settings and modules never come back for a parent or a player.
        if ( \TT\Infrastructure\Search\SearchViewer::isFamily( $user_id ) ) return [];
        $out = [];
        foreach ( SettingsRegistry::navigableEntriesFor( $user_id ) as $entry ) {
            $out[] = [
                'key'      => (string) $entry['key'],
                'title'    => (string) $entry['label'],
                'text'     => SettingsRegistry::haystack( $entry ),
                'label'    => (string) $entry['label'],
                'sublabel' => NavigationGroups::label( (string) $entry['section'] ),
                'url'      => (string) $entry['url'],
            ];
        }
        return $out;
    }
}
