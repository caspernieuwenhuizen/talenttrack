<?php
namespace TT\Tests\Php;

use TT\Shared\Frontend\ThemePreference;
use WP_UnitTestCase;

/**
 * #5089 — every theme clears the contrast floors of CLAUDE.md §2.
 *
 * Text at 4.5:1, the focus ring and the active marker at 3:1, for every
 * role a theme sets: body, muted and soft ink, the buttons and their
 * hovers, links, the secondary printed as text, the header, and the
 * rail's text, marker and brand mark. A token resolves the way the browser
 * resolves it: the theme's own declaration, else tokens.css, following
 * `var()` and its fallback. A token-only theme is measured with the rules
 * of theme-shared.css, which it loads.
 *
 * The named exception is an owner decision rather than an oversight:
 * `theme-leon-hutten` (Kristalblauw) keeps its look on live installs, so
 * its cyan as text and on the primary band, and its blue primary on its
 * cyan fill (2.20:1, held for the owner's call, #5148), stay as they are.
 * Its marker on the active row is measured like every other theme's.
 */
final class ThemeContrastTest extends WP_UnitTestCase {

    /** @var array<string, list<string>> palette => roles it is excused from */
    private const EXCEPTIONS = [
        'theme-leon-hutten' => [ 'secondary-text', 'secondary-on-primary', 'on-secondary' ],
    ];

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /** A sheet with its comments removed, so prose is never read as a rule. */
    private function css( string $file ): string {
        $css = (string) file_get_contents( $this->root() . '/assets/css/' . $file );
        return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
    }

    /** @return array<string, string> token => raw value, first declaration wins */
    private function declarations( string $css ): array {
        $out = [];
        if ( preg_match_all( '/(--tt-[a-z0-9-]+)\s*:\s*([^;{}]+);/', $css, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $row ) {
                if ( ! isset( $out[ $row[1] ] ) ) $out[ $row[1] ] = trim( $row[2] );
            }
        }
        return $out;
    }

