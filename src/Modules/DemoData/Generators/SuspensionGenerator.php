<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Journey\SuspensionService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * SuspensionGenerator (#4103) — writes `tt_player_suspensions` through
 * `SuspensionRepository`, so the journey events fire and the served ones
 * are marked served exactly as a real suspension is.
 *
 * Runs last among the dependent generators: it needs the fixtures and
 * their results to exist. Roughly half the teams get one suspension
 * counting from one of their matches; when the team still has matches to
 * play, the last suspension is placed so it is still running today, which
 * is the case match prep and the planned squad have to show.
 */
class SuspensionGenerator implements DependentGeneratorInterface {

    /** @var array<string, string[]> */
    private const NOTES_BY_LANGUAGE = [
        'en_US' => [
            'Fifth yellow card of the season, picked up in the second half.',
            'Sent off for a second booking. Spoken to after the match.',
            'Talked through with the player and the parents on Monday.',
        ],
        'nl_NL' => [
            'Vijfde gele kaart van het seizoen, in de tweede helft.',
            'Uitgesloten na een tweede gele kaart. Na de wedstrijd besproken.',
            'Maandag doorgesproken met de speler en de ouders.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var object[] */
    private array $players;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'suspensions';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->players, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[] $teams
     * @param object[] $players
     */
    public function __construct( DemoBatchRegistry $registry, array $teams, array $players, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->players  = $players;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        $reasons = [];
        foreach ( QueryHelpers::get_lookups( 'suspension_reason' ) as $row ) {
            $reasons[] = (int) ( ( (array) $row )['id'] ?? 0 );
        }
        $notes = self::NOTES_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];

        $window_start = gmdate( 'Y-m-d', (int) strtotime( '-' . $this->weeks . ' weeks' ) );
        $today        = current_time( 'Y-m-d' );
        $repo         = new SuspensionRepository();

        $total = 0;
        foreach ( $this->teams as $team ) {
            $team_id = (int) ( ( (array) $team )['id'] ?? 0 );
            if ( $team_id <= 0 || mt_rand( 1, 100 ) > 50 ) continue;

            $squad = $this->playersOf( $team_id );
            $fixtures = SuspensionService::fixtureSequence( $team_id, $window_start );
            if ( $squad === [] || $fixtures === [] ) continue;

            // Start just after a match: a still-running ban when the team
            // has a match ahead of today, otherwise one that was served.
            $upcoming = array_values( array_filter( $fixtures, static fn( array $f ): bool => $f['date'] >= $today ) );
            $start    = $upcoming !== [] ? $upcoming[0]['date'] : $fixtures[ mt_rand( 0, count( $fixtures ) - 1 ) ]['date'];

            $player    = $squad[ mt_rand( 0, count( $squad ) - 1 ) ];
            $player_id = (int) ( ( (array) $player )['id'] ?? 0 );
            if ( $player_id <= 0 ) continue;

            $id = $repo->create( [
                'player_id'        => $player_id,
                'team_id'          => $team_id,
                'started_on'       => $start,
                'match_count'      => mt_rand( 1, 3 ),
                'reason_lookup_id' => $reasons !== [] ? $reasons[ mt_rand( 0, count( $reasons ) - 1 ) ] : 0,
                'notes'            => $notes[ mt_rand( 0, count( $notes ) - 1 ) ],
            ] );

            if ( $id > 0 ) {
                $this->registry->tag( 'player_suspension', $id, [ 'player_id' => $player_id, 'team_id' => $team_id ] );
                $total++;
            }
        }

        return $total;
    }

    /** @return list<object> */
    private function playersOf( int $team_id ): array {
        $out = [];
        foreach ( $this->players as $p ) {
            if ( (int) ( ( (array) $p )['team_id'] ?? 0 ) === $team_id ) $out[] = $p;
        }
        return $out;
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
