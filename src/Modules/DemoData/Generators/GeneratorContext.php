<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\DemoBatchRegistry;

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

    /** @var object[] tt_players rows */
    public array $players;

    /** @var array{teams:int, players_per_team:int, weeks:int} */
    public array $preset;

    /** Locale the generated content is written in, e.g. `nl_NL`. */
    public string $contentLanguage;

    /**
     * @param array<string,int>  $users
     * @param object[]           $persons
     * @param object[]           $teams
     * @param object[]           $players
     * @param array{teams:int, players_per_team:int, weeks:int} $preset
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $users,
        array $persons,
        array $teams,
        array $players,
        array $preset,
        string $contentLanguage
    ) {
        $this->registry        = $registry;
        $this->users           = $users;
        $this->persons         = $persons;
        $this->teams           = $teams;
        $this->players         = $players;
        $this->preset          = $preset;
        $this->contentLanguage = $contentLanguage;
    }

    public function weeks(): int {
        return (int) ( $this->preset['weeks'] ?? 0 );
    }

    public function playersPerTeam(): int {
        return (int) ( $this->preset['players_per_team'] ?? 0 );
    }
}
