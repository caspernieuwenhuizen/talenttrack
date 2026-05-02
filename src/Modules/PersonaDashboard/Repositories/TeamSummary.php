<?php
namespace TT\Modules\PersonaDashboard\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamSummary — one team's headline numbers for the HoD landing grid.
 *
 * `avg_rating` and `attendance_pct` are nullable: a team with no
 * evaluations / no activities in the window has no defensible number,
 * so the widget renders an em-dash rather than `0`.
 */
final class TeamSummary {

    public int $team_id;
    public string $name;
    public string $age_group;
    public ?string $head_coach_name;
    public ?float $avg_rating;
    public ?float $attendance_pct;
    public int $player_count;
    public int $players_below_status;

    public function __construct(
        int $team_id,
        string $name,
        string $age_group = '',
        ?string $head_coach_name = null,
        ?float $avg_rating = null,
        ?float $attendance_pct = null,
        int $player_count = 0,
        int $players_below_status = 0
    ) {
        $this->team_id              = $team_id;
        $this->name                 = $name;
        $this->age_group            = $age_group;
        $this->head_coach_name      = $head_coach_name;
        $this->avg_rating           = $avg_rating;
        $this->attendance_pct       = $attendance_pct;
        $this->player_count         = $player_count;
        $this->players_below_status = $players_below_status;
    }
}