    /**
     * Resolve a value to a hex colour: a hex as it is, a `var()` through the
     * declarations and then its fallback. Null for anything else.
     *
     * @param array<string, string> $d
     */
    private function resolve( string $value, array $d, int $depth = 0 ): ?string {
        $value = trim( $value );
        if ( $depth > 10 ) return null;
        if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) return strtolower( $value );
        if ( preg_match( '/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $value, $short ) ) {
            return strtolower( '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3] );
        }
        if ( ! preg_match( '/^var\(\s*(--tt-[a-z0-9-]+)\s*(?:,(.*))?\)$/s', $value, $m ) ) return null;
        if ( isset( $d[ $m[1] ] ) ) {
            return $this->resolve( $d[ $m[1] ], $d, $depth + 1 );
        }
        return isset( $m[2] ) ? $this->resolve( $m[2], $d, $depth + 1 ) : null;
    }

    /** @param array<string, string> $d */
    private function token( string $name, array $d ): string {
        $hex = $this->resolve( 'var(' . $name . ')', $d );
        $this->assertNotNull( $hex, $name . ' does not resolve to a colour' );
        return (string) $hex;
    }

    /** @return array<string, array{decls: array<string, string>, rules: string, own: string}> */
    private function palettes(): array {
        $base     = $this->declarations( $this->css( 'tokens.css' ) );
        $palettes = [ 'default' => [ 'decls' => $base, 'rules' => '', 'own' => '' ] ];
        foreach ( ThemePreference::themes() as $theme ) {
            if ( $theme === ThemePreference::DEFAULT_THEME ) continue;
            $own   = $this->css( 'theme-' . $theme . '.css' );
            $rules = ThemePreference::usesSharedSheet( $theme ) ? $this->css( ThemePreference::SHARED_SHEET ) . "\n" . $own : $own;
            $palettes[ 'theme-' . $theme ] = [
                'decls' => array_merge( $base, $this->declarations( $own ) ),
                'rules' => $rules,
                'own'   => $own,
            ];
        }
        return $palettes;
    }

    private function excused( string $palette, string $role ): bool {
        return in_array( $role, self::EXCEPTIONS[ $palette ] ?? [], true );
    }

    private function assertRatio( float $floor, string $fg, string $bg, string $what ): void {
        $ratio = $this->contrast( $fg, $bg );
        $this->assertGreaterThanOrEqual( $floor, $ratio, sprintf( '%s: %s on %s is %.2f:1', $what, $fg, $bg, $ratio ) );
    }

    private function luminance( string $hex ): float {
        $channels = [];
        foreach ( [ 1, 3, 5 ] as $i ) {
            $c          = hexdec( substr( $hex, $i, 2 ) ) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function contrast( string $a, string $b ): float {
        $la = $this->luminance( $a );
        $lb = $this->luminance( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    /**
     * The colour a rule sets for a property, resolved; null when the rule
     * is absent.
     *
     * @param array<string, string> $d
     */
    private function ruleColour( string $rules, string $selectorPattern, string $property, array $d ): ?string {
        if ( ! preg_match( '/' . $selectorPattern . '\s*\{([^}]*)\}/', $rules, $rule ) ) return null;
        if ( ! preg_match( '/(?:^|;|\s)' . preg_quote( $property, '/' ) . ':\s*([^;]+);/', $rule[1], $m ) ) return null;
        $value = trim( $m[1] );
        if ( $property === 'box-shadow' ) $value = (string) preg_replace( '/^inset 3px 0 0 /', '', $value );
        if ( $property === 'outline' ) $value = (string) preg_replace( '/^2px solid /', '', $value );
        $hex = $this->resolve( $value, $d );
        $this->assertNotNull( $hex, $selectorPattern . ' ' . $property . ' does not resolve: ' . $value );
        return $hex;
    }

    public function test_text_on_paper_clears_four_and_a_half(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $d = $p['decls'];
            foreach ( [ '--tt-paper', '--tt-bg-soft' ] as $bg ) {
                foreach ( [ '--tt-ink', '--tt-ink-soft', '--tt-muted', '--tt-link', '--tt-link-hover' ] as $fg ) {
                    $this->assertRatio( 4.5, $this->token( $fg, $d ), $this->token( $bg, $d ), "$name $fg on $bg" );
                }
            }
        }
    }

    public function test_buttons_and_their_hovers_clear_four_and_a_half(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $d = $p['decls'];
            $this->assertRatio( 4.5, '#ffffff', $this->token( '--tt-primary', $d ), "$name white on primary" );
            $this->assertRatio( 4.5, '#ffffff', $this->token( '--tt-primary-ink', $d ), "$name white on primary-ink" );
            $hover = isset( $d['--tt-primary-hover'] ) ? '--tt-primary-hover' : '--tt-secondary';
            $this->assertRatio( 4.5, $this->token( '--tt-ink', $d ), $this->token( $hover, $d ), "$name ink on the button hover" );
        }
    }

    /** `--tt-focus` is drawn in `--tt-primary` (tokens.css). */
    public function test_the_focus_ring_clears_three_on_paper(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $d = $p['decls'];
            $this->assertRatio( 3.0, $this->token( '--tt-primary', $d ), $this->token( '--tt-paper', $d ), "$name focus ring" );
        }
    }

    /**
     * The secondary printed as text: on paper, on the primary band, on the
     * header. Checked for every palette; a theme that declares a secondary
     * declares the text token itself rather than inheriting tokens.css's.
     */
    public function test_the_secondary_as_text_reads(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $d = $p['decls'];
            if ( $name !== 'default' && preg_match( '/--tt-secondary\s*:/', $p['own'] ) ) {
                $this->assertMatchesRegularExpression( '/--tt-secondary-text\s*:/', $p['own'], "$name sets a secondary but not --tt-secondary-text" );
            }
            if ( ! $this->excused( $name, 'secondary-text' ) ) {
                foreach ( [ '--tt-paper', '--tt-bg-soft' ] as $bg ) {
                    $this->assertRatio( 4.5, $this->token( '--tt-secondary-text', $d ), $this->token( $bg, $d ), "$name secondary-text on $bg" );
                }
            }
            if ( ! $this->excused( $name, 'secondary-on-primary' ) ) {
                $this->assertRatio( 4.5, $this->token( '--tt-secondary-on-primary', $d ), $this->token( '--tt-primary', $d ), "$name secondary-on-primary" );
            }
        }
    }

    /**
     * #5108 — a status colour printed as text reads its `-text` variant,
     * which clears 4.5:1 on paper, the soft background and the status's own
     * subtle fill. A theme that sets a status colour sets its `-text` itself.
     */
    public function test_status_text_reads(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $d = $p['decls'];
            foreach ( [ 'success', 'warning', 'danger', 'info' ] as $status ) {
                if ( $name !== 'default' && preg_match( '/--tt-' . $status . '\s*:/', $p['own'] ) ) {
                    $this->assertMatchesRegularExpression( '/--tt-' . $status . '-text\s*:/', $p['own'], "$name sets --tt-$status but not --tt-$status-text" );
                }
                $text = $this->token( '--tt-' . $status . '-text', $d );
                foreach ( [ '--tt-paper', '--tt-bg-soft', '--tt-' . $status . '-subtle' ] as $bg ) {
                    $this->assertRatio( 4.5, $text, $this->token( $bg, $d ), "$name --tt-$status-text on $bg" );
                }
            }
        }
    }

