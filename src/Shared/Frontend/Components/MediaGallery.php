<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\Delivery\MediaDelivery;
use TT\Modules\Media\Links\VideoLinkResolver;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;

/**
 * MediaGallery (#2594, epic #2589) — media attached to one record.
 *
 * One component for the player tab and, from #2595, the team and activity
 * tabs. The same reasoning as `MediaUploader`: three near-copies would
 * drift in exactly the places a reader notices — the keyboard order, the
 * active state, the way a video decides whether to download itself.
 *
 * Two decisions carry most of the behaviour here:
 *
 *   - **Sorted by when it was taken, not when it was uploaded.** That is
 *     the repository's job and it is unconditional, but it is the reason
 *     this component exists on a player rather than being a file list: a
 *     player's media is a chronological story about the player.
 *   - **`preload="metadata"`, never `auto`.** A tab holding eight clips
 *     would otherwise pull tens of megabytes the moment it opens, over
 *     whatever connection the coach happens to be on.
 *
 * Tiles reserve their space with `aspect-ratio` so nothing shifts once
 * the images arrive (CLAUDE.md §2), and everything below the fold is
 * `loading="lazy"`.
 */
final class MediaGallery {

    /**
     * Tiles per page (#2745).
     *
     * Three rows of eight on a desktop grid, twelve rows of two at 360px.
     * `loading="lazy"` already spares the bandwidth of what is below the
     * fold; what a cap saves is DOM size and an unbounded query, both of
     * which a season of media would otherwise grow without limit.
     */
    public const PAGE_SIZE = 24;

    /**
     * @param array{
     *   entity_type: string,
     *   entity_id: int,
     *   can_edit?: bool,
     *   tag_players?: array<int, string>,
     *   empty_headline?: string,
     *   empty_explainer?: string
     * } $args
     */
    public static function render( array $args ): void {
        $entity_type = (string) ( $args['entity_type'] ?? '' );
        $entity_id   = (int) ( $args['entity_id'] ?? 0 );

        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return;

        $user = get_current_user_id();

        // #2745 — one page, plus a probe row so we know whether to offer
        // "Show more" without counting the whole join again.
        $rows     = ( new MediaRepository() )->listForEntity( $entity_type, $entity_id, false, self::PAGE_SIZE + 1 );
        $has_more = count( $rows ) > self::PAGE_SIZE;
        if ( $has_more ) $rows = array_slice( $rows, 0, self::PAGE_SIZE );

        // Rows consumed, not rows shown: filterVisible can drop items, and
        // an offset advanced by the visible count would step over them.
        $next_offset = count( $rows );

        $items = ( new MediaVisibilityService() )->filterVisible( $user, $rows );

        $can_edit = (bool) ( $args['can_edit'] ?? false );

        self::enqueue();

        $tag_players = (array) ( $args['tag_players'] ?? [] );

        // The wrapper, the grid and the lightbox are emitted even when there
        // is nothing to show. #2742 — an upload inserts its tile into this
        // grid without a reload, and the empty gallery used to render no
        // gallery at all, so there was nowhere to put it. An empty
        // `.tt-media-grid` is display:grid with no children and paints
        // nothing, so this costs the reader no whitespace.
        echo '<div class="tt-media-gallery" data-entity-type="' . esc_attr( $entity_type ) . '" data-entity-id="' . (int) $entity_id . '">';

        // Only genuinely empty when there is no next page either — a first
        // page whose items are all filtered out still has more to fetch.
        if ( $items === [] && ! $has_more ) {
            echo '<div data-role="empty">';
            EmptyStateCard::render( array_filter( [
                'headline'  => (string) ( $args['empty_headline'] ?? __( 'No photos or video yet', 'talenttrack' ) ),
                'explainer' => (string) ( $args['empty_explainer'] ?? '' ),
                'cta_label' => $can_edit ? __( 'Add media', 'talenttrack' ) : null,
                'cta_url'   => $can_edit ? self::addUrl( $entity_type, $entity_id ) : null,
            ] ) );
            echo '</div>';
        }

        echo '<ul class="tt-media-grid" data-role="grid">';

        foreach ( $items as $item ) {
            self::renderTile( $item, $can_edit, $tag_players );
        }

        echo '</ul>';

        // #2745 — inside the gallery wrapper so the delegated click
        // handler already bound there picks it up.
        if ( $has_more ) {
            printf(
                '<button type="button" class="tt-btn tt-btn-secondary tt-media-more" data-role="more"'
                    . ' data-offset="%1$d" data-limit="%2$d">%3$s</button>',
                (int) $next_offset,
                (int) self::PAGE_SIZE,
                esc_html__( 'Show more', 'talenttrack' )
            );
        }

        // Appending tiles is silent for anyone not watching the screen, so
        // the count that arrived is announced here instead.
        echo '<p class="tt-visually-hidden" data-role="status" role="status" aria-live="polite"></p>';

        echo '</div>';

        self::renderLightbox();

        if ( $can_edit ) self::renderInlineUploader( $entity_type, $entity_id );
    }

