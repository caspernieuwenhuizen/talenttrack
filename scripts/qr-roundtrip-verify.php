<?php
/**
 * Round-trip verification for QrCodeRenderer (#1393 / #1506).
 *
 * Encodes representative otpauth URIs with the production QrCodeRenderer,
 * then decodes the resulting matrix with an INDEPENDENT from-spec
 * ISO/IEC 18004 byte-mode decoder, and asserts the decoded string equals
 * the input. Exercises the v6–v10 paths a real otpauth URI always hits.
 *
 * Standalone (the repo has no PHPUnit harness — #1388); wired into CI via
 * .github/workflows/qr-roundtrip.yml, the same way scripts/audit-1-coverage.php
 * is. Run: php scripts/qr-roundtrip-verify.php
 * Exit 0 = all round-trips pass; exit 1 = a mismatch (encoder bug).
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../src/Modules/Mfa/Domain/QrCodeRenderer.php';

use TT\Modules\Mfa\Domain\QrCodeRenderer;

/* ---- parse the rendered SVG back into a 0/1 matrix ---- */
function svg_to_matrix( string $svg ): array {
    if ( ! preg_match( '/viewBox="0 0 (\d+) \d+"/', $svg, $m ) ) {
        throw new RuntimeException( 'no viewBox' );
    }
    $total = (int) $m[1];
    $quiet = 4;
    $size  = $total - 2 * $quiet;
    $matrix = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
    if ( preg_match_all( '/M(\d+) (\d+)h1v1h-1z/', $svg, $mm, PREG_SET_ORDER ) ) {
        foreach ( $mm as $r ) {
            $x = (int) $r[1] - $quiet;
            $y = (int) $r[2] - $quiet;
            if ( $x >= 0 && $y >= 0 && $x < $size && $y < $size ) $matrix[ $y ][ $x ] = 1;
        }
    }
    return $matrix;
}

/* ---- independent function-pattern (reserved) map per spec ---- */
function alignment_positions( int $v ): array {
    $map = [ 1=>[],2=>[6,18],3=>[6,22],4=>[6,26],5=>[6,30],6=>[6,34],
             7=>[6,22,38],8=>[6,24,42],9=>[6,26,46],10=>[6,28,50] ];
    return $map[ $v ];
}
function reserved_map( int $version ): array {
    $size = 17 + 4 * $version;
    $res  = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
    $mark = function( &$res, $r0, $r1, $c0, $c1 ) use ( $size ) {
        for ( $r = $r0; $r <= $r1; $r++ ) for ( $c = $c0; $c <= $c1; $c++ )
            if ( $r >= 0 && $c >= 0 && $r < $size && $c < $size ) $res[ $r ][ $c ] = 1;
    };
    // Finders + separators (8x8 incl. separator on inner edges).
    $mark( $res, 0, 7, 0, 7 );
    $mark( $res, 0, 7, $size - 8, $size - 1 );
    $mark( $res, $size - 8, $size - 1, 0, 7 );
    // Timing patterns (row 6 / col 6) between the finders.
    for ( $i = 8; $i < $size - 8; $i++ ) { $res[6][$i] = 1; $res[$i][6] = 1; }
    // Alignment patterns (5x5), skipping finder corners.
    $pos = alignment_positions( $version ); $n = count( $pos );
    for ( $i = 0; $i < $n; $i++ ) for ( $j = 0; $j < $n; $j++ ) {
        if ( ( $i===0 && $j===0 ) || ( $i===0 && $j===$n-1 ) || ( $i===$n-1 && $j===0 ) ) continue;
        $mark( $res, $pos[$i]-2, $pos[$i]+2, $pos[$j]-2, $pos[$j]+2 );
    }
    // Format info strips.
    for ( $i = 0; $i < 9; $i++ ) { $res[8][$i] = 1; $res[$i][8] = 1; }
    for ( $i = 0; $i < 8; $i++ ) { $res[8][$size-1-$i] = 1; $res[$size-1-$i][8] = 1; }
    // Dark module.
    $res[$size-8][8] = 1;
    // Version info (v7+).
    if ( $version >= 7 ) {
        $mark( $res, 0, 5, $size-11, $size-9 );
        $mark( $res, $size-11, $size-9, 0, 5 );
    }
    return $res;
}

