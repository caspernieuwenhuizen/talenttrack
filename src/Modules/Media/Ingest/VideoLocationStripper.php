<?php
namespace TT\Modules\Media\Ingest;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * VideoLocationStripper (#2611, epic #2589) — removes the location
 * metadata a phone writes into an uploaded MP4 or MOV.
 *
 * Images have had this since #2590: read the capture date, apply the
 * orientation, re-encode, and what lands on disk is pixels. Video could
 * not follow, because re-encoding a video needs a demuxer the plugin does
 * not ship. iOS writes GPS into the `moov` atom by default, so a clip of
 * a training session carried the coordinates of a field full of children
 * inside the file TalentTrack then served back.
 *
 * This does it without a demuxer, and without touching a single byte of
 * the media streams.
 *
 * ## Why the file never changes length
 *
 * The obvious implementation — find the box, cut it out, fix the parent
 * sizes — is the one that corrupts files. `stco` / `co64` hold **absolute
 * file offsets** into `mdat`. In a faststart file `moov` sits before
 * `mdat`, so shortening `moov` slides every sample and invalidates every
 * offset in the table. The video stops playing.
 *
 * So nothing is cut. A located box is **overwritten in place** with a
 * `free` box of exactly the same size, and its payload is zeroed. `free`
 * is defined by ISO 14496-12 as skippable at any level, every reader
 * ignores it, and not one byte offset in the file moves. The coordinates
 * are gone; the container is untouched.
 *
 * ## What it looks for
 *
 * | Box | Where | Written by |
 * | --- | --- | --- |
 * | `©xyz` | `moov/udta`, `moov/trak/udta` | iOS, most Android |
 * | `loci` | `moov/udta`, `moov/trak/udta` | 3GPP, older Android |
 * | `©xyz` | `moov/meta/ilst` | iTunes-style metadata |
 * | indexed entry | `moov/meta/ilst` via `moov/meta/keys` | modern iOS |
 *
 * The last one is why a `©xyz` sweep alone is not enough. Recent iOS
 * writes a `keys` box naming `com.apple.quicktime.location.ISO6709` and
 * an `ilst` whose child box types are 1-based *indexes* into it, so the
 * coordinates live in a box called `\0\0\0\x03`. The keys table has to be
 * read to know which index that is.
 *
 * ## Honesty about what it cannot do
 *
 * A container this does not understand is never reported as clean. After
 * stripping, the `moov` region is scanned for anything still shaped like
 * an ISO 6709 coordinate; if the tree would not parse, or something
 * coordinate-shaped survives, the answer is `unreadable` and the uploader
 * is told the file may still say where it was filmed. Silence would be
 * the friendlier answer and the wrong one.
 */
final class VideoLocationStripper {

    /** Location data was found and removed. */
    public const REMOVED = 'removed';

    /** The file carried no location metadata to begin with. */
    public const NONE = 'none';

    /**
     * The file could not be fully understood, or something
     * coordinate-shaped survived. Never treat as clean.
     */
    public const UNREADABLE = 'unreadable';

    /**
     * Boxes worth descending into. Everything else is skipped by size,
     * which is what keeps this off `mdat` — walking into a gigabyte of
     * sample data looking for coordinates would be both pointless and
     * slow.
     */
    private const CONTAINERS = [ 'moov', 'udta', 'trak', 'meta', 'ilst' ];

    /** Leaf boxes that hold coordinates outright, whatever their parent. */
    private const LOCATION_LEAVES = [ "\xA9xyz", 'loci' ];

    /**
     * ISO 6709, as a phone writes it: `+52.3702+004.8952+002.000/`.
     * Deliberately matches the coordinate shape rather than the word
     * "location" — a `keys` box legitimately still names
     * `com.apple.quicktime.location.ISO6709` after its value is gone, and
     * flagging a schema name as leaked data would make the warning
     * meaningless.
     */
    private const COORD_PATTERN = '/[+-]\d{1,3}\.\d+[+-]\d{1,3}\.\d+/';

    /** Deepest box nesting we will follow. Guards against a crafted tree. */
    private const MAX_DEPTH = 8;

    /** Read/scan buffer. Large enough to swallow a typical `moov` in one pass. */
    private const CHUNK = 1048576;

    /** @var resource */
    private $handle;

    /** @var int */
    private $filesize;

    /** @var bool */
    private $removed = false;

    /** @var bool */
    private $unreadable = false;

    /** @var array<int, string> 1-based key index => key name, from `keys`. */
    private $keys = [];

    /** @param resource $handle An open `r+b` stream on the video. */
    private function __construct( $handle, int $filesize ) {
        $this->handle   = $handle;
        $this->filesize = $filesize;
    }

    /**
     * Strip one file in place.
     *
     * @return string One of REMOVED / NONE / UNREADABLE.
     */
    public static function strip( string $path ): string {
        if ( $path === '' || ! is_file( $path ) ) return self::UNREADABLE;

        $filesize = (int) filesize( $path );
        if ( $filesize < 8 ) return self::UNREADABLE;

        $handle = @fopen( $path, 'r+b' );
        if ( ! is_resource( $handle ) ) return self::UNREADABLE;

        $stripper = new self( $handle, $filesize );

        try {
            $stripper->walk( 0, $filesize, 0, '' );
        } catch ( \Throwable $e ) {
            unset( $e );
            $stripper->unreadable = true;
        }

        $residue = $stripper->hasCoordinateResidue();

        fclose( $handle );

        if ( $stripper->unreadable || $residue ) return self::UNREADABLE;
        return $stripper->removed ? self::REMOVED : self::NONE;
    }

    /**
     * Walk the boxes between `$offset` and `$end`.
     *
     * `$parent` is the containing box type, which is what lets a `©xyz`
     * be treated as a location leaf under `udta` and as an indexed entry
     * under `ilst`.
     */
    private function walk( int $offset, int $end, int $depth, string $parent ): void {
        if ( $depth > self::MAX_DEPTH ) {
            $this->unreadable = true;
            return;
        }

        while ( $offset + 8 <= $end ) {
            $box = $this->readHeader( $offset, $end );
            if ( $box === null ) {
                $this->unreadable = true;
                return;
            }

            [ $size, $type, $header ] = $box;

            $payload     = $offset + $header;
            $payload_end = $offset + $size;

            if ( $parent === 'ilst' ) {
                // An `ilst` child is either a named key (`©xyz`) or a
                // 1-based index into the `keys` table. Both can hold the
                // coordinates; which one it is depends on how the phone
                // wrote the file.
                if ( $this->isLocationIlstEntry( $type ) ) {
                    $this->neutralise( $offset, $size, $header );
                    $offset = $payload_end;
                    continue;
                }
            } elseif ( in_array( $type, self::LOCATION_LEAVES, true ) ) {
                $this->neutralise( $offset, $size, $header );
                $offset = $payload_end;
                continue;
            }

            if ( $type === 'keys' ) {
                $this->readKeys( $payload, $payload_end );
                $offset = $payload_end;
                continue;
            }

            if ( in_array( $type, self::CONTAINERS, true ) ) {
                $child = $type === 'meta'
                    ? $this->metaChildOffset( $payload, $payload_end )
                    : $payload;

                if ( $child === null ) {
                    // A `meta` we cannot make sense of. Not fatal — the
                    // rest of the tree is still worth walking — but it
                    // means we cannot promise the file is clean.
                    $this->unreadable = true;
                } else {
                    $this->walk( $child, $payload_end, $depth + 1, $type );
                }
            }

            $offset = $payload_end;
        }
    }

    /**
     * Parse one box header.
     *
     * @return array{0:int,1:string,2:int}|null size, type, header length.
     */
    private function readHeader( int $offset, int $end ): ?array {
        $raw = $this->readAt( $offset, 8 );
        if ( $raw === null || strlen( $raw ) < 8 ) return null;

        $size = self::uint32( substr( $raw, 0, 4 ) );
        $type = substr( $raw, 4, 4 );

        $header = 8;

        if ( $size === 1 ) {
            // 64-bit `largesize` follows the type.
            $large = $this->readAt( $offset + 8, 8 );
            if ( $large === null || strlen( $large ) < 8 ) return null;

            $high = self::uint32( substr( $large, 0, 4 ) );
            $low  = self::uint32( substr( $large, 4, 4 ) );

            // PHP ints are 64-bit signed; a video whose single box exceeds
            // 2^63 is not a thing, but a crafted header claiming one is.
            if ( $high > 0x7FFFFFFF ) return null;
            $size   = ( $high << 32 ) | $low;
            $header = 16;
        } elseif ( $size === 0 ) {
            // "To the end of the enclosing box." Legal, and the last box.
            $size = $end - $offset;
        }

        if ( $size < $header ) return null;
        if ( $offset + $size > $end ) return null;

        return [ $size, $type, $header ];
    }

    /**
     * Where a `meta` box's children start.
     *
     * ISO 14496-12 makes `meta` a FullBox — four bytes of version and
     * flags before the children. QuickTime does not, and both shapes are
     * in the wild in files a phone produced. Rather than guess from the
     * brand, try the plain layout, then the FullBox one, and take
     * whichever parses as a box.
     *
     * @return int|null Null when neither reading makes sense.
     */
    private function metaChildOffset( int $payload, int $payload_end ): ?int {
        foreach ( [ $payload, $payload + 4 ] as $candidate ) {
            if ( $this->looksLikeBox( $candidate, $payload_end ) ) return $candidate;
        }

        return null;
    }

    /**
     * Is there a real box header at `$offset`?
     *
     * Stricter than `readHeader()` on purpose. `readHeader()` accepts a
     * declared size of zero, which ISO 14496-12 defines as "runs to the end
     * of the enclosing box" — legal, and exactly what a FullBox `meta`'s
     * four zero bytes of version and flags look like. Accepting that here
     * would make the plain-layout guess succeed on every ISO `meta` and
     * send the walk four bytes short of the children.
     *
     * So a candidate has to declare a real size and carry a type made of
     * the characters box types are actually made of.
     */
    private function looksLikeBox( int $offset, int $end ): bool {
        if ( $offset + 8 > $end ) return false;

        $raw = $this->readAt( $offset, 8 );
        if ( $raw === null || strlen( $raw ) < 8 ) return false;

        $size = self::uint32( substr( $raw, 0, 4 ) );
        if ( $size < 8 || $offset + $size > $end ) return false;

        return preg_match( '/^[\xA9A-Za-z0-9]{4}$/', substr( $raw, 4, 4 ) ) === 1;
    }

    /**
     * Read the `keys` table so indexed `ilst` entries can be resolved.
     *
     * Layout: version+flags (4), entry_count (4), then entry_count
     * entries of [size (4)][namespace (4)][name (size - 8)].
     */
    private function readKeys( int $payload, int $payload_end ): void {
        if ( $payload + 8 > $payload_end ) return;

        $head = $this->readAt( $payload, 8 );
        if ( $head === null || strlen( $head ) < 8 ) return;

        $count  = self::uint32( substr( $head, 4, 4 ) );
        $offset = $payload + 8;

        // A count is a claim, not a fact. Bound it against the space the
        // box actually has: the smallest possible entry is 8 bytes.
        $max = intdiv( max( 0, $payload_end - $offset ), 8 );
        if ( $count > $max ) $count = $max;

        for ( $index = 1; $index <= $count; $index++ ) {
            if ( $offset + 8 > $payload_end ) return;

            $entry = $this->readAt( $offset, 8 );
            if ( $entry === null || strlen( $entry ) < 8 ) return;

            $size = self::uint32( substr( $entry, 0, 4 ) );
            if ( $size < 8 || $offset + $size > $payload_end ) return;

            $name = $size > 8 ? (string) $this->readAt( $offset + 8, $size - 8 ) : '';

            $this->keys[ $index ] = $name;
            $offset += $size;
        }
    }

    /**
     * Does this `ilst` child hold location data?
     *
     * Two shapes: the named `©xyz` key, or a box whose four type bytes are
     * a big-endian 1-based index into the `keys` table naming something
     * with "location" in it.
     */
    private function isLocationIlstEntry( string $type ): bool {
        if ( in_array( $type, self::LOCATION_LEAVES, true ) ) return true;

        $index = self::uint32( $type );
        if ( $index <= 0 || ! isset( $this->keys[ $index ] ) ) return false;

        return stripos( $this->keys[ $index ], 'location' ) !== false;
    }

    /**
     * Turn a box into a `free` box of identical size and zero its payload.
     *
     * The size field is left exactly as it was — that is the whole point.
     * Only the four type bytes and the payload change, so every offset in
     * the file, including the ones `stco` holds into `mdat`, stays true.
     */
    private function neutralise( int $offset, int $size, int $header ): void {
        if ( @fseek( $this->handle, $offset + 4 ) !== 0 ) {
            $this->unreadable = true;
            return;
        }

        if ( @fwrite( $this->handle, 'free' ) !== 4 ) {
            $this->unreadable = true;
            return;
        }

        $remaining = $size - $header;

        // A 64-bit header carries 8 bytes of largesize between the type
        // and the payload. Zero those too: they are not data anyone reads
        // on a `free` box, and leaving them is a needless difference.
        if ( $header > 8 ) $remaining += $header - 8;

        if ( $remaining > 0 && @fseek( $this->handle, $offset + 8 ) !== 0 ) {
            $this->unreadable = true;
            return;
        }

        while ( $remaining > 0 ) {
            $write = (int) min( $remaining, self::CHUNK );
            if ( @fwrite( $this->handle, str_repeat( "\0", $write ) ) !== $write ) {
                $this->unreadable = true;
                return;
            }
            $remaining -= $write;
        }

        $this->removed = true;
    }

    /**
     * Anything still shaped like a coordinate anywhere in the file.
     *
     * Runs after the walk, over the whole file rather than only the boxes
     * we understood — the question this answers is "could this file still
     * say where it was filmed", and a box we failed to parse is exactly
     * where the answer would hide.
     *
     * Chunked, with an overlap, so a coordinate straddling a buffer
     * boundary is not missed.
     */
    private function hasCoordinateResidue(): bool {
        $overlap = 64;
        $offset  = 0;
        $carry   = '';

        while ( $offset < $this->filesize ) {
            $chunk = $this->readAt( $offset, self::CHUNK );
            if ( $chunk === null || $chunk === '' ) return false;

            if ( preg_match( self::COORD_PATTERN, $carry . $chunk ) === 1 ) return true;

            $carry   = substr( $chunk, -$overlap );
            $offset += strlen( $chunk );
        }

        return false;
    }

    private function readAt( int $offset, int $length ): ?string {
        if ( $length <= 0 || $offset < 0 || $offset >= $this->filesize ) return null;
        if ( @fseek( $this->handle, $offset ) !== 0 ) return null;

        $data = @fread( $this->handle, $length );
        return is_string( $data ) ? $data : null;
    }

    private static function uint32( string $bytes ): int {
        if ( strlen( $bytes ) < 4 ) return 0;
        $parsed = unpack( 'N', $bytes );
        return is_array( $parsed ) ? (int) $parsed[1] : 0;
    }
}