    /**
     * One tile as markup, for the REST layer to hand back after an upload.
     *
     * The alternative was building the tile in JavaScript from the JSON
     * payload, which would mean two implementations of the same markup —
     * and the JS one would have re-created #2715, because the payload's
     * `_links` deliberately carry no nonce and an `<img src>` needs one.
     * Rendering here keeps a single source and gets the tag control, the
     * video badge and the external-link shape for free.
     *
     * @param array<int, string> $tag_players
     */
    public static function tileHtml( object $item, bool $can_edit, array $tag_players = [] ): string {
        ob_start();
        self::renderTile( $item, $can_edit, $tag_players );
        return (string) ob_get_clean();
    }

    // Internals

    /** @param array<int, string> $tag_players */
    private static function renderTile( object $item, bool $can_edit, array $tag_players = [] ): void {
        $uuid = (string) $item->uuid;
        $kind = (string) $item->kind;
        // Nonce-bearing, because <img>/<video> cannot send the header (#2715).
        $file_url = MediaDelivery::url( $uuid, MediaDelivery::VARIANT_FILE );

        $title = (string) $item->title;
        if ( $title === '' ) $title = MediaKind::label( $kind );

        $when = self::whenLabel( $item );

        echo '<li class="tt-media-tile tt-media-tile--' . esc_attr( $kind ) . '">';

        if ( $kind === MediaKind::VIDEO_LINK ) {
            self::renderLinkTile( $item, $title, $when );
        } else {
            $thumb = ! empty( $item->thumbnail_key )
                ? MediaDelivery::url( $uuid, MediaDelivery::VARIANT_THUMB )
                : $file_url;

            printf(
                '<button type="button" class="tt-media-tile__open" data-role="open" data-uuid="%1$s" data-kind="%2$s" data-src="%3$s" data-title="%4$s" data-when="%5$s">',
                esc_attr( $uuid ),
                esc_attr( $kind ),
                esc_url( $file_url ),
                esc_attr( $title ),
                esc_attr( $when )
            );

            // A video with no poster still needs its box reserved, so the
            // placeholder is a styled block rather than a missing image.
            if ( $kind === MediaKind::VIDEO && empty( $item->thumbnail_key ) ) {
                echo '<span class="tt-media-tile__placeholder" aria-hidden="true"></span>';
            } else {
                printf(
                    '<img class="tt-media-tile__img" src="%1$s" alt="%2$s" loading="lazy" decoding="async" />',
                    esc_url( $thumb ),
                    esc_attr( $title )
                );
            }

            if ( $kind === MediaKind::VIDEO ) {
                echo '<span class="tt-media-tile__play" aria-hidden="true">▶</span>';
                $duration = (int) ( $item->duration_seconds ?? 0 );
                if ( $duration > 0 ) {
                    echo '<span class="tt-media-tile__badge">' . esc_html( self::duration( $duration ) ) . '</span>';
                }
            }

            echo '</button>';
        }

        echo '<div class="tt-media-tile__meta">';
        echo '<span class="tt-media-tile__title">' . esc_html( $title ) . '</span>';
        if ( $when !== '' ) {
            echo '<span class="tt-media-tile__when">' . esc_html( $when ) . '</span>';
        }
        echo '</div>';

        if ( $can_edit && $tag_players !== [] ) {
            self::renderTagControl( $item, $tag_players );
        }

        if ( $can_edit ) {
            printf(
                '<button type="button" class="tt-media-tile__delete" data-role="delete" data-uuid="%1$s" aria-label="%2$s">%3$s</button>',
                esc_attr( $uuid ),
                esc_attr(
                    sprintf(
                        /* translators: %s is the title of the photo or video. */
                        __( 'Remove %s', 'talenttrack' ),
                        $title
                    )
                ),
                esc_html__( 'Remove', 'talenttrack' )
            );
        }

        echo '</li>';
    }

