<?php
/**
 * Docs-corpus gate (#2551, epic #2543).
 *
 * Twenty workflows lint this repository and, until now, none looked at
 * `docs/`. That is why the corpus reached the state #2543 measured: 53
 * files unreachable in-product, 38 links bouncing readers into wp-admin,
 * and a view→topic map covering 27 of 144 screens. Every one of those was
 * a convention written down in `docs/contributing.md` and enforced
 * nowhere. Conventions that are not enforced decay at a predictable rate,
 * so without this gate the epic buys a good corpus for exactly one
 * release.
 *
 * The rules fall into four groups:
 *
 *   Registry integrity   1-3   protects #2544, #2548
 *   Reference integrity  4-6   protects #2546, #2547
 *   Link hygiene         7-10  protects #2545
 *   Encoding             15    protects everything (see below)
 *
 * Voice rules 11-12 are diff-only, so the existing backlog is
 * grandfathered and only newly-added prose is held to them. Translation
 * rules 13-14 land with #2550, which is what makes them passable.
 *
 * Rule 15 is not in the original issue. It was added after a sweep in
 * #2546 split every star glyph in the corpus mid-character — the script
 * used `preg_split('/\R/')` without `/u`, and outside Unicode mode PCRE
 * treats `\x85` as a line break, which is the third byte of U+2605. Two
 * files shipped with fifteen lines of replacement characters through the
 * full gate suite and were caught by eye. One line of `mb_check_encoding`
 * catches the whole class.
 *
 * Runs on plain PHP with no WordPress, and requires the real parsers
 * rather than re-implementing them, so the gate cannot drift from the
 * runtime it guards — the failure mode every hand-rolled lint develops.
 *
 * Usage:
 *   php tools/check-docs.php                 whole corpus
 *   php tools/check-docs.php --base=origin/main   adds the diff-only voice rules
 */

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

require_once $root . '/src/Modules/Documentation/DocFrontMatter.php';

use TT\Modules\Documentation\DocFrontMatter;

$base = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( strpos( $arg, '--base=' ) === 0 ) $base = substr( $arg, 7 );
}

/** @var list<string> */
$fail = [];
$note = static function ( string $file, string $msg ) use ( &$fail ): void {
    $fail[] = "$file — $msg";
};

// ── vocabularies, read from the code rather than restated ──────────────

$helpTopicsSrc = (string) file_get_contents( $root . '/src/Modules/Documentation/HelpTopics.php' );
preg_match( '/function groups\(\).*?return \[(.*?)\];/s', $helpTopicsSrc, $gm );
preg_match_all( "/'([a-z0-9-]+)'\s*=/", $gm[1] ?? '', $km );
$groups = $km[1] ?? [];
if ( count( $groups ) < 2 ) {
    fwrite( STDERR, "check-docs: could not read HelpTopics::groups()\n" );
    exit( 2 );
}

$audiences = [ 'user', 'admin', 'dev', 'player', 'parent' ];
$tiers     = [ 'free', 'standard', 'pro' ];

/** Dev-only files: no front matter, invisible to the product, on purpose. */
$devOnly = [
    'architecture-mobile-first', 'back-navigation', 'branded-404', 'contributing',
    'dev-tier-rest-port-backlog', 'frontend-2026-patterns', 'frontend-shell',
    'frontend-themes', 'i18n-architecture', 'i18n-audit-2026-05', 'index',
    'methodology-authoring', 'mobile-patterns', 'translator-brief', 'ui-copy',
];

$noHelpTopic = (array) require $root . '/config/no_help_topic.php';

/**
 * Front-matter strings that are legitimately the same word in Dutch.
 *
 * Rule 14 flags a Dutch `title` or `summary` identical to its English
 * source, because that is almost always an untranslated file. A handful of
 * words genuinely do not change. Keeping them here rather than softening
 * the rule means each one was a decision somebody made.
 */
$identicalByDesign = [
    'Modules',
];

/**
 * Every routable `?tt_view=` slug, read out of the dispatcher.
 *
 * The deriver is shared with the mobile-class and tile-route gates
 * (#3022). This gate used to carry its own `case '<literal>':` scan, which
 * saw eight fewer routes than the shared one — constant `case` arms and
 * the pre-auth comparisons above the dispatch chain — so "every routable
 * view declares a help topic" held only for the routes it could parse, and
 * writing an arm as `case SomeView::SLUG:` bought an exemption from the
 * requirement.
 */
