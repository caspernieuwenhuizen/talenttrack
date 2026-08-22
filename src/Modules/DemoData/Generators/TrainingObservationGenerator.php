<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * TrainingObservationGenerator — writes tt_training_observations.
 *
 * Epic decision D18: a demo academy carries plans, runs **and**
 * observations. The first two make the module look furnished; this one
 * makes it look used. A coach evaluating TalentTrack should open a
 * player and find someone's words about them from a Tuesday in August,
 * because that is the thing the module is actually for.
 *
 * ## Deliberately sparse, and deliberately mostly unrated
 *
 * Roughly one player in three from a run gets a note, and only half of
 * those get a score. That is not laziness in the generator — it is what
 * the real data looks like. A coach with fifteen children does not rate
 * all of them; they write two sentences about the two that stood out.
 * A demo where every player carries a tidy 7 would teach an evaluator
 * that this is an assessment form, which is exactly the wrong idea.
 *
 * `tt_player_principle_exposure` is NOT generated — it is derived, and
 * the nightly workflow job fills it. Generating it would create rows
 * that disagree with their own source the first time the job runs.
 */
class TrainingObservationGenerator implements DependentGeneratorInterface {

    /** @var array<string, list<string>> */
    private const NOTES_BY_LANGUAGE = [
        'en_US' => [
            'Kept the ball moving under pressure. Better first touch than last week.',
            'Lost the duel three times on the same shoulder — worth a word before Saturday.',
            'Talked the back four through the whole game. Quietly leading.',
            'Dropped too deep whenever we had the ball. Needs to hold the line.',
            'Took the ball on the half-turn twice without being asked.',
            'Tired in the last block; came off the pitch fine.',
        ],
        'nl_NL' => [
            'Bleef de bal rondspelen onder druk. Beter eerste balcontact dan vorige week.',
            'Verloor het duel drie keer over dezelfde schouder — voor zaterdag even bespreken.',
            'Coachte de hele partij de laatste linie. Neemt stilletjes de leiding.',
            'Zakte te ver in zodra wij de bal hadden. Moet de linie vasthouden.',
            'Nam de bal twee keer uit zichzelf in de draai aan.',
            'Werd moe in het laatste blok; kwam goed van het veld.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    public static function category(): string {
        return 'training_observations';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->users, $ctx->contentLanguage );
    }

    /** @param array<string,int> $users */
    public function __construct( DemoBatchRegistry $registry, array $users, string $language = '' ) {
        $this->registry = $registry;
        $this->users    = $users;
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $notes  = self::NOTES_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $author = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $club   = CurrentClub::id();

        $scale = $this->scale();
        $total = 0;
        $n     = 0;

        $runs = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, activity_id, run_date FROM {$wpdb->prefix}tt_training_plan_runs
              WHERE club_id = %d AND status = 'completed' ORDER BY id ASC",
            $club
        ) );

        foreach ( $runs as $run ) {
            $players = (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT COALESCE( guest_player_id, player_id )
                   FROM {$wpdb->prefix}tt_attendance
                  WHERE activity_id = %d AND club_id = %d
                    AND record_type = 'actual' AND status IN ( 'present', 'late' )
               ORDER BY 1 ASC",
                (int) $run->activity_id,
                $club
            ) );

            foreach ( $players as $index => $player_id ) {
                // Roughly one in three. Deterministic rather than random:
                // demo data has to reproduce from a seed.
                if ( ( $index + (int) $run->id ) % 3 !== 0 ) continue;

                // Half of those carry a score. The other half are the
                // wet-Tuesday case the nullable column exists for.
                $rated  = ( $n % 2 ) === 0;
                $rating = $rated && $scale !== [] ? $scale[ $n % count( $scale ) ] : null;

                $ok = $wpdb->insert( "{$wpdb->prefix}tt_training_observations", [
                    'uuid'           => self::uuid(),
                    'club_id'        => $club,
                    'run_id'         => (int) $run->id,
                    'player_id'      => (int) $player_id,
                    'rating'         => $rating,
                    'note'           => $notes[ $n % count( $notes ) ],
                    'author_user_id' => $author ?: null,
                    'created_at'     => (string) $run->run_date . ' 20:15:00',
                ] );

                $n++;
                if ( $ok === false ) continue;

                $this->registry->tag( 'training_observation', (int) $wpdb->insert_id, [
                    'player_id' => (int) $player_id,
                ] );
                $total++;
            }
        }

        return $total;
    }

    /**
     * The install's own scale, so demo ratings are values the product
     * would actually accept. A hard-coded 7 on an academy configured
     * 1–5 would be refused by the repository the first time a coach
     * edited it.
     *
     * @return list<string>
     */
    private function scale(): array {
        $min  = (float) QueryHelpers::get_config( 'rating_min', '5' );
        $max  = (float) QueryHelpers::get_config( 'rating_max', '9' );
        $step = (float) QueryHelpers::get_config( 'rating_step', '1' );
        if ( $step <= 0 ) $step = 1.0;
        if ( $max < $min ) [ $min, $max ] = [ $max, $min ];

        // The middle of the scale and just above it — a demo full of
        // nines would read as an academy that rates everyone highly.
        $out   = [];
        $value = $min;
        while ( $value <= $max + 0.0001 && count( $out ) < 20 ) {
            $out[] = number_format( $value, 1, '.', '' );
            $value += $step;
        }

        $middle = (int) floor( count( $out ) / 2 );

        return array_slice( $out, max( 0, $middle - 1 ), 3 ) ?: $out;
    }

    private static function uuid(): string {
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::NOTES_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::NOTES_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
