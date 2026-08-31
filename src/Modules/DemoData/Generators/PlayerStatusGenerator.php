<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Players\PlayerStatusModule;

/**
 * PlayerStatusGenerator — the two traffic-light inputs the demo academy had
 * none of: potential bands and behaviour ratings (#3242).
 *
 * `PlayerStatusCalculator` weights four inputs, and two of them were empty
 * on every generated player. So the status every screen showed was not the
 * status the product would produce for a real club, and everything built on
 * top of the missing halves — the "Current:" line on the capture screen, the
 * #3226 trajectory, the `DevelopmentScorer` contribution to team chemistry,
 * the PDP `EvidencePacket` — rendered blank. A feature that shows nothing
 * looks like a feature nobody uses, and the demo academy is the shop window.
 *
 * ## Potential is a history, not a row
 *
 * Two to four dated entries for most eligible players, spread back across
 * the window, so the trajectory has something to draw. At least one player
 * per squad is revised **down** — that is the case the trajectory exists to
 * make visible, and a demo where every line only ever goes up teaches the
 * wrong thing about what the band is for.
 *
 * ## And it stops below the age floor
 *
 * #3265 declines to ask for a potential band below
 * `PlayerStatusModule::POTENTIAL_MIN_AGE`. Seeding one anyway would make the
 * demo contradict the rule it is meant to illustrate, so eligibility is
 * checked against the same predicate the product uses rather than a second
 * copy of the age arithmetic. On a squad below the floor this generator
 * writes behaviour ratings and no potential at all, which is exactly what a
 * real academy running that squad would have.
 *
 * ## Deliberate gaps
 *
 * Roughly a fifth of eligible players get no potential, and a few get one
 * old enough for #3225's alert to have something true to say. A demo where
 * nothing is ever missing or overdue teaches the wrong thing about what the
 * product notices — the alert would look like a feature that never fires.
 */
class PlayerStatusGenerator implements DependentGeneratorInterface {

    /**
     * Bands best-first, matching `PotentialBand::ALL`. Held as a local list
     * rather than read from the vocabulary because the *distribution* below
     * is the point: an academy where everybody is a first-team prospect is
     * not a useful illustration of a judgement.
     */
    private const BANDS = [
        'first_team',
        'professional_elsewhere',
        'semi_pro',
        'top_amateur',
        'recreational',
    ];

    /** Cumulative weights — the middle of the ladder is where most sit. */
    private const BAND_WEIGHTS = [
        [ 8,   'first_team' ],
        [ 26,  'professional_elsewhere' ],
        [ 58,  'semi_pro' ],
        [ 88,  'top_amateur' ],
        [ 100, 'recreational' ],
    ];

    /** @var array<string, string[]> */
    private const POTENTIAL_NOTES = [
        'en_US' => [
            'Comfortable a level up in the last block of sessions.',
            'Physically behind the group, technically ahead of it.',
            'Consistent over a long stretch now, not just on his best day.',
            'Revised after the winter block — the gap to the group has closed.',
            'Holding the band, but the last six weeks have been flat.',
            'Reads the game earlier than anyone else in the squad.',
        ],
        'nl_NL' => [
            'Kon in het laatste blok makkelijk een niveau hoger mee.',
            'Fysiek achter op de groep, technisch erboven.',
            'Nu over een lange periode constant, niet alleen op zijn beste dag.',
            'Herzien na het winterblok — het gat met de groep is gedicht.',
            'Klasse blijft staan, maar de laatste zes weken waren vlak.',
            'Leest het spel eerder dan wie ook in de selectie.',
        ],
    ];

