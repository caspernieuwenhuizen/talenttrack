<?php
namespace TT\Modules\Pdp\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\PdpConversationTemplate;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\Pdp\Repositories\GoalLinksRepository;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Translations\TranslationLayer;

/**
 * PdpFamilyReader (#3645) — the development plan as a player and their
 * family may read it.
 *
 * ## Why this is not `pdp-files/{id}`
 *
 * `GET pdp-files/{id}` is a staff surface. It answers with the notes and
 * agreed actions of every conversation whether or not the coach has signed
 * the talk off, and `PdpAccess::canSeeFile()` — the gate it shares with the
 * coach manage view and the verdict routes — has no family branch by
 * design. Widening that gate would hand a parent the coach's view of the
 * file rather than the family's.
 *
 * So the family gets its own read model: what My PDP puts on the screen,
 * and nothing else. A parent could already acknowledge a talk through
 * `PATCH pdp-conversations/{id}` but had no route on which to find the
 * conversation, which is the hole this closes.
 *
 * ## What it deliberately leaves out
 *
 * - The coach's **preparation** (epic #3301, decision 4). It is where a
 *   coach writes candidly about a minor before sitting down with them.
 * - `notes` and `agreed_actions` **before sign-off**: both come back null
 *   until `coach_signoff_at` is set, the same rule the rendered view
 *   applies. A half-written note is not yet what was agreed.
 *
 * The rendered My PDP view composes this reader, so the screen and the API
 * cannot drift (CLAUDE.md §4 — the view decides nothing).
 */
final class PdpFamilyReader {

    /**
     * The development picture for one player's current season.
     *
     * `season` is null when the install has no current season; `file` is
     * null when no plan has been opened for the player this season. Both
     * are explicit rather than an error — "nothing has started yet" is a
     * real answer, and a caller that treats it as a failure shows a family
     * a broken screen instead of "not yet".
     *
     * @return array{
     *   player_id:int,
     *   season:array{id:int,name:string}|null,
     *   file:array{id:int,status:string,status_localised:string}|null,
     *   conversations:list<array<string,mixed>>,
     *   next_conversation_id:int,
     *   goals:list<array<string,mixed>>,
     *   verdict:array<string,mixed>|null
     * }
     */
    public function forPlayer( int $player_id ): array {
        $out = [
            'player_id'            => $player_id,
            'season'               => null,
            'file'                 => null,
            'conversations'        => [],
            'next_conversation_id' => 0,
            'goals'                => $this->goals( $player_id ),
            'verdict'              => null,
        ];

        if ( $player_id <= 0 ) return $out;

        $season = ( new SeasonsRepository() )->current();
        if ( ! $season ) return $out;

        $season_row = (array) $season;
        $season_id  = (int) ( $season_row['id'] ?? 0 );

        $out['season'] = [
            'id'   => $season_id,
            'name' => (string) ( $season_row['name'] ?? '' ),
        ];

        $file = ( new PdpFilesRepository() )->findByPlayerSeason( $player_id, $season_id );
        if ( ! $file ) return $out;

        $file_row = (array) $file;
        $file_id  = (int) ( $file_row['id'] ?? 0 );

        $out['file'] = [
            'id'               => $file_id,
            'status'           => (string) ( $file_row['status'] ?? '' ),
            'status_localised' => (string) ( $file_row['status_localised'] ?? '' ),
        ];

        $convs   = ( new PdpConversationsRepository() )->listForFile( $file_id );
        $next    = PdpCycleState::nextPlanned( $convs );
        $next_id = $next === null ? 0 : (int) ( ( (array) $next )['id'] ?? 0 );

        $out['conversations']        = $this->conversations( $convs, $next_id, $player_id );
        $out['next_conversation_id'] = $next_id;

        $verdict = ( new PdpVerdictsRepository() )->findForFile( $file_id );
        if ( $verdict !== null ) {
            $verdict_row    = (array) $verdict;
            $out['verdict'] = [
                'decision'           => (string) ( $verdict_row['decision'] ?? '' ),
                'decision_localised' => (string) ( $verdict_row['decision_localised'] ?? '' ),
                'summary'            => (string) ( $verdict_row['summary'] ?? '' ),
                'signed_off_at'      => self::nullableString( $verdict_row['signed_off_at'] ?? null ),
            ];
        }

        return $out;
    }