require_once __DIR__ . '/lib/routable-slugs.php';

[ $routable, $unresolvedRoutes ] = tt_routable_slugs( $root, $root . '/src/Shared/Frontend/DashboardShortcode.php' );

if ( $routable === [] ) {
    fwrite( STDERR, "check-docs: parsed no routable slugs at all — the dispatcher shape has changed and rule 4/5 are blind\n" );
    exit( 2 );
}

foreach ( $unresolvedRoutes as $where ) {
    echo "note: route at {$where} is built from something this gate cannot resolve statically. Claim its topic by hand if it is reachable.\n";
}

$featureSrc = (string) file_get_contents( $root . '/src/Core/FeatureRegistry.php' );
$capsSrc    = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) ) as $f ) {
    if ( $f->isFile() && substr( $f->getFilename(), -4 ) === '.php' ) {
        $capsSrc .= file_get_contents( $f->getPathname() );
    }
}

// ── walk the corpus ────────────────────────────────────────────────────

$claimedViews = [];
$registered   = [];
$scanned      = 0;

$files = glob( $root . '/docs/*.md' ) ?: [];
foreach ( glob( $root . '/docs/*/', GLOB_ONLYDIR ) ?: [] as $dir ) {
    $files = array_merge( $files, glob( $dir . '*.md' ) ?: [] );
}

