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
 *
 * #4936 — each hit carries its full path ("Configuration › Academy ›
 * Appearance") as `path` and as its sublabel. For a viewer who may switch
 * modules on, a switched-off module is a hit too
 * (`SettingsRegistry::offModulesFor()`), and so is a setting of one: marked
 * `disabled`, with a "Turn on" action that opens the module's toggle.
 * Nobody else gets either.
 */
final class SettingSearchProvider extends MemorySearchProvider {

    public function kind(): string { return 'setting'; }

    protected function entries( int $user_id ): array {
        // #4673 — settings and modules never come back for a parent or a player.
        if ( \TT\Infrastructure\Search\SearchViewer::isFamily( $user_id ) ) return [];
        $out   = [];
        $taken = [];
        foreach ( SettingsRegistry::navigableEntriesFor( $user_id ) as $entry ) {
            $taken[ $entry['label'] . '|' . $entry['url'] ] = true;
            $path = SettingsRegistry::path( $entry );
            $off  = $entry['module_state'] === SettingsRegistry::STATE_OFF;
            $out[] = [
                'key'      => (string) $entry['key'],
                'title'    => (string) $entry['label'],
                'text'     => SettingsRegistry::haystack( $entry ),
                'label'    => (string) $entry['label'],
                'sublabel' => $path,
                'url'      => (string) $entry['url'],
                'extra'    => self::extra( $path, $off, (string) $entry['url'] ),
            ];
        }
        foreach ( SettingsRegistry::offModulesFor( $user_id ) as $module ) {
            // A module whose setting already leads to its toggle under the
            // same name is that row already.
            if ( isset( $taken[ $module['label'] . '|' . $module['url'] ] ) ) continue;
            $out[] = [
                'key'      => 'module-' . sanitize_key( str_replace( '\\', '-', $module['class'] ) ),
                'title'    => $module['label'],
                'text'     => implode( ' ', [ $module['description'], __( 'Module', 'talenttrack' ), NavigationGroups::label( 'features_plan' ) ] ),
                'label'    => $module['label'],
                'sublabel' => $module['path'],
                'url'      => $module['url'],
                'extra'    => self::extra( $module['path'], true, $module['url'] ),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function extra( string $path, bool $off, string $url ): array {
        return [
            'path'     => $path,
            'disabled' => $off,
            'actions'  => $off ? [ [ 'key' => 'turn_on', 'label' => __( 'Turn on', 'talenttrack' ), 'url' => $url ] ] : [],
        ];
    }
}
