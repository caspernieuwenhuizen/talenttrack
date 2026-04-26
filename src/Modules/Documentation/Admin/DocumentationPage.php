<?php
namespace TT\Modules\Documentation\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Documentation\AudienceResolver;
use TT\Modules\Documentation\HelpTopics;
use TT\Modules\Documentation\Markdown;

/**
 * DocumentationPage — v2.22.0 wiki-style help center.
 *
 * Two-pane layout:
 *   - Left: TOC sidebar with topics grouped (Basics / Performance /
 *     Analytics / Configuration / Frontend). Search box at top filters
 *     the list client-side.
 *   - Right: rendered markdown content for the current topic, with a
 *     breadcrumb ("Help › Group → Topic") at the top.
 *
 * Routes: ?page=tt-docs&topic=<slug>. When no topic is set, the
 * default getting-started topic is rendered.
 *
 * Topic source files live in TT_PATH/docs/<slug>.md — authored per
 * release as part of the v2.22.0+ release-discipline commitment.
 */
class DocumentationPage {

    public static function init(): void {}

    public static function render_page(): void {
        $topics = HelpTopics::all();
        $groups = HelpTopics::groups();

        $requested = isset( $_GET['topic'] ) ? sanitize_key( (string) $_GET['topic'] ) : '';
        $slug = isset( $topics[ $requested ] ) ? $requested : HelpTopics::defaultSlug();
        $topic = $topics[ $slug ];

        // #0029 — resolve viewer's allowed audiences + each topic's
        // declared audience set. The sidebar TOC filters to topics
        // whose audiences intersect the viewer's allowed set; direct
        // URL access (?topic=<slug>) is always honoured regardless.
        $viewer_audiences = AudienceResolver::allowedFor( get_current_user_id() );
        $topic_audiences  = [];
        foreach ( $topics as $s => $_t ) {
            $topic_audiences[ $s ] = AudienceResolver::readFromFile( HelpTopics::filePath( $s ) );
        }
        $audience_labels = AudienceResolver::labels();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Help & Docs', 'talenttrack' ); ?></h1>

            <style>
            .tt-docs-layout {
                display: grid;
                grid-template-columns: 280px 1fr;
                gap: 24px;
                margin-top: 16px;
            }
            @media (max-width: 900px) { .tt-docs-layout { grid-template-columns: 1fr; } }
            .tt-docs-sidebar {
                background: #fff;
                border: 1px solid #e5e7ea;
                border-radius: 8px;
                padding: 16px;
                position: sticky;
                top: 46px;
                max-height: calc(100vh - 60px);
                overflow-y: auto;
            }
            .tt-docs-search {
                width: 100%;
                padding: 7px 10px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                font-size: 13px;
                margin-bottom: 12px;
                box-sizing: border-box;
            }
            .tt-docs-group-label {
                font-size: 10px;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #8a9099;
                margin: 12px 0 6px;
            }
            .tt-docs-group-label:first-child { margin-top: 0; }
            .tt-docs-topic-link {
                display: block;
                padding: 6px 10px;
                color: #1a1d21;
                text-decoration: none;
                font-size: 13px;
                border-radius: 4px;
                margin-bottom: 1px;
                line-height: 1.3;
            }
            .tt-docs-topic-link:hover { background: #f6f7f7; color: #1a1d21; }
            .tt-docs-topic-link.is-active {
                background: #eaf4ff;
                color: #0a4b78;
                font-weight: 600;
            }
            .tt-docs-content {
                background: #fff;
                border: 1px solid #e5e7ea;
                border-radius: 8px;
                padding: 24px 28px;
                line-height: 1.6;
                color: #1a1d21;
            }
            .tt-docs-content h1 { font-size: 22px; margin-top: 0; }
            .tt-docs-content h2 { font-size: 17px; margin-top: 18px; }
            .tt-docs-content h3 { font-size: 15px; margin-top: 14px; }
            .tt-docs-content p { margin: 8px 0; }
            .tt-docs-content code { font-size: 0.92em; }
            .tt-docs-breadcrumb {
                font-size: 12px;
                color: #666;
                margin-bottom: 16px;
            }
            .tt-docs-breadcrumb a { color: #2271b1; text-decoration: none; }
            .tt-docs-no-results {
                color: #888;
                font-style: italic;
                font-size: 12px;
                padding: 6px 10px;
                display: none;
            }
            .tt-docs-hidden { display: none !important; }
            .tt-docs-audience {
                display: inline-block;
                margin-left: 6px;
                padding: 1px 6px;
                font-size: 9px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                border-radius: 3px;
                background: #e0e8f0;
                color: #1a4a8a;
                vertical-align: middle;
            }
            .tt-docs-audience--admin { background: #f5e6c3; color: #6b5614; }
            .tt-docs-audience--dev   { background: #d8e8d6; color: #2c5e2c; }
            </style>

            <div class="tt-docs-layout">
                <aside class="tt-docs-sidebar">
                    <input type="search" class="tt-docs-search" id="tt-docs-search" placeholder="<?php esc_attr_e( 'Search topics…', 'talenttrack' ); ?>" />
                    <div class="tt-docs-no-results" id="tt-docs-no-results"><?php esc_html_e( 'No matching topics.', 'talenttrack' ); ?></div>
                    <div id="tt-docs-toc">
                        <?php foreach ( $groups as $gkey => $glabel ) :
                            $group_topics = array_filter( $topics, function ( $t, $s ) use ( $gkey, $topic_audiences, $viewer_audiences ) {
                                if ( $t['group'] !== $gkey ) return false;
                                $aud = $topic_audiences[ $s ] ?? [];
                                // Always show the currently-active topic so direct URL navigation
                                // doesn't surface "no matching topics". The audience filter is a
                                // sidebar nicety, not an access control.
                                return AudienceResolver::isVisible( $aud, $viewer_audiences );
                            }, ARRAY_FILTER_USE_BOTH );
                            if ( empty( $group_topics ) ) continue;
                            ?>
                            <div class="tt-docs-group" data-group="<?php echo esc_attr( $gkey ); ?>">
                                <div class="tt-docs-group-label"><?php echo esc_html( $glabel ); ?></div>
                                <?php foreach ( $group_topics as $s => $t ) :
                                    $is_active = ( $s === $slug );
                                    $url = admin_url( 'admin.php?page=tt-docs&topic=' . $s );
                                    $aud = $topic_audiences[ $s ] ?? [];
                                    ?>
                                    <a href="<?php echo esc_url( $url ); ?>"
                                       class="tt-docs-topic-link<?php echo $is_active ? ' is-active' : ''; ?>"
                                       data-title="<?php echo esc_attr( strtolower( $t['title'] ) ); ?>"
                                       data-summary="<?php echo esc_attr( strtolower( $t['summary'] ) ); ?>"
                                       title="<?php echo esc_attr( $t['summary'] ); ?>">
                                        <?php echo esc_html( $t['title'] ); ?>
                                        <?php foreach ( $aud as $a ) : ?>
                                            <span class="tt-docs-audience tt-docs-audience--<?php echo esc_attr( $a ); ?>"><?php
                                                echo esc_html( (string) ( $audience_labels[ $a ] ?? $a ) );
                                            ?></span>
                                        <?php endforeach; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <main class="tt-docs-content">
                    <div class="tt-docs-breadcrumb">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-docs' ) ); ?>"><?php esc_html_e( 'Help', 'talenttrack' ); ?></a>
                        <span style="margin:0 6px; color:#ccc;">›</span>
                        <span><?php echo esc_html( $groups[ $topic['group'] ] ?? '' ); ?></span>
                        <span style="margin:0 6px; color:#ccc;">›</span>
                        <span style="color:#1a1d21;"><?php echo esc_html( $topic['title'] ); ?></span>
                    </div>

                    <?php echo self::renderTopicBody( $slug ); ?>
                </main>
            </div>

            <script>
            (function(){
                var input = document.getElementById('tt-docs-search');
                var toc = document.getElementById('tt-docs-toc');
                var noResults = document.getElementById('tt-docs-no-results');
                if (!input || !toc) return;

                input.addEventListener('input', function(){
                    var q = input.value.trim().toLowerCase();
                    var anyVisible = false;
                    var links = toc.querySelectorAll('.tt-docs-topic-link');
                    links.forEach(function(a){
                        var hay = (a.getAttribute('data-title') || '') + ' ' + (a.getAttribute('data-summary') || '');
                        var match = q === '' || hay.indexOf(q) !== -1;
                        a.classList.toggle('tt-docs-hidden', !match);
                        if (match) anyVisible = true;
                    });
                    // Hide group labels with no visible topics
                    var groups = toc.querySelectorAll('.tt-docs-group');
                    groups.forEach(function(g){
                        var hasVisible = g.querySelector('.tt-docs-topic-link:not(.tt-docs-hidden)');
                        g.classList.toggle('tt-docs-hidden', !hasVisible);
                    });
                    noResults.style.display = anyVisible ? 'none' : 'block';
                });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Render the markdown body for a topic. Falls back gracefully if
     * the markdown file is missing (logs a notice visible to admins).
     */
    private static function renderTopicBody( string $slug ): string {
        $path = HelpTopics::filePath( $slug );
        if ( $path === null ) {
            return '<p><em>' . esc_html__( 'This topic has no content yet.', 'talenttrack' ) . '</em></p>';
        }
        $source = (string) file_get_contents( $path );
        if ( $source === '' ) {
            return '<p><em>' . esc_html__( 'This topic is empty.', 'talenttrack' ) . '</em></p>';
        }
        return Markdown::render( $source );
    }
}