foreach ( $files as $path ) {
    $rel  = ltrim( str_replace( $root, '', $path ), '/\\' );
    $rel  = str_replace( '\\', '/', $rel );
    $slug = basename( $path, '.md' );
    $raw  = (string) file_get_contents( $path );
    $scanned++;

    // 15. Encoding. First, because everything below reads this text.
    if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
        $note( $rel, 'not valid UTF-8 — a truncated multi-byte sequence, usually a sweep that split on bytes rather than characters' );
        continue;
    }

    $isLocalised = strpos( $rel, 'docs/' ) === 0 && substr_count( $rel, '/' ) > 1;
    $data        = DocFrontMatter::parse( $raw );

    // 1. Front matter, or the dev-only list. No third state.
    if ( $data === [] ) {
        if ( ! $isLocalised && ! in_array( $slug, $devOnly, true ) ) {
            $note( $rel, 'no front matter, and not on the dev-only allowlist in docs/contributing.md — pick one' );
        }
        continue;
    }
    if ( ! $isLocalised && in_array( $slug, $devOnly, true ) ) {
        $note( $rel, 'has front matter AND is on the dev-only allowlist — it cannot be both' );
    }

    if ( ! $isLocalised ) $registered[ $slug ] = true;

    // 13-14. translation parity. Deferred out of #2551 until #2550 brought
    // the corpus to parity — enforcing it before the translation pass would
    // have meant an exempt label on every PR, which is the same as no rule.
    if ( ! $isLocalised ) {
        $aud_now  = DocFrontMatter::list( $data, 'audience' );
        $needsNl  = (bool) array_intersect( $aud_now, [ 'user', 'player', 'parent', 'admin' ] );
        $nlPath   = $root . '/docs/nl_NL/' . $slug . '.md';

        if ( $needsNl && ! file_exists( $nlPath ) ) {
            $note( $rel, 'is reader- or admin-facing but has no docs/nl_NL twin. The install is Dutch; an English-only topic here is a silent gap' );
        } elseif ( $needsNl ) {
            $nlData = DocFrontMatter::parse( (string) file_get_contents( $nlPath ) );
            if ( $nlData === [] ) {
                $note( "docs/nl_NL/$slug.md", 'has no front matter, so the Dutch TOC falls back to the English title' );
            } else {
                foreach ( [ 'title', 'summary' ] as $k ) {
                    $enV = DocFrontMatter::string( $data, $k );
                    $nlV = DocFrontMatter::string( $nlData, $k );
                    if ( $enV === '' || $nlV === '' ) continue;
                    if ( $enV === $nlV && ! in_array( $enV, $identicalByDesign, true ) ) {
                        $note( "docs/nl_NL/$slug.md", "$k: is identical to the English (\"$enV\"). Translate it, or add it to \$identicalByDesign in this file if the word is the same in Dutch" );
                    }
                }
                foreach ( [ 'group', 'audience', 'order' ] as $k ) {
                    if ( DocFrontMatter::string( $data, $k ) !== DocFrontMatter::string( $nlData, $k ) ) {
                        $note( "docs/nl_NL/$slug.md", "$k: differs from the English twin. These three key off the same registry entry and must match; only title and summary are translated" );
                    }
                }
            }
        }
    }

    // 2. group
    $group = DocFrontMatter::string( $data, 'group' );
    if ( $group === '' ) {
        $note( $rel, 'front matter has no group:' );
    } elseif ( ! in_array( $group, $groups, true ) ) {
        $note( $rel, "group: '$group' is not a key of HelpTopics::groups() (" . implode( ', ', $groups ) . ')' );
    }
    if ( DocFrontMatter::string( $data, 'title' ) === '' )   $note( $rel, 'front matter has no title:' );
    if ( DocFrontMatter::string( $data, 'summary' ) === '' ) $note( $rel, 'front matter has no summary:' );

    // 3. audience
    $aud = DocFrontMatter::list( $data, 'audience' );
    if ( $aud === [] ) {
        $note( $rel, 'front matter has no audience:' );
    }
    foreach ( $aud as $a ) {
        if ( ! in_array( $a, $audiences, true ) ) {
            $note( $rel, "audience: '$a' is not one of " . implode( ' / ', $audiences ) );
        }
    }

    // 4. views: slugs are routable
    foreach ( DocFrontMatter::list( $data, 'views' ) as $view ) {
        if ( ! isset( $routable[ $view ] ) ) {
            $note( $rel, "views: names '$view', which the dashboard dispatcher does not route" );
        }
        if ( ! $isLocalised ) $claimedViews[ $view ] = $slug;
    }

    // 6. gating keys resolve
    $module = DocFrontMatter::string( $data, 'module' );
    if ( $module !== '' ) {
        $file = $root . '/src/' . str_replace( [ 'TT\\', '\\' ], [ '', '/' ], $module ) . '.php';
        if ( ! file_exists( $file ) ) $note( $rel, "module: '$module' names no class in src/" );
    }
    $feature = DocFrontMatter::string( $data, 'feature' );
    if ( $feature !== '' && strpos( $featureSrc, "'" . $feature . "'" ) === false ) {
        $note( $rel, "feature: '$feature' is not a FeatureRegistry catalog key" );
    }
    $tier = DocFrontMatter::string( $data, 'tier' );
    if ( $tier !== '' && ! in_array( $tier, $tiers, true ) ) {
        $note( $rel, "tier: '$tier' is not one of " . implode( ' / ', $tiers ) );
    }
    $cap = DocFrontMatter::string( $data, 'capability' );
    if ( $cap !== '' && strpos( $capsSrc, "'" . $cap . "'" ) === false ) {
        $note( $rel, "capability: '$cap' appears nowhere in src/ — a capability nobody grants hides the topic from everyone" );
    }

    $isAdminOnly = $aud === [ 'admin' ] || ( in_array( 'admin', $aud, true ) && ! array_intersect( $aud, [ 'user', 'player', 'parent' ] ) );

    // 7-10. links
    if ( preg_match( '/]\(\?page=tt-docs&topic=/', $raw ) ) {
        $note( $rel, 'links to another topic via ?page=tt-docs&topic= — use the relative <slug>.md form so it resolves in the frontend viewer too' );
    }
    if ( ! $isAdminOnly && preg_match( '/]\(\?page=/', $raw ) ) {
        $note( $rel, 'links into wp-admin (?page=) from a topic that is not admin-only — no coach, player or parent should be sent there' );
    }
    if ( preg_match_all( '/]\(([a-z0-9-]+)\.md(?:#[^)]*)?\)/', $raw, $m ) ) {
        foreach ( $m[1] as $target ) {
            $dir = dirname( $path );
            if ( ! file_exists( $dir . '/' . $target . '.md' ) && ! file_exists( $root . '/docs/' . $target . '.md' ) ) {
                $note( $rel, "links to $target.md, which does not exist" );
            }
        }
    }
    if ( preg_match_all( '/]\(\?tt_view=([a-z0-9-]+)/', $raw, $m ) ) {
        foreach ( $m[1] as $view ) {
            if ( ! isset( $routable[ $view ] ) ) {
                $note( $rel, "links to ?tt_view=$view, which the dispatcher does not route" );
            }
        }
    }
}

