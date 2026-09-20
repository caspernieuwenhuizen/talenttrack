<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ScoutPlayerLinks;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;

/**
 * ScoutPlayerCard (#3807) — the deliberately thin view of a squad player
 * that a scout may read.
 *
 * ## Why this exists rather than a wider `players/{id}`
 *
 * A scout's job is comparing a trialist against the players the club
 * already has, and until now they could read a squad player's minutes
 * share and not one line about who that player is: `GET /players`
 * answered 200 with zero rows and every per-player route answered 403.
 * The obvious fix — letting a scout through `players/{id}` — is the wrong
 * one. That route returns the **full** record, `guardian_name`,
 * `guardian_email`, `guardian_phone` and `custom_fields` included, and
 * `custom_fields` is hydrated with every field an academy has defined,
 * with no per-field visibility filter. Whatever a club has put there —
 * medical notes, safeguarding remarks — would ride out with it.
 *
 * So the card is its own composition with its own field list, and the
 * list is the whole point of the class. **A reviewer should refuse a
 * change that adds any of**: guardian name, e-mail or phone;
 * `custom_fields` in any form; evaluations; measurements; injuries or
 * anything medical; behaviour ratings; PDP content; safeguarding notes.
 *
 * ## What is on it, and why each one earns its place
 *
 *  - **Name and birth year.** Who this is, and roughly how old — the
 *    year, not the date of birth, because a comparison needs the age
 *    band and a date of birth is an identity document field.
 *  - **Team and position.** What the club already has in that slot.
 *  - **Minutes share.** The one number the scout could already read, now
 *    beside the player it describes.
 *  - **Status.** The club's own standing judgement, and the only
 *    judgement on the card. It answers the scout's real question —
 *    whether the club already rates this player — and it stays a status
 *    label, never the evaluation behind it.
 *  - **The caller's own observations.** What this scout themselves wrote
 *    when they watched the player. Theirs, and only theirs: another
 *    scout's notes are not on this card.
 *
 * Composition only, in the domain layer, so the REST route and the
 * rendered card answer identically (CLAUDE.md §4).
 */
final class ScoutPlayerCard {

    /** How far back the minutes-share window reaches by default. */
    private const WINDOW_MONTHS = 12;

    /**
     * May this caller read the card for this player?
     *
     * Two ways in, and they are different questions. Somebody who can
     * already read the full player record is by definition entitled to
     * this subset of it. A scout is entitled through their **link** to
     * this player — a panel seat or the head of development's assignment
     * list, resolved by `ScoutPlayerLinks` and nowhere else (#3566).
     */
    public static function canRead( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        return \TT\Infrastructure\Security\AuthorizationService::canViewPlayer( $user_id, $player_id )
            || ScoutPlayerLinks::isLinkedTo( $user_id, $player_id );
    }

    /**
     * The card, or null when the player does not exist in this club.
     *
     * @return array<string,mixed>|null
     */
    public static function forPlayer( int $player_id, int $viewer_id ): ?array {
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) return null;

        $team_id = (int) ( $player->team_id ?? 0 );

        return [
            'player_id'     => $player_id,
            'name'          => QueryHelpers::player_display_name( $player ),
            'birth_year'    => self::birthYear( (string) ( $player->date_of_birth ?? '' ) ),
            'team_id'       => $team_id ?: null,
            'team_name'     => self::teamName( $team_id ),
            'position'      => self::position( $player ),
            'status'        => (string) ( $player->status ?? '' ),
            'minutes_share' => self::minutesShare( $team_id, $player_id ),
            'observations'  => self::ownObservations( $player_id, $viewer_id ),
        ];
    }

    /**
     * The year, never the date. An age band is what a comparison needs;
     * a full date of birth is an identity field and has no business on a
     * card this thin.
     */
    private static function birthYear( string $dob ): ?int {
        $usable = QueryHelpers::usableDate( $dob );
        if ( $usable === null ) return null;
        $year = (int) substr( $usable, 0, 4 );
        return $year > 1900 ? $year : null;
    }

    private static function teamName( int $team_id ): ?string {
        if ( $team_id <= 0 ) return null;
        $team = QueryHelpers::get_team( $team_id );
        if ( ! $team ) return null;
        $name = trim( (string) ( $team->name ?? '' ) );
        return $name !== '' ? $name : null;
    }

    /**
     * The player's first preferred position, translated through the
     * lookup layer so the card reads the same as every other surface.
     */
    private static function position( object $player ): ?string {
        $raw = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
        if ( ! is_array( $raw ) || $raw === [] ) return null;

        $first = trim( (string) reset( $raw ) );
        if ( $first === '' ) return null;

        return LabelTranslator::positionLabel( $first );
    }

    /**
     * Share of the team's available minutes over the last year.
     *
     * Null when the player is on no team, or has no recorded minutes in
     * the window — "not measured" and "measured at zero" are different
     * answers and a scout comparing squads needs to be able to tell.
     *
     * @return array<string,mixed>|null
     */
    private static function minutesShare( int $team_id, int $player_id ): ?array {
        if ( $team_id <= 0 ) return null;

        $to   = (string) current_time( 'Y-m-d' );
        $from = gmdate( 'Y-m-d', (int) strtotime( $to . ' -' . self::WINDOW_MONTHS . ' months' ) );

        $row = ( new MinutesShareQuery() )->forPlayer( $team_id, $player_id, $from, $to );
        if ( $row === null ) return null;

        return [
            'from'      => $from,
            'to'        => $to,
            'minutes'   => $row['minutes'] ?? null,
            'share_pct' => $row['share_pct'] ?? null,
            'matches'   => $row['matches'] ?? null,
        ];
    }

    /**
     * What **this** scout wrote when they watched this player.
     *
     * Reached through the prospect the player was promoted from, which is
     * where a scouting observation lives. Filtered to the caller's own
     * rows: another scout's notes about a child are not this scout's to
     * read off a card, and the issue's field list says "the scout's own
     * scouting observations" for that reason.
     *
     * @return list<array<string,mixed>>
     */
    private static function ownObservations( int $player_id, int $viewer_id ): array {
        if ( $viewer_id <= 0 ) return [];
        if ( ! class_exists( ProspectsRepository::class ) ) return [];

        $prospect = ( new ProspectsRepository() )->findPromotedForPlayer( $player_id );
        if ( ! $prospect ) return [];

        $out = [];
        foreach ( ( new ProspectVisitObservationsRepository() )->forProspect( (int) $prospect->id ) as $row ) {
            if ( (int) ( $row->created_by ?? 0 ) !== $viewer_id ) continue;
            $out[] = [
                'observed_at' => (string) ( $row->observed_at ?? $row->visit_date ?? '' ),
                'location'    => (string) ( $row->location ?? '' ),
                'notes'       => (string) ( $row->notes ?? '' ),
            ];
        }
        return $out;
    }
}
