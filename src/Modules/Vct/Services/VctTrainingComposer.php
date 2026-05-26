<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctSessionsRepository;
use TT\Modules\Vct\Repositories\VctSessionBlocksRepository;
use TT\Modules\Vct\Rules\RulesEngine;
use TT\Modules\Vct\Rules\SessionPlanContext;

/**
 * VctTrainingComposer — high-level entry for POST /vct/sessions/generate.
 *
 * Spec calls this the "session generator"; the class name is prefixed
 * with `Vct` and avoids the legacy `Session…Generator` token (banned
 * under the #0035 no-regression linter, where it was the name of the
 * deleted generator from the renamed-away `tt_sessions` entity). VCT's
 * equivalent is functionally the same role — orchestrates context build
 * + RulesEngine::compose() + persistence — just under a name that
 * doesn't collide.
 *
 * Builds a SessionPlanContext from the request payload, runs the
 * engine's `compose()`, persists the resulting VCT-session row + its
 * blocks, and returns a hydrated record the REST controller serialises.
 *
 * Two failure modes:
 *   - Blocking validation error (missing age profile, missing template,
 *     no candidate for a required slot, intensity ceiling breach). The
 *     row is NOT created; the caller gets null back and reads
 *     `$ctx->warnings` for the reasons (the REST controller turns these
 *     into 400 + the structured reasons[] envelope per spec § REST API).
 *   - Soft warnings (over-envelope, no macro-block, PHV reduction).
 *     The row is persisted; warnings are returned alongside it.
 */
class VctTrainingComposer {

    private RulesEngine $engine;
    private VctSessionsRepository $sessions;
    private VctSessionBlocksRepository $blocks;

    public function __construct(
        RulesEngine $engine,
        VctSessionsRepository $sessions,
        VctSessionBlocksRepository $blocks
    ) {
        $this->engine   = $engine;
        $this->sessions = $sessions;
        $this->blocks   = $blocks;
    }

    /**
     * Compose + persist a new session. Returns the session record
     * + warnings, or null if a blocking warning prevented persistence.
     *
     * @param array<string,mixed> $payload
     * @return array{session:array<string,mixed>, warnings:list<array<string,mixed>>}|null
     */
    public function generate( array $payload ): ?array {
        $ctx = $this->buildContext( $payload );
        $ctx = $this->engine->compose( $ctx );

        if ( $this->hasBlockingWarning( $ctx ) ) {
            // Don't persist — caller surfaces the warnings as a 400.
            return null;
        }

        $new_id = $this->sessions->create( [
            'team_id'                 => $ctx->team_id,
            'session_date'            => $ctx->session_date,
            'start_time'              => $ctx->start_time,
            'age_group'               => $ctx->age_group,
            'md_context'              => $ctx->md_context,
            'tactical_theme'          => $ctx->tactical_theme,
            'total_duration_minutes'  => (int) ( $ctx->requested_duration_minutes ?? 0 ),
            'total_load'              => $ctx->total_load,
            'generated_by'            => $ctx->generated_by,
        ] );
        if ( $new_id <= 0 ) return null;

        $this->blocks->replaceForSession( $new_id, $ctx->blocks );

        $session = $this->sessions->find( $new_id );
        if ( $session === null ) return null;

        return [
            'session'  => array_merge( $session, [
                'blocks' => $this->blocks->listForSession( $new_id ),
            ] ),
            'warnings' => $ctx->warnings,
        ];
    }

    /**
     * Build a context from a request payload. Unrecognised keys are
     * ignored; missing keys take the SessionPlanContext defaults
     * (engine then emits structured warnings as appropriate).
     *
     * @param array<string,mixed> $payload
     */
    private function buildContext( array $payload ): SessionPlanContext {
        $ctx = new SessionPlanContext();
        $ctx->team_id        = (int)    ( $payload['team_id']        ?? 0 );
        $ctx->season_id      = (int)    ( $payload['season_id']      ?? 0 );
        $ctx->age_group      = (string) ( $payload['age_group']      ?? 'U10' );
        $ctx->session_date   = (string) ( $payload['session_date']   ?? '' );
        $ctx->tactical_theme = isset( $payload['tactical_theme'] ) && $payload['tactical_theme'] !== ''
            ? (string) $payload['tactical_theme']
            : null;
        $ctx->start_time     = isset( $payload['start_time'] ) && $payload['start_time'] !== ''
            ? (string) $payload['start_time']
            : null;
        $ctx->roster_player_ids = array_values( array_map( 'intval', (array) ( $payload['roster_player_ids'] ?? [] ) ) );
        $ctx->generated_by   = (int)    ( $payload['generated_by']   ?? get_current_user_id() );
        if ( isset( $payload['requested_duration_minutes'] ) ) {
            $ctx->requested_duration_minutes = (int) $payload['requested_duration_minutes'];
        }
        return $ctx;
    }

    private function hasBlockingWarning( SessionPlanContext $ctx ): bool {
        foreach ( $ctx->warnings as $w ) {
            if ( ( $w['severity'] ?? '' ) === 'block' ) return true;
        }
        return false;
    }
}