    /**
     * Tag the players in this photo, from the activity's own roster.
     *
     * A `<details>` rather than a modal: on a phone it pushes the page
     * down instead of covering the photo the coach is looking at while
     * they decide who is in it, and it needs no focus management.
     *
     * What is inside it is the shared chip field (#3093) — the same
     * control the wizard's Details step offers, so tagging looks and
     * behaves the same whether it happens while adding or afterwards.
     * The summary keeps the count, because a collapsed control still has
     * to say whether anyone is tagged.
     *
     * @param array<int, string> $players
     */
    private static function renderTagControl( object $item, array $players ): void {
        $attached = [];
        foreach ( ( new MediaLinksRepository() )->listForMedia( (int) $item->id ) as $link ) {
            if ( (string) $link->entity_type === MediaEntityType::PLAYER ) {
                $attached[ (int) $link->entity_id ] = (int) $link->id;
            }
        }

        $uuid = (string) $item->uuid;

        echo '<details class="tt-media-tag" data-role="tag" data-uuid="' . esc_attr( $uuid ) . '">';
        echo '<summary class="tt-media-tag__summary">' . esc_html(
            $attached === []
                ? __( 'Tag players', 'talenttrack' )
                : sprintf(
                    /* translators: %d is how many players are tagged in this photo or video. */
                    _n( '%d player tagged', '%d players tagged', count( $attached ), 'talenttrack' ),
                    count( $attached )
                )
        ) . '</summary>';

        MediaPlayerTagField::render( [
            'mode'     => 'tile',
            'players'  => $players,
            'selected' => $attached,
            'uuid'     => $uuid,
        ] );

        echo '</details>';
    }

    /**
     * A link tile leaves the site, so it is an anchor rather than a
     * lightbox trigger — and it says where it is going before the tap.
     */
    private static function renderLinkTile( object $item, string $title, string $when ): void {
        $provider = (string) ( $item->provider ?? VideoLinkResolver::PROVIDER_OTHER );

        printf(
            '<a class="tt-media-tile__open tt-media-tile__open--link" href="%1$s" target="_blank" rel="noopener noreferrer">',
            esc_url( (string) $item->external_url )
        );

        if ( ! empty( $item->thumbnail_key ) ) {
            printf(
                '<img class="tt-media-tile__img" src="%1$s" alt="%2$s" loading="lazy" decoding="async" />',
                esc_url( MediaDelivery::url( (string) $item->uuid, MediaDelivery::VARIANT_THUMB ) ),
                esc_attr( $title )
            );
        } else {
            echo '<span class="tt-media-tile__placeholder" aria-hidden="true"></span>';
        }

        echo '<span class="tt-media-tile__play" aria-hidden="true">▶</span>';
        echo '<span class="tt-media-tile__badge tt-media-tile__badge--provider">' . esc_html( self::providerLabel( $provider ) ) . '</span>';
        echo '</a>';
    }

