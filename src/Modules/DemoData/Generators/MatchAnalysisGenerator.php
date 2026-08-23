<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * MatchAnalysisGenerator — writes tt_match_analyses and its two children.
 *
 * A demo academy that has played matches should have opinions about them.
 * Without this, the match-analysis surface in a demo install is an empty
 * form, and an evaluator reading an empty form learns nothing about what
 * the module is for.
 *
 * ## Deliberately uneven
 *
 * Not every match gets every section, and roughly a third of the players
 * who appeared get a line. That is what real reviews look like: a coach
 * writes two sentences about the two children who stood out and says
 * nothing about the rest. A demo where every phase carries a tidy rating
 * and every player a note would teach an evaluator that this is a form to
 * be completed, which is the opposite of the intent.
 *
 * Deterministic throughout — demo data has to reproduce from a seed, so
 * the choices key off row ids rather than `rand()`.
 */
class MatchAnalysisGenerator implements DependentGeneratorInterface {

    /** @var array<string, list<string>> */
    private const SUMMARIES = [
        'en_US' => [
            'Started slowly and grew into it. Second half was the football we have been training.',
            'Better than the result says. We created enough; we finished almost none of it.',
            'Hard afternoon. We were second to nearly every loose ball in the first twenty minutes.',
            'Comfortable. The shape held and nobody had to be told twice where to stand.',
        ],
        'nl_NL' => [
            'Traag begonnen en er langzaam ingegroeid. Tweede helft was het voetbal waarop we trainen.',
            'Beter dan de uitslag zegt. We creëerden genoeg; we maakten er bijna niets van af.',
            'Zware middag. We waren de eerste twintig minuten bij vrijwel elke tweede bal te laat.',
            'Comfortabel. De organisatie hield stand en niemand hoefde twee keer te horen waar hij moest staan.',
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const SECTION_NOTES = [
        'en_US' => [
            MethodologyEnums::FUNCTION_AANVALLEN => [
                'Built out from the back patiently when they pressed.',
                'Too few runs behind the last line.',
                'The switch to the far winger came on twice and worked both times.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN => [
                'First pass after winning it went backwards too often.',
                'Two counters that ended in a shot. That is the idea.',
            ],
            MethodologyEnums::FUNCTION_VERDEDIGEN => [
                'Compact between the lines, they had nothing through the middle.',
                'The line dropped as soon as we lost the ball on their half.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN => [
                'Slow to close down after losing it. Costs us the first goal.',
                'Better after half time — the nearest player pressed straight away.',
            ],
            MatchAnalysisEnums::SECTION_SET_PIECES => [
                'Our corners still land on the first defender.',
                'Defended their free kicks well; everyone picked a man.',
            ],
        ],
        'nl_NL' => [
            MethodologyEnums::FUNCTION_AANVALLEN => [
                'Rustig van achteruit opgebouwd toen ze druk zetten.',
                'Te weinig loopacties in de diepte achter de laatste lijn.',
                'De wissel naar de verre buitenspeler kwam er twee keer uit en werkte allebei de keren.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN => [
                'De eerste pass na balwinst ging te vaak achteruit.',
                'Twee counters die op een schot eindigden. Dat is de bedoeling.',
            ],
            MethodologyEnums::FUNCTION_VERDEDIGEN => [
                'Compact tussen de linies, door het midden kwamen ze er niet door.',
                'De linie zakte meteen zodra we op hun helft de bal verloren.',
            ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN => [
                'Traag in het druk zetten na balverlies. Kost ons de eerste tegengoal.',
                'Na rust beter — de dichtstbijzijnde speler ging er direct op.',
            ],
            MatchAnalysisEnums::SECTION_SET_PIECES => [
                'Onze corners komen nog steeds op de eerste verdediger.',
                'Hun vrije trappen goed verdedigd; iedereen pakte een man.',
            ],
        ],
    ];

    /** @var array<string, list<string>> */
    private const PLAYER_NOTES = [
        'en_US' => [
            'Took the ball on the half-turn under pressure, twice, without being asked.',
            'Kept talking the back four through it. Quietly running the line.',
            'Stopped tracking his winger after the hour. That is where the second goal came from.',
            'Won everything in the air. Held the ball up when we needed a breather.',
            'Rushed the two chances he had. Worth a calm word before Saturday.',
            'Did exactly the job he was given and did not need reminding.',
        ],
        'nl_NL' => [
            'Nam de bal twee keer onder druk in de draai aan, zonder dat het gevraagd werd.',
            'Bleef de laatste linie coachen. Stuurt de lijn stilletjes aan.',
            'Ging zijn buitenspeler na een uur niet meer volgen. Daar komt de tweede tegengoal vandaan.',
            'Won alles in de lucht. Hield de bal vast toen we even lucht nodig hadden.',
            'Overhaastte zijn twee kansen. Voor zaterdag rustig even bespreken.',
            'Deed precies wat hem gevraagd was en had daar geen herinnering voor nodig.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    public static function category(): string {
        return 'match_analyses';
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

        $lang    = self::resolveLanguage( $this->language );
        $club    = CurrentClub::id();
        $author  = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $ratings = [
            MatchAnalysisEnums::RATING_WENT_WELL,
            MatchAnalysisEnums::RATING_MIXED,
            MatchAnalysisEnums::RATING_NEEDS_WORK,
        ];
        $markers = [
            MatchAnalysisEnums::MARKER_STOOD_OUT,
            MatchAnalysisEnums::MARKER_BELOW_PAR,
            MatchAnalysisEnums::MARKER_AS_EXPECTED,
        ];

        // Only matches that have been played. A demo full of analyses of
        // fixtures still in the future would misrepresent the flow.
        $matches = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_date FROM {$wpdb->prefix}tt_activities
              WHERE club_id = %d
                AND activity_type_key IN ( 'game', 'match' )
                AND session_date <= %s
           ORDER BY id ASC",
            $club,
            current_time( 'Y-m-d' )
        ) );

        $total = 0;
        $n     = 0;

        foreach ( $matches as $match ) {
            $activity_id = (int) $match->id;

            // Two in three. A coach does not write up every game.
            if ( $activity_id % 3 === 0 ) continue;

            $prep = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_match_prep WHERE activity_id = %d AND club_id = %d",
                $activity_id, $club
            ) );
            $exec = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_match_execution WHERE activity_id = %d AND club_id = %d",
                $activity_id, $club
            ) );