// 5. every routable slug is claimed or deliberately not
foreach ( array_keys( $routable ) as $view ) {
    if ( ! isset( $claimedViews[ $view ] ) && ! isset( $noHelpTopic[ $view ] ) ) {
        $fail[] = "config/no_help_topic.php — '$view' is routable but no topic claims it in views:, and it has no allowlist entry saying why";
    }
}
foreach ( array_keys( $noHelpTopic ) as $view ) {
    if ( isset( $claimedViews[ $view ] ) ) {
        $fail[] = "config/no_help_topic.php — '$view' is allowlisted as having no topic, but {$claimedViews[$view]}.md claims it";
    }
    if ( ! isset( $routable[ $view ] ) ) {
        $fail[] = "config/no_help_topic.php — '$view' is not a routable view; the entry is stale";
    }
    if ( trim( (string) $noHelpTopic[ $view ] ) === '' ) {
        $fail[] = "config/no_help_topic.php — '$view' has no reason";
    }
}

// ── 11-12. voice, diff-only ────────────────────────────────────────────

if ( $base !== '' ) {
    $changed = array_filter( explode( "\n", (string) shell_exec(
        'git diff --name-only --diff-filter=d ' . escapeshellarg( $base ) . '...HEAD -- docs 2>/dev/null'
    ) ) );

    foreach ( $changed as $rel ) {
        $rel = trim( $rel );
        if ( $rel === '' || substr( $rel, -3 ) !== '.md' ) continue;

        $path = $root . '/' . $rel;
        if ( ! file_exists( $path ) ) continue;

        $data = DocFrontMatter::parse( (string) file_get_contents( $path ) );
        if ( $data === [] ) continue;

        $aud       = DocFrontMatter::list( $data, 'audience' );
        $isReader  = (bool) array_intersect( $aud, [ 'user', 'player', 'parent' ] );

        $diff  = (string) shell_exec( 'git diff ' . escapeshellarg( $base ) . '...HEAD -- ' . escapeshellarg( $rel ) . ' 2>/dev/null' );
        $added = [];
        foreach ( explode( "\n", $diff ) as $line ) {
            if ( strlen( $line ) > 1 && $line[0] === '+' && strpos( $line, '+++' ) !== 0 ) {
                $added[] = substr( $line, 1 );
            }
        }
        $addedText = implode( "\n", $added );

        if ( $isReader ) {
            if ( preg_match( '/\bv\d+\.\d+\.\d+/', $addedText, $m ) ) {
                $fail[] = "$rel — adds a version stamp ({$m[0]}) to a reader-facing topic. Release history belongs in CHANGES.md; the doc describes what the product does now.";
            }
            if ( preg_match( '/(?<![\w&])#\d{3,}/', $addedText, $m ) ) {
                $fail[] = "$rel — adds an issue reference ({$m[0]}) to a reader-facing topic. A coach cannot open it and does not need it.";
            }
        }
        if ( preg_match( '/\b(coming soon|planned for|in a future release)\b/i', $addedText, $m ) ) {
            $fail[] = "$rel — adds forward-looking language (\"{$m[0]}\"). A support doc describes what exists; anything planned either shipped, or is not documentation yet.";
        }
    }
}

// ── report ─────────────────────────────────────────────────────────────

if ( $fail !== [] ) {
    fwrite( STDERR, "check-docs FAILED — " . count( $fail ) . " problem(s):\n\n" );
    foreach ( $fail as $f ) fwrite( STDERR, "  $f\n" );
    fwrite( STDERR, "\nRules and both allowlists are documented in docs/contributing.md.\n" );
    fwrite( STDERR, "If a failure is a deliberate exception, label the PR `docs-lint-exempt`.\n" );
    exit( 1 );
}

echo "check-docs OK — {$scanned} files scanned, " . count( $registered ) . " registered topics, "
    . count( $claimedViews ) . " of " . count( $routable ) . " routable views claimed, "
    . count( $noHelpTopic ) . " deliberately unclaimed.\n";
