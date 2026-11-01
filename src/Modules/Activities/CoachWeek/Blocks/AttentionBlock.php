<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;

/**
 * #4496 — the "Players who need attention" section below the week's list:
 * injured and returning players, players who missed their last trainings,
 * trial players and birthdays.
 *
 * These are minors' data, so every block in the section keeps the same
 * rules:
 * - A player is named only when `WeekContext::canSeePlayer()` admits the
 *   viewer. Every other player in the line is counted, never identified:
 *   no id, no name.
 * - No medical detail, no age and no date of birth leaves the server.
 * - A block that has nothing to say returns an empty line; the view hides
 *   the section when every line is empty.
 *
 * Each line is `{ players: list<item>, others: int }` under the block's own
 * top-level field.
 */
abstract class AttentionBlock extends AbstractWeekBlock {

    /** The section's defaults, per persona and device (#4496). */
    private const DEFAULTS = [
        'head_coach'          => [ 'desktop' => [ 'injuries', 'missed', 'trial', 'birthdays' ], 'phone' => [ 'injuries' ] ],
        'assistant_coach'     => [ 'desktop' => [ 'injuries', 'missed', 'birthdays' ], 'phone' => [] ],
        'team_manager'        => [ 'desktop' => [ 'birthdays' ], 'phone' => [] ],
        'head_of_development' => [ 'desktop' => [ 'missed', 'trial' ], 'phone' => [] ],
    ];

    /** The top-level `GET me/week` field this block owns. */
    abstract protected function field(): string;

    /**
     * The players this line is about, before the naming rule: each item
     * carries `player_id` plus the block's own display fields.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function items( WeekContext $week ): array;

    public function group(): string {
        return WeekBlockGroup::ATTENTION;
    }

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $this->key(), self::DEFAULTS[ $persona ][ $device ] ?? [], true );
    }

    public function panelDefaults(): array {
        return [ $this->field() => self::emptyLine() ];
    }

    public function panelFields( WeekContext $week ): array {
        $players = [];
        $others  = 0;
        foreach ( $this->items( $week ) as $item ) {
            if ( $week->canSeePlayer( (int) ( $item['player_id'] ?? 0 ) ) ) {
                $players[] = $item;
            } elseif ( $this->countsOthers() ) {
                $others++;
            }
        }
        return [ $this->field() => [ 'players' => $players, 'others' => $others ] ];
    }

    /**
     * Whether players the viewer may not open are counted. A line whose
     * mere count would tell something about a hidden child (a birthday in
     * this week) returns false and leaves them out altogether.
     */
    protected function countsOthers(): bool {
        return true;
    }

    /** @return array{players: list<array<string, mixed>>, others: int} */
    public static function emptyLine(): array {
        return [ 'players' => [], 'others' => 0 ];
    }

    /**
     * The current players of the week's teams, id => fields, read once for
     * every block in the section. The date of birth stays here: a block
     * derives the day it needs from it and never returns it.
     *
     * @return array<int, array{id:int, team_id:int, first_name:string, last_name:string, date_of_birth:string}>
     */
    protected static function players( WeekContext $week ): array {
        return $week->once( 'attention_players', static function () use ( $week ): array {
            $out = [];
            foreach ( QueryHelpers::get_players_for_teams( array_keys( $week->teams ) ) as $player ) {
                $f   = (array) $player;
                $pid = (int) ( $f['id'] ?? 0 );
                if ( $pid <= 0 ) continue;
                $out[ $pid ] = [
                    'id'            => $pid,
                    'team_id'       => (int) ( $f['team_id'] ?? 0 ),
                    'first_name'    => trim( (string) ( $f['first_name'] ?? '' ) ),
                    'last_name'     => trim( (string) ( $f['last_name'] ?? '' ) ),
                    'date_of_birth' => (string) ( $f['date_of_birth'] ?? '' ),
                ];
            }
            return $out;
        } );
    }

    protected static function fullName( string $first, string $last ): string {
        return trim( $first . ' ' . $last );
    }
}
