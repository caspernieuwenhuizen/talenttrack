<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Documentation\AudienceResolver;
use TT\Modules\Documentation\HelpTopics;
use TT\Modules\Documentation\Markdown;

/**
 * FrontendDocsView — frontend Help & Docs page.
 *
 * Mirrors the wp-admin DocumentationPage layout (sidebar TOC + content
 * pane) but rendered in the frontend admin tier so non-admin users
 * (coaches, observers) can read documentation without being redirected
 * by FrontendAccessControl. Capability-gated per #0006: topics whose
 * audience markers don't intersect the viewer's allowed set are
 * filtered out of the TOC and from direct URL access.
 *
 * Routes: ?tt_view=docs, ?tt_view=docs&topic=<slug>.
 */
class FrontendDocsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $topics = HelpTopics::all();
        $groups = HelpTopics::groups();

        $viewer_audiences = AudienceResolver::allowedFor( $user_id );

        $topic_audiences  = [];
        foreach ( $topics as $s => $_t ) {
            $topic_audiences[ $s ] = AudienceResolver::readFromFile( HelpTopics::filePath( $s ) );
        }

        $requested = isset( $_GET['topic'] ) ? sanitize_key( (string) $_GET['topic'] ) : '';
        $slug      = HelpTopics::defaultSlug();
        if ( isset( $topics[ $requested ] )
             && AudienceResolver::isVisible( $topic_audiences[ $requested ] ?? [], $viewer_audiences ) ) {
            $slug = $requested;
        }
        $topic = $topics[ $slug ] ?? null;

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Help & Docs', 'talenttrack' ) );
        self::renderHeader( __( 'Help & Docs', 'talenttrack' ) );

        if ( ! $topic ) {
            echo '<p class="tt-notice">' . esc_html__( 'No documentation topics are available.', 'talenttrack' ) . '</p>';
            return;
        }

        $base_url = remove_query_arg( [ 'topic' ] );
        ?>
        <style>
        .tt-docs-fr-layout { display: grid; grid-template-columns: 260px 1fr; gap: 20px; margin-top: 8px; }
        @media (max-width: 880px) { .tt-docs-fr-layout { grid-template-columns: 1fr; } }
        .tt-docs-fr-sidebar { background: #fff; border: 1px solid var(--tt-line, #e3e1d8); border-radius: 8px; padding: 14px; max-height: calc(100vh - 120px); overflow-y: auto; }
        .tt-docs-fr-search { width: 100%; padding: 7px 10px; border: 1px solid var(--tt-line, #dcdcde); border-radius: 4px; font-size: 13px; box-sizing: border-box; margin-bottom: 10px; }
        .tt-docs-fr-group-label { font-size: 10px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--tt-muted, #8a9099); margin: 12px 0 6px; }
        .tt-docs-fr-group-label:first-child { margin-top: 0; }
        .tt-docs-fr-link { display: block; padding: 6px 10px; color: var(--tt-ink, #1a1d21); text-decoration: none; font-size: 13px; border-radius: 4px; line-height: 1.3; }
        .tt-docs-fr-link:hover { background: #f6f7f7; }
        .tt-docs-fr-link.is-active { background: var(--tt-accent-l, #eaf4ff); color: var(--tt-primary, #0a4b78); font-weight: 600; }
        .tt-docs-fr-content { background: #fff; border: 1px solid var(--tt-line, #e5e7ea); border-radius: 8px; padding: 22px 26px; line-height: 1.6; }
        .tt-docs-fr-content h1 { font-size: 22px; margin-top: 0; }
        .tt-docs-fr-content h2 { font-size: 17px; margin-top: 18px; }
        .tt-docs-fr-content h3 { font-size: 15px; margin-top: 14px; }
        .tt-docs-fr-breadcrumb { font-size: 12px; color: var(--tt-muted, #6a6d66); margin-bottom: 14px; }
        </style>

        <div class="tt-docs-fr-layout">
            <aside class="tt-docs-fr-sidebar">
                <input type="search" class="tt-docs-fr-search" id="tt-docs-fr-search" placeholder="<?php esc_attr_e( 'Search topics…', 'talenttrack' ); ?>" />
                <div id="tt-docs-fr-toc">
                    <?php foreach ( $groups as $gkey => $glabel ) :
                        $group_topics = array_filter( $topics, function ( $t, $s ) use ( $gkey, $topic_audiences, $viewer_audiences ) {
                            if ( $t['group'] !== $gkey ) return false;
                            return AudienceResolver::isVisible( $topic_audiences[ $s ] ?? [], $viewer_audiences );
                        }, ARRAY_FILTER_USE_BOTH );
                        if ( empty( $group_topics ) ) continue;
                        ?>
                        <div class="tt-docs-fr-group" data-group="<?php echo esc_attr( $gkey ); ?>">
                            <div class="tt-docs-fr-group-label"><?php echo esc_html( $glabel ); ?></div>
                            <?php foreach ( $group_topics as $s => $t ) :
                                $is_active = ( $s === $slug );
                                $url = add_query_arg( 'topic', $s, $base_url );
                                ?>
                                <a href="<?php echo esc_url( $url ); ?>"
                                   class="tt-docs-fr-link<?php echo $is_active ? ' is-active' : ''; ?>"
                                   data-title="<?php echo esc_attr( strtolower( $t['title'] ) ); ?>"
                                   data-summary="<?php echo esc_attr( strtolower( $t['summary'] ?? '' ) ); ?>">
                                    <?php echo esc_html( $t['title'] ); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>

            <main class="tt-docs-fr-content">
                <div class="tt-docs-fr-breadcrumb">
                    <a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Help', 'talenttrack' ); ?></a>
                    <span style="margin:0 6px; color:#ccc;">›</span>
                    <span><?php echo esc_html( $groups[ $topic['group'] ] ?? '' ); ?></span>
                    <span style="margin:0 6px; color:#ccc;">›</span>
                    <span style="color:var(--tt-ink, #1a1d21);"><?php echo esc_html( $topic['title'] ); ?></span>
                </div>

                <?php echo self::renderTopicBody( $slug ); ?>
            </main>
        </div>

        <script>
        (function(){
            var input = document.getElementById('tt-docs-fr-search');
            var toc   = document.getElementById('tt-docs-fr-toc');
            if (!input || !toc) return;
            input.addEventListener('input', function(){
                var q = input.value.trim().toLowerCase();
                toc.querySelectorAll('.tt-docs-fr-link').forEach(function(a){
                    var hay = (a.getAttribute('data-title') || '') + ' ' + (a.getAttribute('data-summary') || '');
                    a.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
                });
                toc.querySelectorAll('.tt-docs-fr-group').forEach(function(g){
                    var any = g.querySelector('.tt-docs-fr-link:not([style*="none"])');
                    g.style.display = any ? '' : 'none';
                });
            });
        })();
        </script>
        <?php
    }

    private static function renderTopicBody( string $slug ): string {
        $path = HelpTopics::filePath( $slug );
        if ( $path === null ) return '<p><em>' . esc_html__( 'This topic has no content yet.', 'talenttrack' ) . '</em></p>';
        $source = (string) file_get_contents( $path );
        if ( $source === '' ) return '<p><em>' . esc_html__( 'This topic is empty.', 'talenttrack' ) . '</em></p>';
        return Markdown::render( $source );
    }
}
