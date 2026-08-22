<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Media\Ingest\MediaIngestService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Storage\MediaStorage;

/**
 * MediaGenerator (#2596, epic #2589) — demo photos and video.
 *
 * A demo academy whose media tab is empty does not demo the feature, so
 * this writes enough to show what the surfaces are for: a squad photo per
 * team, a handful of player portraits, and one external video link so the
 * provider badge and the link path are both visible.
 *
 * **Images are drawn at runtime, not shipped.** Committing a folder of
 * JPEGs to the repository to make demo data look real is a poor trade —
 * it inflates every clone forever, and a generated gradient with initials
 * on it reads as obviously-demo, which is exactly what a demo photo of a
 * child should read as. Nothing here resembles a real person.
 *
 * The `video_link` row points at a plausible Veo URL and is never
 * fetched: `VideoLinkResolver` only queries providers with an oEmbed
 * endpoint, and Veo has none. So generating demo data makes no outbound
 * request, which matters for an offline or air-gapped install.
 */
class MediaGenerator implements DependentGeneratorInterface {

    /** Portraits per team, drawn from the front of the roster. */
    private const PORTRAITS_PER_TEAM = 3;

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var object[] */
    private array $players;

    private string $language;

    public static function category(): string {
        return 'media';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->players, $ctx->contentLanguage );
    }

    /**
     * @param object[] $teams
     * @param object[] $players
     */
    public function __construct( DemoBatchRegistry $registry, array $teams, array $players, string $language = '' ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->players  = $players;
        $this->language = $language !== '' ? $language : 'en_US';
    }

    public function generate(): int {
        // GD is what draws the placeholder. Without it the generator does
        // nothing rather than writing rows pointing at files that were
        // never created — an empty tab is better than a broken one.
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return 0;

        $media = new MediaRepository();
        $links = new MediaLinksRepository();
        $total = 0;

        foreach ( $this->teams as $index => $team ) {
            $team_id = (int) ( $team->id ?? 0 );
            if ( $team_id <= 0 ) continue;

            $roster = $this->rosterFor( $team_id );

            $squad_id = $this->storeImage(
                $media,
                $this->isDutch() ? 'Teamfoto' : 'Squad photo',
                (string) ( $team->name ?? 'Team' ),
                $this->capturedAt( 30 + $index )
            );
            if ( $squad_id > 0 ) {
                $links->link( $squad_id, MediaEntityType::TEAM, $team_id, true );
                $this->registry->tag( 'media', $squad_id );
                $total++;

                // The squad photo depicts the whole team, which is the
                // co-depiction case the epic decided on (D5) — so the demo
                // shows it rather than leaving that policy abstract.
                foreach ( array_slice( $roster, 0, self::PORTRAITS_PER_TEAM ) as $player ) {
                    $links->link( $squad_id, MediaEntityType::PLAYER, (int) $player->id );
                }
            }

            foreach ( array_slice( $roster, 0, self::PORTRAITS_PER_TEAM ) as $offset => $player ) {
                $player_id = (int) ( $player->id ?? 0 );
                if ( $player_id <= 0 ) continue;

                $portrait_id = $this->storeImage(
                    $media,
                    $this->isDutch() ? 'In de training' : 'In training',
                    trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) ),
                    $this->capturedAt( 7 + $offset * 3 )
                );
                if ( $portrait_id <= 0 ) continue;

                $links->link( $portrait_id, MediaEntityType::PLAYER, $player_id, true );
                $this->registry->tag( 'media', $portrait_id );
                $total++;
            }

            // One link row per academy is enough to show the shape.
            if ( $index === 0 ) {
                $link_id = $media->insert( [
                    'kind'         => MediaKind::VIDEO_LINK,
                    'title'        => $this->isDutch() ? 'Wedstrijdbeelden' : 'Match footage',
                    'provider'     => 'veo',
                    'external_url' => 'https://app.veo.co/matches/demo-match/',
                    'captured_at'  => $this->capturedAt( 14 ),
                ] );
                if ( $link_id > 0 ) {
                    $links->link( $link_id, MediaEntityType::TEAM, $team_id );
                    $this->registry->tag( 'media', $link_id );
                    $total++;
                }
            }
        }

        return $total;
    }

    // Internals

    /**
     * Draw a placeholder JPEG and hand it to the store.
     *
     * Deliberately abstract: a coloured gradient with initials, nothing
     * that could be mistaken for a photograph of a real child.
     */
    private function storeImage( MediaRepository $media, string $title, string $subject, string $captured_at ): int {
        $path = $this->drawPlaceholder( $subject );
        if ( $path === '' ) return 0;

        $storage = MediaStorage::default();
        $key     = $storage->store( $path, 'jpg' );
        if ( $key === '' ) {
            if ( file_exists( $path ) ) @unlink( $path );
            return 0;
        }

        return $media->insert( [
            'kind'            => MediaKind::IMAGE,
            'title'           => $title,
            'storage_adapter' => $storage->name(),
            'storage_key'     => $key,
            'mime_type'       => 'image/jpeg',
            'file_size'       => (int) $storage->size( $key ),
            'width'           => 640,
            'height'          => 480,
            'captured_at'     => $captured_at,
        ] );
    }

    /** @return string Temp path, or '' if it could not be drawn. */
    private function drawPlaceholder( string $subject ): string {
        $image = @imagecreatetruecolor( 640, 480 );
        if ( ! $image ) return '';

        // A stable hue per subject, so the same demo seed produces the
        // same colours and a regenerated academy looks unchanged.
        $hue  = abs( crc32( $subject ) ) % 360;
        $rgb  = self::hueToRgb( $hue );
        $back = imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
        imagefilledrectangle( $image, 0, 0, 640, 480, $back );

        $ink = imagecolorallocate( $image, 255, 255, 255 );
        imagestring( $image, 5, 24, 220, self::initials( $subject ), $ink );

        // Through the shared helper: demo generation runs in admin today,
        // but it is reachable from WP-CLI and REST too, where
        // `wp_tempnam()` is not loaded (#2674).
        $path = MediaIngestService::tempFile( 'tt-demo-media' );
        if ( $path === '' ) {
            imagedestroy( $image );
            return '';
        }

        $ok = @imagejpeg( $image, $path, 70 );
        imagedestroy( $image );

        if ( ! $ok ) {
            if ( file_exists( $path ) ) @unlink( $path );
            return '';
        }

        return $path;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hueToRgb( int $hue ): array {
        $h = $hue / 60;
        $x = (int) round( 255 * ( 1 - abs( fmod( $h, 2 ) - 1 ) ) );

        switch ( (int) floor( $h ) % 6 ) {
            case 0:  return [ 200, $x, 60 ];
            case 1:  return [ $x, 180, 60 ];
            case 2:  return [ 60, 180, $x ];
            case 3:  return [ 60, $x, 180 ];
            case 4:  return [ $x, 60, 180 ];
            default: return [ 180, 60, $x ];
        }
    }

    private static function initials( string $subject ): string {
        $parts = preg_split( '/\s+/', trim( $subject ) ) ?: [];
        $out   = '';
        foreach ( array_slice( $parts, 0, 2 ) as $part ) {
            if ( $part !== '' ) $out .= strtoupper( substr( $part, 0, 1 ) );
        }
        return $out !== '' ? $out : 'TT';
    }

    /** @return object[] */
    private function rosterFor( int $team_id ): array {
        $out = [];
        foreach ( $this->players as $player ) {
            if ( (int) ( $player->team_id ?? 0 ) === $team_id ) $out[] = $player;
        }
        return $out;
    }

    private function capturedAt( int $days_ago ): string {
        return gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );
    }

    private function isDutch(): bool {
        return strpos( $this->language, 'nl' ) === 0;
    }
}
