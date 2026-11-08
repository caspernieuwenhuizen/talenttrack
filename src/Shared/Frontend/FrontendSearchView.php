<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\ParentSearchService;
use TT\Infrastructure\Search\SearchViewer;
use TT\Infrastructure\REST\SearchRestController;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchRegistry;
use TT\Infrastructure\Search\SearchService;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendSearchView (#4672) — `?tt_view=search&q=`: every kind the search
 * engine (#4671) serves the caller, filtered by kind, team and season, one
 * page at a time. Since #4673 it is the page for parents and players too;
 * each kind scopes itself to them, so the view does not branch on who asks
 * beyond its wording.
 *
 * The view composes. What matches, what the viewer may open and what is
 * counted are the providers' decisions, reached through `SearchService`,
 * the same call `GET /search?page=` answers from.
 */
class FrontendSearchView extends FrontendViewBase {

    public const SLUG = 'search';

    private const PER_PAGE = 20;

    /**
     * The groups, in the order the header box and this page show them,
     * with how many rows the header box gives each.
     *
     * @return list<array{kind:string, label:string, limit:int}>
     */
    public static function groups(): array {
        $groups = [
            [ 'kind' => 'player',     'label' => _x( 'Players', 'search group', 'talenttrack' ),     'limit' => 4 ],
            [ 'kind' => 'team',       'label' => _x( 'Teams', 'search group', 'talenttrack' ),       'limit' => 3 ],
            [ 'kind' => 'activity',   'label' => _x( 'Activities', 'search group', 'talenttrack' ),  'limit' => 3 ],
            [ 'kind' => 'evaluation', 'label' => _x( 'Evaluations', 'search group', 'talenttrack' ), 'limit' => 3 ],
            [ 'kind' => 'goal',       'label' => _x( 'Goals', 'search group', 'talenttrack' ),       'limit' => 3 ],
            [ 'kind' => 'message',    'label' => _x( 'Messages', 'search group', 'talenttrack' ),    'limit' => 3 ],
            [ 'kind' => 'person',     'label' => _x( 'People', 'search group', 'talenttrack' ),      'limit' => 3 ],
            [ 'kind' => 'setting',    'label' => _x( 'Settings', 'search group', 'talenttrack' ),    'limit' => 3 ],
            [ 'kind' => 'view',       'label' => _x( 'Sections', 'search group', 'talenttrack' ),    'limit' => 3 ],
            [ 'kind' => 'help',       'label' => _x( 'Help', 'search group', 'talenttrack' ),        'limit' => 3 ],
        ];
        $known = SearchRegistry::kinds();
        return array_values( array_filter( $groups, static fn( array $g ): bool => in_array( $g['kind'], $known, true ) ) );
    }

    /** One result's kind, as a row label. */
    public static function kindLabel( string $kind ): string {
        switch ( $kind ) {
            case 'player':     return _x( 'Player', 'search result kind', 'talenttrack' );
            case 'team':       return _x( 'Team', 'search result kind', 'talenttrack' );
            case 'activity':   return _x( 'Activity', 'search result kind', 'talenttrack' );
            case 'evaluation': return _x( 'Evaluation', 'search result kind', 'talenttrack' );
            case 'goal':       return _x( 'Goal', 'search result kind', 'talenttrack' );
            case 'message':    return _x( 'Message', 'search result kind', 'talenttrack' );
            case 'person':     return _x( 'Person', 'search result kind', 'talenttrack' );
            case 'setting':    return _x( 'Setting', 'search result kind', 'talenttrack' );
            case 'view':       return _x( 'Section', 'search result kind', 'talenttrack' );
            case 'help':       return _x( 'Help topic', 'search result kind', 'talenttrack' );
            default:           return '';
        }
    }

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-search',
            TT_PLUGIN_URL . 'assets/css/frontend-search.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id ): void {
        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Search', 'talenttrack' ) );
        self::renderHeader( __( 'Search', 'talenttrack' ) );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET search.
        $raw  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
        $kind = isset( $_GET['type'] ) ? sanitize_key( (string) $_GET['type'] ) : '';
        $team = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $sid  = isset( $_GET['season_id'] ) ? absint( $_GET['season_id'] ) : 0;
        $page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        // phpcs:enable

        if ( ! in_array( $kind, SearchRegistry::kinds(), true ) ) $kind = '';
        $page = max( 1, min( SearchRestController::MAX_PAGE, $page ) );

        $teams   = QueryHelpers::get_teams_in_scope( $user_id, current_user_can( 'tt_edit_settings' ) );
        $seasons = self::seasons();
        if ( ! isset( self::idMap( $teams )[ $team ] ) ) $team = 0;
        if ( ! isset( $seasons[ $sid ] ) ) $sid = 0;

        $args = array_filter( [
            'q'         => $raw,
            'type'      => $kind,
            'team_id'   => $team ?: null,
            'season_id' => $sid ?: null,
        ], static fn( $v ): bool => $v !== null && $v !== '' );

        self::renderForm( $raw, $kind, $team, $sid, $teams, $seasons );

        $parent = ParentSearchService::isParentCaller( $user_id );
        $family = SearchViewer::isFamily( $user_id );
        // A parent with no child linked yet, and no record of their own,
        // has nothing to search; say so instead of an empty result.
        if ( $parent && $family && ParentChildResolver::childCount( $user_id ) === 0 && SearchViewer::ownPlayer( $user_id ) === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'No child is linked to your account yet, so there is nothing to search. Ask the academy to link you.', 'talenttrack' ) . '</p>';
            return;
        }

        $query = new SearchQuery( $raw );
        if ( $query->isEmpty() ) {
            if ( ! $family ) {
                $hint = __( 'Search for a player, team, activity, person, setting or help topic.', 'talenttrack' );
            } elseif ( $parent ) {
                $hint = __( 'Search your children\'s activities, evaluations, goals and messages by name or by date.', 'talenttrack' );
            } else {
                $hint = __( 'Search for yourself, your teammates, your team\'s activities, your evaluations and your goals.', 'talenttrack' );
            }
            echo '<p class="tt-search-page__hint">' . esc_html( $hint ) . '</p>';
            self::renderSuggestions( $family );
            return;
        }

        if ( $team > 0 ) $query->team_id = $team;
        if ( $sid > 0 ) {
            $query->from = $seasons[ $sid ]['from'];
            $query->to   = $seasons[ $sid ]['to'];
        }

        $service = new SearchService();
        // One pass: the items of the chosen kind (or all), the count of every kind.
        $all     = $service->page( $query, [], $page, self::PER_PAGE, $user_id, $kind === '' ? null : [ $kind ] );
        $result  = $all;

        self::renderUnderstood( $all['understood'] );
        self::renderKinds( $all['counts'], (int) $all['total'], $kind, $args );

        if ( $result['items'] === [] ) {
            echo '<div class="tt-search-page__empty">';
            echo '<p class="tt-notice">' . esc_html__( 'Nothing matched.', 'talenttrack' ) . '</p>';
            self::renderSuggestions( $family );
            echo '</div>';
            return;
        }

        self::renderResults( $result['items'] );

        $total = $kind === '' ? (int) $all['total'] : (int) ( $all['counts'][ $kind ] ?? 0 );
        self::renderPager( $page, (int) min( SearchRestController::MAX_PAGE, max( 1, (int) ceil( $total / self::PER_PAGE ) ) ), $args );
    }

    /**
     * The field and the team and season filters. A GET form, so every
     * state of the page is a URL that can be shared or bookmarked.
     *
     * @param object[]                                                $teams
     * @param array<int, array{label:string, from:string, to:string}> $seasons
     */
    private static function renderForm( string $raw, string $kind, int $team, int $season, array $teams, array $seasons ): void {
        echo '<form class="tt-search-page__form" method="get" action="' . esc_url( RecordLink::dashboardUrl() ) . '" role="search">';
        echo '<input type="hidden" name="tt_view" value="' . esc_attr( self::SLUG ) . '" />';
        if ( $kind !== '' ) echo '<input type="hidden" name="type" value="' . esc_attr( $kind ) . '" />';

        echo '<div class="tt-search-page__query">';
        echo '<label class="tt-field-label" for="tt-search-page-q">' . esc_html__( 'Search', 'talenttrack' ) . '</label>';
        echo '<input id="tt-search-page-q" class="tt-input" type="search" name="q" value="' . esc_attr( $raw ) . '"'
            . ' inputmode="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false"'
            . ' placeholder="' . esc_attr__( 'Name, #7, O14, 12-10…', 'talenttrack' ) . '" />';
        echo '</div>';

        if ( $teams !== [] ) {
            echo '<div class="tt-search-page__filter">';
            echo '<label class="tt-field-label" for="tt-search-page-team">' . esc_html__( 'Team', 'talenttrack' ) . '</label>';
            echo '<select id="tt-search-page-team" class="tt-input" name="team_id">';
            echo '<option value="">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
            foreach ( $teams as $t ) {
                $id = (int) ( $t->id ?? 0 );
                echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $id, $team, false ) . '>' . esc_html( (string) ( $t->name ?? '' ) ) . '</option>';
            }
            echo '</select>';
            echo '</div>';
        }

        if ( $seasons !== [] ) {
            echo '<div class="tt-search-page__filter">';
            echo '<label class="tt-field-label" for="tt-search-page-season">' . esc_html__( 'Season', 'talenttrack' ) . '</label>';
            echo '<select id="tt-search-page-season" class="tt-input" name="season_id">';
            echo '<option value="">' . esc_html__( 'Any season', 'talenttrack' ) . '</option>';
            foreach ( $seasons as $id => $s ) {
                echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $id, $season, false ) . '>' . esc_html( $s['label'] ) . '</option>';
            }
            echo '</select>';
            echo '</div>';
        }

        echo '<div class="tt-search-page__submit">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Search', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
    }

    /** @param list<array{pattern:string, value:string, label:string}> $understood */
    private static function renderUnderstood( array $understood ): void {
        if ( $understood === [] ) return;
        $labels = array_map( static fn( array $u ): string => (string) $u['label'], $understood );
        echo '<p class="tt-search-page__understood">' . esc_html( sprintf(
            /* translators: %s: what the search read in the query, e.g. "Shirt number 7" */
            __( 'Understood: %s', 'talenttrack' ),
            implode( ', ', $labels )
        ) ) . '</p>';
    }

    /**
     * The kind filter, a chip per kind with its count. Links rather than a
     * select, so on a phone it is one horizontal row of taps.
     *
     * @param array<string,int>    $counts
     * @param array<string,mixed>  $args
     */
    private static function renderKinds( array $counts, int $total, string $active, array $args ): void {
        unset( $args['type'] );
        echo '<ul class="tt-search-page__kinds" aria-label="' . esc_attr__( 'Filter by kind', 'talenttrack' ) . '">';
        self::kindChip( __( 'All', 'talenttrack' ), $total, $active === '', self::url( $args ) );
        foreach ( self::groups() as $group ) {
            $n = (int) ( $counts[ $group['kind'] ] ?? 0 );
            if ( $n === 0 && $active !== $group['kind'] ) continue;
            self::kindChip( $group['label'], $n, $active === $group['kind'], self::url( $args + [ 'type' => $group['kind'] ] ) );
        }
        echo '</ul>';
    }

    private static function kindChip( string $label, int $count, bool $current, string $url ): void {
        echo '<li>';
        echo '<a class="tt-search-page__kind' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $current ? ' aria-current="true"' : '' ) . '>';
        echo '<span>' . esc_html( $label ) . '</span>';
        echo ' <span class="tt-search-page__count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
        echo '</a>';
        echo '</li>';
    }

    /** @param list<array<string,mixed>> $items */
    private static function renderResults( array $items ): void {
        echo '<ul class="tt-search-page__results">';
        foreach ( $items as $item ) {
            $type  = (string) ( $item['type'] ?? '' );
            $label = (string) ( $item['label'] ?? '' );
            $url   = (string) ( $item['url'] ?? '' );
            $sub   = (string) ( $item['sublabel'] ?? '' );

            echo '<li class="tt-search-page__result tt-search-page__result--' . esc_attr( $type ) . '">';
            echo '<a class="tt-search-page__link" href="' . esc_url( BackLink::appendTo( $url ) ) . '">';
            if ( $type === 'player' ) {
                $photo = (string) ( $item['photo_url'] ?? '' );
                if ( $photo !== '' ) {
                    echo '<img class="tt-search-page__avatar" src="' . esc_url( $photo ) . '" width="40" height="40" alt="" loading="lazy" />';
                } else {
                    echo '<span class="tt-search-page__avatar tt-search-page__avatar--initials" aria-hidden="true">' . esc_html( FrontendAppChrome::initials( $label ) ) . '</span>';
                }
            }
            echo '<span class="tt-search-page__text">';
            echo '<span class="tt-search-page__label">' . esc_html( $label ) . '</span>';
            $meta = array_filter( [ self::kindLabel( $type ), $sub ] );
            echo '<span class="tt-search-page__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
            echo '</span>';
            echo '</a>';

            $actions = (array) ( $item['actions'] ?? [] );
            if ( $actions !== [] ) {
                echo '<span class="tt-search-page__actions">';
                foreach ( $actions as $action ) {
                    $action = (array) $action;
                    $a_url  = (string) ( $action['url'] ?? '' );
                    if ( $a_url === '' ) continue;
                    echo '<a class="tt-btn tt-btn-secondary tt-btn-sm" href="' . esc_url( BackLink::appendTo( $a_url ) ) . '"'
                        . ' aria-label="' . esc_attr( (string) ( $action['label'] ?? '' ) . ': ' . $label ) . '">'
                        . esc_html( (string) ( $action['label'] ?? '' ) ) . '</a>';
                }
                echo '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /** @param array<string,mixed> $args */
    private static function renderPager( int $page, int $pages, array $args ): void {
        if ( $pages <= 1 ) return;
        echo '<nav class="tt-search-page__pager" aria-label="' . esc_attr__( 'Search result pages', 'talenttrack' ) . '">';
        if ( $page > 1 ) {
            echo '<a class="tt-btn tt-btn-secondary" rel="prev" href="' . esc_url( self::url( $args + [ 'paged' => $page - 1 ] ) ) . '">' . esc_html__( 'Previous', 'talenttrack' ) . '</a>';
        }
        echo '<span class="tt-search-page__page">' . esc_html( sprintf(
            /* translators: 1: current page, 2: number of pages */
            __( 'Page %1$d of %2$d', 'talenttrack' ),
            $page,
            $pages
        ) ) . '</span>';
        if ( $page < $pages ) {
            echo '<a class="tt-btn tt-btn-secondary" rel="next" href="' . esc_url( self::url( $args + [ 'paged' => $page + 1 ] ) ) . '">' . esc_html__( 'Next', 'talenttrack' ) . '</a>';
        }
        echo '</nav>';
    }

    /** What to try. A parent or a player gets the tips that find their own records. */
    private static function renderSuggestions( bool $family = false ): void {
        echo '<ul class="tt-search-page__tips">';
        if ( $family ) {
            echo '<li>' . esc_html__( 'Type part of a title or a name, such as "training".', 'talenttrack' ) . '</li>';
            echo '<li>' . esc_html__( 'Type a date such as 3 nov, 2026-11-03 or 12-10, or a weekday.', 'talenttrack' ) . '</li>';
            echo '</ul>';
            return;
        }
        echo '<li>' . esc_html__( 'Type part of a name: "den b" finds Daan den Besten.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'Type #7 for the players with shirt number 7.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'Type O14 for the O14 teams and their players.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'Type a date such as 12-10, or a weekday, for the activities on that day.', 'talenttrack' ) . '</li>';
        echo '</ul>';
    }

    /**
     * The academy's seasons, newest first, as a period to filter by.
     *
     * @return array<int, array{label:string, from:string, to:string}>
     */
    private static function seasons(): array {
        if ( ! class_exists( '\\TT\\Modules\\Pdp\\Repositories\\SeasonsRepository' ) ) return [];
        $out = [];
        foreach ( ( new \TT\Modules\Pdp\Repositories\SeasonsRepository() )->all() as $s ) {
            $from = substr( (string) ( $s->start_date ?? '' ), 0, 10 );
            $to   = substr( (string) ( $s->end_date ?? '' ), 0, 10 );
            if ( $from === '' || $to === '' ) continue;
            $out[ (int) ( $s->id ?? 0 ) ] = [ 'label' => (string) ( $s->name ?? $from ), 'from' => $from, 'to' => $to ];
        }
        return $out;
    }

    /**
     * @param object[] $rows
     * @return array<int, object>
     */
    private static function idMap( array $rows ): array {
        $map = [];
        foreach ( $rows as $row ) $map[ (int) ( $row->id ?? 0 ) ] = $row;
        return $map;
    }

    /** @param array<string,mixed> $args */
    private static function url( array $args ): string {
        // The page linking to itself with other filters: not a cross-view link.
        return (string) add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), add_query_arg( 'tt_view', self::SLUG, RecordLink::dashboardUrl() ) ); /* tt-xview-ok */
    }
}
