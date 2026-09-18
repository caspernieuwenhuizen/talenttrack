<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * TeamReportSnapshotGenerator (#3539) — writes `tt_team_report_snapshots`.
 *
 * A snapshot is a monthly report frozen for a staff meeting, plus the notes
 * the meeting wrote on it. It is one of the few places the product's *record
 * of a conversation* is visible, which is why this table is generated rather
 * than exempt like its neighbour `tt_scheduled_reports` — a demo snapshot
 * sends nothing and dispatches nothing.
 *
 * **Composed through the real report path, never hand-written JSON.**
 * `data_json` is a whole rendered report, and a hand-written payload would
 * drift from the real shape the moment a block changed — a demo that quietly
 * stopped matching production is worse than no demo. So this calls
 * `TeamMonthlyReport::forTeam()` exactly as the screen does and stores what
 * comes back.
 *
 * That is also why it runs late: the composed payload is empty without the
 * activities, attendance and evaluations the earlier generators write. It is
 * appended at the end of the run order rather than inserted, so every
 * generator before it draws the same values from the seeded stream and the
 * same (seed, preset) keeps reproducing the same academy (#3242).
 */
class TeamReportSnapshotGenerator implements DependentGeneratorInterface {

    /**
     * Section notes, keyed by the block they hang under.
     *
     * Two of them, not one per section: a meeting annotates what it discussed,
     * and a snapshot with a note on every block would look like a form somebody
     * was made to fill in rather than a record of a conversation.
     *
     * @var array<string, array<string,string>>
     */
    private const NOTES_BY_LANGUAGE = [
        'en_US' => [
            TeamMonthlyReportBlock::ATTENDANCE => 'Two weeks of exam timetables explain most of the dip. Agreed to leave it and look again next month.',
            TeamMonthlyReportBlock::ATTENTION  => 'Spoke to both players and their parents this week. Review at the next staff meeting.',
        ],
        'nl_NL' => [
            TeamMonthlyReportBlock::ATTENDANCE => 'Twee weken toetsweek verklaren het grootste deel van de dip. Afgesproken om het te laten en volgende maand opnieuw te kijken.',
            TeamMonthlyReportBlock::ATTENTION  => 'Deze week beide spelers en hun ouders gesproken. Volgend stafoverleg opnieuw bekijken.',
        ],
    ];

    /** @var array<string, string> */
    private const TITLE_BY_LANGUAGE = [
        'en_US' => 'Staff meeting %s',
        'nl_NL' => 'Stafoverleg %s',
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private string $language;

    public static function category(): string {
        return 'report_snapshots';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->users, $ctx->contentLanguage );
    }

    /**
     * @param object[]          $teams
     * @param array<string,int> $users
     */
    public function __construct( DemoBatchRegistry $registry, array $teams, array $users, string $language = '' ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        $author = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );
        if ( $author <= 0 ) return 0;

        $team_id = (int) ( ( $this->teams[0] ?? null )->id ?? 0 );
        if ( $team_id <= 0 ) return 0;

        [ $from, $to ] = self::lastFullMonth();

        $language = self::resolveLanguage( $this->language );
        $repo     = new TeamReportSnapshotRepository();
        $composer = new TeamMonthlyReport();

        // One team, one month. A season of frozen reports across every squad
        // would be megabytes of JSON demonstrating exactly what one row
        // demonstrates.
        $report = $composer->forTeam( $team_id, $from, $to, [], $author );

        $composition = [
            'blocks'  => $report['blocks'],
            'options' => [],
            'layout'  => 'B',
        ];

        $month = strtotime( $from );
        $title = sprintf(
            self::TITLE_BY_LANGUAGE[ $language ],
            gmdate( 'F Y', $month !== false ? $month : time() )
        );

        $uuid = $repo->create( $team_id, $composition, $report, $title, $author );
        if ( $uuid === '' ) return 0;

        foreach ( self::NOTES_BY_LANGUAGE[ $language ] as $section => $body ) {
            // Only sections this snapshot actually carries — a note under a
            // block the composition left out would never be rendered.
            if ( ! in_array( $section, $report['blocks'], true ) ) continue;
            $repo->putNote( $uuid, $section, $body, $author );
        }

        // The registry tags numeric ids — that is what the cleaner deletes by,
        // through `DemoCoverage::tableMap()`. The repository hands back a uuid,
        // so the row is read once to get its primary key; without this the
        // snapshot would survive a demo wipe.
        $row = $repo->find( $uuid );
        $id  = (int) ( $row->id ?? 0 );
        if ( $id <= 0 ) return 0;

        $this->registry->tag( 'team_report_snapshot', $id, [ 'team_id' => $team_id ] );

        return 1;
    }

    /**
     * The last complete calendar month, which is the window a monthly report is
     * written about. Deliberately not "the last 30 days": a snapshot named
     * "Staff meeting August" that covered part of September would be a demo of
     * the wrong thing.
     *
     * @return array{0:string, 1:string}
     */
    private static function lastFullMonth(): array {
        // A day inside the previous month: the first of this one, minus a day.
        $in_last = (int) gmmktime( 0, 0, 0, (int) gmdate( 'n' ), 0, (int) gmdate( 'Y' ) );

        return [ gmdate( 'Y-m-01', $in_last ), gmdate( 'Y-m-t', $in_last ) ];
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