    /**
     * #5130 — status text on a component's own fixed fill, rather than on
     * the status's `-subtle` token: the rule reads the status `-text` token,
     * and that clears 4.5:1 on the fill in every palette. An `rgba()` fill
     * is blended on the palette's paper.
     */
    public function test_status_text_on_fixed_component_fills_reads(): void {
        $surfaces = [
            [ 'frontend-player-detail.css', '.tt-player-pill[data-status="trial"]', 'warning' ],
            [ 'frontend-player-detail.css', '.tt-player-row__pill[data-status="late"]', 'warning' ],
            [ 'frontend-player-detail.css', '.tt-player-row__pill[data-priority="medium"]', 'warning' ],
            [ 'frontend-match-prep.css', '.tt-mp-roster tbody td.tt-mp-on', 'success' ],
            [ 'frontend-match-prep-2026.css', '.tt-match-prep .tt-mp-roster tbody td.tt-mp-on', 'success' ],
        ];
        foreach ( $surfaces as [ $file, $selector, $status ] ) {
            $css = $this->css( $file );
            $this->assertSame( 1, preg_match( '/(?:^|\})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $rule ), "$file $selector" );
            $this->assertMatchesRegularExpression( '/(?:^|[;\s])color:\s*var\(--tt-' . $status . '-text\b/', $rule[1], "$file $selector reads --tt-$status-text" );
            $this->assertSame( 1, preg_match( '/background:\s*([^;]+);/', $rule[1], $bg ), "$file $selector has a fill" );

            foreach ( $this->palettes() as $name => $p ) {
                $d    = $p['decls'];
                $fill = $this->fill( trim( $bg[1] ), $this->token( '--tt-paper', $d ), $d );
                $this->assertRatio( 4.5, $this->token( '--tt-' . $status . '-text', $d ), $fill, "$name $selector" );
            }
        }
    }

    /**
     * A fill as the browser paints it: a hex or `var()` resolved, an
     * `rgba()` blended on the page behind it.
     *
     * @param array<string, string> $d
     */
    private function fill( string $value, string $paper, array $d ): string {
        if ( preg_match( '/^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([0-9.]+)\s*\)$/', $value, $m ) ) {
            $under = [ hexdec( substr( $paper, 1, 2 ) ), hexdec( substr( $paper, 3, 2 ) ), hexdec( substr( $paper, 5, 2 ) ) ];
            $a     = (float) $m[4];
            $mixed = [];
            foreach ( [ 1, 2, 3 ] as $i ) {
                $mixed[] = (int) round( (int) $m[ $i ] * $a + $under[ $i - 1 ] * ( 1 - $a ) );
            }
            return sprintf( '#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2] );
        }
        $hex = $this->resolve( $value, $d );
        $this->assertNotNull( $hex, 'fill does not resolve: ' . $value );
        return (string) $hex;
    }

    /**
     * #5130, #5136 — a custom property resolves its `var()` where it is
     * declared, and a theme sets its tokens on `.tt-dashboard`. Every alias
     * of a token any theme sets that a component sheet declares on
     * `.tt-root` is re-declared, by name, in a rule of the same sheet whose
     * selector starts with `.tt-dashboard` (`.tt-dashboard` itself, or
     * `.tt-dashboard.tt-themed` where the default design must keep the
     * `.tt-root` value), or the alias ignores the theme.
     */
    public function test_component_aliases_of_theme_tokens_follow_the_theme(): void {
        $themed = [];
        foreach ( ThemePreference::themes() as $theme ) {
            if ( $theme === ThemePreference::DEFAULT_THEME ) continue;
            if ( preg_match_all( '/(--tt-[a-z0-9-]+)\s*:/', $this->css( 'theme-' . $theme . '.css' ), $m ) ) {
                foreach ( $m[1] as $name ) $themed[ $name ] = true;
            }
        }
        $this->assertNotEmpty( $themed );

        $offenders = [];
        $files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/assets/css' ) );
        foreach ( $files as $file ) {
            $path = str_replace( '\\', '/', (string) $file );
            if ( substr( $path, -4 ) !== '.css' ) continue;
            $name = basename( $path );
            if ( $name === 'tokens.css' || strpos( $name, 'theme-' ) === 0 ) continue;
            $css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
            if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) continue;

            $rooted    = [];
            $dashboard = [];
            foreach ( $rules as $rule ) {
                $selectors = array_map( 'trim', explode( ',', $rule[1] ) );
                $on_root   = in_array( '.tt-root', $selectors, true );
                $on_dash   = false;
                foreach ( $selectors as $selector ) {
                    if ( preg_match( '/^\.tt-dashboard(?![a-z0-9_-])/', $selector ) ) $on_dash = true;
                }
                if ( ! preg_match_all( '/(--[a-z0-9-]+)\s*:\s*var\(\s*(--tt-[a-z0-9-]+)/', $rule[2], $decls, PREG_SET_ORDER ) ) continue;
                foreach ( $decls as $decl ) {
                    if ( $on_dash ) $dashboard[ $decl[1] ] = true;
                    if ( $on_root && ! $on_dash && isset( $themed[ $decl[2] ] ) ) $rooted[ $decl[1] ] = true;
                }
            }
            $missing = array_keys( array_diff_key( $rooted, $dashboard ) );
            if ( $missing ) {
                $offenders[] = $name . ': ' . implode( ', ', $missing );
            }
        }

        $this->assertSame( [], $offenders, 're-declare these aliases in a .tt-dashboard rule' );
    }

    /**
     * #5136 — team chemistry's sandbox banner hint reads at 4.5:1 on the
     * banner's tint, laid over paper and over the soft background, in every
     * palette. Both colours resolve through the sheet's own aliases.
     */
    public function test_the_chemistry_sandbox_hint_reads(): void {
        $sheet = $this->css( 'frontend-team-chemistry.css' );
        $local = $this->declarations( $sheet );
        $this->assertSame( 1, preg_match( '/\.tt-tc-override-hint\s*\{[^}]*?(?:^|[;\s{])color:\s*([^;]+);/', $sheet, $fg ), 'hint colour' );
        $this->assertSame( 1, preg_match( '/\.tt-tc-override-banner\s*\{[^}]*background:\s*([^;]+);/', $sheet, $bg ), 'banner fill' );

        foreach ( $this->palettes() as $name => $p ) {
            $d    = array_merge( $local, $p['decls'] );
            $text = $this->resolve( trim( $fg[1] ), $d );
            $this->assertNotNull( $text, "$name hint colour does not resolve" );
            $fill = $this->rawValue( trim( $bg[1] ), $d );
            foreach ( [ '--tt-paper', '--tt-bg-soft' ] as $under ) {
                $this->assertRatio( 4.5, (string) $text, $this->fill( $fill, $this->token( $under, $d ), $d ), "$name chemistry sandbox hint over $under" );
            }
        }
    }

    /**
     * #5136 — every measurement-level status chip on the Measurements tab
     * prints its text at 4.5:1 or better on its own fill, in every palette.
     * The fill and text resolve through the sheet's level aliases; a chip
     * that sets no colour takes the chip's base text colour.
     */
    public function test_measurement_status_chips_read(): void {
        $sheet = $this->css( 'frontend-measurement-levels.css' );
        $local = $this->declarations( $sheet );
        $this->assertSame( 1, preg_match( '/(?:^|\})\s*\.tt-meas-value--status\s*\{[^}]*?(?:^|[;\s{])color:\s*([^;]+);/', $sheet, $base ), 'chip base colour' );
        $this->assertGreaterThan( 0, preg_match_all( '/\.tt-meas-value--status\.tt-mlvl-swatch--([a-z]+)\s*\{([^}]*)\}/', $sheet, $chips, PREG_SET_ORDER ), 'chips' );
        $this->assertSame( 9, count( $chips ), 'the nine curated levels' );

        foreach ( $this->palettes() as $name => $p ) {
            $d = array_merge( $local, $p['decls'] );
            foreach ( $chips as [ , $level, $body ] ) {
                $this->assertSame( 1, preg_match( '/background:\s*([^;]+);/', $body, $bg ), "$level fill" );
                $text = preg_match( '/(?:^|[;\s])color:\s*([^;]+);/', $body, $c ) ? trim( $c[1] ) : trim( $base[1] );
                $fg   = $this->resolve( $text, $d );
                $fill = $this->resolve( trim( $bg[1] ), $d );
                $this->assertNotNull( $fg, "$name $level text does not resolve" );
                $this->assertNotNull( $fill, "$name $level fill does not resolve" );
                $this->assertRatio( 4.5, (string) $fg, (string) $fill, "$name measurement chip $level" );
            }
        }
    }

    /** `color-mix(in srgb, $hex $share, white)`, as the browser mixes it. */
    private function mixWithWhite( string $hex, float $share ): string {
        $out = [];
        foreach ( [ 1, 3, 5 ] as $i ) {
            $out[] = (int) round( hexdec( substr( $hex, $i, 2 ) ) * $share + 255 * ( 1 - $share ) );
        }
        return sprintf( '#%02x%02x%02x', $out[0], $out[1], $out[2] );
    }

    /**
     * Follow a `var()` chain through the declarations to the first value
     * that is not itself a `var()` of a declared property.
     *
     * @param array<string, string> $d
     */
    private function rawValue( string $value, array $d ): string {
        for ( $i = 0; $i < 10; $i++ ) {
            if ( ! preg_match( '/^var\(\s*(--[a-z0-9-]+)\s*(?:,.*)?\)$/s', $value, $m ) || ! isset( $d[ $m[1] ] ) ) break;
            $value = trim( $d[ $m[1] ] );
        }
        return $value;
    }

    /**
     * #5148 — text printed on a secondary fill reads
     * `--tt-color-on-secondary`, which clears 4.5:1 on the secondary in
     * every palette; on the default design it is the primary, as before.
     */
    public function test_text_on_the_secondary_fill_reads(): void {
        $tokens = $this->declarations( $this->css( 'tokens.css' ) );
        $this->assertSame( 'var(--tt-primary)', $tokens['--tt-color-on-secondary'] ?? null, 'the default design keeps the primary' );

        $this->assertSame(
            1,
            preg_match( '/\.tt-mexec \.tt-mexec-score-btn--goal:active\s*\{\s*background:\s*color-mix\(in srgb, var\(--tt-mx-gold\) (\d+)%, white\);\s*\}/', $this->css( 'frontend-match-execution.css' ), $pressed ),
            'the pressed goal button lightens the gold toward white'
        );

        foreach ( $this->palettes() as $name => $p ) {
            $d = $p['decls'];
            // The ink is the other text the guard below accepts on a
            // secondary fill (the landing dashboard's call to action).
            $this->assertRatio( 4.5, $this->token( '--tt-ink', $d ), $this->token( '--tt-secondary', $d ), "$name ink on the secondary fill" );
            if ( $this->excused( $name, 'on-secondary' ) ) continue;
            $on = $this->token( '--tt-color-on-secondary', $d );
            $this->assertRatio( 4.5, $on, $this->token( '--tt-secondary', $d ), "$name text on the secondary fill" );
            // The goal button while pressed: the secondary lightened toward white.
            $this->assertRatio( 4.5, $on, $this->mixWithWhite( $this->token( '--tt-secondary', $d ), (float) $pressed[1] / 100 ), "$name text on the pressed goal button" );
        }
    }

    /**
     * The custom properties a sheet defines as `$root`, directly or as an
     * alias of an alias, `$root` included.
     *
     * @return list<string>
     */
    private function aliasesOf( string $css, string $root ): array {
        $aliases = [ $root ];
        do {
            $count = count( $aliases );
            $alt   = implode( '|', array_map( static fn( string $n ): string => preg_quote( $n, '/' ), $aliases ) );
            if ( preg_match_all( '/(--[a-z0-9-]+)\s*:\s*var\(\s*(?:' . $alt . ')\s*[,)]/', $css, $m ) ) {
                $aliases = array_values( array_unique( array_merge( $aliases, $m[1] ) ) );
            }
        } while ( count( $aliases ) !== $count );
        return $aliases;
    }

    /**
     * Rules in a sheet that paint the secondary as their fill, directly or
     * through a custom property the sheet defines as one, and set a text
     * colour that is neither `--tt-color-on-secondary` nor the ink (or an
     * alias of the ink): the two pairs the contrast test measures.
     *
     * @return array{checked: int, offenders: list<string>}
     */
    private function secondaryFillOffenders( string $css ): array {
        $css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
        $quote = static fn( array $names ): string => implode( '|', array_map( static fn( string $n ): string => preg_quote( $n, '/' ), $names ) );
        $fills = $quote( $this->aliasesOf( $css, '--tt-secondary' ) );
        $texts = $quote( array_merge( [ '--tt-color-on-secondary' ], $this->aliasesOf( $css, '--tt-ink' ) ) );

        $checked   = 0;
        $offenders = [];
        if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
            foreach ( $rules as $rule ) {
                if ( ! preg_match( '/(?:^|[;\s])background(?:-color)?:\s*var\(\s*(?:' . $fills . ')\s*[,)]/', $rule[2] ) ) continue;
                if ( ! preg_match( '/(?:^|[;\s])color:\s*([^;]+);/', $rule[2], $c ) ) continue;
                $checked++;
                if ( ! preg_match( '/^var\(\s*(?:' . $texts . ')\s*[,)]/', trim( $c[1] ) ) ) {
                    $offenders[] = trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) ) . ' (color: ' . trim( $c[1] ) . ')';
                }
            }
        }
        return [ 'checked' => $checked, 'offenders' => $offenders ];
    }

    public function test_the_secondary_fill_guard_follows_local_aliases(): void {
        $planted = '.tt-x { --tt-gold: var(--tt-secondary, #e8b624); --tt-gold-2: var(--tt-gold); --tt-pri: var(--tt-primary); --tt-txt: var(--tt-ink); }'
            . ' .tt-a { background: var(--tt-gold); color: var(--tt-pri); }'
            . ' .tt-b { background: var(--tt-gold-2); color: var(--tt-primary); }'
            . ' .tt-c { background: var(--tt-gold); color: var(--tt-color-on-secondary, var(--tt-pri)); }'
            . ' .tt-d { background: var(--tt-pri); color: var(--tt-gold); }'
            . ' .tt-e { background: var(--tt-gold-2); color: var(--tt-txt); }';

        $found = $this->secondaryFillOffenders( $planted );
        $this->assertSame( 4, $found['checked'] );
        $this->assertSame( [ '.tt-a (color: var(--tt-pri))', '.tt-b (color: var(--tt-primary))' ], $found['offenders'] );
    }

    /**
     * #5148 — every component rule that paints the secondary as its fill and
     * sets a text colour reads `--tt-color-on-secondary` for it, so the pair
     * the test above measures is the pair every such rule prints.
     */
    public function test_every_secondary_fill_prints_the_on_secondary_token(): void {
        $offenders = [];
        $checked   = 0;
        $files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/assets/css' ) );
        foreach ( $files as $file ) {
            $path = str_replace( '\\', '/', (string) $file );
            if ( substr( $path, -4 ) !== '.css' ) continue;
            $name = basename( $path );
            if ( $name === 'tokens.css' || strpos( $name, 'theme-' ) === 0 ) continue;
            $found    = $this->secondaryFillOffenders( (string) file_get_contents( $path ) );
            $checked += $found['checked'];
            foreach ( $found['offenders'] as $offender ) {
                $offenders[] = $name . ' ' . $offender;
            }
        }
        $this->assertGreaterThan( 0, $checked, 'no secondary-fill rule found' );
        $this->assertSame( [], $offenders, 'read var(--tt-color-on-secondary, …) for text on a secondary fill' );
    }

    /**
     * #5136 — under a theme the landing dashboard's fixed colours follow the
     * theme too: the hero gradient ends in the theme's deep colour, the
     * secondary text is the theme's soft ink, and the soft accent and the
     * dividers are tints of the theme's primary. The `.tt-root` block keeps
     * the default design's values, which the visual editor writes over.
     */
    public function test_the_themed_dashboard_block_follows_the_theme(): void {
        $css = $this->css( 'persona-dashboard.css' );
        $this->assertSame( 1, preg_match( '/(?:^|\})\s*\.tt-dashboard\.tt-themed\s*\{([^}]*)\}/', $css, $themed ), 'themed block' );
        $this->assertSame( 1, preg_match( '/(?:^|\})\s*\.tt-root\s*\{([^}]*)\}/', $css, $root ), 'root block' );

        $expected = [
            '--tt-pd-hero-end'       => '/^var\(--tt-primary-deep\b/',
            '--tt-pd-text-secondary' => '/^var\(--tt-ink-soft\b/',
            '--tt-pd-accent-soft'    => '/^rgba\(var\(--tt-primary-rgb\),\s*[0-9.]+\)$/',
            '--tt-pd-divider'        => '/^rgba\(var\(--tt-primary-rgb\),\s*[0-9.]+\)$/',
            '--tt-pd-divider-strong' => '/^rgba\(var\(--tt-primary-rgb\),\s*[0-9.]+\)$/',
        ];
        $themed_decls = $this->declarations( $themed[1] );
        foreach ( $expected as $name => $pattern ) {
            $this->assertArrayHasKey( $name, $themed_decls, "$name is not re-declared under a theme" );
            $this->assertMatchesRegularExpression( $pattern, $themed_decls[ $name ], $name );
        }

        $root_decls = $this->declarations( $root[1] );
        $this->assertSame( '#07261c', $root_decls['--tt-pd-hero-end'] ?? null, 'the default hero end is unchanged' );
        $this->assertSame( '#46544c', $root_decls['--tt-pd-text-secondary'] ?? null, 'the default secondary text is unchanged' );
    }

    /** The header: its text at 4.5:1 and the accent icon at 3:1. */
    public function test_the_header_reads(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $d      = $p['decls'];
            $header = $this->ruleColour( $p['rules'], '\.tt-dash-header', 'background', $d ) ?? $this->token( '--tt-primary', $d );
            $this->assertRatio( 4.5, $this->token( '--tt-color-on-primary', $d ), $header, "$name header text" );
            $this->assertRatio( 3.0, $this->token( '--tt-secondary-on-chrome', $d ), $header, "$name accent on the header" );
        }
    }

    /**
     * A themed rail: text on the rail, its hover and its active row; soft
     * text on the rail and its hover; the marker and the rail's focus ring
     * on the rail and the active row; the brand mark's text on its fill.
     */
    public function test_the_rail_reads(): void {
        $checked = 0;
        foreach ( $this->palettes() as $name => $p ) {
            $d     = $p['decls'];
            $rules = $p['rules'];
            $rail  = $this->ruleColour( $rules, '\.tt-shell-nav', 'background', $d );
            if ( $rail === null ) continue;
            $checked++;

            $hover  = (string) $this->ruleColour( $rules, '\.tt-shell-nav__link:hover', 'background', $d );
            $active = (string) $this->ruleColour( $rules, '\.tt-shell-nav__link\.is-active', 'background', $d );
            $ink    = (string) $this->ruleColour( $rules, '\.tt-shell-nav__link', 'color', $d );
            $inkOn  = (string) $this->ruleColour( $rules, '\.tt-shell-nav__link\.is-active', 'color', $d );
            $soft   = (string) $this->ruleColour( $rules, '\.tt-shell-nav__icon', 'color', $d );
            $marker = (string) $this->ruleColour( $rules, '\.tt-shell-nav__link\.is-active', 'box-shadow', $d );
            $ring   = (string) $this->ruleColour( $rules, '\.tt-shell-nav__link:focus-visible', 'outline', $d );
            $markBg = (string) $this->ruleColour( $rules, '\.tt-shell-nav__mark', 'background', $d );
            $markFg = (string) $this->ruleColour( $rules, '\.tt-shell-nav__mark', 'color', $d );

            foreach ( [ 'rail' => $rail, 'hover' => $hover ] as $where => $bg ) {
                $this->assertRatio( 4.5, $ink, $bg, "$name rail ink on the $where" );
                $this->assertRatio( 4.5, $soft, $bg, "$name rail soft ink on the $where" );
            }
            $this->assertRatio( 4.5, $inkOn, $active, "$name rail ink on the active row" );
            $this->assertRatio( 3.0, $marker, $rail, "$name marker on the rail" );
            $this->assertRatio( 3.0, $ring, $rail, "$name rail focus ring on the rail" );
            $this->assertRatio( 3.0, $marker, $active, "$name marker on the active row" );
            $this->assertRatio( 4.5, $markFg, $markBg, "$name brand mark text" );
        }
        $this->assertGreaterThan( 0, $checked, 'no themed rail was measured' );
    }
}
