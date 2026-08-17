<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * TestTrainingGenerator — writes tt_test_trainings, the sessions a club runs
 * for prospects and trialists.
 *
 * One per age group across the window, so the scouting side of the calendar
 * has something on it even before the pipeline wave adds the prospects that
 * attend them.
 */
class TestTrainingGenerator implements DependentGeneratorInterface {

    /** @var array<string, array{location:string, notes:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'location' => 'Main pitch',
            'notes'    => 'Open session for invited players. Two coaches observing.',
        ],
        'nl_NL' => [
            'location' => 'Hoofdveld',
            'notes'    => 'Open training voor uitgenodigde spelers. Twee trainers kijken mee.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'test_trainings';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[] $teams
     * @param array<string,int> $users
     */
    public function __construct( DemoBatchRegistry $registry, array $teams, array $users, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $copy       = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $age_groups = $this->ageGroupLookupIds();
        $author     = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $scout      = (int) ( $this->users['scout'] ?? $author );

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        $total = 0;
        foreach ( $this->teams as $index => $team ) {
            $group_name = isset( $team->age_group ) ? (string) $team->age_group : '';
            $lookup_id  = (int) ( $age_groups[ $group_name ] ?? 0 );

            // One in the past, one ahead, so both states show on the calendar.
            $offsets = [ 0.4, 1.15 ];
            foreach ( $offsets as $fraction ) {
                $when = $window_start + (int) ( $this->weeks * $fraction * WEEK_IN_SECONDS );

                $wpdb->insert( "{$wpdb->prefix}tt_test_trainings", [
                    'club_id'             => CurrentClub::id(),
                    'uuid'                => self::uuid(),
                    'date'                => gmdate( 'Y-m-d H:i:s', $when ),
                    'location'            => $copy['location'],
                    'age_group_lookup_id' => $lookup_id > 0 ? $lookup_id : null,
                    'coach_user_id'       => (int) ( $team->head_coach_user_id ?? $scout ),
                    'notes'               => $copy['notes'],
                    'created_by'          => $author,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'test_training', $id, [ 'age_group' => $group_name ] );
                    $total++;
                }
            }
        }
        return $total;
    }

    /** @return array<string,int> age group name => lookup id */
    private function ageGroupLookupIds(): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( 'age_group' ) as $item ) {
            $out[ (string) $item->name ] = (int) $item->id;
        }
        return $out;
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
        if ( isset( self::COPY_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::COPY_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
