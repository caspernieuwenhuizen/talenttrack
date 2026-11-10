<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\PdpFormAuthor;
use TT\Modules\Pdp\Services\PdpCycleState;

/**
 * PdpFormReader (#4977, epic #4975) — a PDP conversation form as one user
 * may read it.
 *
 * The REST route, the player's and the trainer's views, the parent's view
 * and the print all compose this reader, so the rules of `PdpFormAccess`
 * hold on every surface, including ones added later. A part the user may
 * not read is `null`, never an empty list, so "hidden" and "nothing entered
 * yet" cannot be confused.
 *
 * It is also the only reader of the player's scores (owner decision 9): the
 * evaluations, the analytics, Report Studio, the player report, the evidence
 * packet and the family reader never select them.
 */
final class PdpFormReader {

    private PdpFormEntriesRepository $entries;

    public function __construct( ?PdpFormEntriesRepository $entries = null ) {
        $this->entries = $entries ?? new PdpFormEntriesRepository();
    }

    /**
     * The pinned definition of a conversation, or null when it uses no form
     * yet (or one that is no longer shipped). Sign-off reads this one: by
     * then a write has pinned the form. Before the first write, what the
     * form will be is `PdpFormChoice::formFor()`.
     *
     * @return array<string,mixed>|null
     */
    public static function definitionFor( object $conversation ): ?array {
        $row      = (array) $conversation;
        $form_key = (string) ( $row['form_key'] ?? '' );
        if ( $form_key === '' ) return null;
        return PdpFormRegistry::definition( $form_key, (int) ( $row['form_version'] ?? 0 ) );
    }

    /**
     * @return array{
     *   conversation_id:int,
     *   form:array{key:string,version:int,definition:array<string,mixed>}|null,
     *   state:array<string,mixed>,
     *   player:array{scores:array<string,int|null>,answers:array<string,string>}|null,
     *   coach:array{scores:array<string,int|null>,answers:array<string,string>}|null,
     *   goals:list<array<string,mixed>>|null,
     *   differences:list<string>|null
     * }
     */
    public function read( PdpFormAccess $access, object $conversation, ?int $now_ts = null ): array {
        $row        = (array) $conversation;
        $id         = (int) ( $row['id'] ?? 0 );
        // #4978 — before the first write the form follows the position; it
        // is pinned on the conversation by that write.
        $choice     = PdpFormChoice::formFor( $conversation, $access->playerId() );
        $definition = $choice !== null ? PdpFormRegistry::definition( $choice['key'], $choice['version'] ) : null;
        $form_key   = $definition !== null ? (string) $definition['form_key'] : '';
        $prepared   = self::nullable( $row['player_prepared_at'] ?? null );
        $signed     = self::nullable( $row['coach_signoff_at'] ?? null ) !== null;

        // Decision 6: the trainer may switch until the player's first save;
        // "klaar" counts as one. Not on a talk that did not take place.
        $switch_to = null;
        if ( $definition !== null && ! $signed && $prepared === null && ! PdpCycleState::isNotHeld( $conversation )
            && $access->choosesForm() && ! $this->entries->hasEntries( $id, PdpFormAuthor::PLAYER ) ) {
            $switch_to = PdpFormChoice::switchTarget( $form_key );
        }

        $player = null;
        $coach  = null;
        $goals  = null;
        $changed_after = null;

        if ( $definition !== null && $access->readsPlayerPart() ) {
            $player = [
                'scores'  => $this->entries->scores( $id, $form_key, PdpFormAuthor::PLAYER ),
                'answers' => $this->entries->answers( $id, $form_key, PdpFormAuthor::PLAYER ),
            ];
            if ( $prepared !== null ) {
                $last          = $this->entries->lastChange( $id, $form_key, PdpFormAuthor::PLAYER );
                $changed_after = $last !== null && $last > $prepared;
            }
        }
        if ( $definition !== null && $access->readsCoachPart() ) {
            $coach = [
                'scores'  => $this->entries->scores( $id, $form_key, PdpFormAuthor::COACH ),
                'answers' => $this->entries->answers( $id, $form_key, PdpFormAuthor::COACH ),
            ];
            $goals = [];
            $saved = $this->entries->goals( $id );
            foreach ( PdpFormRegistry::goalSlots( $definition ) as $slot => $prompt_key ) {
                $g       = $saved[ $slot ] ?? null;
                $goals[] = [
                    'slot'       => $slot,
                    'prompt_key' => $prompt_key,
                    'action'     => $g !== null ? $g['action_text'] : '',
                    'support'    => $g !== null ? $g['support_text'] : '',
                    'due_date'   => $g !== null ? $g['due_date'] : null,
                    'item_key'   => $g !== null ? $g['item_key'] : null,
                    'goal_id'    => $g !== null ? $g['goal_id'] : null,
                ];
            }
        }

        return [
            'conversation_id' => $id,
            'form'            => $definition !== null
                ? [ 'key' => $form_key, 'version' => (int) $definition['version'], 'definition' => $definition ]
                : null,
            'state'           => [
                'form_key'                      => $form_key !== '' ? $form_key : null,
                'form_version'                  => $definition !== null ? (int) $definition['version'] : null,
                'form_pinned'                   => $choice !== null && $choice['pinned'],
                'form_switch_to'                => $switch_to,
                'player_prepared_at'            => $prepared,
                'player_changed_after_prepared' => $changed_after,
                'coach_signoff_at'              => self::nullable( $row['coach_signoff_at'] ?? null ),
                'player_locked'                 => PdpCycleState::playerPartLocked( $conversation, $now_ts ),
                // #4979 — the player's preparation window: open from 14 days
                // before the planned time, never without one.
                'preparation_open'              => PdpCycleState::preparationOpen( $conversation, $now_ts ),
                'preparation_opens_at'          => self::opensAt( $conversation ),
            ],
            'player'          => $player,
            'coach'           => $coach,
            'goals'           => $goals,
            'differences'     => ( $player !== null && $coach !== null ) ? self::differences( $player['scores'], $coach['scores'] ) : null,
        ];
    }

    /**
     * The items where both scores are set and differ (owner decision 7:
     * "Bespreken" at any difference).
     *
     * @param array<string,int|null> $player
     * @param array<string,int|null> $coach
     * @return list<string>
     */
    public static function differences( array $player, array $coach ): array {
        $out = [];
        foreach ( $player as $item => $score ) {
            if ( $score === null ) continue;
            $other = $coach[ $item ] ?? null;
            if ( $other !== null && $other !== $score ) $out[] = (string) $item;
        }
        return $out;
    }

    /** When the preparation opens (UTC, `Y-m-d H:i:s`), or null without a planned time. */
    private static function opensAt( object $conversation ): ?string {
        $ts = PdpCycleState::preparationOpensAt( $conversation );
        return $ts !== null ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
    }

    /** @param mixed $value */
    private static function nullable( $value ): ?string {
        $value = (string) ( $value ?? '' );
        return $value === '' ? null : $value;
    }
}
