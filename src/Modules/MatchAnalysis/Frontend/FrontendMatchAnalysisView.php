<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMatchAnalysisView (#2706, #2707) — the post-match review surface.
 *
 * One page, two halves. The top half is the team read: an overall summary
 * followed by the four methodology team functions and set pieces, each with
 * a rating and short bullets, and each showing what the match plan asked
 * for so the review is against the plan rather than against memory. The
 * bottom half is the roster — every player who played, listed with their
 * minutes, each optionally carrying a marker and one specific line.
 *
 * Everything is optional. A coach with three minutes and one thing to say
 * writes one line and saves; the empty sections stay empty rather than
 * demanding a grade the coach does not have.
 *
 * Save is explicit (CLAUDE.md §6), unlike match prep's live-save. Match
 * prep is filled in at the pitch under time pressure where losing an edit
 * to a flaky connection is the worse failure; an analysis is written in one
 * sitting afterwards, where being able to abandon a half-written draft is
 * worth more than never pressing Save.
 */
class FrontendMatchAnalysisView extends FrontendViewBase {

    /** How many bullet inputs each rated section offers. */
    private const BULLETS = 4;

    public static function render( int $user_id, bool $is_admin ): void {
        // The module is switchable, and the dispatcher routes by slug
        // rather than by module, so the off-switch is enforced here — a
        // surface that stays reachable after being turned off is not
        // switchable, it is merely hidden.
        if ( ! \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\MatchAnalysis\MatchAnalysisModule::class ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match analysis', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match analysis is switched off for this academy.', 'talenttrack' ) . '</p>';
            return;
        }

        $can_edit = current_user_can( 'tt_edit_activities' );
        if ( ! $can_edit && ! current_user_can( 'tt_view_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match analysis is restricted to coaches and admins.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) {
            self::renderBreadcrumbs( null );
            echo '<p class="tt-notice">' . esc_html__( 'Open Match analysis from a match activity\'s detail page.', 'talenttrack' ) . '</p>';
            return;
        }

        $composer = new MatchAnalysisComposer();
        $payload  = $composer->forActivity( $activity_id, false );
        if ( $payload === null ) {
            self::renderBreadcrumbs( null );
            echo '<p class="tt-notice">' . esc_html__( 'A match analysis can only be written for a match activity.', 'talenttrack' ) . '</p>';
            return;
        }

        /** @var object $activity */
        $activity = $payload['activity'];

        if ( ! MatchAnalysisComposer::isReviewable( $activity ) ) {
            self::renderBreadcrumbs( $activity );
            self::enqueueStyles();
            echo '<p class="tt-notice">'
                . esc_html__( 'This match has not been played yet. The analysis opens once it has.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::renderBreadcrumbs( $activity );
        self::enqueueAssets();
        self::enqueueStyles();

        echo '<div class="tt-ma">';
        self::renderMatchHeader( $activity, (array) $payload['result'], (array) $payload );

        if ( $can_edit ) {
            self::renderForm( $activity_id, $payload );
        } else {
            self::renderReadOnly( $payload );
        }

        echo '</div>';
    }

    // -----------------------------------------------------------------
    // Shared share-link surface
    // -----------------------------------------------------------------

    /**
     * The staff share-link page. No session, no capability check — the
     * signed URL is the authorisation (see `MatchAnalysisShareLink`).
     *
     * Staff-only by intent: it carries the player items in full. The page
     * says so on its face and asks not to be indexed, because a URL naming
     * which children underperformed is one that should not turn up in a
     * search result.
     */
    public static function renderShared(): void {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'match_analysis_sharing' ) ) {
            self::renderShareNotFound();
            return;
        }

        $uuid  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : '';
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';

        $analysis = MatchAnalysisShareLink::resolve( $uuid, $token );
        if ( $analysis === null ) {
            self::renderShareNotFound();
            return;
        }

        // Scope reads to the analysis's own club: the viewer has no
        // session, so `CurrentClub` would otherwise answer with the
        // install default. The club id is not a secret — the URL already
        // addresses one specific analysis.
        $club_id     = (int) ( $analysis->club_id ?? 1 );
        $club_filter = static fn( $club ) => $club_id > 0 ? $club_id : $club;
        add_filter( 'tt_current_club_id', $club_filter );

        $payload = ( new MatchAnalysisComposer() )->forActivity( (int) $analysis->activity_id, false );

        if ( $payload === null ) {
            remove_filter( 'tt_current_club_id', $club_filter );
            self::renderShareNotFound();
            return;
        }

        self::enqueueStyles();

        echo '<div class="tt-ma tt-ma--shared">';
        echo '<p class="tt-ma__share-note">'
            . esc_html__( 'Shared staff document. It names players and what they were told; please do not forward it outside the staff.', 'talenttrack' )
            . '</p>';
        self::renderMatchHeader( $payload['activity'], (array) $payload['result'], (array) $payload );
        self::renderReadOnly( $payload );
        echo '</div>';

        remove_filter( 'tt_current_club_id', $club_filter );
    }

    public static function noindexMeta(): void {
        echo '<meta name="robots" content="noindex, nofollow, noarchive" />' . "\n";
        echo '<meta name="referrer" content="no-referrer" />' . "\n";
    }

    /**
     * Every failure mode of a share URL lands here with the same words —
     * an unknown analysis, a revoked link and a tampered token must not be
     * distinguishable from outside, or the page becomes an oracle for
     * whichever part the prober got wrong.
     */
    private static function renderShareNotFound(): void {
        self::enqueueStyles();

        echo '<div class="tt-ma tt-ma--gone">';
        echo '<h1 class="tt-ma__title">' . esc_html__( 'This link is no longer valid', 'talenttrack' ) . '</h1>';
        echo '<p class="tt-ma__meta">'
            . esc_html__( 'The analysis may have been removed, or the link may have been reissued. Ask the coach who shared it for a new one.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    // -----------------------------------------------------------------
    // Header
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $payload
     */
    private static function renderMatchHeader( object $activity, array $result, array $payload ): void {
        $title = (string) ( $activity->title ?? '' );
        if ( $title === '' ) $title = __( 'Match analysis', 'talenttrack' );

        echo '<header class="tt-ma__head">';
        echo '<h1 class="tt-ma__title">' . esc_html( $title ) . '</h1>';

        $meta = [];

        $date = (string) ( $activity->session_date ?? '' );
        if ( $date !== '' ) {
            $meta[] = date_i18n( (string) get_option( 'date_format' ), strtotime( $date ) );
        }

        $opponent = (string) ( $result['opponent'] ?? '' );
        if ( $opponent !== '' ) {
            $home_away = strtolower( (string) ( $result['home_away'] ?? '' ) );
            $meta[] = $home_away === 'away'
                /* translators: %s: opponent name */
                ? sprintf( __( 'Away at %s', 'talenttrack' ), $opponent )
                /* translators: %s: opponent name */
                : sprintf( __( 'Against %s', 'talenttrack' ), $opponent );
        }

        if ( ! empty( $result['has_score'] ) ) {
            $meta[] = sprintf( '%d – %d', (int) $result['home_score'], (int) $result['away_score'] );
        }

        if ( $meta ) {
            echo '<p class="tt-ma__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        }

        if ( empty( $payload['has_prep'] ) && empty( $payload['has_exec'] ) ) {
            echo '<p class="tt-ma__hint">'
                . esc_html__( 'This match was not prepped or run in the app, so there is nothing to pre-fill. Write it from what you saw.', 'talenttrack' )
                . '</p>';
        }

        echo '</header>';
    }

    // -----------------------------------------------------------------
    // Editable form
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     */
    private static function renderForm( int $activity_id, array $payload ): void {
        $sections = (array) $payload['sections'];
        $players  = (array) $payload['players'];

        printf(
            '<form class="tt-ajax-form tt-ma__form" data-rest-path="activities/%d/analysis" data-rest-method="PUT" data-redirect-after-save="reload">',
            $activity_id
        );

        // --- Overall ------------------------------------------------
        $general = $sections[ MatchAnalysisEnums::SECTION_GENERAL ];
        echo '<section class="tt-ma__section tt-ma__section--general">';
        echo '<h2 class="tt-ma__section-title">' . esc_html( (string) $general['label'] ) . '</h2>';
        self::renderPlanned( (string) $general['planned'] );
        echo '<label class="tt-ma__label" for="tt-ma-summary">'
            . esc_html__( 'How did the match go, in a few sentences?', 'talenttrack' )
            . '</label>';
        echo '<textarea class="tt-input tt-ma__summary" id="tt-ma-summary" name="summary" rows="4">'
            . esc_textarea( (string) $payload['summary'] )
            . '</textarea>';
        echo '</section>';

        // --- Team functions + set pieces ----------------------------
        foreach ( $sections as $key => $section ) {
            if ( ! $section['rated'] ) continue;
            self::renderSectionFields( (string) $key, $section );
        }

        // --- Players -------------------------------------------------
        self::renderPlayerFields( $players );

        // --- Save ----------------------------------------------------
        echo '<div class="tt-ma__actions">';
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes its own output
            'label'      => __( 'Save analysis', 'talenttrack' ),
            'cancel_url' => self::cancelUrl( $activity_id ),
        ] );
        echo '</div>';

        echo '</form>';

        self::renderOutputActions( $activity_id, (int) $payload['analysis_id'] );
    }

    /**
     * @param array<string,mixed> $section
     */
    private static function renderSectionFields( string $key, array $section ): void {
        $ratings = MatchAnalysisEnums::ratings();
        $current = (string) ( $section['rating'] ?? '' );
        $lines   = self::bulletLines( (string) $section['notes'] );

        echo '<section class="tt-ma__section">';
        echo '<h2 class="tt-ma__section-title">' . esc_html( (string) $section['label'] ) . '</h2>';
        self::renderPlanned( (string) $section['planned'] );

        printf(
            '<div class="tt-ma__ratings" role="radiogroup" aria-label="%s">',
            esc_attr( sprintf(
                /* translators: %s: section name, e.g. Aanvallen */
                __( 'Rating — %s', 'talenttrack' ),
                (string) $section['label']
            ) )
        );

        // "Not rated" is a real option, not the absence of one: a coach
        // must be able to take a rating back off, and a radio group with
        // no un-check is otherwise a one-way door.
        $options = [ '' => __( 'Not rated', 'talenttrack' ) ] + $ratings;
        foreach ( $options as $value => $label ) {
            $id = 'tt-ma-' . sanitize_key( $key ) . '-' . ( $value === '' ? 'none' : sanitize_key( (string) $value ) );
            printf(
                '<input type="radio" class="tt-ma__rating-input" id="%1$s" name="sections[%2$s][rating]" value="%3$s"%4$s />'
                . '<label class="tt-ma__rating" for="%1$s" data-rating="%3$s">%5$s</label>',
                esc_attr( $id ),
                esc_attr( $key ),
                esc_attr( (string) $value ),
                checked( $current, (string) $value, false ),
                esc_html( (string) $label )
            );
        }
        echo '</div>';

        echo '<ul class="tt-ma__bullets">';
        for ( $i = 0; $i < self::BULLETS; $i++ ) {
            $value = $lines[ $i ] ?? '';
            printf(
                '<li><input type="text" class="tt-input tt-ma__bullet" name="sections[%1$s][notes][%2$d]" value="%3$s" maxlength="180" placeholder="%4$s" aria-label="%5$s" /></li>',
                esc_attr( $key ),
                $i,
                esc_attr( $value ),
                esc_attr__( 'One short point…', 'talenttrack' ),
                esc_attr( sprintf(
                    /* translators: 1: section name, 2: bullet number */
                    __( '%1$s — point %2$d', 'talenttrack' ),
                    (string) $section['label'],
                    $i + 1
                ) )
            );
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * @param list<array<string,mixed>> $players
     */
    private static function renderPlayerFields( array $players ): void {
        echo '<section class="tt-ma__section tt-ma__section--players">';
        echo '<h2 class="tt-ma__section-title">' . esc_html__( 'Players', 'talenttrack' ) . '</h2>';

        if ( empty( $players ) ) {
            echo '<p class="tt-ma__hint">'
                . esc_html__( 'Nobody is recorded as having played this match, so there is no roster to mark up. Record attendance or minutes first.', 'talenttrack' )
                . '</p></section>';
            return;
        }

        echo '<p class="tt-ma__hint">'
            . esc_html__( 'Everyone who played is listed. Leave a player untouched to say nothing about them — most rows usually stay empty.', 'talenttrack' )
            . '</p>';

        $markers = MatchAnalysisEnums::markers();
        $tags    = MatchAnalysisEnums::playerItemTags();

        echo '<ul class="tt-ma__players">';
        foreach ( $players as $player ) {
            $pid     = (int) $player['player_id'];
            $marker  = (string) $player['marker'];
            $minutes = $player['minutes'];

            echo '<li class="tt-ma__player">';
            echo '<div class="tt-ma__player-head">';
            echo '<span class="tt-ma__player-name">' . esc_html( (string) $player['name'] ) . '</span>';
            if ( $minutes !== null ) {
                printf(
                    '<span class="tt-ma__player-min">%s</span>',
                    esc_html( sprintf(
                        /* translators: %d: minutes played */
                        __( "%d'", 'talenttrack' ),
                        (int) $minutes
                    ) )
                );
            }
            echo '</div>';

            if ( (string) $player['prep_focus'] !== '' ) {
                echo '<p class="tt-ma__player-plan">'
                    . esc_html( sprintf(
                        /* translators: %s: the attention note written on the match plan */
                        __( 'Asked to: %s', 'talenttrack' ),
                        (string) $player['prep_focus']
                    ) )
                    . '</p>';
            }

            printf(
                '<div class="tt-ma__markers" role="radiogroup" aria-label="%s">',
                esc_attr( sprintf(
                    /* translators: %s: player name */
                    __( 'How did %s do?', 'talenttrack' ),
                    (string) $player['name']
                ) )
            );

            $options = [ '' => __( 'Not mentioned', 'talenttrack' ) ] + $markers;
            foreach ( $options as $value => $label ) {
                $id = 'tt-ma-p' . $pid . '-' . ( $value === '' ? 'none' : sanitize_key( (string) $value ) );
                printf(
                    '<input type="radio" class="tt-ma__marker-input" id="%1$s" name="players[%2$d][marker]" value="%3$s"%4$s />'
                    . '<label class="tt-ma__marker" for="%1$s" data-marker="%3$s">%5$s</label>',
                    esc_attr( $id ),
                    $pid,
                    esc_attr( (string) $value ),
                    checked( $marker, (string) $value, false ),
                    esc_html( (string) $label )
                );
            }
            echo '</div>';

            printf(
                '<input type="text" class="tt-input tt-ma__player-note" name="players[%1$d][note]" value="%2$s" maxlength="240" placeholder="%3$s" aria-label="%4$s" />',
                $pid,
                esc_attr( (string) $player['note'] ),
                esc_attr__( 'What exactly did they do?', 'talenttrack' ),
                esc_attr( sprintf(
                    /* translators: %s: player name */
                    __( 'Note about %s', 'talenttrack' ),
                    (string) $player['name']
                ) )
            );

            printf(
                '<select class="tt-input tt-ma__player-tag" name="players[%1$d][team_function]" aria-label="%2$s">',
                $pid,
                esc_attr( sprintf(
                    /* translators: %s: player name */
                    __( 'Which part of the game — %s', 'talenttrack' ),
                    (string) $player['name']
                ) )
            );
            printf(
                '<option value=""%s>%s</option>',
                selected( (string) ( $player['team_function'] ?? '' ), '', false ),
                esc_html__( 'No particular phase', 'talenttrack' )
            );
            foreach ( $tags as $tag_key => $tag_label ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( (string) $tag_key ),
                    selected( (string) ( $player['team_function'] ?? '' ), (string) $tag_key, false ),
                    esc_html( (string) $tag_label )
                );
            }
            echo '</select>';

            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * Print + share actions. Outside the form on purpose — they are links,
     * and a link inside a form that submits over REST reads as a second
     * commit action next to Save.
     */
    private static function renderOutputActions( int $activity_id, int $analysis_id ): void {
        if ( $analysis_id <= 0 ) return;

        echo '<div class="tt-ma__outputs">';

        if ( \TT\Core\FeatureRegistry::isEnabled( 'export_match_analysis_pdf' ) ) {
            $print_url = add_query_arg(
                [ 'tt_match_analysis_print' => 1, 'activity_id' => $activity_id ],
                home_url( '/' )
            );
            printf(
                '<a class="tt-btn tt-btn-secondary" href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url( $print_url ),
                esc_html__( 'Print or save as PDF', 'talenttrack' )
            );
        }

        if ( \TT\Core\FeatureRegistry::isEnabled( 'match_analysis_sharing' ) ) {
            $share_url = MatchAnalysisShareLink::urlFor( $analysis_id );
            if ( $share_url !== '' ) {
                printf(
                    '<a class="tt-btn tt-btn-secondary" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url( $share_url ),
                    esc_html__( 'Open staff share link', 'talenttrack' )
                );
                printf(
                    '<button type="button" class="tt-btn tt-btn-secondary tt-ma__rotate" data-rest-path="activities/%d/analysis/share/rotate">%s</button>',
                    $activity_id,
                    esc_html__( 'Revoke and reissue link', 'talenttrack' )
                );
            }
        }

        echo '</div>';
        echo '<p class="tt-ma__hint">'
            . esc_html__( 'The share link is for staff. It shows the player notes in full, and anyone holding it can read them until you reissue it.', 'talenttrack' )
            . '</p>';
    }

    // -----------------------------------------------------------------
    // Read-only rendering (share page, viewers without edit rights)
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     */
    public static function renderReadOnly( array $payload ): void {
        $sections = (array) $payload['sections'];
        $players  = (array) $payload['players'];

        $summary = trim( (string) $payload['summary'] );
        if ( $summary !== '' ) {
            echo '<section class="tt-ma__section">';
            echo '<h2 class="tt-ma__section-title">'
                . esc_html( MatchAnalysisEnums::sectionLabel( MatchAnalysisEnums::SECTION_GENERAL ) )
                . '</h2>';
            echo '<p class="tt-ma__read-summary">' . nl2br( esc_html( $summary ) ) . '</p>';
            echo '</section>';
        }

        $written = 0;
        foreach ( $sections as $section ) {
            if ( ! $section['rated'] ) continue;
            if ( ( $section['rating'] ?? null ) === null && trim( (string) $section['notes'] ) === '' ) continue;
            $written++;

            echo '<section class="tt-ma__section">';
            echo '<h2 class="tt-ma__section-title">' . esc_html( (string) $section['label'] );
            if ( $section['rating'] !== null ) {
                printf(
                    ' <span class="tt-ma__rating-pill" data-rating="%s">%s</span>',
                    esc_attr( (string) $section['rating'] ),
                    esc_html( MatchAnalysisEnums::ratings()[ $section['rating'] ] ?? '' )
                );
            }
            echo '</h2>';

            $lines = self::bulletLines( (string) $section['notes'] );
            if ( $lines ) {
                echo '<ul class="tt-ma__read-bullets">';
                foreach ( $lines as $line ) {
                    echo '<li>' . esc_html( $line ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }

        $mentioned = array_values( array_filter(
            $players,
            static fn( array $p ): bool => (string) $p['marker'] !== '' || trim( (string) $p['note'] ) !== ''
        ) );

        if ( $mentioned ) {
            echo '<section class="tt-ma__section tt-ma__section--players">';
            echo '<h2 class="tt-ma__section-title">' . esc_html__( 'Players', 'talenttrack' ) . '</h2>';
            echo '<ul class="tt-ma__read-players">';
            foreach ( $mentioned as $player ) {
                echo '<li class="tt-ma__read-player">';
                printf(
                    '<span class="tt-ma__player-name">%s</span>',
                    esc_html( (string) $player['name'] )
                );
                if ( (string) $player['marker'] !== '' ) {
                    printf(
                        ' <span class="tt-ma__marker-pill" data-marker="%s">%s</span>',
                        esc_attr( (string) $player['marker'] ),
                        esc_html( MatchAnalysisEnums::markerLabel( (string) $player['marker'] ) )
                    );
                }
                if ( trim( (string) $player['note'] ) !== '' ) {
                    echo '<p class="tt-ma__read-note">' . esc_html( (string) $player['note'] ) . '</p>';
                }
                echo '</li>';
            }
            echo '</ul>';
            echo '</section>';
        }

        if ( $written === 0 && $summary === '' && ! $mentioned ) {
            echo '<p class="tt-notice">' . esc_html__( 'Nothing has been written for this match yet.', 'talenttrack' ) . '</p>';
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Cancel target: back where the user came from when the entry URL said
     * so, otherwise the match's own detail page (CLAUDE.md §6).
     */
    private static function cancelUrl( int $activity_id ): string {
        $back = BackLink::resolve();
        if ( $back !== null ) return $back['url'];

        return RecordLink::detailUrlFor( 'activities', $activity_id );
    }

    /**
     * The plan line above a section's inputs. Rendered only when the plan
     * had something to say — an empty "Planned:" reads as "we planned
     * nothing", which is not the same as "match prep never asked".
     */
    private static function renderPlanned( string $planned ): void {
        $planned = trim( $planned );
        if ( $planned === '' ) return;

        echo '<p class="tt-ma__planned">';
        echo '<span class="tt-ma__planned-label">' . esc_html__( 'Planned', 'talenttrack' ) . '</span> ';
        echo esc_html( str_replace( "\n", ' · ', $planned ) );
        echo '</p>';
    }

    /**
     * @return list<string>
     */
    private static function bulletLines( string $notes ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $notes ) ?: [];
        $lines = array_map( 'trim', $lines );

        return array_values( array_filter( $lines, static fn( string $l ): bool => $l !== '' ) );
    }

    private static function renderBreadcrumbs( ?object $activity ): void {
        $intermediate = [ FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ];

        if ( $activity ) {
            $label = (string) ( $activity->title ?? '' );
            $intermediate[] = [
                'label' => $label !== '' ? $label : __( 'Activity', 'talenttrack' ),
                'url'   => RecordLink::detailUrlFor( 'activities', (int) $activity->id ),
            ];
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Match analysis', 'talenttrack' ), $intermediate );
    }

    private static function enqueueStyles(): void {
        wp_enqueue_style(
            'tt-frontend-match-analysis',
            TT_PLUGIN_URL . 'assets/css/frontend-match-analysis.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-match-analysis',
            TT_PLUGIN_URL . 'assets/js/match-analysis.js',
            [ 'tt-public' ],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-match-analysis', 'TT_MatchAnalysis', [
            'confirmRotate' => __( 'Reissue the share link? Everyone holding the current link loses access immediately.', 'talenttrack' ),
            'rotated'       => __( 'A new link has been issued. The previous one no longer works.', 'talenttrack' ),
            'failed'        => __( 'The link could not be reissued. Try again.', 'talenttrack' ),
        ] );
    }
}
