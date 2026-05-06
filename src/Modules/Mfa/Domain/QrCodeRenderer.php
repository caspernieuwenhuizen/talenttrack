<?php
namespace TT\Modules\Mfa\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QrCodeRenderer — pure-PHP QR Code generator for the MFA enrollment
 * flow (#0086 Workstream B Child 1, sprint 2).
 *
 * Self-contained byte-mode encoder at error-correction level L, with
 * automatic version selection (v1..v10) and full mask-pattern penalty
 * scoring per ISO/IEC 18004:2015. The output is an inline SVG string
 * suitable for embedding directly in the wizard's render output.
 *
 * Why server-side, why pure PHP:
 *   - The otpauth:// URI we encode contains the user's raw TOTP secret.
 *     Rendering server-side keeps the secret inside a single trust
 *     boundary (PHP request handler → SVG bytes → user's screen) rather
 *     than handing the secret to a third-party JS QR library that
 *     would need its own license / supply-chain review.
 *   - No vendored JS dependency; no Composer dependency; no GD / Imagick
 *     requirement (pure SVG, no raster image).
 *
 * Supported QR specs by this renderer:
 *   - Mode: byte (8-bit). Mode indicator = 0100. Sufficient for ASCII
 *     otpauth URIs.
 *   - Versions: 1..10 (21x21 .. 57x57 modules). The longest realistic
 *     otpauth URI on this codebase is ~180 characters, which fits
 *     comfortably inside v8 (192 bytes data capacity at ECC-L). Cap
 *     at v10 (271 bytes) so callers can be extra-cautious without
 *     overflowing the encoder.
 *   - Error correction: L only (~7% recovery). Smaller QR for the
 *     same data; the QR is displayed on a screen, not printed onto
 *     a sticker that might get scratched, so high error-correction
 *     isn't worth the version inflation.
 *   - Mask: full penalty-based mask selection (all 8 masks evaluated,
 *     lowest penalty wins) per spec §7.8.
 *
 * Reference: ISO/IEC 18004:2015 — sections §6 (data encoding) and §7
 * (matrix layout). Constants below match the standard's tables.
 */
final class QrCodeRenderer {

    /**
     * Render a QR code for the given text as inline SVG. The SVG has
     * `width=100%` so it scales to its container; callers wrap in a
     * `style="max-width:240px"` for the desktop case.
     *
     * @param string $text       The string to encode (typically an otpauth URI).
     * @param int    $module_px  The target on-screen pixel size of one module
     *                           (used only for the SVG `viewBox` ratio; the SVG
     *                           is scalable). Default 6.
     * @return string  SVG markup, ready for `echo`.
     */
    public static function svg( string $text, int $module_px = 6 ): string {
        $matrix = self::buildMatrix( $text );
        $size   = count( $matrix );
        // Quiet zone: 4 modules per spec.
        $quiet  = 4;
        $total  = $size + 2 * $quiet;
        $px     = $total * max( 1, $module_px );

        // Build the foreground path as a single `<path d="...">` of `M x y h 1 v 1 h -1 z` rectangles
        // so the SVG payload is small and scales cleanly.
        $rects = '';
        for ( $y = 0; $y < $size; $y++ ) {
            for ( $x = 0; $x < $size; $x++ ) {
                if ( $matrix[ $y ][ $x ] === 1 ) {
                    $rects .= 'M' . ( $x + $quiet ) . ' ' . ( $y + $quiet ) . 'h1v1h-1z';
                }
            }
        }

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"';
        $svg .= ' width="' . (int) $px . '" height="' . (int) $px . '" shape-rendering="crispEdges" role="img" aria-label="MFA secret QR code">';
        $svg .= '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>';
        $svg .= '<path d="' . $rects . '" fill="#000000"/>';
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Build the final QR module matrix (1 = dark, 0 = light) for `$text`.
     * Pipeline: choose version → encode bits → split into blocks →
     * compute Reed-Solomon ECC → interleave → place into matrix → apply
     * best mask → write format info.
     *
     * @return array<int, array<int, int>>  Square matrix indexed by [row][col].
     */
    private static function buildMatrix( string $text ): array {
        $bytes  = array_values( unpack( 'C*', $text ) );
        $length = count( $bytes );

        // Pick the smallest version that fits — table is hand-copied
        // from ISO/IEC 18004:2015 Table 7 (byte mode, ECC level L).
        $capacities = [
            1 => 17,  2 => 32,  3 => 53,  4 => 78,  5 => 106,
            6 => 134, 7 => 154, 8 => 192, 9 => 230, 10 => 271,
        ];
        $version = 0;
        foreach ( $capacities as $v => $cap ) {
            if ( $length <= $cap ) { $version = $v; break; }
        }
        if ( $version === 0 ) {
            // Defensive: caller passed something longer than 271 bytes,
            // which our otpauth URIs never do. Fall back to v10 and
            // truncate; the QR will still be valid for whatever fit.
            $version = 10;
            $bytes   = array_slice( $bytes, 0, 271 );
            $length  = 271;
        }

        $bitstream = self::encodeData( $bytes, $version );
        $codewords = self::buildCodewords( $bitstream, $version );

        // Empty matrix + reserved-module mask. The reserved mask tracks
        // function patterns (finders, timing, alignment, format-info,
        // version-info) so the data-placement loop and the mask-XOR pass
        // know what to leave alone.
        $size = 17 + 4 * $version;
        $matrix   = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
        $reserved = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
        self::placeFunctionPatterns( $matrix, $reserved, $version );
        self::placeData( $matrix, $reserved, $codewords );

        $best_mask = self::pickBestMask( $matrix, $reserved );
        self::applyMask( $matrix, $reserved, $best_mask );
        self::writeFormatInfo( $matrix, $best_mask );
        if ( $version >= 7 ) {
            self::writeVersionInfo( $matrix, $version );
        }
        return $matrix;
    }

    // -----------------------------------------------------------------
    // Data encoding (§6)
    // -----------------------------------------------------------------

    /**
     * Encode the byte sequence into the QR bitstream:
     *   mode (4) + length (8 or 16) + data (8n) + terminator (≤4)
     *   + zero-pad to byte + alternating pad bytes (0xEC, 0x11) to capacity.
     *
     * @param array<int,int> $bytes
     * @return string  Bit string of '0'/'1'.
     */
    private static function encodeData( array $bytes, int $version ): string {
        $bits = '0100'; // byte mode
        // Character count indicator: 8 bits for v1-v9, 16 bits for v10+.
        $count_bits = ( $version <= 9 ) ? 8 : 16;
        $bits .= str_pad( decbin( count( $bytes ) ), $count_bits, '0', STR_PAD_LEFT );
        foreach ( $bytes as $b ) {
            $bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
        }
        $total_data_codewords = self::dataCodewordsByVersion( $version );
        $total_bits           = $total_data_codewords * 8;

        // Terminator: up to 4 zero bits, no more than the remaining capacity.
        $bits .= str_repeat( '0', min( 4, max( 0, $total_bits - strlen( $bits ) ) ) );
        // Pad to byte boundary.
        if ( strlen( $bits ) % 8 !== 0 ) {
            $bits .= str_repeat( '0', 8 - ( strlen( $bits ) % 8 ) );
        }
        // Fill with alternating 0xEC / 0x11 pad bytes.
        $pad_bytes = [ '11101100', '00010001' ];
        $i = 0;
        while ( strlen( $bits ) < $total_bits ) {
            $bits .= $pad_bytes[ $i % 2 ];
            $i++;
        }
        return $bits;
    }

    /**
     * Build the final codeword sequence: split the data into ECC-L
     * blocks, compute Reed-Solomon ECC per block, interleave data and
     * ECC across blocks per spec §7.6.
     *
     * @return array<int,int>  Bytes (0..255) ready for placement.
     */
    private static function buildCodewords( string $bitstream, int $version ): array {
        // Convert bitstream into bytes.
        $data_bytes = [];
        for ( $i = 0; $i < strlen( $bitstream ); $i += 8 ) {
            $data_bytes[] = bindec( substr( $bitstream, $i, 8 ) );
        }
        [ $g1_blocks, $g1_data, $g2_blocks, $g2_data, $ecc_per_block ] = self::blockLayout( $version );

        // Split data into blocks.
        $blocks = [];
        $pos    = 0;
        for ( $i = 0; $i < $g1_blocks; $i++ ) {
            $blocks[] = array_slice( $data_bytes, $pos, $g1_data );
            $pos += $g1_data;
        }
        for ( $i = 0; $i < $g2_blocks; $i++ ) {
            $blocks[] = array_slice( $data_bytes, $pos, $g2_data );
            $pos += $g2_data;
        }

        // Reed-Solomon ECC per block.
        $generator = self::rsGeneratorPoly( $ecc_per_block );
        $ecc_blocks = [];
        foreach ( $blocks as $block ) {
            $ecc_blocks[] = self::rsRemainder( $block, $generator );
        }

        // Interleave: column-by-column across blocks for data, then ECC.
        $max_data_len = max( $g1_data, $g2_data );
        $interleaved = [];
        for ( $col = 0; $col < $max_data_len; $col++ ) {
            foreach ( $blocks as $block ) {
                if ( isset( $block[ $col ] ) ) {
                    $interleaved[] = $block[ $col ];
                }
            }
        }
        for ( $col = 0; $col < $ecc_per_block; $col++ ) {
            foreach ( $ecc_blocks as $block ) {
                $interleaved[] = $block[ $col ];
            }
        }
        return $interleaved;
    }

    // -----------------------------------------------------------------
    // QR version constants (ECC-L only — see class docblock)
    // -----------------------------------------------------------------

    /**
     * Total data codewords (excluding ECC) for ECC-L at each version.
     * From ISO/IEC 18004 Table 9.
     */
    private static function dataCodewordsByVersion( int $version ): int {
        $map = [
            1 => 19,  2 => 34,  3 => 55,  4 => 80,  5 => 108,
            6 => 136, 7 => 156, 8 => 194, 9 => 232, 10 => 274,
        ];
        return $map[ $version ];
    }

    /**
     * Block layout for ECC-L at each version: [g1_blocks, g1_data, g2_blocks, g2_data, ecc_per_block].
     * From ISO/IEC 18004 Table 9.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int}
     */
    private static function blockLayout( int $version ): array {
        $map = [
            1  => [ 1, 19,  0, 0,   7 ],
            2  => [ 1, 34,  0, 0,  10 ],
            3  => [ 1, 55,  0, 0,  15 ],
            4  => [ 1, 80,  0, 0,  20 ],
            5  => [ 1, 108, 0, 0,  26 ],
            6  => [ 2, 68,  0, 0,  18 ],
            7  => [ 2, 78,  0, 0,  20 ],
            8  => [ 2, 97,  0, 0,  24 ],
            9  => [ 2, 116, 0, 0,  30 ],
            10 => [ 2, 68,  2, 69, 18 ],
        ];
        return $map[ $version ];
    }

    // -----------------------------------------------------------------
    // Reed-Solomon over GF(256) with primitive 0x11D
    // -----------------------------------------------------------------

    private static array $gf_exp = [];
    private static array $gf_log = [];

    private static function gfInit(): void {
        if ( ! empty( self::$gf_exp ) ) return;
        $exp = array_fill( 0, 512, 0 );
        $log = array_fill( 0, 256, 0 );
        $x = 1;
        for ( $i = 0; $i < 255; $i++ ) {
            $exp[ $i ] = $x;
            $log[ $x ] = $i;
            $x <<= 1;
            if ( $x & 0x100 ) $x ^= 0x11D;
        }
        for ( $i = 255; $i < 512; $i++ ) $exp[ $i ] = $exp[ $i - 255 ];
        self::$gf_exp = $exp;
        self::$gf_log = $log;
    }

    private static function gfMul( int $a, int $b ): int {
        if ( $a === 0 || $b === 0 ) return 0;
        return self::$gf_exp[ self::$gf_log[ $a ] + self::$gf_log[ $b ] ];
    }

    /**
     * Build the generator polynomial for `$degree` ECC bytes.
     * Coefficients are returned high-to-low.
     *
     * @return array<int,int>
     */
    private static function rsGeneratorPoly( int $degree ): array {
        self::gfInit();
        $g = [ 1 ];
        for ( $i = 0; $i < $degree; $i++ ) {
            // Multiply g(x) by (x - alpha^i).
            $next = array_fill( 0, count( $g ) + 1, 0 );
            for ( $j = 0; $j < count( $g ); $j++ ) {
                $next[ $j ]     ^= self::gfMul( $g[ $j ], 1 ); // shift-up coefficient stays
                $next[ $j + 1 ] ^= self::gfMul( $g[ $j ], self::$gf_exp[ $i ] );
            }
            $g = $next;
        }
        return $g;
    }

    /**
     * Compute the Reed-Solomon remainder (the ECC bytes) for `$message`
     * against the precomputed generator polynomial. Output length =
     * count($generator) - 1.
     *
     * @param array<int,int> $message
     * @param array<int,int> $generator
     * @return array<int,int>
     */
    private static function rsRemainder( array $message, array $generator ): array {
        self::gfInit();
        $degree = count( $generator ) - 1;
        $remainder = array_fill( 0, $degree, 0 );
        foreach ( $message as $b ) {
            $factor = $b ^ array_shift( $remainder );
            $remainder[] = 0;
            if ( $factor !== 0 ) {
                for ( $i = 0; $i <= $degree; $i++ ) {
                    if ( isset( $generator[ $i ] ) ) {
                        // generator[0] is always 1 — skip it; we shift the remainder by 1 anyway.
                        if ( $i === 0 ) continue;
                        $remainder[ $i - 1 ] ^= self::gfMul( $generator[ $i ], $factor );
                    }
                }
            }
        }
        return $remainder;
    }

    // -----------------------------------------------------------------
    // Function-pattern placement (§6.3)
    // -----------------------------------------------------------------

    /**
     * Drop in finder patterns + separators + timing patterns + alignment
     * patterns + format-info reservation + version-info reservation.
     * `$reserved` is updated alongside `$matrix` so subsequent passes
     * skip these modules.
     *
     * @param array<int,array<int,int>> $matrix
     * @param array<int,array<int,int>> $reserved
     */
    private static function placeFunctionPatterns( array &$matrix, array &$reserved, int $version ): void {
        $size = 17 + 4 * $version;

        // Three finder patterns (top-left, top-right, bottom-left). Each
        // is a 7x7 module square inside an 8x8 reservation (separator).
        self::drawFinder( $matrix, $reserved, 0, 0 );
        self::drawFinder( $matrix, $reserved, $size - 7, 0 );
        self::drawFinder( $matrix, $reserved, 0, $size - 7 );

        // Timing patterns: row 6 and col 6, alternating 1/0 starting at 1
        // through the un-reserved span.
        for ( $i = 8; $i < $size - 8; $i++ ) {
            $bit = ( $i % 2 === 0 ) ? 1 : 0;
            $matrix[ 6 ][ $i ]   = $bit; $reserved[ 6 ][ $i ]   = 1;
            $matrix[ $i ][ 6 ]   = $bit; $reserved[ $i ][ 6 ]   = 1;
        }

        // Alignment patterns: 5x5 inner pattern at every (row, col) on
        // the version-specific position grid, except where they collide
        // with the finders.
        $positions = self::alignmentPositions( $version );
        $count = count( $positions );
        for ( $i = 0; $i < $count; $i++ ) {
            for ( $j = 0; $j < $count; $j++ ) {
                $r = $positions[ $i ];
                $c = $positions[ $j ];
                // Skip the three finder corners.
                if ( ( $i === 0 && $j === 0 ) ||
                     ( $i === 0 && $j === $count - 1 ) ||
                     ( $i === $count - 1 && $j === 0 ) ) continue;
                self::drawAlignment( $matrix, $reserved, $r, $c );
            }
        }

        // Reserve format-info modules (15 bits, in two strips around the
        // top-left finder + along the top of bottom-left and right of top-right).
        for ( $i = 0; $i < 9; $i++ ) {
            $reserved[ 8 ][ $i ] = 1;
            $reserved[ $i ][ 8 ] = 1;
        }
        for ( $i = 0; $i < 8; $i++ ) {
            $reserved[ 8 ][ $size - 1 - $i ] = 1;
            $reserved[ $size - 1 - $i ][ 8 ] = 1;
        }
        // Dark module (always 1) at (8, size-8) per spec §7.9.1.
        $matrix[ $size - 8 ][ 8 ] = 1;
        $reserved[ $size - 8 ][ 8 ] = 1;

        // Version-info reservation (v7+): 6x3 blocks at top-right and bottom-left.
        if ( $version >= 7 ) {
            for ( $r = 0; $r < 6; $r++ ) {
                for ( $c = $size - 11; $c < $size - 8; $c++ ) {
                    $reserved[ $r ][ $c ] = 1;
                }
            }
            for ( $r = $size - 11; $r < $size - 8; $r++ ) {
                for ( $c = 0; $c < 6; $c++ ) {
                    $reserved[ $r ][ $c ] = 1;
                }
            }
        }
    }

    /**
     * 7x7 finder pattern with the surrounding 1-module separator.
     */
    private static function drawFinder( array &$matrix, array &$reserved, int $r0, int $c0 ): void {
        for ( $r = -1; $r <= 7; $r++ ) {
            for ( $c = -1; $c <= 7; $c++ ) {
                $rr = $r0 + $r; $cc = $c0 + $c;
                if ( $rr < 0 || $cc < 0 || $rr >= count( $matrix ) || $cc >= count( $matrix[0] ) ) continue;
                $reserved[ $rr ][ $cc ] = 1;
                if ( $r === -1 || $r === 7 || $c === -1 || $c === 7 ) {
                    $matrix[ $rr ][ $cc ] = 0; // separator
                } elseif ( ( $r >= 0 && $r <= 6 ) && ( $c >= 0 && $c <= 6 ) ) {
                    // Outer ring (1), inner ring (0), center 3x3 (1).
                    $is_outer  = ( $r === 0 || $r === 6 || $c === 0 || $c === 6 );
                    $is_centre = ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 );
                    $matrix[ $rr ][ $cc ] = ( $is_outer || $is_centre ) ? 1 : 0;
                }
            }
        }
    }

    /**
     * 5x5 alignment pattern (centre dark module + 1-module light ring + 1-module dark ring).
     */
    private static function drawAlignment( array &$matrix, array &$reserved, int $r0, int $c0 ): void {
        for ( $r = -2; $r <= 2; $r++ ) {
            for ( $c = -2; $c <= 2; $c++ ) {
                $rr = $r0 + $r; $cc = $c0 + $c;
                $reserved[ $rr ][ $cc ] = 1;
                $is_outer  = ( abs( $r ) === 2 || abs( $c ) === 2 );
                $is_centre = ( $r === 0 && $c === 0 );
                $matrix[ $rr ][ $cc ] = ( $is_outer || $is_centre ) ? 1 : 0;
            }
        }
    }

    /**
     * Alignment-pattern centre coordinates per version. Hand-copied from
     * ISO/IEC 18004 Annex E.
     *
     * @return array<int,int>
     */
    private static function alignmentPositions( int $version ): array {
        $map = [
            1  => [],
            2  => [ 6, 18 ],
            3  => [ 6, 22 ],
            4  => [ 6, 26 ],
            5  => [ 6, 30 ],
            6  => [ 6, 34 ],
            7  => [ 6, 22, 38 ],
            8  => [ 6, 24, 42 ],
            9  => [ 6, 26, 46 ],
            10 => [ 6, 28, 50 ],
        ];
        return $map[ $version ];
    }

    // -----------------------------------------------------------------
    // Data placement (§7.7.3)
    // -----------------------------------------------------------------

    /**
     * Snake the codewords through the matrix in 2-column right-to-left
     * strips, skipping reserved modules and the timing column.
     *
     * @param array<int,array<int,int>> $matrix
     * @param array<int,array<int,int>> $reserved
     * @param array<int,int>            $codewords
     */
    private static function placeData( array &$matrix, array $reserved, array $codewords ): void {
        $size  = count( $matrix );
        $bits  = '';
        foreach ( $codewords as $b ) {
            $bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
        }
        $idx = 0;
        $up  = true; // upward strip flag
        for ( $col = $size - 1; $col > 0; $col -= 2 ) {
            if ( $col === 6 ) $col--; // skip the vertical timing column
            for ( $i = 0; $i < $size; $i++ ) {
                $row = $up ? ( $size - 1 - $i ) : $i;
                for ( $c_off = 0; $c_off < 2; $c_off++ ) {
                    $c = $col - $c_off;
                    if ( $reserved[ $row ][ $c ] === 1 ) continue;
                    $bit = ( $idx < strlen( $bits ) ) ? (int) $bits[ $idx ] : 0;
                    $matrix[ $row ][ $c ] = $bit;
                    $idx++;
                }
            }
            $up = ! $up;
        }
    }

    // -----------------------------------------------------------------
    // Mask patterns (§7.8) + penalty scoring
    // -----------------------------------------------------------------

    /**
     * The 8 standard mask functions. Returns true when (row, col) should
     * have its data bit XOR'd. Function-pattern modules (`$reserved`) are
     * never masked.
     */
    private static function maskBit( int $mask, int $r, int $c ): int {
        switch ( $mask ) {
            case 0: return ( ( $r + $c ) % 2 === 0 ) ? 1 : 0;
            case 1: return ( $r % 2 === 0 ) ? 1 : 0;
            case 2: return ( $c % 3 === 0 ) ? 1 : 0;
            case 3: return ( ( $r + $c ) % 3 === 0 ) ? 1 : 0;
            case 4: return ( ( ( (int) floor( $r / 2 ) ) + ( (int) floor( $c / 3 ) ) ) % 2 === 0 ) ? 1 : 0;
            case 5: return ( ( ( $r * $c ) % 2 ) + ( ( $r * $c ) % 3 ) === 0 ) ? 1 : 0;
            case 6: return ( ( ( ( $r * $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) % 2 === 0 ) ? 1 : 0;
            case 7: return ( ( ( ( $r + $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) % 2 === 0 ) ? 1 : 0;
        }
        return 0;
    }

    /**
     * XOR the chosen mask onto data modules in-place.
     *
     * @param array<int,array<int,int>> $matrix
     * @param array<int,array<int,int>> $reserved
     */
    private static function applyMask( array &$matrix, array $reserved, int $mask ): void {
        $size = count( $matrix );
        for ( $r = 0; $r < $size; $r++ ) {
            for ( $c = 0; $c < $size; $c++ ) {
                if ( $reserved[ $r ][ $c ] === 1 ) continue;
                if ( self::maskBit( $mask, $r, $c ) === 1 ) {
                    $matrix[ $r ][ $c ] ^= 1;
                }
            }
        }
    }

    /**
     * Run all 8 masks against a fresh copy of the data matrix, score
     * each, return the index with the lowest penalty.
     *
     * @param array<int,array<int,int>> $matrix
     * @param array<int,array<int,int>> $reserved
     */
    private static function pickBestMask( array $matrix, array $reserved ): int {
        $best_mask  = 0;
        $best_score = PHP_INT_MAX;
        for ( $m = 0; $m < 8; $m++ ) {
            $candidate = $matrix;
            self::applyMask( $candidate, $reserved, $m );
            // Format info contributes to penalty too; write a placeholder
            // version into the candidate so the masked-finder edge regions
            // get scored correctly.
            self::writeFormatInfo( $candidate, $m );
            $score = self::penaltyScore( $candidate );
            if ( $score < $best_score ) {
                $best_score = $score;
                $best_mask  = $m;
            }
        }
        return $best_mask;
    }

    /**
     * Spec §7.8.3.1 penalty rules:
     *   N1 — runs of 5+ same-coloured modules in row/col: score = (run_length - 2).
     *   N2 — 2x2 blocks of same colour: 3 per block.
     *   N3 — finder-like patterns (1011101 with 4 light modules either side): 40 each.
     *   N4 — overall dark proportion deviation from 50%: 10 per 5% off.
     *
     * @param array<int,array<int,int>> $matrix
     */
    private static function penaltyScore( array $matrix ): int {
        $size = count( $matrix );
        $score = 0;

        // N1 — runs in rows.
        for ( $r = 0; $r < $size; $r++ ) {
            $prev = -1; $run = 0;
            for ( $c = 0; $c < $size; $c++ ) {
                if ( $matrix[ $r ][ $c ] === $prev ) {
                    $run++;
                } else {
                    if ( $run >= 5 ) $score += ( $run - 2 );
                    $prev = $matrix[ $r ][ $c ]; $run = 1;
                }
            }
            if ( $run >= 5 ) $score += ( $run - 2 );
        }
        // N1 — runs in cols.
        for ( $c = 0; $c < $size; $c++ ) {
            $prev = -1; $run = 0;
            for ( $r = 0; $r < $size; $r++ ) {
                if ( $matrix[ $r ][ $c ] === $prev ) {
                    $run++;
                } else {
                    if ( $run >= 5 ) $score += ( $run - 2 );
                    $prev = $matrix[ $r ][ $c ]; $run = 1;
                }
            }
            if ( $run >= 5 ) $score += ( $run - 2 );
        }

        // N2 — 2x2 same-colour blocks.
        for ( $r = 0; $r < $size - 1; $r++ ) {
            for ( $c = 0; $c < $size - 1; $c++ ) {
                $v = $matrix[ $r ][ $c ];
                if ( $matrix[ $r ][ $c + 1 ] === $v &&
                     $matrix[ $r + 1 ][ $c ] === $v &&
                     $matrix[ $r + 1 ][ $c + 1 ] === $v ) {
                    $score += 3;
                }
            }
        }

        // N3 — finder-like patterns: 1011101 with 4 same-colour either side.
        $pattern = '10111010000';
        $reverse = '00001011101';
        for ( $r = 0; $r < $size; $r++ ) {
            $row_str = '';
            for ( $c = 0; $c < $size; $c++ ) $row_str .= (string) $matrix[ $r ][ $c ];
            $score += 40 * substr_count( $row_str, $pattern );
            $score += 40 * substr_count( $row_str, $reverse );
        }
        for ( $c = 0; $c < $size; $c++ ) {
            $col_str = '';
            for ( $r = 0; $r < $size; $r++ ) $col_str .= (string) $matrix[ $r ][ $c ];
            $score += 40 * substr_count( $col_str, $pattern );
            $score += 40 * substr_count( $col_str, $reverse );
        }

        // N4 — dark module proportion.
        $dark = 0;
        for ( $r = 0; $r < $size; $r++ ) {
            for ( $c = 0; $c < $size; $c++ ) {
                if ( $matrix[ $r ][ $c ] === 1 ) $dark++;
            }
        }
        $total      = $size * $size;
        $proportion = $dark * 100 / $total;
        $deviation  = (int) ( abs( $proportion - 50 ) / 5 );
        $score     += 10 * $deviation;

        return $score;
    }

    // -----------------------------------------------------------------
    // Format info (§7.9.1) and version info (§7.9.2)
    // -----------------------------------------------------------------

    /**
     * Encode the 5-bit (ECC level + mask) into 15 bits via BCH(15,5)
     * and XOR with the spec mask 0x5412. Then write into the two format-info
     * strips around the finders.
     *
     * Format bits layout: bits 0..4 = ECC|mask, bits 5..14 = BCH ECC.
     *
     * @param array<int,array<int,int>> $matrix
     */
    private static function writeFormatInfo( array &$matrix, int $mask ): void {
        // ECC level L = 01 (per spec Table 12).
        $data = ( 0b01 << 3 ) | $mask;
        // BCH(15,5) with generator 0x537.
        $rem = $data << 10;
        $g   = 0x537;
        for ( $i = 14; $i >= 10; $i-- ) {
            if ( ( $rem >> $i ) & 1 ) {
                $rem ^= $g << ( $i - 10 );
            }
        }
        $bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;

        $size = count( $matrix );
        // Write bits 0..14 along the top-left finder (vertical then horizontal).
        $coords = [
            // bit 0 .. bit 14 — clockwise around the top-left finder.
            [ 8, 0 ], [ 8, 1 ], [ 8, 2 ], [ 8, 3 ], [ 8, 4 ], [ 8, 5 ],
            [ 8, 7 ], [ 8, 8 ],
            [ 7, 8 ], [ 5, 8 ], [ 4, 8 ], [ 3, 8 ], [ 2, 8 ], [ 1, 8 ], [ 0, 8 ],
        ];
        // Mirror: bits 0..7 along the right edge of bottom-left finder
        // (rows size-1 down to size-8, col 8); bits 8..14 along the
        // bottom edge of top-right finder (row 8, cols size-7 to size-1).
        $coords_mirror = [
            [ $size - 1, 8 ], [ $size - 2, 8 ], [ $size - 3, 8 ], [ $size - 4, 8 ],
            [ $size - 5, 8 ], [ $size - 6, 8 ], [ $size - 7, 8 ], [ $size - 8, 8 ],
            [ 8, $size - 7 ], [ 8, $size - 6 ], [ 8, $size - 5 ],
            [ 8, $size - 4 ], [ 8, $size - 3 ], [ 8, $size - 2 ], [ 8, $size - 1 ],
        ];

        for ( $i = 0; $i < 15; $i++ ) {
            $bit = ( $bits >> $i ) & 1;
            [ $r, $c ] = $coords[ $i ];
            $matrix[ $r ][ $c ] = $bit;
            [ $r, $c ] = $coords_mirror[ $i ];
            $matrix[ $r ][ $c ] = $bit;
        }
        // Restore the always-dark module at (size-8, 8) — it spatially
        // collides with mirror bit 7 (above), so re-set it last so the
        // matrix is spec-compliant regardless of the bit value.
        $matrix[ $size - 8 ][ 8 ] = 1;
    }

    /**
     * Encode the 6-bit version into 18 bits via BCH(18,6) and write into
     * the two 6x3 version-info reservations (top-right + bottom-left).
     *
     * @param array<int,array<int,int>> $matrix
     */
    private static function writeVersionInfo( array &$matrix, int $version ): void {
        $rem = $version << 12;
        $g   = 0x1F25;
        for ( $i = 17; $i >= 12; $i-- ) {
            if ( ( $rem >> $i ) & 1 ) {
                $rem ^= $g << ( $i - 12 );
            }
        }
        $bits = ( $version << 12 ) | $rem;

        $size = count( $matrix );
        for ( $i = 0; $i < 18; $i++ ) {
            $bit = ( $bits >> $i ) & 1;
            $a   = (int) floor( $i / 3 );  // 0..5
            $b   = $i % 3;                  // 0..2
            // Top-right block at (a, size - 11 + b).
            $matrix[ $a ][ $size - 11 + $b ] = $bit;
            // Bottom-left block at (size - 11 + b, a).
            $matrix[ $size - 11 + $b ][ $a ] = $bit;
        }
    }
}
