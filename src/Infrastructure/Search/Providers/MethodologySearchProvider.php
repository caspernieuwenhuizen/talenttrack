<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchViewer;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\LearningGoalsRepository;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;
use TT\Modules\Methodology\Repositories\SubPrinciplesRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Methodology (#4942): the principles, sub-principles and learning goals
 * of the methodology the club has selected, found by their name and their
 * description.
 *
 * Read through the repositories the methodology page reads, so the rows
 * are the page's: the club's, not archived, of the active set
 * (`MethodologyScope::active()`, plus the rows that belong to no set), the
 * learning goals of the active framework primer. Their titles are stored
 * per language, so they are matched in memory, in the viewer's language.
 *
 * The viewer needs the page's gate (`tt_view_methodology`, the
 * `methodology` read). A principle opens its own detail; a sub-principle
 * and a learning goal have none, so they open the tab that lists them. No
 * excerpt: the text matched on is the page's own. With the Methodology
 * module off the kind returns nothing.
 */
final class MethodologySearchProvider extends MemorySearchProvider {

    public function kind(): string { return 'methodology'; }

    public function minChars(): int { return 2; }

    protected function entries( int $user_id ): array {
        if ( ! self::serves( $user_id ) ) return [];

        $kind = _x( 'Methodology', 'search result kind', 'talenttrack' );
        $base = add_query_arg( [ 'tt_view' => 'methodology' ], RecordLink::dashboardUrl() );
        $out  = [];

        foreach ( ( new PrinciplesRepository() )->listFiltered() as $p ) {
            $id    = (int) ( $p->id ?? 0 );
            $code  = (string) ( $p->code ?? '' );
            $title = MultilingualField::string( $p->title_json ?? null );
            if ( $id <= 0 || ( $code === '' && $title === '' ) ) continue;
            $out[] = [
                'key'      => 'principle-' . $id,
                'title'    => trim( $code . ' ' . $title ),
                'text'     => MultilingualField::string( $p->explanation_json ?? null ),
                'label'    => $code !== '' && $title !== '' ? $code . ' · ' . $title : ( $title !== '' ? $title : $code ),
                'sublabel' => $kind . ' · ' . _x( 'Principle', 'methodology search result', 'talenttrack' ),
                'url'      => (string) add_query_arg( [ 'mtab' => 'principles', 'pid' => $id ], $base ),
            ];
        }

        foreach ( ( new SubPrinciplesRepository() )->listFiltered() as $sp ) {
            $id    = (int) ( $sp->id ?? 0 );
            $title = MultilingualField::string( $sp->title_json ?? null );
            if ( $id <= 0 || $title === '' ) continue;
            $out[] = [
                'key'      => 'sub-principle-' . $id,
                'title'    => $title,
                'text'     => MultilingualField::string( $sp->description_json ?? null ),
                'label'    => $title,
                'sublabel' => $kind . ' · ' . _x( 'Sub-principle', 'methodology search result', 'talenttrack' ),
                'url'      => (string) add_query_arg( [ 'mtab' => 'principles' ], $base ),
            ];
        }

        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( $primer !== null ) {
            foreach ( ( new LearningGoalsRepository() )->listForPrimer( (int) $primer->id ) as $goal ) {
                $id    = (int) ( $goal->id ?? 0 );
                $title = MultilingualField::string( $goal->title_json ?? null );
                if ( $id <= 0 || $title === '' ) continue;
                $out[] = [
                    'key'      => 'learning-goal-' . $id,
                    'title'    => $title,
                    'text'     => implode( ' ', MultilingualField::stringList( $goal->bullets_json ?? null ) ),
                    'label'    => $title,
                    'sublabel' => $kind . ' · ' . _x( 'Learning goal', 'methodology search result', 'talenttrack' ),
                    'url'      => (string) add_query_arg( [ 'mtab' => 'framework' ], $base ),
                ];
            }
        }

        return $out;
    }

    public function confirm( int $user_id, array $hits ): array {
        return self::serves( $user_id ) ? $hits : [];
    }

    /** Staff with the methodology read, with the module on. */
    private static function serves( int $user_id ): bool {
        if ( $user_id <= 0 || SearchViewer::isFamily( $user_id ) ) return false;
        if ( ! class_exists( '\\TT\\Modules\\Methodology\\MethodologyModule' ) ) return false;
        if ( ! \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\Methodology\MethodologyModule::class ) ) return false;
        return user_can( $user_id, 'tt_view_methodology' );
    }
}
