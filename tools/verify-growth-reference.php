<?php
/**
 * Standalone verification of the shipped LMS table: recompute every WHO
 * published cut-off from L, M, S and compare with what WHO printed.
 * No WordPress needed — the arithmetic is the whole point.
 */

$data      = require $argv[1];
$lms       = $data['lms'];
$published = $data['published_sd'];

function value_at( float $z, array $t ): ?float {
    [ $l, $m, $s ] = $t;
    if ( $m <= 0 || $s <= 0 ) return null;
    if ( abs( $l ) < 1e-9 ) return $m * exp( $s * $z );
    $base = 1 + $l * $s * $z;
    if ( $base <= 0 ) return null;
    return $m * $base ** ( 1 / $l );
}

function sds_of( float $v, array $t ): ?float {
    [ $l, $m, $s ] = $t;
    if ( $v <= 0 || $m <= 0 || $s <= 0 ) return null;
    if ( abs( $l ) < 1e-9 ) return log( $v / $m ) / $s;
    return ( ( $v / $m ) ** $l - 1 ) / ( $l * $s );
}

$checked = 0;
$worst   = 0.0;
$fails   = [];

foreach ( [ 'boys', 'girls' ] as $table ) {
    foreach ( $published[ $table ] as $month => $cuts ) {
        foreach ( $cuts as $z => $expected ) {
            $actual = value_at( (float) $z, $lms[ $table ][ $month ] );
            if ( $actual === null ) { $fails[] = "$table m$month z$z null"; continue; }
            $diff = abs( $actual - $expected );
            if ( $diff > $worst ) $worst = $diff;
            if ( $diff > 0.001 ) {
                $fails[] = sprintf( '%s m%d z%d: WHO=%.3f ours=%.6f diff=%.6f', $table, $month, $z, $expected, $actual, $diff );
            }
            $checked++;
        }
    }
}

printf( "checked   : %d cut-offs\n", $checked );
printf( "worst diff: %.8f\n", $worst );
printf( "failures  : %d\n", count( $fails ) );
foreach ( array_slice( $fails, 0, 10 ) as $f ) echo "  $f\n";

// Inverse round-trip
$rt_worst = 0.0;
foreach ( [ 'boys', 'girls' ] as $table ) {
    foreach ( [ 61, 100, 150, 200, 228 ] as $month ) {
        foreach ( [ -3.0, -1.5, 0.0, 1.5, 3.0 ] as $z ) {
            $v  = value_at( $z, $lms[ $table ][ $month ] );
            $z2 = sds_of( (float) $v, $lms[ $table ][ $month ] );
            $rt_worst = max( $rt_worst, abs( $z2 - $z ) );
        }
    }
}
printf( "round-trip worst z error: %.10f\n", $rt_worst );

exit( $fails ? 1 : 0 );
