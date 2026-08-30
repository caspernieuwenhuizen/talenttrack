<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\SaveState;
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
 * ## Save model (#3007, epic #2881)
 *
 * The surface autosaves. It used to require an explicit Save on the
 * argument that abandoning a half-written draft was worth more than never
 * pressing Save — but the pilot said the opposite: a coach writing up a
 * game on a phone after the final whistle is composing over minutes, and
 * the work most worth protecting is the sentence they were halfway through.
 *
 * What "abandon a draft" turned into is better than what it replaced. The
 * analysis carries a status, and autosave only ever writes the **draft**;
 * **Mark as final** is the one deliberate commit on the surface, and it is
 * a publish rather than a save — until it is pressed, the share link says
 * the analysis is not ready rather than showing half a sentence about a
 * child. Backing out of an edit is `TT.Autosave`'s undo and revert (#3005,
 * #3006), which reach further than abandoning ever did.
 *
 * There is no Cancel: CLAUDE.md §6 governs forms where Save is the commit,
 * and here there is nothing uncommitted to walk away from.
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

        // #3151 — this surface names every player on the roster and quotes
        // the staff notes written about them, and `tt_view_activities` is
        // club-wide. Refuse before the composer reads any of it. An id that
        // resolves to no activity falls through, so the composer's own
        // "not a match activity" notice keeps saying the accurate thing.
        $scoped_team_id = \TT\Modules\Authorization\ActivityTeamScope::teamIdForActivity( $activity_id );
        if ( $scoped_team_id !== null
             && ! \TT\Modules\Authorization\ActivityTeamScope::coversTeam( $user_id, $scoped_team_id, $is_admin )
        ) {
            self::renderBreadcrumbs( null );
            echo \TT\Modules\Authorization\ActivityTeamScope::refusalNotice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped at source
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

        // #3105 — `match_analysis` is Pro. #3017's third decision: what the
        // club already wrote stays readable, so an out-of-plan install drops
        // to the read-only document with the upgrade panel above it rather
        // than losing the page. The editing form is what the plan buys.
        $in_plan = \TT\Modules\License\LicenseGate::allows( 'match_analysis' );

        echo '<div class="tt-ma">';

        if ( ! $in_plan ) {
            echo \TT\Modules\License\UpgradePanel::render( 'match_analysis', [ 'reads_kept' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — UpgradePanel returns escaped HTML
            self::renderReadOnly( $payload );
        } elseif ( $can_edit ) {
            // The editing surface keeps its own header; the document
            // renders its own, so only one of the two draws it.
            self::renderMatchHeader( $activity, (array) $payload['result'], (array) $payload );
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

        // #3096 — count the open, now that the token has resolved and there
        // is a document to show. Deliberately after both not-found returns:
        // recording a failed probe would make this table the oracle that
        // `renderShareNotFound()`'s identical wording exists to deny.
        // Read through an array cast rather than `$analysis->id`: the
        // resolver is typed `?object`, so a second undefined-property
        // access here would need a second baseline entry to say nothing.
        $row = (array) $analysis;

        ( new \TT\Shared\Sharing\ShareViewRecorder() )->record(
            \TT\Shared\Sharing\ShareViewRecorder::SUBJECT_MATCH_ANALYSIS,
            (int) ( $row['id'] ?? 0 ),
            $club_id,
            $uuid
        );

        // #3007 — a draft is not a document. Now that the surface autosaves,
        // this link would otherwise show whatever the coach happened to have
        // typed the last time it debounced: half a sentence about a named
        // child, sent to the staff room. The link is valid, so this is not a
        // not-found — it says the analysis is not published yet, which is
        // also why it sits *after* the recorder: a coach who has not marked
        // theirs final should be able to see that people are waiting on it.
        //
        // The guarantee the share link has always carried survives autosave:
        // if you were sent one and it renders, you are reading a finished
        // document.
        if ( (string) ( $payload['status'] ?? '' ) !== MatchAnalysisEnums::STATUS_FINAL ) {
            remove_filter( 'tt_current_club_id', $club_filter );
            self::renderShareNotFinal();
            return;
        }

        self::enqueueStyles();

        echo '<div class="tt-ma tt-ma--shared">';
        echo '<p class="tt-ma__share-note">'
            . esc_html__( 'Shared staff document. It names players and what they were told; please do not forward it outside the staff.', 'talenttrack' )
            . '</p>';
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
    /**
     * The link is good, the analysis is not finished (#3007).
     *
     * Deliberately different words from `renderShareNotFound()`. This one
     * is not an oracle risk: the reader was sent the link by the coach who
     * is writing it, so telling them it is not ready leaks nothing they
     * were not already told, and "this link is no longer valid" would send
     * them back to ask for a link they already have.
     */
    private static function renderShareNotFinal(): void {
        self::enqueueStyles();

        echo '<div class="tt-ma tt-ma--pending">';
        echo '<h1 class="tt-ma__title">' . esc_html__( 'This analysis is not finished yet', 'talenttrack' ) . '</h1>';
        echo '<p class="tt-ma__meta">'
            . esc_html__( 'The coach is still writing it. The link stays valid — open it again once they have marked it final.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

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

        // #3007 — no `tt-ajax-form`, and no submit. The form is now driven
        // by `TT.Autosave` from `match-analysis.js`: every edit debounces
        // into the same `PUT` the Save button used to fire, and the status
        // line below says where it got to. Leaving the shared submit
        // handler bound as well would give one form two ways to write
        // itself, racing each other on the same record.
        //
        // `data-updated` is the version token the surface sends back with
        // every write so a second coach cannot be overwritten silently.
        printf(
            '<form class="tt-ma__form" data-tt-ma-form data-rest-path="activities/%d/analysis" data-status="%s" data-updated="%s">',
            $activity_id,
            esc_attr( (string) ( $payload['status'] ?? MatchAnalysisEnums::STATUS_DRAFT ) ),
            esc_attr( (string) ( $payload['updated_at'] ?? '' ) )
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

        // --- The two chains -----------------------------------------
        // Written in the order they are read back: with the ball, then
        // without it. A transition only means something next to the phase
        // it comes out of, which is why these are two columns and not one
        // list of six.
        $chain_labels = MatchAnalysisEnums::chainLabels();

        // #2836 — the rating pills below carry glyphs alone, so the page
        // states the vocabulary once, on the line that introduces the
        // phases, instead of printing the three words on every one of them.
        echo '<div class="tt-ma__group-head tt-ma__group-head--chains">';
        SectionRatingControl::renderLegend();
        // #3091 — and what the + / − in front of each bullet mean. Same
        // place, same reason: state the vocabulary once rather than on
        // every one of the thirty controls that use it.
        NoteValenceControl::renderLegend();
        echo '</div>';

        echo '<div class="tt-ma__chains">';
        foreach ( MatchAnalysisEnums::chains() as $chain => $keys ) {
            echo '<div class="tt-ma__chain">';
            echo '<p class="tt-ma__chain-head">' . esc_html( $chain_labels[ $chain ] ?? '' ) . '</p>';
            foreach ( $keys as $key ) {
                if ( ! isset( $sections[ $key ] ) ) continue;
                self::renderSectionFields( (string) $key, (array) $sections[ $key ] );
            }
            echo '</div>';
        }
        echo '</div>';

        // --- Players -------------------------------------------------
        echo '<section class="tt-ma__section tt-ma__section--players">';
        echo '<h2 class="tt-ma__section-title">' . esc_html__( 'Players', 'talenttrack' ) . '</h2>';
        PlayerTallyRoster::render( $players, 'ma' );
        echo '</section>';

        // --- Save state, and the one deliberate commit ----------------
        //
        // #3007 — the Save button is gone. What replaces it is not a second
        // save affordance but a publish: **Mark as final** is what turns a
        // draft the coach is still composing into the document the share
        // link is allowed to show.
        $is_final = (string) ( $payload['status'] ?? '' ) === MatchAnalysisEnums::STATUS_FINAL;

        echo '<div class="tt-ma__actions">';
        SaveState::render( 'tt-ma__save-state' );

        printf(
            '<button type="button" class="tt-btn tt-btn-primary tt-ma__finalise" data-tt-ma-finalise%s>%s</button>',
            $is_final ? ' hidden' : '',
            esc_html__( 'Mark as final', 'talenttrack' )
        );
        printf(
            '<p class="tt-ma__final-note" data-tt-ma-final-note%s>%s</p>',
            $is_final ? '' : ' hidden',
            esc_html__( 'Marked final. Anyone holding the share link can read it.', 'talenttrack' )
        );
        echo '</div>';

        echo '</form>';

        self::renderOutputActions( $activity_id, (int) $payload['analysis_id'] );
    }

    /**
     * @param array<string,mixed> $section
     */
    private static function renderSectionFields( string $key, array $section ): void {
        $current = (string) ( $section['rating'] ?? '' );
        $items   = is_array( $section['note_items'] ?? null ) ? array_values( $section['note_items'] ) : [];

        echo '<section class="tt-ma__section">';

        // Title and rating share a line: the rating is a one-word judgement
        // and the bullets below are where the thinking goes, so giving it
        // an equal-weight row of its own said the opposite (#2748).
        echo '<div class="tt-ma__section-head">';
        echo '<h2 class="tt-ma__section-title">' . esc_html( (string) $section['label'] ) . '</h2>';
        SectionRatingControl::render( $key, (string) $section['label'], $current, 'ma' );
        echo '</div>';

        self::renderPlanned( (string) $section['planned'] );

        echo '<ul class="tt-ma__bullets">';
        for ( $i = 0; $i < self::BULLETS; $i++ ) {
            $item    = $items[ $i ] ?? [];
            $value   = (string) ( $item['body'] ?? '' );
            $valence = (string) ( $item['valence'] ?? '' );
            $context = sprintf(
                /* translators: 1: section name, 2: bullet number */
                __( '%1$s — point %2$d', 'talenttrack' ),
                (string) $section['label'],
                $i + 1
            );

            echo '<li class="tt-ma__bullet-row">';

            // #3091 — the mark sits in front of the sentence it qualifies,
            // so a phase rated "mixed" reads as the two halves it was.
            NoteValenceControl::render(
                sprintf( 'sections[%s][notes][%d]', $key, $i ),
                $valence,
                'tt-ma-' . sanitize_key( $key ) . '-n' . $i,
                $context
            );

            printf(
                '<input type="text" class="tt-input tt-ma__bullet" name="sections[%1$s][notes][%2$d][body]" value="%3$s" maxlength="180" placeholder="%4$s" aria-label="%5$s" />',
                esc_attr( $key ),
                $i,
                esc_attr( $value ),
                esc_attr__( 'One short point…', 'talenttrack' ),
                esc_attr( $context )
            );

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

        echo '</div>';

        if ( \TT\Core\FeatureRegistry::isEnabled( 'match_analysis_sharing' ) ) {
            self::renderShareBlock( $activity_id, $analysis_id );
        }
    }

    /**
     * The share link.
     *
     * Two states, because minting one is a decision and not a side effect
     * of opening the page (#2749). Before: a single Create button. After:
     * the URL itself with Copy as the primary action — what a coach wants
     * to do with a link is send it — plus a quiet reissue that says what it
     * costs the people already holding one.
     *
     * The URL is never built on render unless a seed already exists, so
     * merely looking at an analysis leaves it unshared.
     */
    private static function renderShareBlock( int $activity_id, int $analysis_id ): void {
        $seed      = ( new MatchAnalysisRepository() )->shareTokenSeed( $analysis_id );
        $share_url = $seed !== '' ? MatchAnalysisShareLink::urlFor( $analysis_id ) : '';

        printf( '<div class="tt-ma__share" data-activity-id="%d">', $activity_id );

        echo '<h2 class="tt-ma__share-title">' . esc_html__( 'Share with the staff', 'talenttrack' ) . '</h2>';

        printf(
            '<div class="tt-ma__share-empty"%s>',
            $share_url === '' ? '' : ' hidden'
        );
        echo '<p class="tt-ma__hint">'
            . esc_html__( 'No link exists yet, so this analysis cannot be opened by anyone outside the app.', 'talenttrack' )
            . '</p>';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-ma__share-create">'
            . esc_html__( 'Create share link', 'talenttrack' )
            . '</button>';
        echo '</div>';

        printf(
            '<div class="tt-ma__share-live"%s>',
            $share_url === '' ? ' hidden' : ''
        );
        echo '<div class="tt-ma__share-row">';
        printf(
            '<input type="text" class="tt-input tt-ma__share-url" value="%s" readonly aria-label="%s" />',
            esc_attr( $share_url ),
            esc_attr__( 'Staff share link', 'talenttrack' )
        );
        echo '<button type="button" class="tt-btn tt-btn-primary tt-ma__share-copy">'
            . esc_html__( 'Copy link', 'talenttrack' )
            . '</button>';
        echo '</div>';
        echo '<p class="tt-ma__hint">'
            . esc_html__( 'Anyone holding this link can read the analysis, including the player notes. It keeps working until you replace it.', 'talenttrack' )
            . '</p>';

        self::renderSeenBy( $analysis_id );
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-ma__share-rotate">'
            . esc_html__( 'Replace link', 'talenttrack' )
            . '</button>';
        echo '<span class="tt-ma__hint tt-ma__share-rotate-hint">'
            . esc_html__( 'The current link stops working immediately.', 'talenttrack' )
            . '</span>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * "Seen by 4 people · last opened 2 days ago" (#3096).
     *
     * One line inside the block that already exists, not a screen and not a
     * per-visit log. A coach wants to know whether the thing they sent
     * landed; a document shared between colleagues should not read as a
     * record of who looked at it and when.
     *
     * Silent until the first real visit. "Seen by 0 people" is a failure a
     * coach cannot act on — it would be read as broken rather than as
     * unread, and they would go looking for the bug instead of the staff.
     *
     * The numbers come from `ShareViewQuery`, which the REST endpoint also
     * calls, so the page and the API cannot drift (CLAUDE.md §4).
     */
    private static function renderSeenBy( int $analysis_id ): void {
        $summary = ( new \TT\Shared\Sharing\ShareViewQuery() )->summaryFor(
            \TT\Shared\Sharing\ShareViewRecorder::SUBJECT_MATCH_ANALYSIS,
            $analysis_id
        );

        $unique = (int) $summary['unique'];
        if ( $unique < 1 ) return;

        $line = sprintf(
            /* translators: %s: number of people who opened the share link. */
            _n( 'Seen by %s person', 'Seen by %s people', $unique, 'talenttrack' ),
            number_format_i18n( $unique )
        );

        $last = (string) $summary['last_seen_at'];
        if ( $last !== '' ) {
            $ts = strtotime( $last );
            if ( $ts !== false ) {
                $line .= ' · ' . sprintf(
                    /* translators: %s: human-readable time difference, e.g. "2 days" */
                    __( 'last opened %s ago', 'talenttrack' ),
                    human_time_diff( $ts, current_time( 'timestamp' ) )
                );
            }
        }

        echo '<p class="tt-ma__hint tt-ma__share-seen">' . esc_html( $line ) . '</p>';
    }

    // -----------------------------------------------------------------
    // Read-only rendering (share page, viewers without edit rights)
    // -----------------------------------------------------------------

    /**
     * The finished analysis. Rendered by `MatchAnalysisDocument` so this
     * view, the share page and the print sheet cannot grow three different
     * ideas of what a finished analysis looks like — and so the one people
     * forward to each other is the one that gets the attention.
     *
     * @param array<string,mixed> $payload
     */
    public static function renderReadOnly( array $payload ): void {
        MatchAnalysisDocument::render( $payload );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

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
        MatchAnalysisAssets::enqueue();
    }
}
