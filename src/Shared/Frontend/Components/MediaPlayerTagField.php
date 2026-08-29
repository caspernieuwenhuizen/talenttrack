<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The one control for tagging players on media (#3093).
 *
 * It replaces a per-tile `<details>` full of checkboxes that almost
 * nobody opened, and it fills the hole the add flow had: the wizard asked
 * for a title, a description and a date, and never once mentioned that a
 * photo could name the players in it. Media that is not tagged never
 * reaches the tagged player's own record, so the control being hard to
 * find was quietly costing the thing the feature exists for.
 *
 * Two modes, one component, because two components would drift:
 *
 *   - `wizard` — the picks ride in a hidden field and are applied to the
 *     whole batch when the wizard is submitted. Nothing is written while
 *     the coach is still deciding.
 *   - `tile` — each pick is saved immediately against the media item, the
 *     way the checkboxes did. There is no Save button on a tile, and a
 *     tag that needs confirming elsewhere is a tag people forget to
 *     confirm.
 *
 * The roster travels in the markup rather than behind a typeahead
 * endpoint: it is one activity's squad, twenty-odd names, already loaded
 * by the time the control renders. A round trip per keystroke would buy
 * nothing and would not work on a phone with one bar of signal.
 */
final class MediaPlayerTagField {

    /** How many fields this request has rendered, for unique element ids. */
    private static int $rendered = 0;

    /**
     * @param array{
     *   mode?: string,
     *   players?: array<int, string>,
     *   selected?: array<int, int>,
     *   uuid?: string,
     *   field_name?: string,
     *   mentions?: string,
     *   label?: string
     * } $args
     */
    public static function render( array $args ): void {
        $players = (array) ( $args['players'] ?? [] );
        if ( $players === [] ) return;

        $mode = ( (string) ( $args['mode'] ?? 'wizard' ) ) === 'tile' ? 'tile' : 'wizard';

        // Tile mode carries the link id per player, because detaching is a
        // DELETE on the link rather than on the pair. Wizard mode has no
        // links yet, so a plain list of ids is all there is to carry.
        $selected = (array) ( $args['selected'] ?? [] );

        self::enqueue();

        $roster = [];
        foreach ( $players as $player_id => $name ) {
            $roster[] = [ 'id' => (int) $player_id, 'name' => (string) $name ];
        }

        // Counted rather than random: the id is only there to tie the
        // input to its listbox, and stable markup keeps the E2E suite and
        // any caching layer honest.
        $instance = 'tt-tagfield-' . ( ++self::$rendered );

        printf(
            '<div class="tt-tagfield" data-role="tagfield" data-mode="%1$s" data-roster="%2$s"%3$s%4$s>',
            esc_attr( $mode ),
            esc_attr( (string) wp_json_encode( $roster ) ),
            isset( $args['uuid'] ) ? ' data-uuid="' . esc_attr( (string) $args['uuid'] ) . '"' : '',
            isset( $args['mentions'] ) ? ' data-mentions="' . esc_attr( (string) $args['mentions'] ) . '"' : ''
        );

        if ( isset( $args['label'] ) ) {
            printf(
                '<label class="tt-tagfield__label" for="%1$s"><span>%2$s</span></label>',
                esc_attr( $instance ),
                esc_html( (string) $args['label'] )
            );
        }

        echo '<ul class="tt-tagfield__chips" data-role="chips">';
        foreach ( $selected as $player_id => $link_id ) {
            if ( ! isset( $players[ (int) $player_id ] ) ) continue;
            self::renderChip( (int) $player_id, (string) $players[ (int) $player_id ], (int) $link_id );
        }
        echo '</ul>';

        echo '<div class="tt-tagfield__entry">';
        printf(
            '<input type="text" class="tt-input tt-tagfield__input" id="%1$s" data-role="tagfield-input"'
                . ' role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="%1$s-list"'
                . ' autocomplete="off" placeholder="%2$s" />',
            esc_attr( $instance ),
            esc_attr__( 'Type a name, or @', 'talenttrack' )
        );
        printf(
            '<ul class="tt-tagfield__list" id="%1$s-list" data-role="tagfield-list" role="listbox" aria-label="%2$s" hidden></ul>',
            esc_attr( $instance ),
            esc_attr__( 'Players you can tag', 'talenttrack' )
        );
        echo '</div>';

        if ( $mode === 'wizard' ) {
            printf(
                '<input type="hidden" name="%1$s" data-role="tagfield-value" value="%2$s" />',
                esc_attr( (string) ( $args['field_name'] ?? 'media_tag_player_ids' ) ),
                esc_attr( implode( ',', array_map( 'intval', array_keys( $selected ) ) ) )
            );
        }

        // Adding a chip is silent for anyone not watching the field.
        echo '<p class="tt-visually-hidden" data-role="tagfield-status" role="status" aria-live="polite"></p>';

        echo '</div>';
    }

    /**
     * One chip. Also the shape the script builds client-side, so a tag
     * added without a reload looks like one that came from the server.
     */
    private static function renderChip( int $player_id, string $name, int $link_id ): void {
        printf(
            '<li class="tt-tagfield__chip" data-role="chip" data-player-id="%1$d" data-link-id="%2$d">'
                . '<span class="tt-tagfield__chip-name">%3$s</span>'
                . '<button type="button" class="tt-tagfield__remove" data-role="chip-remove" aria-label="%4$s">'
                . '<span aria-hidden="true">&times;</span></button></li>',
            $player_id,
            $link_id,
            esc_html( $name ),
            esc_attr( sprintf(
                /* translators: %s is a player's name. */
                __( 'Remove %s', 'talenttrack' ),
                $name
            ) )
        );
    }

    public static function enqueue(): void {
        if ( wp_script_is( 'tt-media-tags', 'enqueued' ) ) return;

        wp_enqueue_style(
            'tt-media',
            plugins_url( 'assets/css/frontend-media.css', TT_PLUGIN_FILE ),
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-media-tags',
            plugins_url( 'assets/js/frontend-media-tags.js', TT_PLUGIN_FILE ),
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-media-tags', 'TT_MediaTags', [
            'root'  => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'tagFailed'  => __( 'That tag could not be saved.', 'talenttrack' ),
                'tagNone'    => __( 'Tag players', 'talenttrack' ),
                'tagOne'     => __( '1 player tagged', 'talenttrack' ),
                /* translators: %d is how many players are tagged. */
                'tagCount'   => __( '%d players tagged', 'talenttrack' ),
                'noMatches'  => __( 'No players match', 'talenttrack' ),
                /* translators: %s is a player's name. */
                'removeTag'  => __( 'Remove %s', 'talenttrack' ),
                /* translators: %s is a player's name. */
                'tagAdded'   => __( '%s tagged', 'talenttrack' ),
                /* translators: %s is a player's name. */
                'tagRemoved' => __( '%s untagged', 'talenttrack' ),
            ],
        ] );
    }
}