    /**
     * One entry per conversation, oldest first.
     *
     * @param array<int,object> $convs
     * @return list<array<string,mixed>>
     */
    private function conversations( array $convs, int $next_id, int $player_id ): array {
        $out = [];
        foreach ( $convs as $conv ) {
            $row    = (array) $conv;
            $id     = (int) ( $row['id'] ?? 0 );
            $key    = (string) ( $row['template_key'] ?? '' );
            $signed = self::nullableString( $row['coach_signoff_at'] ?? null );

            $out[] = [
                'id'                     => $id,
                'sequence'               => (int) ( $row['sequence'] ?? 0 ),
                'template_key'           => $key,
                'template_label'         => PdpConversationTemplate::label( $key ),
                'state'                  => self::stateOf( $row, $next_id ),
                'scheduled_at'           => self::nullableString( $row['scheduled_at'] ?? null ),
                'conducted_at'           => self::nullableString( $row['conducted_at'] ?? null ),
                'coach_signoff_at'       => $signed,
                'player_ack_at'          => self::nullableString( $row['player_ack_at'] ?? null ),
                'parent_ack_at'          => self::nullableString( $row['parent_ack_at'] ?? null ),
                'player_reflection'      => self::nullableString( $row['player_reflection'] ?? null ),
                'reflection_window_open' => $id > 0 && $id === $next_id
                    ? PdpCycleState::reflectionWindowOpen( $conv )
                    : false,
                'updated_at'             => self::nullableString( $row['updated_at'] ?? null ),
                // Only after the coach signs the talk off. Before that the
                // family sees the date and nothing else.
                'notes'                  => $signed === null ? null : self::nullableString( $row['notes'] ?? null ),
                'agreed_actions'         => $signed === null ? null : self::nullableString( $row['agreed_actions'] ?? null ),
                'goals_discussed'        => $this->goalTitles( $id, $player_id ),
            ];
        }
        return $out;
    }

    /**
     * Marker state on the season rail: a completed talk, one whose planned
     * date has passed without it being held, the single next planned one,
     * or one still ahead of it.
     *
     * #3692 — `overdue` is checked before `next` and `future`, so a talk
     * left behind reads as overdue whether or not it happens to be the next
     * one in the cycle. Before this every unheld talk read "Planned",
     * however long the date had passed, which told the family nothing about
     * whether the talk had been held, moved or forgotten.
     *
     * @param array<string,mixed> $row a conversation row as an array.
     * @param int|null $now_ts Unix timestamp for "now" (UTC), for tests.
     */
    public static function stateOf( array $row, int $next_id, ?int $now_ts = null ): string {
        if ( ! empty( $row['conducted_at'] ) || ! empty( $row['coach_signoff_at'] ) ) return 'done';
        if ( PdpCycleState::isOverdue( (object) $row, $now_ts ) ) return 'overdue';
        if ( $next_id > 0 && (int) ( $row['id'] ?? 0 ) === $next_id ) return 'next';
        return 'future';
    }

    /**
     * The titles of the goals a talk covered, in the academy's language.
     *
     * @return list<string>
     */
    private function goalTitles( int $conversation_id, int $player_id ): array {
        if ( $conversation_id <= 0 || $player_id <= 0 ) return [];

        $goal_ids = ( new GoalLinksRepository() )->goalsForConversation( $conversation_id );
        if ( empty( $goal_ids ) ) return [];

        $goals  = new GoalsRepository();
        $titles = [];
        foreach ( $goal_ids as $goal_id ) {
            // Scoped to the player, so a mis-linked row cannot pull another
            // player's goal into this family's plan.
            $goal = $goals->findForPlayer( (int) $goal_id, $player_id );
            if ( $goal === null ) continue;
            $title = TranslationLayer::render( (string) ( ( (array) $goal )['title'] ?? '' ) );
            if ( $title !== '' ) $titles[] = $title;
        }
        return $titles;
    }

    /**
     * The player's top active goals — what they are working on now.
     *
     * @return list<array<string,mixed>>
     */
    private function goals( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        $out = [];
        foreach ( ( new GoalsRepository() )->topActiveForPlayer( $player_id, 3 ) as $goal ) {
            $row   = (array) $goal;
            $out[] = [
                'id'               => (int) ( $row['id'] ?? 0 ),
                'title'            => TranslationLayer::render( (string) ( $row['title'] ?? '' ) ),
                'status'           => (string) ( $row['status'] ?? '' ),
                'status_localised' => (string) ( $row['status_localised'] ?? '' ),
                'due_date'         => self::nullableString( $row['due_date'] ?? null ),
            ];
        }
        return $out;
    }

    /**
     * A stored column as a string, or null when it holds nothing. Keeps
     * "never written" distinguishable from "written empty" for a consumer
     * that is not this plugin's own view.
     *
     * @param mixed $value
     */
    private static function nullableString( $value ): ?string {
        if ( $value === null ) return null;
        $value = (string) $value;
        return $value === '' ? null : $value;
    }
}
