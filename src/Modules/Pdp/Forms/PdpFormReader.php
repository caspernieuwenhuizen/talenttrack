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
        $changed_items = null;
        $missing       = null;

        if ( $definition !== null && $access->readsPlayerPart() ) {
            $player = [
                'scores'  => $this->entries->scores( $id, $form_key, PdpFormAuthor::PLAYER ),
                'answers' => $this->entries->answers( $id, $form_key, PdpFormAuthor::PLAYER ),
            ];
            if ( $prepared !== null ) {
                $last          = $this->entries->lastChange( $id, $form_key, PdpFormAuthor::PLAYER );
                $changed_after = $last !== null && $last > $prepared;
                // #4980 — which scores moved after "klaar", for the trainer's
                // "aangepast" marker.
                $changed_items = $this->entries->scoresChangedAfter( $id, $form_key, PdpFormAuthor::PLAYER, $prepared );
            }
        }
        if ( $definition !== null && $access->readsCoachPart() ) {
            $coach = [
                'scores'  => $this->entries->scores( $id, $form_key, PdpFormAuthor::COACH ),
                'answers' => $this->entries->answers( $id, $form_key, PdpFormAuthor::COACH ),
            ];
            // #4980 — what sign-off still needs, with a label each, so the
            // screen can name it and keep its button closed until it is all
            // there (decision 4). The rule is sign-off's own.
            $missing = self::missingWithLabels( $definition, PdpFormSignoff::missing( $definition, $coach['scores'], $coach['answers'], $this->entries->goals( $id ) ) );

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
                'player_changed_items'          => $changed_items,
                'missing'                       => $missing,
                // #4980 — whether this reader can write the trainer's part
                // and sign off now; null for a reader of neither.
                'coach_writable'                => $access->readsCoachPart() ? $access->coachWritable() : null,
                'signs_off'                     => $access->readsCoachPart() ? $access->signsOffNow() : null,
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
     * #5082 — the talk as the room display shows it: an allowlist, built
     * here so the view, the REST route and any other room screen show the
     * same thing. Everything not named below is left out of the answer, not
     * hidden later. Who may ask is the caller's rule
     * (`canRead() && readsCoachPart()`); this method projects what that
     * reader reads.
     *
     * In: the player (name, photo, team, shirt number, position), the talk,
     * the club's name and logo, the form's sections and items, per section
     * both authors' averages and the number to discuss, per item both scores
     * and "Bespreken", the shared fields (both texts) and the development
     * points.
     *
     * Never in: the background (`EvidencePacket`), the trainer's section
     * notes ("Toelichting", owner decision on #5082), any other text field
     * of either author, what sign-off still needs, and sign-off itself.
     *
     * @return array<string,mixed>
     */
    public function forDisplay( PdpFormAccess $access, object $conversation, ?int $now_ts = null ): array {
        $form       = $this->read( $access, $conversation, $now_ts );
        $row        = (array) $conversation;
        $definition = $form['form'] !== null ? (array) $form['form']['definition'] : null;
        $player     = is_array( $form['player'] ) ? $form['player'] : null;
        $coach      = is_array( $form['coach'] ) ? $form['coach'] : null;
        $diffs      = is_array( $form['differences'] ) ? $form['differences'] : [];

        $sections = [];
        $scores   = [];
        $shared   = [];
        $goals    = [];
        $scale    = [];
        if ( $definition !== null ) {
            for ( $i = 1; $i <= 5; $i++ ) {
                $scale[] = [ 'value' => $i, 'label' => (string) ( $definition['scale'][ $i ]['label'] ?? (string) $i ) ];
            }

            $player_summary = $player !== null ? PdpFormSummary::of( $definition, $player['scores'], [], PdpFormAuthor::PLAYER ) : null;
            $coach_summary  = $coach !== null ? PdpFormSummary::of( $definition, $coach['scores'], [], PdpFormAuthor::COACH ) : null;
            foreach ( array_values( (array) $definition['sections'] ) as $n => $section ) {
                $items = [];
                $keys  = [];
                foreach ( (array) $section['items'] as $item ) {
                    $key    = (string) $item['key'];
                    $keys[] = $key;
                    $items[] = [ 'key' => $key, 'label' => (string) $item['label'], 'definition' => (string) $item['definition'] ];
                    $scores[ $key ] = [
                        'player'  => $player !== null ? ( $player['scores'][ $key ] ?? null ) : null,
                        'coach'   => $coach !== null ? ( $coach['scores'][ $key ] ?? null ) : null,
                        'discuss' => in_array( $key, $diffs, true ),
                    ];
                }
                $sections[] = [
                    'key'            => (string) $section['key'],
                    'title'          => (string) $section['title'],
                    'items'          => $items,
                    'to_discuss'     => count( array_intersect( $keys, $diffs ) ),
                    'player_average' => $player_summary !== null ? $player_summary['sections'][ $n ]['average'] : null,
                    'coach_average'  => $coach_summary !== null ? $coach_summary['sections'][ $n ]['average'] : null,
                ];
            }

            $prompts = [];
            foreach ( (array) $definition['prompts'] as $prompt ) $prompts[ (string) $prompt['key'] ] = $prompt;
            foreach ( PdpFormRegistry::sharedFields( $definition ) as $field ) {
                $shared[] = [
                    'key'    => $field,
                    'label'  => (string) ( $prompts[ $field ]['label'] ?? $field ),
                    'player' => $player !== null ? (string) ( $player['answers'][ $field ] ?? '' ) : null,
                    'agreed' => $coach !== null ? (string) ( $coach['answers'][ $field ] ?? '' ) : null,
                ];
            }

            $labels = [];
            foreach ( PdpFormRegistry::items( $definition ) as $item ) $labels[ $item['key'] ] = $item['label'];
            foreach ( (array) ( $form['goals'] ?? [] ) as $goal ) {
                $goals[] = [
                    'slot'       => (int) $goal['slot'],
                    'label'      => (string) ( $prompts[ (string) $goal['prompt_key'] ]['label'] ?? $goal['prompt_key'] ),
                    'action'     => (string) $goal['action'],
                    'support'    => (string) $goal['support'],
                    'due_date'   => $goal['due_date'],
                    'item_label' => $goal['item_key'] !== null ? (string) ( $labels[ (string) $goal['item_key'] ] ?? '' ) : null,
                ];
            }
        }

        // Who and which talk: the same identity the talk's page shows.
        $file_id  = (int) ( $row['pdp_file_id'] ?? 0 );
        $file     = ( new \TT\Modules\Pdp\Repositories\PdpFilesRepository() )->find( $file_id );
        $identity = \TT\Modules\Pdp\Services\PdpTalkIdentity::of(
            $file ?? (object) [ 'id' => $file_id, 'player_id' => $access->playerId() ],
            $conversation
        );

        return [
            'conversation_id' => $form['conversation_id'],
            'file_id'         => $file_id,
            'club'            => [
                'name'     => (string) \TT\Infrastructure\Query\QueryHelpers::get_config( 'academy_name', 'TalentTrack' ),
                'logo_url' => \TT\Infrastructure\Query\QueryHelpers::get_config( 'show_logo', '0' ) === '1'
                    ? (string) \TT\Infrastructure\Query\QueryHelpers::get_config( 'logo_url', '' )
                    : '',
            ],
            'player'          => [
                'id'           => $identity['player_id'],
                'name'         => $identity['name'],
                'photo_url'    => $identity['photo_url'],
                'team'         => $identity['team'],
                'shirt_number' => $identity['shirt_number'],
                'position'     => $identity['position'],
            ],
            'talk'            => [
                'sequence'     => $identity['sequence'],
                'total'        => $identity['total'],
                'template'     => $identity['template'],
                'scheduled_at' => $identity['scheduled_at'],
                'conducted_at' => $identity['conducted_at'],
            ],
            'form'            => $definition !== null ? [
                'key'            => (string) $definition['form_key'],
                'version'        => (int) $definition['version'],
                'position_group' => (string) $definition['position_group'],
                'scale'          => $scale,
                'score_columns'  => [
                    'player' => (string) ( $definition['score_columns']['player'] ?? '' ),
                    'coach'  => (string) ( $definition['score_columns']['coach'] ?? '' ),
                ],
            ] : null,
            'player_ready'    => $player !== null,
            'sections'        => $sections,
            'scores'          => $scores,
            'shared'          => $shared,
            'goals'           => $goals,
            'state'           => [
                'coach_writable' => ( $form['state']['coach_writable'] ?? false ) === true,
            ],
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

    /**
     * #4980 — sign-off's missing field paths, each with what a trainer reads:
     * the item's label, the prompt's label, or "Ontwikkelpunt 1: deadline".
     * An item without a label reads as its section's title; anything else
     * without one as "a required field". A trainer never reads a stored path.
     *
     * @param array<string,mixed> $definition
     * @param list<string>        $fields
     * @return list<array{field:string, label:string}>
     */
    private static function missingWithLabels( array $definition, array $fields ): array {
        $items = [];
        foreach ( (array) $definition['sections'] as $section ) {
            foreach ( (array) ( $section['items'] ?? [] ) as $item ) {
                $label = trim( (string) ( $item['label'] ?? '' ) );
                $items[ (string) $item['key'] ] = $label !== '' ? $label : trim( (string) ( $section['title'] ?? '' ) );
            }
        }
        $prompts = [];
        foreach ( (array) $definition['prompts'] as $prompt ) $prompts[ (string) $prompt['key'] ] = trim( (string) $prompt['label'] );
        $slots   = PdpFormRegistry::goalSlots( $definition );
        $generic = __( 'a required field', 'talenttrack' );
        $parts   = [
            'action'   => __( 'what the player does', 'talenttrack' ),
            'support'  => __( 'how the trainer helps', 'talenttrack' ),
            'due_date' => __( 'deadline', 'talenttrack' ),
        ];

        $out = [];
        foreach ( $fields as $field ) {
            $bits  = explode( '.', $field, 3 );
            $label = '';
            if ( $bits[0] === 'scores' ) {
                $label = $items[ $bits[1] ?? '' ] ?? '';
            } elseif ( $bits[0] === 'answers' ) {
                $label = $prompts[ substr( $field, 8 ) ] ?? '';
            } elseif ( $bits[0] === 'goals' ) {
                $slot   = (int) ( $bits[1] ?? 0 );
                $prompt = $prompts[ $slots[ $slot ] ?? '' ] ?? '';
                $part   = $parts[ $bits[2] ?? '' ] ?? '';
                if ( $prompt !== '' && $part !== '' ) $label = $prompt . ': ' . $part;
            }
            $out[] = [ 'field' => $field, 'label' => $label !== '' ? $label : $generic ];
        }
        return $out;
    }

    /** @param mixed $value */
    private static function nullable( $value ): ?string {
        $value = (string) ( $value ?? '' );
        return $value === '' ? null : $value;
    }
}
