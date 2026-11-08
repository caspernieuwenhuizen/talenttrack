<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMatchRecord;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MatchesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Results (#4572) — the team monthly report's match section, read through
 * its composer: every fixture in the period (tournaments left out, as the
 * report leaves them out) with the date, home or away, the opponent and the
 * score, and the record over them. The record is `TeamMatchRecord`'s, the
 * one the statistics tab reads, so "Played" is the same figure on both.
 * Staff only, like the report.
 */
final class MatchResultsBlock implements ReportBlock {

    public function key(): string {
        return 'match_results';
    }

    public function label(): string {
        return _x( 'Results', 'report studio block: match results', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_TEAM;
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $section = SharedReads::remember(
            'team_matches_section',
            $context,
            static fn(): array => MatchesComposer::compose( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )
        );

        $home = _x( 'H', 'home match, one letter', 'talenttrack' );
        $away = _x( 'A', 'away match, one letter', 'talenttrack' );
        $rows = [];
        foreach ( (array) ( $section['fixtures'] ?? [] ) as $f ) {
            if ( ! is_array( $f ) ) continue;
            $where    = (string) ( $f['home_away'] ?? '' );
            $opponent = (string) ( $f['opponent'] ?? '' );
            $scored   = ( $f['team_score'] ?? null ) !== null && ( $f['opp_score'] ?? null ) !== null;
            $rows[]   = [
                'match_date' => (string) ( $f['date'] ?? '' ),
                'venue'      => $where === 'away' ? $away : ( $where !== '' ? $home : '' ),
                'opponent'   => $opponent !== '' ? $opponent : __( 'Unknown opponent', 'talenttrack' ),
                'score'      => $scored ? (int) $f['team_score'] . '–' . (int) $f['opp_score'] : __( 'no score recorded', 'talenttrack' ),
            ];
        }

        return [
            'breakdown'            => 'match',
            'rows'                 => $rows,
            'record'               => is_array( $section['record'] ?? null ) ? $section['record'] : [],
            'tournaments_excluded' => (int) ( $section['tournaments_excluded'] ?? 0 ),
            'note'                 => TeamMatchRecord::tournamentsNote( (int) ( $section['tournaments_excluded'] ?? 0 ) ),
        ];
    }
}