            $when = (string) $match->session_date . ' 19:30:00';

            $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analyses", [
                'uuid'               => self::uuid(),
                'club_id'            => $club,
                'activity_id'        => $activity_id,
                'match_prep_id'      => $prep ?: null,
                'match_execution_id' => $exec ?: null,
                'summary'            => self::SUMMARIES[ $lang ][ $n % count( self::SUMMARIES[ $lang ] ) ],
                'status'             => MatchAnalysisEnums::STATUS_FINAL,
                'share_token_seed'   => '',
                'created_by'         => $author ?: null,
                'created_at'         => $when,
                'updated_at'         => $when,
            ] );
            $n++;
            if ( $ok === false ) continue;

            $analysis_id = (int) $wpdb->insert_id;
            $this->registry->tag( 'match_analysis', $analysis_id, [ 'activity_id' => $activity_id ] );
            $total++;

            // Three of the five rated sections per match, rotating which
            // ones — a review that always covers the same phases would
            // read as a template rather than as a coach's attention.
            $sections = array_keys( self::SECTION_NOTES[ $lang ] );
            $offset   = $activity_id % count( $sections );
            for ( $i = 0; $i < 3; $i++ ) {
                $key   = $sections[ ( $offset + $i ) % count( $sections ) ];
                $notes = self::SECTION_NOTES[ $lang ][ $key ];

                $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analysis_sections", [
                    'club_id'     => $club,
                    'analysis_id' => $analysis_id,
                    'section_key' => $key,
                    'rating'      => $ratings[ ( $activity_id + $i ) % count( $ratings ) ],
                    'notes'       => implode( "\n", array_slice( $notes, 0, 1 + ( ( $activity_id + $i ) % 2 ) ) ),
                    'updated_at'  => $when,
                ] );
                if ( $ok !== false ) {
                    $this->registry->tag( 'match_analysis_section', (int) $wpdb->insert_id, [] );
                    $total++;
                }
            }

            // Players who actually appeared, roughly one in three of them.
            $players = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT player_id, COALESCE( minutes_override, minutes_played ) AS minutes
                   FROM {$wpdb->prefix}tt_attendance
                  WHERE activity_id = %d AND club_id = %d AND is_guest = 0
                    AND record_type = 'actual' AND status IN ( 'present', 'late' )
               ORDER BY player_id ASC",
                $activity_id, $club
            ) );

            foreach ( $players as $index => $row ) {
                if ( ( $index + $activity_id ) % 3 !== 0 ) continue;

                $player_id = (int) $row->player_id;
                if ( $player_id <= 0 ) continue;

                $ok = $wpdb->insert( "{$wpdb->prefix}tt_match_analysis_players", [
                    'club_id'        => $club,
                    'analysis_id'    => $analysis_id,
                    'player_id'      => $player_id,
                    'marker'         => $markers[ $n % count( $markers ) ],
                    'note'           => self::PLAYER_NOTES[ $lang ][ $n % count( self::PLAYER_NOTES[ $lang ] ) ],
                    'team_function'  => $sections[ ( $player_id + $activity_id ) % count( $sections ) ],
                    'minutes_played' => $row->minutes !== null ? (int) $row->minutes : null,
                    'updated_at'     => $when,
                ] );
                $n++;
                if ( $ok === false ) continue;

                $this->registry->tag( 'match_analysis_player', (int) $wpdb->insert_id, [
                    'player_id' => $player_id,
                ] );
                $total++;
            }
        }

        return $total;
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
        if ( isset( self::SUMMARIES[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::SUMMARIES ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
