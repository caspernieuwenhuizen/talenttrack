<?php
namespace TT\Modules\Exercises;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ExerciseScenesRepository (#2501, epic #2493) — animated diagrams
 * attached to an exercise.
 *
 * ## The scene is validated on the way in, not on the way out
 *
 * `scene_json` arrives from a canvas editor, which means it arrives from
 * the browser, which means it arrives from anywhere. Every surface that
 * renders a scene — exercise detail, the sideline view, the A4 print —
 * would otherwise each need to defend itself against a malformed one.
 * Normalising here means a row in this table is always renderable, and
 * the renderers can be simple.
 *
 * What normalisation enforces:
 *
 *   - coordinates clamped to the 0–100 pitch space the contract defines,
 *     so a dragged actor can never end up off the pitch;
 *   - keyframe `t` clamped to `0..duration_ms` and sorted, because an
 *     out-of-order keyframe makes the interpolator walk backwards;
 *   - actor `kind` and link `kind` restricted to the known vocabularies,
 *     so a renderer's class-name lookup cannot be used to inject;
 *   - links dropped when either endpoint is not an actor in this scene —
 *     a line to a deleted player is a line to nowhere.
 *
 * ## One primary scene per exercise
 *
 * The three read surfaces each show one scene, and without an explicit
 * flag each would have to invent "the first one" and eventually
 * disagree. `setPrimary()` is the only way to move it, and it clears the
 * others in the same statement pair so two can never both be primary.
 */
final class ExerciseScenesRepository {

    /** Actor kinds the renderer knows how to draw. */
    public const ACTOR_KINDS = [ 'player', 'opponent', 'ball', 'cone', 'goal', 'keeper' ];

    /** Link kinds the stylesheet has a class for. */
    public const LINK_KINDS = [ 'pass', 'dribble', 'run', 'shot', 'press' ];

    /** Pitch presets the renderer can draw. */
    public const PITCH_PRESETS = [ 'full', 'half', 'third', 'blank' ];

    private const MAX_DURATION_MS = 60000;
    private const MIN_DURATION_MS = 500;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_exercise_scenes';
    }

    /** @return list<object> */
    public function listForExercise( int $exercise_id ): array {
        if ( $exercise_id <= 0 ) return [];

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE exercise_id = %d AND club_id = %d
           ORDER BY is_primary DESC, sort_order ASC, id ASC",
            $exercise_id,
            CurrentClub::id()
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The one scene a read surface shows.
     *
     * Falls back to the lowest-sorted scene when nothing is flagged, so
     * an exercise whose scenes predate the flag still renders something
     * rather than nothing.
     */
    public function primaryFor( int $exercise_id ): ?object {
        $scenes = $this->listForExercise( $exercise_id );

        return $scenes === [] ? null : $scenes[0];
    }

    public function findById( int $id ): ?object {
        if ( $id <= 0 ) return null;

        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d",
            $id,
            CurrentClub::id()
        ) );

        return $row ?: null;
    }

    /**
     * @param array{exercise_id:int, name?:string|null, pitch_preset?:string, duration_ms?:int, scene?:array<string,mixed>, is_primary?:bool} $data
     */
    public function create( array $data ): int {
        $exercise_id = (int) ( $data['exercise_id'] ?? 0 );
        if ( $exercise_id <= 0 ) return 0;

        global $wpdb;

        $duration = $this->cleanDuration( $data['duration_ms'] ?? null );
        $scene    = $this->normalise( (array) ( $data['scene'] ?? [] ), $duration );

        $ok = $wpdb->insert( $this->table(), [
            'uuid'         => wp_generate_uuid4(),
            'club_id'      => CurrentClub::id(),
            'exercise_id'  => $exercise_id,
            'name'         => $this->cleanName( $data['name'] ?? null ),
            'pitch_preset' => $this->cleanPreset( $data['pitch_preset'] ?? null ),
            'duration_ms'  => $duration,
            'scene_json'   => (string) wp_json_encode( $scene ),
            'sort_order'   => $this->nextSortOrder( $exercise_id ),
            'is_primary'   => 0,
        ] );
        if ( $ok === false ) return 0;

        $id = (int) $wpdb->insert_id;

        // The first scene on an exercise is its primary one — otherwise
        // authoring a scene would leave every read surface still showing
        // nothing until someone found a flag they did not know existed.
        if ( ! empty( $data['is_primary'] ) || count( $this->listForExercise( $exercise_id ) ) === 1 ) {
            $this->setPrimary( $id );
        }

        return $id;
    }

    /**
     * @param array{name?:string|null, pitch_preset?:string, duration_ms?:int, scene?:array<string,mixed>, sort_order?:int} $patch
     */
    public function update( int $id, array $patch ): bool {
        $existing = $this->findById( $id );
        if ( ! $existing ) return false;

        global $wpdb;

        $fields = [];

        if ( array_key_exists( 'name', $patch ) ) {
            $fields['name'] = $this->cleanName( $patch['name'] );
        }
        if ( array_key_exists( 'pitch_preset', $patch ) ) {
            $fields['pitch_preset'] = $this->cleanPreset( $patch['pitch_preset'] );
        }

        $duration = array_key_exists( 'duration_ms', $patch )
            ? $this->cleanDuration( $patch['duration_ms'] )
            : (int) $existing->duration_ms;

        if ( array_key_exists( 'duration_ms', $patch ) ) {
            $fields['duration_ms'] = $duration;
        }

        if ( array_key_exists( 'scene', $patch ) ) {
            // Re-normalised against the duration being saved, not the one
            // already stored: shortening a scene must pull its keyframes
            // in with it rather than leave them past the end.
            $fields['scene_json'] = (string) wp_json_encode(
                $this->normalise( (array) $patch['scene'], $duration )
            );
        }

        if ( array_key_exists( 'sort_order', $patch ) ) {
            $fields['sort_order'] = max( 0, (int) $patch['sort_order'] );
        }

        if ( $fields === [] ) return true;

        return $wpdb->update( $this->table(), $fields, [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] ) !== false;
    }

    /** Exactly one primary per exercise, enforced by clearing then setting. */
    public function setPrimary( int $id ): bool {
        $scene = $this->findById( $id );
        if ( ! $scene ) return false;

        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()} SET is_primary = 0 WHERE exercise_id = %d AND club_id = %d",
            (int) $scene->exercise_id,
            CurrentClub::id()
        ) );

        return $wpdb->update( $this->table(), [ 'is_primary' => 1 ], [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] ) !== false;
    }

    public function delete( int $id ): bool {
        $scene = $this->findById( $id );
        if ( ! $scene ) return false;

        global $wpdb;

        $deleted = $wpdb->delete( $this->table(), [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] );
        if ( $deleted === false ) return false;

        // Deleting the primary promotes the next one rather than leaving
        // the exercise with scenes none of which is shown.
        if ( (int) $scene->is_primary === 1 ) {
            $remaining = $this->listForExercise( (int) $scene->exercise_id );
            if ( $remaining !== [] ) $this->setPrimary( (int) $remaining[0]->id );
        }

        return true;
    }

    /** Every scene on an exercise, for the cascade when one is removed. */
    public function deleteForExercise( int $exercise_id ): int {
        if ( $exercise_id <= 0 ) return 0;

        global $wpdb;

        $deleted = $wpdb->delete( $this->table(), [
            'exercise_id' => $exercise_id,
            'club_id'     => CurrentClub::id(),
        ] );

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Decode a stored scene, always into a renderable shape.
     *
     * @return array{pitch:string, duration_ms:int, actors:list<array<string,mixed>>, links:list<array<string,mixed>>}
     */
    public function decode( object $row ): array {
        $decoded = json_decode( (string) ( $row->scene_json ?? '' ), true );

        return $this->normalise(
            is_array( $decoded ) ? $decoded : [],
            (int) ( $row->duration_ms ?? 6000 ),
            (string) ( $row->pitch_preset ?? 'full' )
        );
    }

    // ---- normalisation ----------------------------------------------------

    /**
     * @param array<string,mixed> $scene
     * @return array{pitch:string, duration_ms:int, actors:list<array<string,mixed>>, links:list<array<string,mixed>>}
     */
    private function normalise( array $scene, int $duration, ?string $preset = null ): array {
        $actors = [];
        $ids    = [];

        foreach ( (array) ( $scene['actors'] ?? [] ) as $raw ) {
            $actor = $this->normaliseActor( (array) $raw, $duration );
            if ( $actor === null ) continue;

            $actors[]           = $actor;
            $ids[ $actor['id'] ] = true;
        }

        $links = [];
        foreach ( (array) ( $scene['links'] ?? [] ) as $raw ) {
            $link = $this->normaliseLink( (array) $raw, $ids, $duration );
            if ( $link !== null ) $links[] = $link;
        }

        return [
            'pitch'       => $this->cleanPreset( $preset ?? ( $scene['pitch'] ?? null ) ),
            'duration_ms' => $duration,
            'actors'      => $actors,
            'links'       => $links,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function normaliseActor( array $raw, int $duration ): ?array {
        $id = sanitize_key( (string) ( $raw['id'] ?? '' ) );
        if ( $id === '' ) return null;

        $kind = (string) ( $raw['kind'] ?? 'player' );
        if ( ! in_array( $kind, self::ACTOR_KINDS, true ) ) $kind = 'player';

        $keyframes = [];
        foreach ( (array) ( $raw['keyframes'] ?? [] ) as $kf ) {
            $kf = (array) $kf;
            $keyframes[] = [
                't' => max( 0, min( $duration, (int) ( $kf['t'] ?? 0 ) ) ),
                'x' => $this->clampCoord( $kf['x'] ?? 50 ),
                'y' => $this->clampCoord( $kf['y'] ?? 50 ),
            ];
        }

        // An actor with no keyframes has no position, so it cannot be
        // drawn. Dropping it is kinder than rendering it at 0,0 in the
        // corner where nobody put it.
        if ( $keyframes === [] ) return null;

        usort( $keyframes, static fn( array $a, array $b ): int => $a['t'] <=> $b['t'] );

        // Collapse duplicate times, keeping the last authored position.
        // Two keyframes at the same t make an actor appear to teleport
        // there — which is what clamping an out-of-range t produces, and
        // is never what anyone drew.
        $deduped = [];
        foreach ( $keyframes as $frame ) {
            $last = count( $deduped ) - 1;
            if ( $last >= 0 && $deduped[ $last ]['t'] === $frame['t'] ) {
                $deduped[ $last ] = $frame;
                continue;
            }
            $deduped[] = $frame;
        }
        $keyframes = $deduped;

        return [
            'id'        => $id,
            'kind'      => $kind,
            'label'     => mb_substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 4 ),
            'side'      => ( (string) ( $raw['side'] ?? 'own' ) ) === 'opp' ? 'opp' : 'own',
            'keyframes' => $keyframes,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,true>  $ids
     * @return array<string,mixed>|null
     */
    private function normaliseLink( array $raw, array $ids, int $duration ): ?array {
        $from = sanitize_key( (string) ( $raw['from'] ?? '' ) );
        $to   = sanitize_key( (string) ( $raw['to'] ?? '' ) );

        // A link to an actor that is not in this scene is a line to
        // nowhere — most often the residue of deleting a player without
        // deleting the pass drawn to them.
        if ( $from === '' || $to === '' ) return null;
        if ( ! isset( $ids[ $from ] ) || ! isset( $ids[ $to ] ) ) return null;
        if ( $from === $to ) return null;

        $kind = (string) ( $raw['kind'] ?? 'pass' );
        if ( ! in_array( $kind, self::LINK_KINDS, true ) ) $kind = 'pass';

        return [
            'from' => $from,
            'to'   => $to,
            'kind' => $kind,
            't'    => max( 0, min( $duration, (int) ( $raw['t'] ?? 0 ) ) ),
        ];
    }

    private function clampCoord( $value ): float {
        $n = is_numeric( $value ) ? (float) $value : 50.0;

        return round( max( 0.0, min( 100.0, $n ) ), 2 );
    }

    private function cleanDuration( $raw ): int {
        $ms = (int) ( $raw ?? 6000 );
        if ( $ms <= 0 ) $ms = 6000;

        return max( self::MIN_DURATION_MS, min( self::MAX_DURATION_MS, $ms ) );
    }

    private function cleanPreset( $raw ): string {
        $preset = (string) ( $raw ?? 'full' );

        return in_array( $preset, self::PITCH_PRESETS, true ) ? $preset : 'full';
    }

    private function cleanName( $raw ): ?string {
        if ( ! is_string( $raw ) ) return null;

        $name = trim( sanitize_text_field( $raw ) );

        return $name === '' ? null : mb_substr( $name, 0, 190 );
    }

    private function nextSortOrder( int $exercise_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( MAX( sort_order ), -1 ) + 1 FROM {$this->table()}
              WHERE exercise_id = %d AND club_id = %d",
            $exercise_id,
            CurrentClub::id()
        ) );
    }
}
