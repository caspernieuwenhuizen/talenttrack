<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * InjuryGenerator — writes tt_player_injuries through InjuryRepository.
 *
 * Goes through the repository rather than inserting rows, so the journey
 * events and the recovery-due workflow hook fire exactly as they do for a
 * real injury. A demo timeline that skipped those would look different from
 * a production one on the surface that matters most.
 *
 * ~15% of a squad carries a healed injury somewhere in the window and ~5% an
 * open one. Duration follows severity, and every date stays inside the
 * generated window so the timeline reads in order.
 */
class InjuryGenerator implements DependentGeneratorInterface {

    /** severity lookup name => [min days out, max days out]. */
    private const RECOVERY_DAYS = [
        'minor'         => [ 5, 14 ],
        'moderate'      => [ 15, 42 ],
        'serious'       => [ 43, 120 ],
        'season_ending' => [ 121, 240 ],
    ];

    /** Weighted severity draw — most youth injuries are minor. */
    private const SEVERITY_WEIGHTS = [
        [ 55, 'minor' ],
        [ 85, 'moderate' ],
        [ 97, 'serious' ],
        [ 100, 'season_ending' ],
    ];

    /** @var array<string, string[]> */
    private const NOTES_BY_LANGUAGE = [
        'en_US' => [
            'Picked up in training; physio assessment booked.',
            'Felt it during a match, substituted as a precaution.',
            'Gradual onset — load managed down for now.',
            'Landed awkwardly. Iced and monitored.',
            'Reported soreness after the session; held out as a precaution.',
        ],
        'nl_NL' => [
            'Opgelopen tijdens de training; afspraak met de fysio staat.',
            'Voelde het tijdens de wedstrijd, uit voorzorg gewisseld.',
            'Geleidelijk ontstaan — belasting voorlopig omlaag.',
            'Verkeerd neergekomen. Gekoeld en in de gaten gehouden.',
            'Meldde pijn na de training; uit voorzorg eruit gehouden.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'injuries';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->weeks(), $ctx->contentLanguage );
    }

    /** @param object[] $players */
    public function __construct( DemoBatchRegistry $registry, array $players, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        $types      = $this->lookupIds( 'injury_type' );
        $body_parts = $this->lookupIds( 'body_part' );
        $severities = $this->lookupIds( 'injury_severity' );
        if ( ! $severities ) return 0;

        $repo  = new InjuryRepository();
        $notes = self::NOTES_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();
        $window_days = max( 1, (int) floor( ( time() - $window_start ) / DAY_IN_SECONDS ) );

        $total = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $roll = mt_rand( 1, 100 );
            if ( $roll > 20 ) continue;          // 80% of the squad is untouched
            $open = $roll <= 5;                   // 5% are currently out

            $severity_name = $this->pickSeverity();
            $severity_id   = $severities[ $severity_name ] ?? 0;
            [ $min_days, $max_days ] = self::RECOVERY_DAYS[ $severity_name ];
            $out_days = mt_rand( $min_days, $max_days );

            if ( $open ) {
                // Started recently enough that the player is still out.
                $days_ago   = mt_rand( 1, max( 1, min( $out_days - 1, $window_days ) ) );
                $started_ts = time() - ( $days_ago * DAY_IN_SECONDS );
                $actual     = null;
            } else {
                // Healed: the whole episode has to fit inside the window.
                $latest_start = max( 1, $window_days - $out_days );
                $days_ago     = mt_rand( $out_days, max( $out_days, $latest_start + $out_days ) );
                $started_ts   = time() - ( $days_ago * DAY_IN_SECONDS );
                if ( $started_ts < $window_start ) $started_ts = $window_start;
                $actual = gmdate( 'Y-m-d', $started_ts + ( $out_days * DAY_IN_SECONDS ) );
            }

            $injury_id = $repo->create( [
                'player_id'             => $player_id,
                'started_on'            => gmdate( 'Y-m-d', $started_ts ),
                'expected_return'       => gmdate( 'Y-m-d', $started_ts + ( $out_days * DAY_IN_SECONDS ) ),
                'actual_return'         => $actual,
                'injury_type_lookup_id' => $types ? (int) $types[ array_rand( $types ) ] : null,
                'body_part_lookup_id'   => $body_parts ? (int) $body_parts[ array_rand( $body_parts ) ] : null,
                'severity_lookup_id'    => $severity_id > 0 ? $severity_id : null,
                'notes'                 => $notes[ mt_rand( 0, count( $notes ) - 1 ) ],
            ] );

            if ( $injury_id > 0 ) {
                $this->registry->tag( 'player_injury', $injury_id, [
                    'player_id' => $player_id,
                    'severity'  => $severity_name,
                    'open'      => $open ? 1 : 0,
                ] );
                $total++;
            }
        }

        return $total;
    }

    /**
     * Dates a player is unavailable, for match-prep availability (#2465) to
     * cross-reference. Keyed by player id, each entry a [start, end] pair of
     * Y-m-d strings; an open injury has a null end.
     *
     * @return array<int, array<int, array{0:string, 1:?string}>>
     */
    public static function unavailabilityByPlayer(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.player_id, i.started_on, i.actual_return
               FROM {$wpdb->prefix}tt_player_injuries i
               JOIN {$wpdb->prefix}tt_demo_tags d
                 ON d.entity_type = 'player_injury' AND d.entity_id = i.id AND d.club_id = %d
              WHERE i.club_id = %d",
            CurrentClub::id(), CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->player_id ][] = [
                (string) $r->started_on,
                $r->actual_return !== null ? (string) $r->actual_return : null,
            ];
        }
        return $out;
    }

    private function pickSeverity(): string {
        $roll = mt_rand( 1, 100 );
        foreach ( self::SEVERITY_WEIGHTS as [ $cut, $name ] ) {
            if ( $roll <= $cut ) return $name;
        }
        return 'minor';
    }

    /** @return array<string,int> lookup name => id */
    private function lookupIds( string $type ): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( $type ) as $item ) {
            $out[ (string) $item->name ] = (int) $item->id;
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
