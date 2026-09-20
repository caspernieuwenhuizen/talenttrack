<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;

/**
 * Everything a dependent generator needs, assembled once by the
 * orchestrator after master data exists. Without this, each new wave's
 * generator would grow its own six-argument constructor and the
 * orchestrator would need to know all of them.
 */
class GeneratorContext {

    public DemoBatchRegistry $registry;

    /** @var array<string,int> persona slug => WP user id */
    public array $users;

    /** @var object[] tt_people rows */
    public array $persons;

    /** @var object[] tt_teams rows */
    public array $teams;

    /** @var object[] tt_players rows — the current, active roster */
    public array $players;

    /**
     * Players who left the academy inside the generated window (#3402).
     *
     * They are not on any current roster, so they are deliberately absent
     * from `$players`: nothing should give a departed player an injury next
     * week or a place in this season's squad. The generators that write
     * history — attendance, evaluations, test results, the PDP dossier that
     * ends in their release — read `historicPlayers()` instead.
     *
     * @var object[] tt_players rows
     */
    public array $formerPlayers;

    /** @var array{teams:int, players_per_team:int, weeks:int} */
    public array $preset;

    /** Locale the generated content is written in, e.g. `nl_NL`. */
    public string $contentLanguage;

    /**
     * The instant this run calls "now" (#3775). Carried from `DemoRunState`,
     * because a context is rebuilt per chunk and the calendar must not move
     * underneath a run that spans requests.
     */
    public int $now;

    private ?DemoCalendar $calendar = null;

    private ?DemoRoster $roster = null;

    /**
     * @param array<string,int>  $users
     * @param object[]           $persons
     * @param object[]           $teams
     * @param object[]           $players
     * @param array{teams:int, players_per_team:int, weeks:int} $preset
     * @param object[]           $formerPlayers
     * @param ?int               $now      the run's pinned clock; the wall clock when absent
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $users,
        array $persons,
        array $teams,
        array $players,
        array $preset,
        string $contentLanguage,
        array $formerPlayers = [],
        ?int $now = null
    ) {
        $this->registry        = $registry;
        $this->users           = $users;
        $this->persons         = $persons;
        $this->teams           = $teams;
        $this->players         = $players;
        $this->preset          = $preset;
        $this->contentLanguage = $contentLanguage;
        $this->formerPlayers   = $formerPlayers;
        $this->now             = $now ?? time();
    }

    public function weeks(): int {
        return (int) ( $this->preset['weeks'] ?? 0 );
    }

    public function playersPerTeam(): int {
        return (int) ( $this->preset['players_per_team'] ?? 0 );
    }

    /**
     * Everyone the window covers — the current roster plus the players who
     * left inside it.
     *
     * @return object[]
     */
    public function historicPlayers(): array {
        return array_merge( $this->players, $this->formerPlayers );
    }

    /**
     * The run's seasons, round dates and fixture slots. Built once, against
     * the run's pinned clock rather than the wall clock of whichever request
     * happens to be executing this chunk (#3775).
     */
    public function calendar(): DemoCalendar {
        if ( $this->calendar === null ) {
            $this->calendar = new DemoCalendar( $this->weeks(), $this->now );
        }
        return $this->calendar;
    }

    /** Which team each player was in, season by season. Built once. */
    public function roster(): DemoRoster {
        if ( $this->roster === null ) {
            $this->roster = new DemoRoster( $this->calendar(), $this->teams, $this->historicPlayers() );
        }
        return $this->roster;
    }
}