/* ---- read the mask out of the format-info strip (canonical layout) ---- */
function read_format_mask( array $matrix, int $size ): int {
    // Canonical primary copy: bits 0..5 along row 8 (cols 0..5), bit 6 at
    // [8,7], bit 7 at [8,8], bit 8 at [7,8], bits 9..14 up col 8 (rows 5..0).
    $coords = [ [8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],
                [7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8] ];
    $bits = 0;
    foreach ( $coords as $i => $rc ) $bits |= ( $matrix[$rc[0]][$rc[1]] & 1 ) << $i;
    $unmasked = $bits ^ 0x5412;
    $data5    = ( $unmasked >> 10 ) & 0x1F;
    return $data5 & 0x7;
}
function mask_bit( int $mask, int $r, int $c ): int {
    switch ( $mask ) {
        case 0: return ( ( $r + $c ) % 2 === 0 ) ? 1 : 0;
        case 1: return ( $r % 2 === 0 ) ? 1 : 0;
        case 2: return ( $c % 3 === 0 ) ? 1 : 0;
        case 3: return ( ( $r + $c ) % 3 === 0 ) ? 1 : 0;
        case 4: return ( ( ( (int) floor($r/2) ) + ( (int) floor($c/3) ) ) % 2 === 0 ) ? 1 : 0;
        case 5: return ( ( ( $r*$c ) % 2 ) + ( ( $r*$c ) % 3 ) === 0 ) ? 1 : 0;
        case 6: return ( ( ( ( $r*$c ) % 2 ) + ( ( $r*$c ) % 3 ) ) % 2 === 0 ) ? 1 : 0;
        case 7: return ( ( ( ( $r+$c ) % 2 ) + ( ( $r*$c ) % 3 ) ) % 2 === 0 ) ? 1 : 0;
    }
    return 0;
}

/* ---- read the data bitstream in the zigzag order, codewords out ---- */
function read_codewords( array $matrix, array $reserved, int $mask, int $size ): array {
    $bits = '';
    $up = true;
    for ( $col = $size - 1; $col > 0; $col -= 2 ) {
        if ( $col === 6 ) $col--;
        for ( $i = 0; $i < $size; $i++ ) {
            $row = $up ? ( $size - 1 - $i ) : $i;
            for ( $c_off = 0; $c_off < 2; $c_off++ ) {
                $c = $col - $c_off;
                if ( $reserved[$row][$c] === 1 ) continue;
                $bit = $matrix[$row][$c] ^ mask_bit( $mask, $row, $c );
                $bits .= (string) $bit;
            }
        }
        $up = ! $up;
    }
    $cw = [];
    for ( $i = 0; $i + 8 <= strlen( $bits ); $i += 8 ) $cw[] = bindec( substr( $bits, $i, 8 ) );
    return $cw;
}

/* ---- de-interleave + drop ECC → original data codewords ---- */
function block_layout( int $v ): array {
    $map = [ 1=>[1,19,0,0,7],2=>[1,34,0,0,10],3=>[1,55,0,0,15],4=>[1,80,0,0,20],
             5=>[1,108,0,0,26],6=>[2,68,0,0,18],7=>[2,78,0,0,20],8=>[2,97,0,0,24],
             9=>[2,116,0,0,30],10=>[2,68,2,69,18] ];
    return $map[ $v ];
}
function deinterleave_data( array $cw, int $version ): array {
    [ $g1b, $g1d, $g2b, $g2d, $ecc ] = block_layout( $version );
    $blocks = [];
    $lens   = [];
    for ( $i = 0; $i < $g1b; $i++ ) { $blocks[] = []; $lens[] = $g1d; }
    for ( $i = 0; $i < $g2b; $i++ ) { $blocks[] = []; $lens[] = $g2d; }
    $total_blocks = count( $blocks );
    $total_data   = $g1b*$g1d + $g2b*$g2d;
    $max_data     = max( $g1d, $g2d );
    $p = 0;
    for ( $col = 0; $col < $max_data; $col++ ) {
        for ( $b = 0; $b < $total_blocks; $b++ ) {
            if ( $col < $lens[$b] ) { $blocks[$b][$col] = $cw[$p] ?? 0; $p++; }
        }
    }
    $out = [];
    foreach ( $blocks as $b ) { ksort( $b ); foreach ( $b as $byte ) $out[] = $byte; }
    return array_slice( $out, 0, $total_data );
}

