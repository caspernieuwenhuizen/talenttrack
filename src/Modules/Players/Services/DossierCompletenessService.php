<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DossierCompletenessService (#3805) — what is missing from a squad's
 * paperwork, in one answer.
 *
 * Which player question does this answer? *What does this player need
 * next?*, asked of a whole squad at once, about the part of their file
 * that is nobody's football: is there an adult the club can reach, and did
 * anybody ever ask whether the club may photograph this child.
 *
 * The office used to answer it one player at a time. `GET /players` returns
 * name, foot and shirt number, so the state of sixteen files was sixteen
 * calls to `players/{id}`; `people.no_guardian_contact` counts the gap
 * club-wide but names neither the players nor their teams. Four weeks went
 * by on one missing guardian contact, and pictures on file with no consent
 * were found by opening two players' records by hand.
 *
 * ## Shape
 *
 * Deliberately the same shape as `MeasurementCoverageService::forTeam()`:
 * one entry per check, each carrying `total`, `complete`, `counts` and a
 * `needs` list naming the players. The office reads one layout across both
 * reports and the rendered view reuses the same component pattern.
 *
 * ## What it does not return
 *
 * **Never a guardian's name, e-mail address or phone number.** The report
 * says whether the field is filled in, never what is in it. The question
 * the office is asking is "whose file is incomplete", and answering it with
 * the contact details themselves would turn a per-team checklist into a
 * bulk export of families' contact details — which is exactly what the
 * per-team scoping is there to prevent. The values live on the player's own
 * record, behind that record's permissions, one player at a time.
 *
 * ## Consent is reported, never enforced
 *
 * #3804 locked that media consent is a record and not a gate. This service
 * counts the players whose file holds pictures nobody consented to, so a
 * human can go and ask. It hides nothing and it blocks nothing.
 */
final class DossierCompletenessService {

    public const GUARDIAN_NAME        = 'guardian_name';
    public const GUARDIAN_EMAIL       = 'guardian_email';
    public const GUARDIAN_PHONE       = 'guardian_phone';
    public const PARENT_ACCOUNT       = 'parent_account';
    public const MEDIA_CONSENT        = 'media_consent';
    public const MEDIA_WITHOUT_CONSENT = 'media_without_consent';

    public const STATUS_COMPLETE = 'complete';
    public const STATUS_MISSING  = 'missing';

    /**
     * The six checks, in reading order.
     *
     * @return list<string>
     */
    public static function checkKeys(): array {
        return [
            self::GUARDIAN_NAME,
            self::GUARDIAN_EMAIL,
            self::GUARDIAN_PHONE,
            self::PARENT_ACCOUNT,
            self::MEDIA_CONSENT,
            self::MEDIA_WITHOUT_CONSENT,
        ];
    }

    /** The translated name of one check. */
    public static function checkLabel( string $key ): string {
        switch ( $key ) {
            case self::GUARDIAN_NAME:  return __( 'Guardian name', 'talenttrack' );
            case self::GUARDIAN_EMAIL: return __( 'Guardian e-mail address', 'talenttrack' );
            case self::GUARDIAN_PHONE: return __( 'Guardian phone number', 'talenttrack' );
            case self::PARENT_ACCOUNT: return __( 'Parent account linked', 'talenttrack' );
            case self::MEDIA_CONSENT:  return __( 'Photo and video consent recorded', 'talenttrack' );
            case self::MEDIA_WITHOUT_CONSENT: return __( 'Pictures on file with no consent', 'talenttrack' );
            default: return $key;
        }
    }

    /**
     * What is still missing from this squad's files.
     *
     * @return array{player_count:int, checks:list<array<string,mixed>>}
     */
    public function forTeam( int $team_id ): array {
        $empty = [ 'player_count' => 0, 'checks' => [] ];
        if ( $team_id <= 0 ) return $empty;

        $players = $this->roster( $team_id );
        if ( $players === [] ) return $empty;

        $checks = [];
        foreach ( self::checkKeys() as $key ) {
            $checks[] = $this->check( $key, $players );
        }

        return [ 'player_count' => count( $players ), 'checks' => $checks ];
    }

    /**
     * The squad, with every column the six checks read, in one query.
     *
     * One query rather than one per player: this is a report over a whole
     * roster and the measurement-coverage service next door is the cautionary
     * tale — it asks the results repository once per player, which is fine at
     * sixteen and is not the shape to copy.
     *
     * Rows come back as arrays rather than objects: every column here is
     * read by name, and an array says so to a reader and to static analysis
     * both — `$wpdb->get_results()` returns bare `stdClass` otherwise, whose
     * properties nothing can check.
     *
     * @return list<array<string,mixed>>
     */
    private function roster( int $team_id ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        // The media count is per *link*, matching `GET /players`'s
        // `media_count` (#3804): one squad photo linked to eleven children
        // is one item each. Archived items are excluded, so a cleared-out
        // player reads as clear.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id,
                    p.first_name,
                    p.last_name,
                    p.guardian_name,
                    p.guardian_email,
                    p.guardian_phone,
                    p.media_consent,
                    p.media_consent_at,
                    ( SELECT COUNT(*)
                        FROM {$p}tt_player_parents pp
                       WHERE pp.player_id = p.id
                         AND pp.club_id = p.club_id ) AS parent_count,
                    ( SELECT COUNT(*)
                        FROM {$p}tt_media_links ml
                        JOIN {$p}tt_media m ON m.id = ml.media_id
                       WHERE ml.entity_type = 'player'
                         AND ml.entity_id = p.id
                         AND ml.club_id = p.club_id
                         AND m.archived_at IS NULL ) AS media_count
               FROM {$p}tt_players p
              WHERE p.team_id = %d
                AND p.club_id = %d
                AND p.archived_at IS NULL
                AND p.trashed_at IS NULL
              ORDER BY p.last_name ASC, p.first_name ASC, p.id ASC",
            $team_id,
            $club_id
        ), ARRAY_A );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
    }

    /**
     * One check over the whole roster.
     *
     * @param list<array<string,mixed>> $players
     * @return array<string,mixed>
     */
    private function check( string $key, array $players ): array {
        $total    = count( $players );
        $complete = 0;
        $needs    = [];
        $recorded = [];
        $items    = 0;

        foreach ( $players as $player ) {
            $ok = $this->isComplete( $key, $player );
            if ( $ok ) {
                $complete++;
                if ( $key === self::MEDIA_CONSENT ) {
                    $recorded[] = [
                        'player_id'   => (int) ( $player['player_id'] ?? 0 ),
                        'name'        => self::nameOf( $player ),
                        'recorded_at' => (string) ( $player['media_consent_at'] ?? '' ),
                    ];
                }
                continue;
            }

            $need = [
                'player_id' => (int) ( $player['player_id'] ?? 0 ),
                'name'      => self::nameOf( $player ),
                'status'    => self::STATUS_MISSING,
                'detail'    => '',
            ];

            if ( $key === self::MEDIA_WITHOUT_CONSENT ) {
                $count          = (int) ( $player['media_count'] ?? 0 );
                $items         += $count;
                $need['detail'] = (string) $count;
            }

            $needs[] = $need;
        }

        $out = [
            'key'      => $key,
            'name'     => self::checkLabel( $key ),
            'total'    => $total,
            'complete' => $complete,
            'counts'   => [
                self::STATUS_COMPLETE => $complete,
                self::STATUS_MISSING  => $total - $complete,
            ],
            'needs'    => $needs,
        ];

        // "Media items with no consent" counts items as well as players: one
        // player with nine pictures and no consent is a bigger job than nine
        // players with one each, and the office is deciding what to chase.
        if ( $key === self::MEDIA_WITHOUT_CONSENT ) {
            $out['item_count'] = $items;
        }

        // Consent carries its date, because a consent record without one is
        // an assertion rather than evidence (#2744). The recorded list is the
        // only place a *satisfied* check names players: "when did this family
        // agree" is the question an administrator is actually asked.
        if ( $key === self::MEDIA_CONSENT ) {
            $out['recorded'] = $recorded;
        }

        return $out;
    }

    /**
     * The player's display name, from a roster row.
     *
     * @param array<string,mixed> $player
     */
    private static function nameOf( array $player ): string {
        return trim( (string) ( $player['first_name'] ?? '' ) . ' ' . (string) ( $player['last_name'] ?? '' ) );
    }

    /**
     * Is this check satisfied for this player?
     *
     * A linked parent account and the guardian columns are reported
     * **separately** on purpose. They are two different facts: an account is
     * how a parent reads their child's record, and the columns are how the
     * club phones somebody on a Saturday morning. A player can have one and
     * not the other, and a report that merged them would tell an
     * administrator a file was complete when there is still nobody to call.
     */
    /** @param array<string,mixed> $player */
    private function isComplete( string $key, array $player ): bool {
        switch ( $key ) {
            case self::GUARDIAN_NAME:
                return trim( (string) ( $player['guardian_name'] ?? '' ) ) !== '';
            case self::GUARDIAN_EMAIL:
                return trim( (string) ( $player['guardian_email'] ?? '' ) ) !== '';
            case self::GUARDIAN_PHONE:
                return trim( (string) ( $player['guardian_phone'] ?? '' ) ) !== '';
            case self::PARENT_ACCOUNT:
                return (int) ( $player['parent_count'] ?? 0 ) > 0;
            case self::MEDIA_CONSENT:
                return ! empty( $player['media_consent'] );
            case self::MEDIA_WITHOUT_CONSENT:
                // Complete means "nothing to chase": either consent is on
                // record, or there are no pictures to consent to.
                return ! empty( $player['media_consent'] ) || (int) ( $player['media_count'] ?? 0 ) === 0;
            default:
                return true;
        }
    }
}
