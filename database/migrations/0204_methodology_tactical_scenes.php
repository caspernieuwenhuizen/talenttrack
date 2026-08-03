<?php
/**
 * Migration 0204 — Per-phase tactical scenes (#2323, epic #2316, final child).
 *
 * A tactical scene is a game-phase snapshot with animated player + ball
 * movement and coaching points, scoped to a methodology set and one of
 * its phases. It reuses the normalized 0–100 coordinate convention that
 * `FormationDiagram` already renders (0,0 = top-left, y = 100 is the goal
 * we defend), so the scene renderer shares the pitch geometry.
 *
 * This migration:
 *
 *   - Creates `tt_methodology_tactical_scenes` — the scene entity, with a
 *     `scene_json` keyframe animation payload alongside multilingual
 *     title + coaching-point description, soft-linked to a phase via
 *     `(phase_side, phase_number)` under a methodology set.
 *   - Seeds a representative set of shipped JO13-1 Hedel scenes (one per
 *     distinct phase), scoped to the `jo13-1-hedel` set from 0202.
 *
 * `scene_json` shape (full animation — keyframe model, 0–100 coords,
 * `t` is 0..1 normalized time interpolated linearly across duration_ms):
 *
 *   {
 *     "duration_ms": 5000,
 *     "players":   [ { "slot": 9, "label": "9", "team": "own",
 *                      "keyframes": [ {"t":0,"x":50,"y":55}, {"t":1,"x":55,"y":35} ] } ],
 *     "opponents": [ { "label": "", "team": "opp",
 *                      "keyframes": [ {"t":0,"x":50,"y":20} ] } ],
 *     "ball":      { "keyframes": [ {"t":0,"x":50,"y":55}, {"t":0.6,"x":55,"y":35} ] },
 *     "arrows":    [ { "kind":"run|pass|press|dribble",
 *                      "from":{"x":50,"y":55}, "to":{"x":55,"y":35} } ]
 *   }
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, and every scene is upserted by
 * its natural key (methodology_id + slug-ish sort_order) scoped to the
 * set, so a re-run corrects rather than duplicates.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const CLUB_ID = 1;

    public function getName(): string {
        return '0204_methodology_tactical_scenes';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $this->exec( "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_tactical_scenes (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id          INT UNSIGNED    NOT NULL DEFAULT 1,
            uuid             CHAR(36)        DEFAULT NULL,
            methodology_id   BIGINT UNSIGNED DEFAULT NULL,
            phase_side       VARCHAR(16)     DEFAULT NULL,
            phase_number     TINYINT UNSIGNED DEFAULT NULL,
            formation_id     BIGINT UNSIGNED DEFAULT NULL,
            title_json       TEXT            DEFAULT NULL,
            description_json LONGTEXT        DEFAULT NULL,
            scene_json       LONGTEXT        DEFAULT NULL,
            sort_order       INT             NOT NULL DEFAULT 0,
            is_shipped       TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at      DATETIME        DEFAULT NULL,
            created_at       DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_club_methodology (club_id, methodology_id),
            KEY idx_methodology_phase (methodology_id, phase_side, phase_number)
        ) {$charset};" );

        $set_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodologies WHERE club_id = %d AND slug = %s LIMIT 1",
            self::CLUB_ID, 'jo13-1-hedel'
        ) );
        if ( $set_id <= 0 ) return;

        $formation_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formations WHERE slug = %s LIMIT 1", '1-4-3-3-jo13'
        ) );

        foreach ( $this->scenesData() as $row ) {
            $this->upsertScene( $p, $set_id, $formation_id > 0 ? $formation_id : null, $row );
        }
    }

    private function upsertScene( string $p, int $set_id, ?int $formation_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_tactical_scenes
             WHERE methodology_id = %d AND is_shipped = 1 AND sort_order = %d LIMIT 1",
            $set_id, (int) $row['sort_order']
        ) );
        $payload = [
            'methodology_id'   => $set_id,
            'club_id'          => self::CLUB_ID,
            'formation_id'     => $formation_id,
            'phase_side'       => $row['phase_side'],
            'phase_number'     => (int) $row['phase_number'],
            'title_json'       => wp_json_encode( $row['title'] ),
            'description_json' => wp_json_encode( $row['description'] ),
            'scene_json'       => wp_json_encode( $row['scene'] ),
            'sort_order'       => (int) $row['sort_order'],
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_methodology_tactical_scenes", $payload, [ 'id' => $existing ] );
        } else {
            $payload['uuid']       = wp_generate_uuid4();
            $payload['is_shipped'] = 1;
            $wpdb->insert( "{$p}tt_methodology_tactical_scenes", $payload );
        }
    }

    /* ─────────────────────── Scene data ───────────────────────
     *
     * Coordinate convention (mirrors FormationDiagram): 0–100, 0,0 top-
     * left, the goal we defend at y ≈ 100 (bottom), the opponent goal at
     * y ≈ 0 (top). Own base positions echo the 1-4-3-3 slot coordinates.
     * Defending scenes sit compact in our half (high y); attacking scenes
     * push high up the pitch (low y).
     */

    /** @return array<int, array<string,mixed>> */
    private function scenesData(): array {
        return [
            $this->sceneDefendPress(),
            $this->sceneDefendOwnHalf(),
            $this->sceneTransitionToAttack(),
            $this->sceneBuildUp(),
            $this->sceneFinalPhase(),
        ];
    }

    /* Verdedigen 1 — storen van de opbouw (defending phase 1) */
    private function sceneDefendPress(): array {
        return [
            'sort_order'   => 1,
            'phase_side'   => 'defending',
            'phase_number' => 1,
            'title' => [
                'nl' => 'Verdedigen 1 — storen van de opbouw',
                'en' => 'Defending 1 — disrupting the build-up',
            ],
            'description' => [
                'nl' => "De spits stoort de opbouw en dwingt naar de flank (de langste weg naar doel). De nr 10 staat naast de nr 9 en schermt de passlijn door de as af. As dicht, tegenstander via de flank vastzetten.",
                'en' => "The striker disrupts the build-up and forces it wide (the longest route to goal). The 10 sits beside the 9 and screens the central lane. Axis closed, lock the opponent in on the flank.",
            ],
            'scene' => [
                'duration_ms' => 5000,
                'players' => [
                    // The 9 presses the ball-side centre-back and steers wide.
                    [ 'slot' => 9,  'label' => '9',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 30 ], [ 't' => 1, 'x' => 63, 'y' => 26 ] ] ],
                    // The 10 slides across to screen the central lane.
                    [ 'slot' => 10, 'label' => '10', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 45 ], [ 't' => 1, 'x' => 44, 'y' => 40 ] ] ],
                    [ 'slot' => 7,  'label' => '7',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 78, 'y' => 40 ], [ 't' => 1, 'x' => 72, 'y' => 34 ] ] ],
                    [ 'slot' => 11, 'label' => '11', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 22, 'y' => 40 ] ] ],
                    [ 'slot' => 8,  'label' => '8',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 40, 'y' => 58 ] ] ],
                    [ 'slot' => 6,  'label' => '6',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 55, 'y' => 62 ] ] ],
                ],
                'opponents' => [
                    // Opponent centre-back on the ball, forced wide.
                    [ 'label' => '', 'team' => 'opp',
                      'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 18 ], [ 't' => 1, 'x' => 66, 'y' => 20 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 30, 'y' => 18 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 8 ] ] ],
                ],
                'ball' => [
                    'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 18 ], [ 't' => 1, 'x' => 66, 'y' => 20 ] ],
                ],
                'arrows' => [
                    [ 'kind' => 'press', 'from' => [ 'x' => 50, 'y' => 30 ], 'to' => [ 'x' => 63, 'y' => 26 ] ],
                    [ 'kind' => 'press', 'from' => [ 'x' => 50, 'y' => 45 ], 'to' => [ 'x' => 44, 'y' => 40 ] ],
                ],
            ],
        ];
    }

    /* Verdedigen 3 — eigen helft (defending phase 3) */
    private function sceneDefendOwnHalf(): array {
        return [
            'sort_order'   => 2,
            'phase_side'   => 'defending',
            'phase_number' => 3,
            'title' => [
                'nl' => 'Verdedigen 3 — eigen helft, overtal rond de bal',
                'en' => 'Defending 3 — our own half, overload around the ball',
            ],
            'description' => [
                'nl' => "Compact op eigen helft. De rechtsback en de nr 8 kantelen mee naar de balkant zodat we rond de bal overtal maken. Balgericht verdedigen zonder de zone prijs te geven — geen balwatchers.",
                'en' => "Compact in our own half. The right-back and the 8 tilt across to the ball side so we overload around the ball. Defend ball-oriented without giving up the zone — no ball-watchers.",
            ],
            'scene' => [
                'duration_ms' => 4500,
                'players' => [
                    // Right-back steps to the ball to make the overload.
                    [ 'slot' => 2, 'label' => '2', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 80, 'y' => 74 ], [ 't' => 1, 'x' => 72, 'y' => 66 ] ] ],
                    // The 8 tilts across to the ball side.
                    [ 'slot' => 8, 'label' => '8', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 45, 'y' => 62 ], [ 't' => 1, 'x' => 60, 'y' => 60 ] ] ],
                    [ 'slot' => 3, 'label' => '3', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 58, 'y' => 82 ] ] ],
                    [ 'slot' => 4, 'label' => '4', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 42, 'y' => 82 ] ] ],
                    [ 'slot' => 5, 'label' => '5', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 20, 'y' => 74 ] ] ],
                    [ 'slot' => 6, 'label' => '6', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 66 ] ] ],
                ],
                'opponents' => [
                    [ 'label' => '', 'team' => 'opp',
                      'keyframes' => [ [ 't' => 0, 'x' => 82, 'y' => 60 ], [ 't' => 1, 'x' => 78, 'y' => 62 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 66, 'y' => 54 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 50 ] ] ],
                ],
                'ball' => [
                    'keyframes' => [ [ 't' => 0, 'x' => 82, 'y' => 60 ], [ 't' => 1, 'x' => 78, 'y' => 62 ] ],
                ],
                'arrows' => [
                    [ 'kind' => 'press', 'from' => [ 'x' => 80, 'y' => 74 ], 'to' => [ 'x' => 72, 'y' => 66 ] ],
                    [ 'kind' => 'press', 'from' => [ 'x' => 45, 'y' => 62 ], 'to' => [ 'x' => 60, 'y' => 60 ] ],
                ],
            ],
        ];
    }

    /* Omschakelen V→A — bal uit de drukte, veld groot maken (transition phase 2) */
    private function sceneTransitionToAttack(): array {
        return [
            'sort_order'   => 3,
            'phase_side'   => 'transition',
            'phase_number' => 2,
            'title' => [
                'nl' => 'Omschakelen V→A — bal veilig, veld groot maken',
                'en' => 'Transition D→A — secure the ball, make the field big',
            ],
            'description' => [
                'nl' => "Na balwinst is de eerste bal balbezit: de nr 6 haalt de bal uit de drukte (diepte of terug, geen breedte). Direct daarna maken we het speelveld groot — de buitenspelers houden breedte en de spits maakt een diepteloopactie.",
                'en' => "After winning it, the first ball is possession: the 6 takes it out of pressure (depth or back, not width). Immediately after, we make the field big — the wingers hold width and the striker runs in behind.",
            ],
            'scene' => [
                'duration_ms' => 5500,
                'players' => [
                    // The 6 wins it and carries out of pressure.
                    [ 'slot' => 6,  'label' => '6',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 62 ], [ 't' => 0.4, 'x' => 46, 'y' => 56 ] ] ],
                    // The 9 makes the run in behind.
                    [ 'slot' => 9,  'label' => '9',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 34 ], [ 't' => 1, 'x' => 54, 'y' => 16 ] ] ],
                    // The 7 stretches wide-right to hold width.
                    [ 'slot' => 7,  'label' => '7',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 74, 'y' => 40 ], [ 't' => 1, 'x' => 84, 'y' => 30 ] ] ],
                    [ 'slot' => 11, 'label' => '11', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 26, 'y' => 40 ], [ 't' => 1, 'x' => 16, 'y' => 30 ] ] ],
                    [ 'slot' => 8,  'label' => '8',  'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 40, 'y' => 50 ] ] ],
                ],
                'opponents' => [
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 52, 'y' => 58 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 48, 'y' => 22 ] ] ],
                ],
                'ball' => [
                    // Ball is won at the 6, carried back, then played into the run.
                    'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 62 ], [ 't' => 0.4, 'x' => 46, 'y' => 56 ], [ 't' => 1, 'x' => 54, 'y' => 18 ] ],
                ],
                'arrows' => [
                    [ 'kind' => 'dribble', 'from' => [ 'x' => 50, 'y' => 62 ], 'to' => [ 'x' => 46, 'y' => 56 ] ],
                    [ 'kind' => 'pass',    'from' => [ 'x' => 46, 'y' => 56 ], 'to' => [ 'x' => 54, 'y' => 18 ] ],
                    [ 'kind' => 'run',     'from' => [ 'x' => 50, 'y' => 34 ], 'to' => [ 'x' => 54, 'y' => 16 ] ],
                ],
            ],
        ];
    }

    /* Aanvallen — opbouw eigen helft (attacking phase 1) */
    private function sceneBuildUp(): array {
        return [
            'sort_order'   => 4,
            'phase_side'   => 'attacking',
            'phase_number' => 1,
            'title' => [
                'nl' => 'Aanvallen — opbouw eigen helft, derde man',
                'en' => 'Attacking — build-up in our half, the third man',
            ],
            'description' => [
                'nl' => "Verzorgd uitvoetballen: de centrale verdediger speelt de nr 6, die kaatst op de nr 8. De nr 8 komt als derde man vrij met het gezicht naar voren. Schuine ballen vóór breedteballen; breedte alleen als het 100% veilig is.",
                'en' => "Considered build-up: the centre-back plays the 6, who lays it off to the 8. The 8 arrives free as the third man facing forward. Slanted passes before square passes; width only when 100% safe.",
            ],
            'scene' => [
                'duration_ms' => 5500,
                'players' => [
                    [ 'slot' => 3, 'label' => '3', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 40, 'y' => 78 ] ] ],
                    // The 6 shows to receive, then lays off.
                    [ 'slot' => 6, 'label' => '6', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 52, 'y' => 62 ], [ 't' => 0.5, 'x' => 50, 'y' => 60 ] ] ],
                    // The 8 breaks forward as the third man.
                    [ 'slot' => 8, 'label' => '8', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 42, 'y' => 52 ], [ 't' => 1, 'x' => 48, 'y' => 36 ] ] ],
                    [ 'slot' => 2, 'label' => '2', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 82, 'y' => 62 ] ] ],
                    [ 'slot' => 9, 'label' => '9', 'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 26 ] ] ],
                ],
                'opponents' => [
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 48, 'y' => 48 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 40, 'y' => 40 ] ] ],
                ],
                'ball' => [
                    // CB → 6 → (lay-off) → 8 as the third man.
                    'keyframes' => [ [ 't' => 0, 'x' => 40, 'y' => 78 ], [ 't' => 0.4, 'x' => 51, 'y' => 61 ], [ 't' => 0.7, 'x' => 44, 'y' => 54 ], [ 't' => 1, 'x' => 48, 'y' => 38 ] ],
                ],
                'arrows' => [
                    [ 'kind' => 'pass', 'from' => [ 'x' => 40, 'y' => 78 ], 'to' => [ 'x' => 51, 'y' => 61 ] ],
                    [ 'kind' => 'pass', 'from' => [ 'x' => 51, 'y' => 61 ], 'to' => [ 'x' => 48, 'y' => 38 ] ],
                    [ 'kind' => 'run',  'from' => [ 'x' => 42, 'y' => 52 ], 'to' => [ 'x' => 48, 'y' => 36 ] ],
                ],
            ],
        ];
    }

    /* Aanvallen — eindfase en scoren (attacking phase 2) */
    private function sceneFinalPhase(): array {
        return [
            'sort_order'   => 5,
            'phase_side'   => 'attacking',
            'phase_number' => 2,
            'title' => [
                'nl' => 'Aanvallen — eindfase, voorzet en scoren',
                'en' => 'Attacking — final phase, cross and score',
            ],
            'description' => [
                'nl' => "De rechtsbuiten zoekt de 1:1-actie en komt tot een voorzet. De spits bezet de eerste paal met een diepteloopactie, de nr 10 sluit aan op de tweede paal. Laag in de hoek afronden met de binnenkant.",
                'en' => "The right winger takes the 1v1 and delivers a cross. The striker attacks the near post with a run in behind, the 10 arrives at the far post. Finish low in the corner with the inside foot.",
            ],
            'scene' => [
                'duration_ms' => 5000,
                'players' => [
                    // Right winger beats his man and crosses.
                    [ 'slot' => 7,  'label' => '7',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 80, 'y' => 28 ], [ 't' => 0.5, 'x' => 84, 'y' => 16 ] ] ],
                    // Striker attacks the near post.
                    [ 'slot' => 9,  'label' => '9',  'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 52, 'y' => 22 ], [ 't' => 1, 'x' => 40, 'y' => 10 ] ] ],
                    // The 10 arrives at the far post.
                    [ 'slot' => 10, 'label' => '10', 'team' => 'own',
                      'keyframes' => [ [ 't' => 0, 'x' => 44, 'y' => 30 ], [ 't' => 1, 'x' => 60, 'y' => 12 ] ] ],
                    [ 'slot' => 8,  'label' => '8',  'team' => 'own', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 40 ] ] ],
                ],
                'opponents' => [
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 78, 'y' => 22 ], [ 't' => 0.5, 'x' => 80, 'y' => 18 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 46, 'y' => 12 ] ] ],
                    [ 'label' => '', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 56, 'y' => 12 ] ] ],
                    // Keeper.
                    [ 'label' => 'K', 'team' => 'opp', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 4 ] ] ],
                ],
                'ball' => [
                    // Carry to the byline, cross to the near post, finish.
                    'keyframes' => [ [ 't' => 0, 'x' => 80, 'y' => 28 ], [ 't' => 0.5, 'x' => 84, 'y' => 16 ], [ 't' => 0.85, 'x' => 42, 'y' => 11 ], [ 't' => 1, 'x' => 50, 'y' => 4 ] ],
                ],
                'arrows' => [
                    [ 'kind' => 'dribble', 'from' => [ 'x' => 80, 'y' => 28 ], 'to' => [ 'x' => 84, 'y' => 16 ] ],
                    [ 'kind' => 'pass',    'from' => [ 'x' => 84, 'y' => 16 ], 'to' => [ 'x' => 42, 'y' => 11 ] ],
                    [ 'kind' => 'run',     'from' => [ 'x' => 52, 'y' => 22 ], 'to' => [ 'x' => 40, 'y' => 10 ] ],
                ],
            ],
        ];
    }
};