/* ---- parse the byte-mode segment → original string ---- */
function parse_byte_mode( array $data_bytes, int $version ): string {
    $bits = '';
    foreach ( $data_bytes as $b ) $bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
    $mode = bindec( substr( $bits, 0, 4 ) );
    if ( $mode !== 0b0100 ) throw new RuntimeException( "mode != byte (got $mode)" );
    $count_bits = ( $version <= 9 ) ? 8 : 16;
    $len  = bindec( substr( $bits, 4, $count_bits ) );
    $off  = 4 + $count_bits;
    $out  = '';
    for ( $i = 0; $i < $len; $i++ ) $out .= chr( bindec( substr( $bits, $off + $i*8, 8 ) ) );
    return $out;
}

function decode( string $svg ): array {
    $matrix  = svg_to_matrix( $svg );
    $size    = count( $matrix );
    $version = (int) ( ( $size - 17 ) / 4 );
    $reserved= reserved_map( $version );
    $mask    = read_format_mask( $matrix, $size );
    $cw      = read_codewords( $matrix, $reserved, $mask, $size );
    $data    = deinterleave_data( $cw, $version );
    return [ parse_byte_mode( $data, $version ), $version, $mask ];
}

/* ---- test corpus: otpauth URIs sized to land at v6..v10 ---- */
$secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; // 32-char base32
function otpauth( string $secret, string $account, string $issuer ): string {
    $params = http_build_query( [
        'secret'=>$secret,'issuer'=>$issuer,'algorithm'=>'SHA1','digits'=>'6','period'=>'30'
    ] );
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . $params;
}
$cases = [
    'realistic short' => otpauth( $secret, 'coach@ajax.nl', 'TalentTrack' ),
    'realistic site'  => otpauth( $secret, 'jan.devries@azalkmaaracademy.nl', 'TalentTrack AZ Alkmaar Academy' ),
    'long site'       => otpauth( $secret, 'verylongname.coach@sparta-rotterdam-youth.example.org', 'TalentTrack Sparta Rotterdam Jeugdopleiding' ),
    'v6 boundary'     => str_repeat( 'A', 120 ),
    'v7 boundary'     => str_repeat( 'B', 145 ),
    'v8 boundary'     => str_repeat( 'C', 180 ),
    'v9 boundary'     => str_repeat( 'D', 210 ),
    'v10 max'         => str_repeat( 'E', 271 ),
];

$fail = 0;
foreach ( $cases as $name => $input ) {
    $svg = QrCodeRenderer::svg( $input );
    if ( $svg === '' ) { printf( "SKIP  %-16s (len %d > cap, renderer refused)\n", $name, strlen($input) ); continue; }
    try {
        [ $decoded, $version, $mask ] = decode( $svg );
    } catch ( Throwable $e ) {
        printf( "FAIL  %-16s len=%d  decode error: %s\n", $name, strlen($input), $e->getMessage() );
        $fail++; continue;
    }
    $ok = ( $decoded === $input );
    printf( "%s  %-16s len=%-3d v%-2d mask=%d  %s\n",
        $ok ? 'PASS' : 'FAIL', $name, strlen($input), $version, $mask,
        $ok ? '' : 'MISMATCH' );
    if ( ! $ok ) {
        $fail++;
        printf( "      in : %s\n      out: %s\n", $input, $decoded );
    }
}
echo $fail === 0 ? "\nALL ROUND-TRIPS PASS\n" : "\n$fail FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
