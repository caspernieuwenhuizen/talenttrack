<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;

/**
 * Exercises (#4942): the club's exercise library, `tt_exercises`, found by
 * the exercise's name, its description and its category's label.
 *
 * The rows the library list shows (`ExercisesRepository::browseClause()`):
 * the club's, the current version only (`superseded_by_id IS NULL`), not
 * archived. The list and the detail page apply no further `visibility`
 * rule for the viewer, so neither does search (owner decision on #4942):
 * search never shows an exercise the viewer could not open from the list.
 *
 * The viewer needs the library page's own gate (`tt_view_activities`) and,
 * stricter than the page, the `exercises` read in the matrix: the entity
 * that names the record decides. A team manager holds `activities` but no
 * `exercises` read, so opens the library and finds no exercises in search,
 * until the page asks the same entity (a separate authorization change).
 * With the Exercises module off the kind returns nothing.
 */
final class ExerciseSearchProvider extends LibraryRecordSearchProvider {

    public function kind(): string { return 'exercise'; }

    protected function alias(): string { return 'e'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_exercises e
           LEFT JOIN {$wpdb->prefix}tt_exercise_categories ec ON ec.id = e.category_id AND ec.club_id = e.club_id";
    }

    protected function columns(): string {
        return 'e.id, e.name, e.description, ec.label AS category_label';
    }

    protected function live(): string {
        return 'e.archived_at IS NULL AND e.superseded_by_id IS NULL';
    }

    protected function titleColumns(): array { return [ 'e.name' ]; }

    protected function textColumns(): array { return [ 'e.description', 'ec.label' ]; }

    protected function tieBreak(): string { return 'e.name ASC, e.id ASC'; }

    protected function moduleOn(): bool {
        return class_exists( '\\TT\\Modules\\Exercises\\ExercisesModule' )
            && \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\Exercises\ExercisesModule::class );
    }

    protected function scope( int $user_id ): ?array {
        return self::mayRead( $user_id ) ? [ 'sql' => '1=1', 'params' => [] ] : null;
    }

    /** The library page's gate and the matrix read: one answer per viewer. */
    private static function mayRead( int $user_id ): bool {
        if ( ! user_can( $user_id, 'tt_view_activities' ) ) return false;
        return class_exists( MatrixGate::class ) && MatrixGate::can( $user_id, 'exercises', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r        = (array) $row;
        $id       = (int) ( $r['id'] ?? 0 );
        $category = trim( (string) ( $r['category_label'] ?? '' ) );
        $hit      = [
            'type'     => 'exercise',
            'id'       => $id,
            'label'    => (string) ( $r['name'] ?? '' ),
            'sublabel' => implode( ' · ', array_filter( [ _x( 'Exercise', 'search result kind', 'talenttrack' ), $category ] ) ),
            'url'      => $this->detailUrl( 'exercises', $id ),
            'score'    => 0,
            'actions'  => [],
        ];
        // The detail page shows the description in full.
        $excerpt = self::excerpt( (string) ( $r['description'] ?? '' ) );
        if ( $excerpt !== '' ) $hit['excerpt'] = $excerpt;
        return $hit;
    }

    /** The exercise's own check is the library's gate: no row-level rule beyond it. */
    protected function confirmHits( int $user_id, array $hits ): array {
        return self::mayRead( $user_id ) ? $hits : [];
    }
}
