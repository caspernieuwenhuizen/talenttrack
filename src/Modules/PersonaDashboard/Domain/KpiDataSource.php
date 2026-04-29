<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KpiDataSource — every KPI in the catalog implements this.
 *
 * `id()` is the slug used inside widget refs (`kpi_card:active_players_total`).
 * `label()` is the human-readable picker entry (already translated).
 * `context()` filters the editor's KPI picker by persona (academy /
 * coach / player_parent). `compute()` returns the live value scoped to
 * the viewing user + their club; KPIs whose backing epic hasn't
 * shipped should return KpiValue::unavailable().
 */
interface KpiDataSource {

    public function id(): string;

    public function label(): string;

    /** One of PersonaContext::ACADEMY | COACH | PLAYER_PARENT. */
    public function context(): string;

    public function compute( int $user_id, int $club_id ): KpiValue;
}
