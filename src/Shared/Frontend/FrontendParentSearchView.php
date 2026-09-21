<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\ParentSearchService;
use TT\Shared\Dates\TTDate;

/**
 * FrontendParentSearchView (#3806) — `?tt_view=search`.
 *
 * A coach says a session is "in TalentTrack now" and the parent has no way
 * to find it. `?tt_view=search` returned the 404 page; the only thing that
 * worked was pasting a record number out of a text message into the address
 * bar. This is the field that was missing.
 *
 * The scope is not this view's to decide. `ParentSearchService` resolves the
 * caller's own children first and bounds every query by them (CLAUDE.md §4:
 * the view composes, the domain layer decides), so the REST route and this
 * page cannot disagree about what a parent may find.
 *
 * A miss renders identically whatever it missed. There is no count of
 * withheld rows, no "no results for that name" that differs from an unknown
 * name, and no suggestion — a response that tells the two apart is a way of
 * discovering that another family's child exists.
 */
class FrontendParentSearchView extends FrontendViewBase {

    public const SLUG = 'search';

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-parent-search',
            TT_PLUGIN_URL . 'assets/css/frontend-parent-search.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render(): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Search', 'talenttrack' ) );
        self::renderHeader( __( 'Search', 'talenttrack' ) );

        $user_id = get_current_user_id();
        $query   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';

        $found = ( new ParentSearchService() )->search( $user_id, $query );

        self::renderForm( $query, $found['children'] );

        if ( trim( $query ) === '' ) {
            echo '<p class="tt-field-hint">' . esc_html__( 'Search your children\'s activities, evaluations, goals and messages by name or by date.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $found['results'] === [] ) {
            // One empty state, whatever the query was. See the class note.
            echo '<p class="tt-notice">' . esc_html__( 'Nothing found.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderResults( $found['results'] );
    }

    /**
     * The field itself. `type="search"` so the phone keyboard offers the
     * right key and the browser offers the clear button.
     *
     * @param list<array{id:int, name:string}> $children
     */
    private static function renderForm( string $query, array $children ): void {
        $action = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();

        echo '<form class="tt-parent-search__form" method="get" action="' . esc_url( $action ) . '" role="search">';
        echo '<input type="hidden" name="tt_view" value="' . esc_attr( self::SLUG ) . '" />';
        echo '<label class="tt-field-label" for="tt-parent-search-q">' . esc_html__( 'Search', 'talenttrack' ) . '</label>';
        echo '<input id="tt-parent-search-q" class="tt-input tt-parent-search__input" type="search" name="q"'
            . ' value="' . esc_attr( $query ) . '"'
            . ' inputmode="search" autocomplete="off" autocapitalize="off" spellcheck="false"'
            . ' placeholder="' . esc_attr__( 'Training, 3 Nov, goal…', 'talenttrack' ) . '" />';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Search', 'talenttrack' ) . '</button>';
        echo '</form>';

        if ( $children === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No child is linked to your account yet, so there is nothing to search. Ask the academy to link you.', 'talenttrack' ) . '</p>';
            return;
        }

        $names = array_filter( array_map( static fn ( array $c ): string => $c['name'], $children ) );
        if ( $names !== [] ) {
            echo '<p class="tt-field-hint">' . esc_html( sprintf(
                /* translators: %s: comma-separated list of the parent's children */
                __( 'Searching the records of %s.', 'talenttrack' ),
                implode( ', ', $names )
            ) ) . '</p>';
        }
    }

    /**
     * @param list<array<string,mixed>> $results
     */
    private static function renderResults( array $results ): void {
        echo '<ul class="tt-parent-search__results">';
        foreach ( $results as $result ) {
            $title = trim( (string) $result['title'] );
            if ( $title === '' ) $title = self::typeLabel( (string) $result['type'] );

            $date  = (string) $result['date'];
            $when  = $date !== '' ? TTDate::date( $date ) : '';
            $meta  = array_filter( [
                self::typeLabel( (string) $result['type'] ),
                $when,
                (string) $result['subtitle'],
            ] );

            echo '<li class="tt-parent-search__result">';
            echo '<a class="tt-parent-search__link" href="' . esc_url( (string) $result['url'] ) . '">';
            echo '<span class="tt-parent-search__title">' . esc_html( $title ) . '</span>';
            echo '<span class="tt-parent-search__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function typeLabel( string $type ): string {
        switch ( $type ) {
            case ParentSearchService::TYPE_ACTIVITY:
                return _x( 'Activity', 'parent search result kind', 'talenttrack' );
            case ParentSearchService::TYPE_EVALUATION:
                return _x( 'Evaluation', 'parent search result kind', 'talenttrack' );
            case ParentSearchService::TYPE_GOAL:
                return _x( 'Goal', 'parent search result kind', 'talenttrack' );
            case ParentSearchService::TYPE_MESSAGE:
                return _x( 'Message', 'parent search result kind', 'talenttrack' );
            default:
                return '';
        }
    }
}
