<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\Export\Format\Renderers\PdfRenderer;

/**
 * PlayerReportDelivery (#3891, epic #3871) — a scheduled player report, from
 * the schedule row to the PDF that is mailed.
 *
 * Two targets. **A team** — the head of development's monthly round: one
 * report per player in the squad, each on its own pages, in one PDF. **A
 * player** — one report, for a player under particular watch.
 *
 * The contract is `TeamMonthlyReportDelivery`'s: the schedule renders its own
 * copy of the composition (never a saved view), the window resolves at run
 * time, and the run renders **as the person who set it up**. It pauses rather
 * than sends when the team or player is gone, or the owner can no longer read
 * it, because the document names minors and goes out unattended.
 *
 * On a squad, one player the owner can no longer read is **left out and
 * named**, not a reason to stop the round — and never left out silently, which
 * would hand someone a pack quietly missing a child.
 */
final class PlayerReportDelivery {

    /**
     * Most players one round will render. Measured: a one-pager renders in well
     * under a second on DomPDF, so the cap is about the size of an email
     * attachment, not render time. Refused above it rather than truncated.
     */
    public const MAX_BATCH = 30;

    /**
     * Everything about a run except the PDF bytes.
     *
     * @param array<string,mixed> $schedule a hydrated `ScheduledReportsRepository` row.
     * @return array{ok:bool, stop:bool, error:string, note:string, composition:array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}, window:array{from:string, to:string, period:string}, players:list<int>, dropped:list<string>, owner:int, filename:string, label:string}
     */
    public static function plan( array $schedule, string $today ): array {
        $raw         = is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [];
        $team_id     = isset( $raw['team_id'] ) && is_numeric( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        $composition = PlayerReportComposition::normalise( $raw );
        $window      = PlayerReportComposition::window( $composition, $today );
        $owner       = (int) ( $schedule['created_by'] ?? 0 );

        $plan = [
            'ok'          => false,
            'stop'        => true,
            'error'       => '',
            'note'        => '',
            'composition' => $composition,
            'window'      => $window,
            'players'     => [],
            'dropped'     => [],
            'owner'       => $owner,
            'filename'    => '',
            'label'       => '',
        ];

        if ( $team_id > 0 ) {
            return self::planTeam( $plan, $team_id );
        }
        return self::planPlayer( $plan, $composition['player_id'] );
    }

    /**
     * @param array{ok:bool, stop:bool, error:string, note:string, composition:array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}, window:array{from:string, to:string, period:string}, players:list<int>, dropped:list<string>, owner:int, filename:string, label:string} $plan
     * @return array{ok:bool, stop:bool, error:string, note:string, composition:array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}, window:array{from:string, to:string, period:string}, players:list<int>, dropped:list<string>, owner:int, filename:string, label:string}
     */
    private static function planTeam( array $plan, int $team_id ): array {
        $team = QueryHelpers::get_team( $team_id );
        if ( $team === null ) {
            $plan['error'] = __( 'Stopped: the team in this schedule no longer exists.', 'talenttrack' );
            return $plan;
        }
        if ( ! empty( $team->archived_at ) ) {
            $plan['error'] = __( 'Stopped: the team in this schedule has been archived.', 'talenttrack' );
            return $plan;
        }
        if ( ! TeamReportAccess::canRead( $plan['owner'], $team_id ) ) {
            $plan['error'] = __( 'Stopped: the person who set up this schedule can no longer read this team’s reports.', 'talenttrack' );
            return $plan;
        }

        $squad = self::inShirtOrder( QueryHelpers::get_players( $team_id ) );
        if ( count( $squad ) > self::MAX_BATCH ) {
            $plan['error'] = sprintf(
                /* translators: 1: players in the squad, 2: the most one scheduled round prints */
                __( 'Stopped: this squad has %1$d players, and one scheduled round prints at most %2$d. Schedule the players you need individually instead.', 'talenttrack' ),
                count( $squad ),
                self::MAX_BATCH
            );
            return $plan;
        }

        foreach ( $squad as $player ) {
            $pid = (int) ( $player->id ?? 0 );
            if ( PlayerReportAccess::canRead( $plan['owner'], $pid ) ) {
                $plan['players'][] = $pid;
            } else {
                $plan['dropped'][] = QueryHelpers::player_display_name( $player );
            }
        }

        // Nothing to print is a quiet month, not a broken schedule: record it
        // and try again next time rather than pausing.
        if ( $plan['players'] === [] ) {
            $plan['stop']  = false;
            $plan['error'] = __( 'Not sent: there is no player in this squad whose report the schedule’s owner can read.', 'talenttrack' );
            return $plan;
        }

        $team_name = (string) ( $team->name ?? '' );
        $plan['ok']       = true;
        $plan['stop']     = false;
        $plan['filename'] = self::filename( ( $team_name !== '' ? $team_name : 'team-' . $team_id ) . '-player-reports', $plan['window']['to'] );
        $plan['label']    = sprintf(
            /* translators: 1: team name, 2: number of players */
            _n( 'Player report %1$s, %2$d player', 'Player reports %1$s, %2$d players', count( $plan['players'] ), 'talenttrack' ),
            $team_name,
            count( $plan['players'] )
        );
        if ( $plan['dropped'] !== [] ) {
            $plan['note'] = sprintf(
                /* translators: %s: comma-separated player names */
                __( 'Sent without %s: the schedule’s owner can no longer read their reports.', 'talenttrack' ),
                implode( ', ', $plan['dropped'] )
            );
        }
        return $plan;
    }

    /**
     * @param array{ok:bool, stop:bool, error:string, note:string, composition:array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}, window:array{from:string, to:string, period:string}, players:list<int>, dropped:list<string>, owner:int, filename:string, label:string} $plan
     * @return array{ok:bool, stop:bool, error:string, note:string, composition:array{player_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}, window:array{from:string, to:string, period:string}, players:list<int>, dropped:list<string>, owner:int, filename:string, label:string}
     */
    private static function planPlayer( array $plan, int $player_id ): array {
        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        if ( $player === null || (int) ( $player->club_id ?? 0 ) !== (int) CurrentClub::id() ) {
            $plan['error'] = __( 'Stopped: the player in this schedule no longer exists.', 'talenttrack' );
            return $plan;
        }
        if ( ! empty( $player->archived_at ) ) {
            $plan['error'] = __( 'Stopped: the player in this schedule has been archived.', 'talenttrack' );
            return $plan;
        }
        if ( ! PlayerReportAccess::canRead( $plan['owner'], $player_id ) ) {
            $plan['error'] = __( 'Stopped: the person who set up this schedule can no longer read this player’s report.', 'talenttrack' );
            return $plan;
        }

        $name = QueryHelpers::player_display_name( $player );
        $plan['ok']       = true;
        $plan['stop']     = false;
        $plan['players']  = [ $player_id ];
        $plan['filename'] = self::filename( $name, $plan['window']['to'] );
        /* translators: %s: player name */
        $plan['label']    = sprintf( __( 'Player report %s', 'talenttrack' ), $name );
        return $plan;
    }

    /**
     * The plan plus the PDF.
     *
     * @param array<string,mixed> $schedule
     * @return array{ok:bool, stop:bool, error:string, note:string, bytes:string, filename:string, label:string}
     */
    public static function render( array $schedule, string $today ): array {
        $plan = self::plan( $schedule, $today );
        $out  = [ 'ok' => false, 'stop' => $plan['stop'], 'error' => $plan['error'], 'note' => $plan['note'], 'bytes' => '', 'filename' => $plan['filename'], 'label' => $plan['label'] ];
        if ( ! $plan['ok'] ) return $out;

        $c = $plan['composition'];

        // The report is composed as its owner would see it, thread notes
        // included, which read the current user. Restored whatever happens.
        $previous = get_current_user_id();
        wp_set_current_user( $plan['owner'] );
        try {
            $reports = [];
            foreach ( $plan['players'] as $player_id ) {
                $report = ( new PlayerReport() )->forPlayer( $player_id, $plan['window']['from'], $plan['window']['to'], $c['blocks'], $plan['owner'], null, $c['options'] );
                if ( $report === null ) continue;
                $fit             = PlayerReportLayout::fit( $report, $c['layout'] );
                $report['data']  = PlayerReportLayout::degrade( $report, $fit['degraded'] )['data'];
                $reports[]       = $report;
            }

            $request = new ExportRequest( 'player_report_pdf', 'pdf', CurrentClub::id(), $plan['owner'], null, [
                'from'   => $plan['window']['from'],
                'to'     => $plan['window']['to'],
                'layout' => $c['layout'],
                'blocks' => $c['blocks'],
            ] );
            $bytes = ( new PdfRenderer() )->render( $request, [
                'html'    => PlayerReportPdfDocument::batchHtml( $reports ),
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ] )->bytes;
        } catch ( \Throwable $e ) {
            $out['stop']  = false;
            $out['error'] = __( 'The PDF could not be created this time. The schedule will try again next time.', 'talenttrack' );
            \TT\Infrastructure\Logging\Logger::error( 'Player report schedule: render failed.', [
                'schedule_id' => (int) ( $schedule['id'] ?? 0 ),
                'error'       => $e->getMessage(),
            ] );
            return $out;
        } finally {
            wp_set_current_user( $previous );
        }

        if ( $bytes === '' ) {
            $out['stop']  = false;
            $out['error'] = __( 'The PDF could not be created this time. The schedule will try again next time.', 'talenttrack' );
            return $out;
        }

        $out['ok']    = true;
        $out['bytes'] = $bytes;
        return $out;
    }

    /** `JO13-1-player-reports-2026-09-30.pdf`: what, whose and as of when. */
    public static function filename( string $subject, string $as_of ): string {
        $stem = sanitize_file_name( str_replace( ' ', '-', trim( $subject ) ) );
        if ( $stem === '' ) $stem = 'player-report';
        return $stem . '-' . $as_of . '.pdf';
    }

    /**
     * Shirt number first, then name — the order every player list in the
     * team report reads in.
     *
     * @param array<int,object> $players
     * @return list<object>
     */
    private static function inShirtOrder( array $players ): array {
        $players = array_values( $players );
        usort( $players, static function ( object $a, object $b ): int {
            $ja = $a->jersey_number ?? null;
            $jb = $b->jersey_number ?? null;
            $ka = $ja === null || $ja === '' ? PHP_INT_MAX : (int) $ja;
            $kb = $jb === null || $jb === '' ? PHP_INT_MAX : (int) $jb;
            if ( $ka !== $kb ) return $ka <=> $kb;
            return strcasecmp( QueryHelpers::player_display_name( $a ), QueryHelpers::player_display_name( $b ) );
        } );
        return $players;
    }
}
