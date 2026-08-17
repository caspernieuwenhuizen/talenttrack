<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Reports\AudienceType;

/**
 * PlayerReportGenerator — writes tt_player_reports.
 *
 * A few generated reports per team across the window, spread over the
 * audiences an academy actually produces. Rows carry no share token and no
 * recipient: a demo report is something to look at, not something that hands
 * out a working public link or addresses a real mailbox.
 */
class PlayerReportGenerator implements DependentGeneratorInterface {

    /** Audiences a demo run produces, weighted towards the everyday ones. */
    private const AUDIENCES = [
        AudienceType::STANDARD,
        AudienceType::STANDARD,
        AudienceType::PARENT_MONTHLY,
        AudienceType::PARENT_MONTHLY,
        AudienceType::INTERNAL_DETAILED,
        AudienceType::PLAYER_PERSONAL,
    ];

    /** @var array<string, array{title:string, intro:string, section:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'title'   => 'Player report',
            'intro'   => 'Summary of the last reporting period.',
            'section' => 'Development highlights',
        ],
        'nl_NL' => [
            'title'   => 'Spelersrapport',
            'intro'   => 'Samenvatting van de afgelopen rapportageperiode.',
            'section' => 'Ontwikkelpunten',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'reports';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->users, $ctx->weeks(), $ctx->contentLanguage );
    }

    /**
     * @param object[] $players
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

        $author = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        $copy   = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];

        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();
        $window_days = max( 1, (int) floor( ( time() - $window_start ) / DAY_IN_SECONDS ) );

        $total = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;
            // Roughly one player in four has a report on file.
            if ( mt_rand( 1, 100 ) > 25 ) continue;

            $audience   = self::AUDIENCES[ mt_rand( 0, count( self::AUDIENCES ) - 1 ) ];
            $created_ts = $window_start + ( mt_rand( 0, $window_days ) * DAY_IN_SECONDS );
            $name       = trim( (string) ( $p->first_name ?? '' ) . ' ' . (string) ( $p->last_name ?? '' ) );

            $config = [
                'audience' => $audience,
                'scope'    => 'last_12_weeks',
                'sections' => [ 'evaluations', 'goals', 'attendance' ],
                'source'   => 'demo',
            ];

            $html = sprintf(
                '<article class="tt-report"><h1>%s</h1><p>%s</p><h2>%s</h2><p>%s</p></article>',
                esc_html( $copy['title'] . ' — ' . $name ),
                esc_html( $copy['intro'] ),
                esc_html( $copy['section'] ),
                esc_html( $this->highlight( $copy ) )
            );

            $wpdb->insert( "{$wpdb->prefix}tt_player_reports", [
                'club_id'         => CurrentClub::id(),
                'player_id'       => $player_id,
                'generated_by'    => $author,
                'audience'        => $audience,
                'config_json'     => (string) wp_json_encode( $config ),
                'rendered_html'   => $html,
                'access_token'    => null,
                'scout_user_id'   => null,
                'recipient_email' => null,
                'cover_message'   => null,
                'created_at'      => gmdate( 'Y-m-d H:i:s', $created_ts ),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'player_report', $id, [ 'player_id' => $player_id, 'audience' => $audience ] );
                $total++;
            }
        }
        return $total;
    }

    /** @param array{title:string, intro:string, section:string} $copy */
    private function highlight( array $copy ): string {
        return $copy['section'] . ': ' . $copy['intro'];
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
