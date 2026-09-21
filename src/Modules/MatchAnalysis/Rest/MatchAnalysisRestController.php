<?php
namespace TT\Modules\MatchAnalysis\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisTrends;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;

/**
 * MatchAnalysisRestController (#2705) — the REST surface for a match's
 * analysis.
 *
 * Resource-oriented under the activity that owns it, because the analysis
 * has no identity apart from its match:
 *
 *   GET    /activities/<id>/analysis
 *   PUT    /activities/<id>/analysis                     (whole document)
 *   PUT    /activities/<id>/analysis/sections/<key>
 *   PUT    /activities/<id>/analysis/players/<player_id>
 *   DELETE /activities/<id>/analysis/players/<player_id>
 *   POST   /activities/<id>/analysis/share/rotate
 *
 * The whole-document PUT is what the on-screen form submits (one save, one
 * request); the granular routes exist so a non-WordPress front end editing
 * one section does not have to send the whole document back. Both go
 * through `MatchAnalysisWriter`, so they cannot drift.
 *
 * Cap: `tt_edit_activities` to write, `tt_view_activities` to read — the
 * same permissions match prep and match execution use. No new capability:
 * an academy that lets someone plan and run a match lets them write it up.
 *
 * Plan (#3105): `match_analysis` is a Pro feature. Every route here is
 * wrapped in `self::gate()`, which asks `LicenseGate::enforceWriteRest()`
 * — verb-aware, so the reads pass through untouched and the writes answer
 * 402. That is #3017's third decision made structural rather than
 * remembered: a club that drops off Pro keeps reading and exporting the
 * analyses it wrote, and cannot write new ones.
 */
class MatchAnalysisRestController {

    private const NS = 'talenttrack/v1';

