<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Help topics (#4671): the topics of the `docs/` registry the viewer may
 * open (`HelpTopics::visibleFor()`), matched on title and summary in the
 * viewer's language — the registry reads them from the localised file.
 */
final class HelpSearchProvider extends MemorySearchProvider {

    public function kind(): string { return 'help'; }

    public function minChars(): int { return 2; }

    protected function entries( int $user_id ): array {
        if ( ! class_exists( '\\TT\\Modules\\Documentation\\HelpTopics' ) ) return [];
        $groups = \TT\Modules\Documentation\HelpTopics::groups();
        $out    = [];
        foreach ( \TT\Modules\Documentation\HelpTopics::visibleFor( $user_id ) as $slug => $topic ) {
            $title = (string) ( $topic['title'] ?? '' );
            if ( $title === '' ) continue;
            $out[] = [
                'key'      => (string) $slug,
                'title'    => $title,
                'text'     => (string) ( $topic['summary'] ?? '' ),
                'label'    => $title,
                'sublabel' => (string) ( $groups[ (string) ( $topic['group'] ?? '' ) ] ?? __( 'Help', 'talenttrack' ) ),
                'url'      => \TT\Modules\Documentation\DocLinkResolver::topic( (string) $slug ),
            ];
        }
        return $out;
    }
}