    /** @var array<string, string[]> */
    private const BEHAVIOUR_NOTES = [
        'en_US' => [
            'First to arrive, last off the pitch.',
            'Took the coaching point and applied it the same session.',
            'Quiet today — worth a word before the next one.',
            'Lifted the group when the session got hard.',
            'Frustrated with himself after losing the ball; managed it well.',
            'Helped a younger player through the warm-up without being asked.',
        ],
        'nl_NL' => [
            'Als eerste er, als laatste van het veld.',
            'Pakte het coachmoment op en paste het dezelfde training toe.',
            'Vandaag stil — even aanspreken voor de volgende keer.',
            'Trok de groep erdoorheen toen de training zwaar werd.',
            'Gefrustreerd na balverlies; ging er goed mee om.',
            'Hielp uit zichzelf een jongere speler door de warming-up.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var array<string,int> slot => user id */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'player_status';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[]          $players
     * @param array<string,int> $users
     */
    public function __construct( DemoBatchRegistry $registry, array $players, array $users, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $lang       = self::resolveLanguage( $this->language );
        $pot_notes  = self::POTENTIAL_NOTES[ $lang ];
        $beh_notes  = self::BEHAVIOUR_NOTES[ $lang ];
        $actor      = $this->actorUserId();
        $window_days = max( 7, $this->weeks * 7 );

        $potential_table = $wpdb->prefix . 'tt_player_potential';
        $behaviour_table = $wpdb->prefix . 'tt_player_behaviour_ratings';

        $total = 0;

        // One downward revision and one stale band per squad are guaranteed
        // rather than left to the dice. On the small preset there are about
        // a dozen eligible players, and a one-in-ten chance across a dozen
        // draws produces nothing often enough to matter — the first run of
        // this generator seeded zero stale bands, which would have left
        // #3225's alert looking like a feature that never fires.
        $downgrade_owed = [];
        $stale_owed     = [];

        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $total += $this->behaviourFor( $player_id, $behaviour_table, $actor, $beh_notes, $window_days );

            if ( ! $this->potentialApplies( $p ) ) continue;

            $team_id   = (int) ( $p->team_id ?? 0 );
            $owe_down  = ! isset( $downgrade_owed[ $team_id ] );
            // Never the same player: a band that is both freshly revised
            // down and six months untouched is two stories in one row, and
            // the one the screen tells would depend on which it read first.
            $owe_stale = ! $owe_down && ! isset( $stale_owed[ $team_id ] );

            $written = $this->potentialFor(
                $player_id, $potential_table, $actor, $pot_notes, $window_days, $owe_down, $owe_stale
            );
            if ( $written > 0 && $owe_down )  $downgrade_owed[ $team_id ] = true;
            if ( $written > 0 && $owe_stale ) $stale_owed[ $team_id ]     = true;

            $total += $written;
        }

        return $total;
    }

    /**
     * Is this player old enough to be asked? (#3265)
     *
     * Asks the product's own predicate rather than re-deriving the age, so
     * the demo cannot end up illustrating a floor the product does not
     * apply. Falls back to seeding when `PlayerStatusModule` is unavailable
     * — a generator is not the place to decide a product rule.
     */
    private function potentialApplies( object $player ): bool {
        if ( ! class_exists( PlayerStatusModule::class ) ) return true;
        if ( ! method_exists( PlayerStatusModule::class, 'potentialAppliesAtBirthdate' ) ) return true;

        return PlayerStatusModule::potentialAppliesAtBirthdate(
            isset( $player->date_of_birth ) ? (string) $player->date_of_birth : null
        );
    }

    /**
     * A dated potential history for one player.
     *
     * @param string[] $notes
     * @return int rows written
     */
    private function potentialFor( int $player_id, string $table, int $actor, array $notes, int $window_days, bool $force_downgrade, bool $force_stale = false ): int {
        global $wpdb;

        // A fifth are never assessed at all, so #3225 has something true to
        // report on a demo install and the "no potential recorded yet" state
        // is reachable on a screen.
        if ( ! $force_downgrade && ! $force_stale && mt_rand( 1, 100 ) <= 20 ) return 0;

        $entries = $force_downgrade ? mt_rand( 2, 4 ) : mt_rand( 1, 4 );

        // Spread back across a longer stretch than the activity window: a
        // band is revised quarterly, so a history confined to eight weeks
        // would show four revisions inside two months.
        $span_days = max( $window_days, 540 );

        // A tenth are left stale — last set beyond the default 180-day
        // threshold — so the alert has a live example.
        $stale     = $force_stale || mt_rand( 1, 100 ) <= 10;
        $newest_at = $stale
            ? mt_rand( 190, max( 191, $span_days ) )
            : mt_rand( 1, 120 );

        $index   = $this->pickBandIndex();
        $written = 0;

        // Oldest first, walking forward to `newest_at`.
        $offsets = [];
        for ( $i = 0; $i < $entries; $i++ ) {
            $offsets[] = $newest_at + (int) round( $i * ( $span_days - $newest_at ) / max( 1, $entries ) );
        }
        rsort( $offsets );

        foreach ( $offsets as $n => $days_ago ) {
            $is_last = ( $n === count( $offsets ) - 1 );

            if ( $n > 0 ) {
                // Move one step, mostly upward — an academy revises a band
                // because a player has grown into it. The forced downgrade
                // lands on the final entry so it is the *current* band, which
                // is what makes it visible on the profile rather than buried.
                $down   = ( $force_downgrade && $is_last ) || mt_rand( 1, 100 ) <= 25;
                $index += $down ? 1 : -1;
                $index  = max( 0, min( count( self::BANDS ) - 1, $index ) );
            }

            $set_at = gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );

            $wpdb->insert( $table, [
                'club_id'        => CurrentClub::id(),
                'player_id'      => $player_id,
                'set_at'         => $set_at,
                'set_by'         => $actor,
                'potential_band' => self::BANDS[ $index ],
                'notes'          => mt_rand( 1, 100 ) <= 55 ? $notes[ mt_rand( 0, count( $notes ) - 1 ) ] : null,
            ] );

            $id = (int) $wpdb->insert_id;
            if ( $id <= 0 ) continue;

            $this->registry->tag( 'player_potential', $id, [
                'player_id' => $player_id,
                'band'      => self::BANDS[ $index ],
                'stale'     => $stale ? 1 : 0,
            ] );
            $written++;
        }

        return $written;
    }