    /**
     * Native `<dialog>` rather than a hand-rolled overlay: it brings its
     * own focus trap, its own Escape handling and its own inertness for
     * the page behind, none of which are worth reimplementing badly.
     */
    private static function renderLightbox(): void {
        echo '<dialog class="tt-media-lightbox" data-role="lightbox" aria-label="' . esc_attr__( 'Media viewer', 'talenttrack' ) . '">';
        echo '<div class="tt-media-lightbox__stage" data-role="stage"></div>';
        echo '<div class="tt-media-lightbox__bar">';
        echo '<button type="button" class="tt-media-lightbox__nav" data-role="prev" aria-label="' . esc_attr__( 'Previous', 'talenttrack' ) . '">‹</button>';
        echo '<span class="tt-media-lightbox__caption" data-role="caption"></span>';
        echo '<button type="button" class="tt-media-lightbox__nav" data-role="next" aria-label="' . esc_attr__( 'Next', 'talenttrack' ) . '">›</button>';
        echo '<button type="button" class="tt-media-lightbox__close" data-role="close">' . esc_html__( 'Close', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</dialog>';
    }

    private static function renderInlineUploader( string $entity_type, int $entity_id ): void {
        echo '<details class="tt-media-inline">';
        echo '<summary class="tt-media-inline__summary">' . esc_html__( 'Add photos or video', 'talenttrack' ) . '</summary>';
        MediaUploader::render( [
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'allow_link'  => true,
        ] );
        echo '</details>';
    }

    /**
     * Wizard when it is available, inline uploader otherwise — the
     * flat-form fallback CLAUDE.md §3 asks for, which is on the page
     * either way.
     */
    private static function addUrl( string $entity_type, int $entity_id ): string {
        return \TT\Shared\Wizards\WizardEntryPoint::urlFor(
            'new-media',
            '',
            [ 'entity_type' => $entity_type, 'entity_id' => $entity_id ]
        );
    }

    /** Capture date, falling back to upload date, formatted for reading. */
    private static function whenLabel( object $item ): string {
        $raw = ! empty( $item->captured_at ) ? (string) $item->captured_at : (string) ( $item->created_at ?? '' );
        if ( $raw === '' ) return '';

        $ts = strtotime( $raw );
        return $ts ? date_i18n( (string) get_option( 'date_format' ), $ts ) : '';
    }

    private static function duration( int $seconds ): string {
        $minutes = (int) floor( $seconds / 60 );
        return sprintf( '%d:%02d', $minutes, $seconds % 60 );
    }

    private static function providerLabel( string $provider ): string {
        switch ( $provider ) {
            case VideoLinkResolver::PROVIDER_YOUTUBE: return 'YouTube';
            case VideoLinkResolver::PROVIDER_VIMEO:   return 'Vimeo';
            case VideoLinkResolver::PROVIDER_VEO:     return 'Veo';
            case VideoLinkResolver::PROVIDER_HUDL:    return 'Hudl';
            default:                                  return __( 'Link', 'talenttrack' );
        }
    }

    public static function enqueue(): void {
        if ( wp_script_is( 'tt-media-gallery', 'enqueued' ) ) return;

        wp_enqueue_style(
            'tt-media',
            plugins_url( 'assets/css/frontend-media.css', TT_PLUGIN_FILE ),
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-media-gallery',
            plugins_url( 'assets/js/frontend-media-gallery.js', TT_PLUGIN_FILE ),
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-media-gallery', 'TT_MediaGallery', [
            'root'  => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'confirmDelete' => __( 'Remove this permanently? The file is deleted, not archived.', 'talenttrack' ),
                'deleteFailed'  => __( 'It could not be removed.', 'talenttrack' ),
                'loading'       => __( 'Loading…', 'talenttrack' ),
                'moreLoaded'    => __( 'More photos and video loaded.', 'talenttrack' ),
                'moreFailed'    => __( 'Could not load more. Try again.', 'talenttrack' ),
            ],
        ] );
    }
}