    /**
     * Wrap a route callback in the plan gate.
     *
     * Applied to every route rather than only the mutating ones on
     * purpose: `enforceWriteRest()` decides from the verb, so wrapping a
     * `GET` is a no-op, and "wrap everything" is a rule a reviewer can
     * check by looking, where "wrap the write ones" is a rule they have to
     * re-derive per route.
     *
     * The feature key is a literal rather than a constant so
     * `FeatureMapGateCoverageTest` can see it — that test greps for the
     * key next to a `LicenseGate::` call, which is what stops a Pro
     * feature shipping ungated.
     */
    private static function gate( callable $callback ): \Closure {
        return static function ( \WP_REST_Request $r ) use ( $callback ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceWriteRest( 'match_analysis', $r );
            return $blocked ?? $callback( $r );
        };
    }

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'get' ] ),
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => self::gate( [ __CLASS__, 'put' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::putArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/sections/(?P<section_key>[a-z_]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => self::gate( [ __CLASS__, 'put_section' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::sectionArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/players/(?P<player_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => self::gate( [ __CLASS__, 'put_player' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::playerArgs(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'delete_player' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/share', [
            [
                'methods'             => 'POST',
                'callback'            => self::gate( [ __CLASS__, 'create_share' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::shareArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/share/rotate', [
            [
                'methods'             => 'POST',
                'callback'            => self::gate( [ __CLASS__, 'rotate_share' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::shareArgs(),
            ],
        ] );

        // #3096 — who opened the share link. Gated on the same capability
        // that gates the analysis itself: the people who may read what was
        // written may see whether it was read.
        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/analysis/share-views', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'share_views' ] ),
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );

        // #2725 — the trend reads. One analysis is a note; these are the
        // same rows read across a period. Aggregation lives in
        // `MatchAnalysisTrends`, so the rendered report and a non-WordPress
        // front end get the same answer (CLAUDE.md §4).
        register_rest_route( self::NS, '/match-analysis-trends/teams/(?P<team_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'team_trends' ] ),
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );

        register_rest_route( self::NS, '/match-analysis-trends/players/(?P<player_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'player_trends' ] ),
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );
    }

    /**
     * #3843 — the body `PUT /activities/{id}/analysis` accepts.
     *
     * Every field is optional: the route writes any subset, so a client
     * that only knows about sections cannot wipe the player items by
     * omission. What the declaration buys is the other half — a key the
     * route does not take is refused by name instead of dropped behind a
     * 200, and route discovery finally says what the body looks like.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function putArgs(): array {
        return [
            'summary' => [
                'type'        => 'string',
                'description' => 'The overall read on the match, in prose. Replaces what is stored.',
            ],
            'status' => [
                'type'        => 'string',
                'enum'        => [ MatchAnalysisEnums::STATUS_DRAFT, MatchAnalysisEnums::STATUS_FINAL ],
                'description' => 'draft while it is being written; final once the share link may show it.',
            ],
            'sections' => [
                'type'        => 'object',
                'description' => 'Either an object of section key to { rating, notes }, or a list of { key, rating, notes }. A section sent replaces that section; one left out is untouched.',
            ],
            'players' => [
                'type'        => 'object',
                'description' => 'Player id to { marker, team_function, notes }. A player sent replaces that item; one left out is untouched.',
            ],
            'base_updated_at' => [
                'type'        => 'string',
                'description' => 'The updated_at this document was composed against. When the stored value has moved on the write is refused with 409 rather than merged.',
            ],
        ];
    }

    /**
     * #3819 — the body `PUT /activities/{activity_id}/analysis/players/{player_id}`
     * takes: one player's entry, the same shape a `players` entry on the
     * whole-document PUT carries.
     *
     * The minutes are not declared. They are read from the match, not from
     * the body — what a player was on the pitch for is a fact about the
     * match, not a thing the write-up may assert.
     *
     * Both note spellings are declared because `MatchAnalysisWriter::notesOf()`
     * reads both: `notes` wins, `note` is what a simpler client sends. The
     * endpoint's promise is that a client which knows less cannot destroy
     * what it does not understand, and refusing the older spelling here
     * would break exactly that.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function playerArgs(): array {
        return [
            'activity_id'   => [ 'type' => [ 'integer', 'string' ], 'description' => 'The match, from the URL. A copy in the body is accepted and ignored.' ],
            'player_id'     => [ 'type' => [ 'integer', 'string' ], 'description' => 'The player, from the URL. A copy in the body is accepted and ignored.' ],
            'marker'        => [ 'type' => 'string', 'description' => 'How the player is marked on the match: the shorthand the coach taps.' ],
            'team_function' => [ 'type' => 'string', 'description' => 'Which team function they were reviewed against.' ],
            'notes'         => [ 'type' => [ 'array', 'string' ], 'description' => 'The bullets on this player, each { body, valence }. A plain string is read as one unmarked bullet. Replaces every bullet they have.' ],
            'note'          => [ 'type' => [ 'array', 'string' ], 'description' => 'The same thing, spelled the way a simpler client sends it. Read only when notes is absent.' ],
        ];
    }

    /**
     * #3819 — the two share routes act on the match in the URL and take no
     * body. The token is never read from one either: a share link whose
     * token a caller could choose is a link a caller could guess.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function shareArgs(): array {
        return [ 'activity_id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The match, from the URL. A copy in the body is accepted and ignored.',
        ] ];
    }

    /**
     * #3843 — the body `PUT /activities/{id}/analysis/sections/{key}` takes.
     *
     * No `enum` on `rating` on purpose. Core checks an enum before the
     * callback runs and answers without `details.allowed`, and being told
     * what a rating may be is the whole point of the refusal here.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function sectionArgs(): array {
        return [
            'rating' => [
                'type'        => 'string',
                'description' => 'went_well, mixed or needs_work. An empty string clears the rating.',
            ],
            'notes' => [
                'type'        => 'array',
                'description' => 'The bullets on this section, each { body, valence }. A flat list of strings is read as unmarked bullets. Replaces every bullet the section has.',
            ],
        ];
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_activities' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_activities' );
    }

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    public static function get( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null ) {
            return self::not_a_match();
        }

        return RestResponse::success( self::shape( $payload ) );
    }

    /**
     * GET /match-analysis-trends/teams/<id>?from=&to=
     *
     * Counts, never averages — see `MatchAnalysisTrends`. `meets_floor`
     * tells the consumer whether there is enough of a sample to draw a
     * trend at all; below it, render "not enough matches yet" rather than
     * a thin line.
     */
    public static function team_trends( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['team_id'] );
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team', __( 'Unknown team.', 'talenttrack' ), 400 );
        }
        [ $from, $to ] = self::window( $r );

        return RestResponse::success( array_merge(
            ( new MatchAnalysisTrends() )->forTeams( [ $team_id ], $from, $to ),
            [ 'from' => $from, 'to' => $to, 'min_rated_matches' => MatchAnalysisTrends::MIN_RATED_MATCHES ]
        ) );
    }

    /** GET /match-analysis-trends/players/<id>?from=&to= */
    public static function player_trends( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['player_id'] );
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_player', __( 'Unknown player.', 'talenttrack' ), 400 );
        }
        if ( ! \TT\Infrastructure\Security\AuthorizationService::canViewPlayer( get_current_user_id(), $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have permission to view this player.', 'talenttrack' ), 403 );
        }
        [ $from, $to ] = self::window( $r );

        return RestResponse::success( array_merge(
            ( new MatchAnalysisTrends() )->forPlayer( $player_id, $from, $to ),
            [ 'from' => $from, 'to' => $to, 'min_rated_matches' => MatchAnalysisTrends::MIN_RATED_MATCHES ]
        ) );
    }

    /**
     * The requested window, defaulting to the last twelve months — long
     * enough for a season, short enough that last season's shape does not
     * blur into this one's.
     *
     * @return array{0:string,1:string}
     */
    private static function window( \WP_REST_Request $r ): array {
        $to   = sanitize_text_field( (string) ( $r['to'] ?? '' ) );
        $from = sanitize_text_field( (string) ( $r['from'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $to = gmdate( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $from = gmdate( 'Y-m-d', (int) strtotime( $to . ' -12 months' ) );
        }
        return [ $from, $to ];
    }

    // -----------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------

    /**
     * Write any subset of the analysis.
     *
     * ## Concurrency (#3007, epic #2881)
     *
     * Two people writing up the same match is plausible — a head coach in
     * the stand and an assistant on the touchline. Since the surface
     * autosaves, "last write wins" would mean the slower typist's document
     * quietly replacing the faster one's, sentence by sentence, with
     * neither of them told.
     *
     * So the write is **refused, not merged**. A client may send
     * `base_updated_at`: the `updated_at` it last saw. If the stored value
     * has moved on, nothing is written and the response is 409 — the coach
     * is looking at a version the server no longer holds, and the honest
     * answer is to say so and let them reload rather than to pick a winner.
     *
     * Opt-in by design: a client that sends no `base_updated_at` (the
     * wizard, an integration, a script) keeps the previous last-write-wins
     * behaviour, because it has no version to have been composed against.
     *
     * The resolution is one second, the resolution `DATETIME` has. Two
     * writes inside the same second are not distinguishable and the second
     * wins — a real hole, but a much smaller one than the every-keystroke
     * race it replaces, and closing it properly needs a revision column,
     * which is a migration this slice deliberately does not carry.
     */
    public static function put( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        // #3843 — the shape first, and before the find-or-create below: a
        // body this route cannot write must not leave an empty analysis
        // behind it, and a section it cannot store must refuse the whole
        // request rather than write the half it understood.
        $refused = BaseController::checkBody( $r, self::putArgs() );
        if ( $refused !== null ) return $refused;

        $body     = self::body( $r );
        $problems = MatchAnalysisWriter::problems( $body );
        if ( $problems !== [] ) return self::unwritable( $problems );

        $too_long = MatchAnalysisWriter::overlongNoteFields( $body );
        if ( $too_long !== [] ) return self::noteTooLong( $too_long );

        $composer = new MatchAnalysisComposer();
        $payload  = $composer->forActivity( $activity_id, true );
        if ( $payload === null ) {
            return self::not_a_match();
        }

        $analysis_id = (int) $payload['analysis_id'];
        if ( $analysis_id <= 0 ) {
            return RestResponse::error( 'db_error', __( 'The analysis could not be created.', 'talenttrack' ), 500 );
        }

        // Query string first: the browser sends it there so the token stays
        // out of the JSON the surface snapshots for undo and revert. The
        // body is accepted too, for an API client that finds that more
        // natural.
        $base = trim( (string) ( $r->get_param( 'base_updated_at' ) ?? '' ) );
        if ( $base === '' && isset( $body['base_updated_at'] ) ) {
            $base = trim( (string) $body['base_updated_at'] );
        }
        if ( $base !== '' ) {
            // Read the row rather than trusting the composed payload: the
            // composer ran before this request's own find-or-create, so on
            // a first write it would answer with the row it just made.
            $row     = ( new MatchAnalysisRepository() )->find( $analysis_id );
            $current = $row !== null ? (string) ( $row->updated_at ?? '' ) : '';
            if ( $current !== '' && $current !== $base ) {
                return RestResponse::error(
                    'analysis_conflict',
                    __( 'Someone else changed this analysis while you were writing. Reload the page to see their version.', 'talenttrack' ),
                    409
                );
            }
        }

        ( new MatchAnalysisWriter() )->apply(
            $analysis_id,
            $body,
            self::minutesByPlayer( $payload )
        );

        $fresh = $composer->forActivity( $activity_id, false );

        return RestResponse::success( $fresh !== null ? self::shape( $fresh ) : null );
    }

    public static function put_section( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $section_key = sanitize_key( (string) $r['section_key'] );

        if ( ! MatchAnalysisEnums::isSectionKey( $section_key ) ) {
            return RestResponse::error(
                'unknown_section',
                __( 'That is not a section of a match analysis.', 'talenttrack' ),
                400
            );
        }

        // #3843 — same contract as the whole-document PUT, checked before
        // the find-or-create so a refused write leaves nothing behind.
        $refused = BaseController::checkBody( $r, self::sectionArgs() );
        if ( $refused !== null ) return $refused;

        $body = self::body( $r );

        if ( array_key_exists( 'rating', $body ) && ! MatchAnalysisWriter::isWritableRating( $body['rating'] ) ) {
            return self::unwritable( [ 'rating' ] );
        }

        // #3853 — every bullet in one answer, before any of them is
        // written: a request that carries one over-long point writes none
        // of them, rather than storing three and shortening the fourth.
        $too_long = self::overlongIn( $body['notes'] ?? [] );
        if ( $too_long !== [] ) return self::noteTooLong( $too_long );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        ( new MatchAnalysisWriter() )->saveSection(
            (int) $payload['analysis_id'],
            $section_key,
            $body['rating'] ?? null,
            // #3091 — notes arrive as `[{body, valence}]`, and the writer
            // also still reads the flat list an older client sends. Every
            // valence is checked against the enum on the way in; an unknown
            // string is stored as neutral, never as itself.
            $body['notes'] ?? []
        );

        return RestResponse::success( [ 'section_key' => $section_key ] );
    }

    public static function put_player( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::playerArgs() );
        if ( $refused !== null ) return $refused;

        $activity_id = absint( $r['activity_id'] );
        $player_id   = absint( $r['player_id'] );

        $body = self::body( $r );

        // #3853 — before the find-or-create, so a refused write leaves
        // neither a shortened note nor an analysis row behind it.
        $too_long = self::overlongIn( MatchAnalysisWriter::notesOf( $body ) );
        if ( $too_long !== [] ) return self::noteTooLong( $too_long );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $minutes = self::minutesByPlayer( $payload );

        ( new MatchAnalysisWriter() )->savePlayerItem(
            (int) $payload['analysis_id'],
            $player_id,
            $body,
            $minutes[ $player_id ] ?? null
        );

        return RestResponse::success( [ 'player_id' => $player_id ] );
    }

    public static function delete_player( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $player_id   = absint( $r['player_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null ) return self::not_a_match();

        if ( (int) $payload['analysis_id'] > 0 ) {
            ( new MatchAnalysisWriter() )->deletePlayerItem( (int) $payload['analysis_id'], $player_id );
        }

        return RestResponse::success( [ 'player_id' => $player_id ] );
    }

    /**
     * Mint the share link. Separate from rendering the surface on purpose
     * (#2749): opening an analysis used to write a seed as a side effect,
     * so every analysis anyone merely looked at ended up with a live,
     * working URL nobody had asked for. Sharing is a decision.
     *
     * Idempotent — calling it twice returns the same link rather than
     * quietly invalidating the one already handed out. Replacing a link is
     * what `share/rotate` is for, and it says so in the UI.
     */
    public static function create_share( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::shareArgs() );
        if ( $refused !== null ) return $refused;

        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $analysis_id = (int) $payload['analysis_id'];

        return RestResponse::success( [
            'share_url' => MatchAnalysisShareLink::urlFor( $analysis_id ),
        ] );
    }

    /**
     * #3096 — unique openers and when the last of them arrived.
     *
     * Reads through `ShareViewQuery`, the same object the rendered share
     * block calls, so the two cannot answer differently. Returns zeroes for
     * an analysis nobody has opened rather than 404 — "not read yet" is an
     * answer, not a missing resource.
     */
    public static function share_views( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null ) return self::not_a_match();

        $summary = ( new \TT\Shared\Sharing\ShareViewQuery() )->summaryFor(
            \TT\Shared\Sharing\ShareViewRecorder::SUBJECT_MATCH_ANALYSIS,
            (int) $payload['analysis_id']
        );

        return RestResponse::success( [
            'unique'       => (int) $summary['unique'],
            'opens'        => (int) $summary['opens'],
            'last_seen_at' => $summary['last_seen_at'],
        ] );
    }

    public static function rotate_share( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::shareArgs() );
        if ( $refused !== null ) return $refused;

        $activity_id = absint( $r['activity_id'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, true );
        if ( $payload === null ) return self::not_a_match();

        $analysis_id = (int) $payload['analysis_id'];
        ( new MatchAnalysisRepository() )->rotateShareTokenSeed( $analysis_id );

        return RestResponse::success( [
            'share_url' => MatchAnalysisShareLink::urlFor( $analysis_id ),
        ] );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The decoded request body. Falls back to form-encoded params so a
     * plain HTML form POST reaches the same route as a JSON client.
     *
     * @return array<string,mixed>
     */
    private static function body( \WP_REST_Request $r ): array {
        $body = $r->get_json_params();
        if ( is_array( $body ) ) return $body;

        return (array) $r->get_body_params();
    }

    /**
     * player id => minutes, as the composer resolved them. Snapshotted onto
     * the item so a printed or shared analysis still says how long a player
     * was on the pitch after the attendance row has moved on.
     *
     * @param array<string,mixed> $payload
     * @return array<int,?int>
     */
    private static function minutesByPlayer( array $payload ): array {
        $out = [];
        foreach ( (array) $payload['players'] as $row ) {
            $out[ (int) $row['player_id'] ] = $row['minutes'] !== null ? (int) $row['minutes'] : null;
        }
        return $out;
    }

    /**
     * The wire shape. Deliberately not the raw rows: sections carry their
     * resolved label and players are a flat list, so a consumer does not
     * have to know the methodology taxonomy to render the document.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function shape( array $payload ): array {
        $activity = $payload['activity'];

        return [
            'activity_id' => (int) $activity->id,
            'analysis_id' => (int) $payload['analysis_id'],
            'exists'      => (int) $payload['analysis_id'] > 0,
            'status'      => (string) $payload['status'],
            // #3007 — the version token. A client that autosaves sends the
            // last value it saw back as `base_updated_at`; see `put()`.
            'updated_at'  => (string) ( $payload['updated_at'] ?? '' ),
            'summary'     => (string) $payload['summary'],
            'match'       => [
                'title'     => (string) ( $activity->title ?? '' ),
                'date'      => (string) ( $activity->session_date ?? '' ),
                'opponent'  => (string) ( $activity->opponent ?? '' ),
                'home_away' => (string) ( $activity->home_away ?? '' ),
                'team_id'   => (int) ( $activity->team_id ?? 0 ),
            ],
            'result'      => $payload['result'],
            'sections'    => array_values( $payload['sections'] ),
            'players'     => $payload['players'],
            // #2860 — read-only. Goals are written on the match-execution
            // surface; a consumer that wants to change one goes there, so
            // there is no matching write on this resource.
            'goals'       => array_values( (array) ( $payload['goals'] ?? [] ) ),
            'sources'     => [
                'match_prep'      => (bool) $payload['has_prep'],
                'match_execution' => (bool) $payload['has_exec'],
            ],
        ];
    }

    /**
     * #3843 — a value this resource cannot store, named rather than nulled.
     *
     * `details.allowed` carries both closed vocabularies a caller could
     * have got wrong, because a rejected `sections[0]` is nearly always a
     * list entry that never said which section it was.
     *
     * @param list<string> $fields
     */
    private static function unwritable( array $fields ): \WP_REST_Response {
        return RestResponse::error(
            'invalid_field',
            sprintf(
                /* translators: %s: comma-separated field names */
                __( 'These fields have a value this request cannot use: %s.', 'talenttrack' ),
                implode( ', ', $fields )
            ),
            400,
            [
                'fields'  => $fields,
                'allowed' => [
                    'sections' => MatchAnalysisEnums::sectionKeys(),
                    'rating'   => array_keys( MatchAnalysisEnums::ratings() ),
                ],
            ]
        );
    }

    /**
     * The over-long items in one note list, as the granular routes name
     * them (#3853).
     *
     * @param mixed $notes
     * @return list<string>
     */
    private static function overlongIn( $notes ): array {
        return array_map(
            static fn( int $index ): string => sprintf( 'notes[%d]', $index ),
            MatchAnalysisWriter::overlongNotes( $notes )
        );
    }

    /**
     * #3853 — a note longer than a note may be, refused rather than cut.
     *
     * The old answer was a 200 over a body that had lost its tail
     * mid-word, which is the worst of both: the coach believes the
     * observation is filed, and the selection call that later quotes it is
     * quoting half a sentence. The maximum is in the message and in
     * `details.max_length`, so a client can say it on the way in rather
     * than discover it on the way out.
     *
     * @param list<string> $fields
     */
    private static function noteTooLong( array $fields ): \WP_REST_Response {
        return RestResponse::error(
            'invalid_field',
            sprintf(
                /* translators: 1: maximum number of characters, 2: comma-separated field names */
                __( 'A note is one short point, at most %1$d characters. Shorten these and save again: %2$s.', 'talenttrack' ),
                MatchAnalysisWriter::NOTE_MAX,
                implode( ', ', $fields )
            ),
            400,
            [ 'fields' => $fields, 'max_length' => MatchAnalysisWriter::NOTE_MAX ]
        );
    }

    private static function not_a_match(): \WP_REST_Response {
        return RestResponse::error(
            'not_a_match',
            __( 'A match analysis can only be written for a match activity.', 'talenttrack' ),
            400
        );
    }
}