    /**
     * Behaviour ratings across the window for one player.
     *
     * Spread over dates rather than clustered, because the capture screen
     * shows a cadence and the calculator reads a trend: a handful of ratings
     * all written on one afternoon looks like a data import, not a season.
     *
     * @param string[] $notes
     * @return int rows written
     */
    private function behaviourFor( int $player_id, string $table, int $actor, array $notes, int $window_days ): int {
        global $wpdb;

        // A tenth of the squad has none — a coach has not got to everyone,
        // which is the honest state of any real academy.
        if ( mt_rand( 1, 100 ) <= 10 ) return 0;

        $count   = mt_rand( 3, 8 );
        $written = 0;

        // Each player sits around their own centre so the squad spreads out
        // rather than every rating landing on the middle of the scale.
        $centre = mt_rand( 25, 42 ) / 10;   // 2.5 – 4.2

        for ( $i = 0; $i < $count; $i++ ) {
            $rating = $centre + ( mt_rand( -8, 8 ) / 10 );
            $rating = max( 1.0, min( 5.0, round( $rating, 1 ) ) );

            $days_ago = (int) round( $i * $window_days / max( 1, $count ) ) + mt_rand( 0, 3 );
            $rated_at = gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );

            $wpdb->insert( $table, [
                'club_id'   => CurrentClub::id(),
                'player_id' => $player_id,
                'rated_at'  => $rated_at,
                'rated_by'  => $actor,
                'rating'    => $rating,
                'context'   => 'training',
                'notes'     => mt_rand( 1, 100 ) <= 40 ? $notes[ mt_rand( 0, count( $notes ) - 1 ) ] : null,
            ] );

            $id = (int) $wpdb->insert_id;
            if ( $id <= 0 ) continue;

            $this->registry->tag( 'player_behaviour_rating', $id, [
                'player_id' => $player_id,
                'rating'    => $rating,
            ] );
            $written++;
        }

        return $written;
    }

    /** Weighted band draw — the middle of the ladder is where most sit. */
    private function pickBandIndex(): int {
        $roll = mt_rand( 1, 100 );
        foreach ( self::BAND_WEIGHTS as [ $ceiling, $band ] ) {
            if ( $roll <= $ceiling ) {
                $index = array_search( $band, self::BANDS, true );
                return $index === false ? 2 : (int) $index;
            }
        }
        return 2;
    }

    /**
     * Who recorded it. The head of development sets potential in the seeded
     * matrix, so attributing it to a coach would make the demo contradict
     * its own authorization model on a screen that shows the name.
     */
    private function actorUserId(): int {
        foreach ( [ 'hod', 'head_dev', 'admin', 'coach1' ] as $slot ) {
            $id = (int) ( $this->users[ $slot ] ?? 0 );
            if ( $id > 0 ) return $id;
        }
        return (int) ( reset( $this->users ) ?: 0 );
    }

    private static function resolveLanguage( string $locale ): string {
        return $locale === 'nl_NL' ? 'nl_NL' : 'en_US';
    }
}
