<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\Players\Services\PlayerPhoto;

/**
 * PlayerReport (#3872, epic #3871) — one player over one window, as blocks.
 *
 * Not a second assembly. Every number comes from `EvidencePacket`, the one the
 * PDP Evidence tab, the printed PDP file and the verdict screen already read
 * (#3304), so a coach and a head of academy looking at the same player on the
 * same day see the same figures whichever surface they opened. This class
 * only selects, orders and shapes.
 *
 * The packet is built at most once per call, and not at all when the selection
 * needs nothing from it (the letterhead and the blank notes area).
 *
 * @phpstan-type Report array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>, audience:string}
 */
final class PlayerReport {

    /** Blocks that read nothing from the packet. */
    private const WITHOUT_PACKET = [ PlayerReportBlock::LETTERHEAD, PlayerReportBlock::NOTES ];

    /**
     * Compose the report for one reader.
     *
     * @param list<string> $blocks Selected block keys; empty means the
     *        audience's default. Unknown keys throw — a typo must not quietly
     *        produce a report missing a section nobody asked to remove.
     * @param string|null  $audience Null resolves it from the viewer, which is
     *        what every reader-facing path must do (#3876). Only a surface
     *        composing *on behalf of* someone else — a coach emailing a scout,
     *        or sharing a report with the family (#3955) — names it.
     * @param array<string,array<string,mixed>> $options #3989 per-block option
     *        bags, as `PlayerReportComposition` normalises them. Forgiving:
     *        anything a block does not recognise is ignored here; a strict
     *        caller refuses it first with `PlayerReportComposition::unknownOptions()`.
     * @return Report|null Null for a player outside the current club.
     * @throws \InvalidArgumentException on unknown block keys or a malformed window.
     */
    public function forPlayer( int $player_id, string $from, string $to, array $blocks, int $viewer_user_id, ?string $audience = null, array $options = [] ): ?array {
        $unknown = PlayerReportBlock::unknown( $blocks );
        if ( $unknown !== [] ) {
            throw new \InvalidArgumentException( 'Unknown block keys: ' . implode( ', ', $unknown ) );
        }
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            throw new \InvalidArgumentException( 'from and to must be Y-m-d dates with from <= to.' );
        }

        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player || (int) ( $player->club_id ?? CurrentClub::id() ) !== (int) CurrentClub::id() ) {
            return null;
        }

        $audience = $audience !== null && PlayerReportAudience::isValid( $audience )
            ? $audience
            : PlayerReportAudience::forReader( $viewer_user_id );

        $allowed  = PlayerReportAudience::blocksFor( $audience, $blocks );
        $selected = PlayerReportBlock::normalise( $allowed !== [] || $blocks === [] ? $allowed : [ PlayerReportBlock::LETTERHEAD ] );

        $packet = null;
        if ( array_diff( $selected, self::WITHOUT_PACKET ) !== [] ) {
            // An external audience reads tests at the public level whoever
            // composed it: a coach sending a scout or a family the report must
            // not hand over a test the academy keeps to its staff.
            $tests_viewer = PlayerReportAudience::isExternal( $audience ) ? 0 : $viewer_user_id;
            $packet = EvidencePacket::forPlayer( $player_id, $from, $to, $viewer_user_id, $tests_viewer );
            if ( $packet === null ) return null;
        }

        $data = [];
        foreach ( $selected as $block ) {
            if ( $block === PlayerReportBlock::LETTERHEAD ) {
                $data[ $block ] = $this->letterhead( $player, $from, $to );
            } elseif ( $block === PlayerReportBlock::MINUTES ) {
                $data[ $block ] = self::minutes( (array) ( ( $packet ?? [] )['minutes'] ?? [] ) ) + self::minutesShare( $player, $from, $to );
            } elseif ( $block === PlayerReportBlock::TALKING_POINTS ) {
                $data[ $block ] = [ 'items' => PlayerTalkingPoints::derive( $packet ?? [], (int) ( $player->team_id ?? 0 ), $from, $to ) ];
            } else {
                $data[ $block ] = self::block( $block, $packet ?? [], PlayerReportComposition::optionsFor( $options, $block ) );
            }
        }

        return PlayerReportAudience::apply( [
            'player_id' => $player_id,
            'from'      => $from,
            'to'        => $to,
            'blocks'    => $selected,
            'data'      => $data,
        ], $audience ) + [ 'audience' => $audience ];
    }

    /**
     * @param array<string,mixed> $packet
     * @param array<string,mixed> $options this block's option bag
     * @return array<string,mixed>
     */
    private static function block( string $block, array $packet, array $options = [] ): array {
        switch ( $block ) {
            case PlayerReportBlock::STATUS:         return (array) ( $packet['status'] ?? [] );
            case PlayerReportBlock::RATINGS:        return self::ratings( (array) ( $packet['evaluations'] ?? [] ), RatingsBlockOptions::detail( $options ) );
            case PlayerReportBlock::ATTENDANCE:     return (array) ( $packet['attendance'] ?? [] );
            case PlayerReportBlock::GOALS:          return [ 'items' => array_values( (array) ( $packet['goals'] ?? [] ) ) ];
            case PlayerReportBlock::PDP:            return (array) ( $packet['pdp'] ?? [] );
            case PlayerReportBlock::NOTES:          return [ 'lines' => 6 ];
            case PlayerReportBlock::MATCHES:        return [ 'items' => array_values( (array) ( $packet['minutes']['breakdown'] ?? [] ) ) ];
            case PlayerReportBlock::TESTS:          return [ 'items' => array_values( (array) ( $packet['tests'] ?? [] ) ) ];
            case PlayerReportBlock::JOURNEY:        return [ 'items' => self::journey( (array) ( $packet['recent_journey'] ?? [] ) ) ];
            case PlayerReportBlock::INJURIES:       return [ 'items' => array_values( (array) ( $packet['injuries'] ?? [] ) ) ];
            case PlayerReportBlock::BEHAVIOUR:      return [ 'items' => self::rows( (array) ( $packet['behaviour'] ?? [] ) ) ];
            case PlayerReportBlock::POTENTIAL:      return [ 'items' => self::rows( (array) ( $packet['potential'] ?? [] ) ) ];
            case PlayerReportBlock::THREAD_NOTES:   return [ 'items' => array_values( (array) ( $packet['notes'] ?? [] ) ) ];
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function letterhead( object $player, string $from, string $to ): array {
        $team_id = (int) ( $player->team_id ?? 0 );
        $team    = $team_id > 0 ? QueryHelpers::get_team( $team_id ) : null;
        $dob     = (string) ( $player->date_of_birth ?? '' );
        $jersey  = $player->jersey_number ?? null;

        return [
            'player_id'     => (int) ( $player->id ?? 0 ),
            'name'          => QueryHelpers::player_display_name( $player ),
            'photo_url'     => PlayerPhoto::url( $player ),
            'team_id'       => $team_id,
            'team_name'     => $team ? (string) ( $team->name ?? '' ) : '',
            'age_group'     => $team ? (string) ( $team->age_group ?? '' ) : '',
            'head_coach'    => $team_id > 0 ? ( new EvalCoverageService() )->headCoachNameForTeam( $team_id ) : '',
            'jersey_number' => $jersey !== null && $jersey !== '' ? (int) $jersey : null,
            'birth_year'    => $dob !== '' ? (int) substr( $dob, 0, 4 ) : null,
            'from'          => $from,
            'to'            => $to,
            'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * Headline numbers and a per-category picture over the window, from the
     * packet's evaluations — the same rows the Evidence tab lists, so the
     * averages here cannot disagree with the evaluations underneath them.
     *
     * #3989 — `has_subcategories` says whether anything in the window was
     * rated at subcategory level, whatever `$detail` is, so the panel knows
     * whether to offer the detailed view. With `$detail = 'sub'` (and
     * something to show), each main category carries `subcategories`: label,
     * latest, average and count, in the order the category tree gives them.
     * A main category rated only through its subcategories is listed too,
     * with no score of its own, so its subcategories have somewhere to sit.
     *
     * @param array<int|string,mixed> $evaluations newest first, as the packet orders them
     * @param array<int,array{label:string, order:int}>|null $tree category id =>
     *        its label and its place in the category tree; null reads it from
     *        the categories table, and only when subcategories are shown.
     * @return array<string,mixed>
     */
    public static function ratings( array $evaluations, string $detail = RatingsBlockOptions::MAIN, ?array $tree = null ): array {
        $overall = [];
        $by_cat  = [];
        $by_sub  = [];
        foreach ( $evaluations as $eval ) {
            if ( ! is_array( $eval ) ) continue;
            if ( isset( $eval['rating'] ) ) {
                $overall[] = [ 'date' => (string) ( $eval['eval_date'] ?? '' ), 'value' => (float) $eval['rating'] ];
            }
            foreach ( (array) ( $eval['categories'] ?? [] ) as $cat ) {
                if ( ! is_array( $cat ) ) continue;
                $id = (int) ( $cat['category_id'] ?? 0 );
                if ( $id <= 0 ) continue;

                if ( empty( $cat['is_main'] ) ) {
                    $parent = (int) ( $cat['parent_id'] ?? 0 );
                    if ( $parent <= 0 ) continue;
                    if ( ! isset( $by_sub[ $parent ][ $id ] ) ) {
                        $by_sub[ $parent ][ $id ] = [ 'category_id' => $id, 'label' => (string) ( $cat['label'] ?? '' ), 'latest' => (float) ( $cat['rating'] ?? 0 ), 'values' => [] ];
                    }
                    $by_sub[ $parent ][ $id ]['values'][] = (float) ( $cat['rating'] ?? 0 );
                    continue;
                }

                if ( ! isset( $by_cat[ $id ] ) ) {
                    // The first seen is the newest: the packet is newest-first.
                    $by_cat[ $id ] = [ 'category_id' => $id, 'label' => (string) ( $cat['label'] ?? '' ), 'latest' => (float) ( $cat['rating'] ?? 0 ), 'values' => [] ];
                }
                $by_cat[ $id ]['values'][] = (float) ( $cat['rating'] ?? 0 );
            }
        }

        $has_subs = $by_sub !== [];
        $detailed = $has_subs && $detail === RatingsBlockOptions::SUB;

        $categories = [];
        foreach ( $by_cat as $cat ) {
            $categories[] = [
                'category_id' => $cat['category_id'],
                'label'       => $cat['label'],
                'latest'      => $cat['latest'],
                'average'     => round( array_sum( $cat['values'] ) / count( $cat['values'] ), 1 ),
                'count'       => count( $cat['values'] ),
            ];
        }

        if ( $detailed ) {
            $tree = $tree ?? self::categoryTree();

            // A main category rated only through its subcategories.
            $mains = array_column( $categories, 'category_id' );
            $extra = array_values( array_diff( array_keys( $by_sub ), $mains ) );
            usort( $extra, static fn( int $a, int $b ): int => ( $tree[ $a ]['order'] ?? PHP_INT_MAX ) <=> ( $tree[ $b ]['order'] ?? PHP_INT_MAX ) ?: $a <=> $b );
            foreach ( $extra as $parent ) {
                $categories[] = [
                    'category_id' => $parent,
                    'label'       => $tree[ $parent ]['label'] ?? '',
                    'latest'      => null,
                    'average'     => null,
                    'count'       => 0,
                ];
            }

            foreach ( $categories as $i => $cat ) {
                $subs = array_values( $by_sub[ $cat['category_id'] ] ?? [] );
                // Tree order; a subcategory the tree does not know keeps the
                // order it was first seen in, after the ones it does.
                $seen = array_flip( array_column( $subs, 'category_id' ) );
                usort( $subs, static function ( array $a, array $b ) use ( $tree, $seen ): int {
                    $oa = $tree[ $a['category_id'] ]['order'] ?? PHP_INT_MAX;
                    $ob = $tree[ $b['category_id'] ]['order'] ?? PHP_INT_MAX;
                    return $oa <=> $ob ?: $seen[ $a['category_id'] ] <=> $seen[ $b['category_id'] ];
                } );
                $categories[ $i ]['subcategories'] = array_map( static fn( array $s ): array => [
                    'category_id' => $s['category_id'],
                    'label'       => $s['label'],
                    'latest'      => $s['latest'],
                    'average'     => round( array_sum( $s['values'] ) / count( $s['values'] ), 1 ),
                    'count'       => count( $s['values'] ),
                ], $subs );
            }
        }

        $values = array_column( $overall, 'value' );

        return [
            'evaluation_count'  => count( $evaluations ),
            'latest'            => $overall[0]['value'] ?? null,
            'latest_date'       => $overall[0]['date'] ?? null,
            'average'           => $values !== [] ? round( array_sum( $values ) / count( $values ), 1 ) : null,
            // Oldest first, so a trend line reads left to right.
            'series'            => array_reverse( $overall ),
            'categories'        => $categories,
            'evaluations'       => array_values( $evaluations ),
            'has_subcategories' => $has_subs,
            'detail'            => $detailed ? RatingsBlockOptions::SUB : RatingsBlockOptions::MAIN,
        ];
    }

    /**
     * Every category's label and place in the tree: main categories in their
     * order, each followed by its subcategories in theirs. Inactive ones are
     * included, because an evaluation from before a category was retired is
     * still evidence.
     *
     * @return array<int,array{label:string, order:int}>
     */
    private static function categoryTree(): array {
        $repo  = new \TT\Infrastructure\Evaluations\EvalCategoriesRepository();
        $rows  = $repo->getAll( false );
        $mains = [];
        $subs  = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_object( $row ) ) continue;
            if ( empty( $row->parent_id ) ) {
                $mains[] = $row;
            } else {
                $subs[ (int) ( $row->parent_id ?? 0 ) ][] = $row;
            }
        }

        $tree  = [];
        $order = 0;
        foreach ( $mains as $main ) {
            $id          = (int) ( $main->id ?? 0 );
            $tree[ $id ] = [ 'label' => \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $main->label ?? '' ), $id ), 'order' => $order++ ];
            foreach ( $subs[ $id ] ?? [] as $sub ) {
                $sid          = (int) ( $sub->id ?? 0 );
                $tree[ $sid ] = [ 'label' => \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $sub->label ?? '' ), $sid ), 'order' => $order++ ];
            }
        }
        return $tree;
    }

    /**
     * @param array<string,mixed> $minutes
     * @return array<string,mixed>
     */
    private static function minutes( array $minutes ): array {
        $breakdown = (array) ( $minutes['breakdown'] ?? [] );
        return [
            'apps'    => (int) ( $minutes['apps'] ?? 0 ),
            'minutes' => (int) ( $minutes['minutes'] ?? 0 ),
            'matches' => count( $breakdown ),
        ];
    }

    /**
     * #3991 — playing time as a share of the minutes available, against the
     * position group and the team. Read from `MinutesQuery::forTeam()` for the
     * player's current team — the call the team minutes report makes — so the
     * numbers agree with it for the same window. No new query beyond the
     * roster, which says who the team is today.
     *
     * The comparison is staff-only: `PlayerReportAudience::apply()` strips it
     * for a family or a scout, who keep the player's own share.
     *
     * @return array{share:?int, comparison:?array{team:int, position:?array{positions:list<string>, count:int, share:int}, player_positions:list<string>}}
     */
    private static function minutesShare( object $player, string $from, string $to ): array {
        $none    = [ 'share' => null, 'comparison' => null ];
        $team_id = (int) ( $player->team_id ?? 0 );
        if ( $team_id <= 0 ) return $none;

        $rows      = ( new MinutesQuery() )->forTeam( $team_id, $from, $to );
        $available = $rows !== [] ? (int) $rows[0]['available_minutes'] : 0;
        if ( $available <= 0 ) return $none;

        $played = [];
        foreach ( $rows as $row ) {
            $played[ (int) $row['player_id'] ] = (int) $row['total_minutes'];
        }

        global $wpdb;
        $roster_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.preferred_positions
               FROM {$wpdb->prefix}tt_players p
              WHERE p.team_id = %d
                AND p.club_id = %d
                AND p.status = 'active'
                AND " . \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active', 'p' ),
            $team_id, CurrentClub::id()
        ) );
        $roster = [];
        foreach ( (array) $roster_rows as $r ) {
            $roster[ (int) $r->id ] = self::positionCodes( (string) ( $r->preferred_positions ?? '' ) );
        }
        $player_id = (int) ( $player->id ?? 0 );
        if ( ! isset( $roster[ $player_id ] ) ) {
            $roster[ $player_id ] = self::positionCodes( (string) ( $player->preferred_positions ?? '' ) );
        }

        return self::shareComparison( $player_id, $roster, $played, $available );
    }

    /**
     * The arithmetic of #3991, apart from the database so a fixture squad can
     * pin it.
     *
     * - share: the player's minutes over the minutes available, whole percent.
     * - team: the mean share of the roster, the player included, each player
     *   once; a roster player who did not play counts as 0.
     * - position: the mean share of roster players who share at least one
     *   profile position with the player, the player excluded, with those
     *   positions and the group's size. Null when the player has no profile
     *   position or nobody shares one.
     *
     * @param array<int,list<string>> $roster    player id => profile position codes
     * @param array<int,int>          $played    player id => minutes in the window
     * @return array{share:?int, comparison:?array{team:int, position:?array{positions:list<string>, count:int, share:int}, player_positions:list<string>}}
     */
    public static function shareComparison( int $player_id, array $roster, array $played, int $available ): array {
        if ( $available <= 0 || $roster === [] ) return [ 'share' => null, 'comparison' => null ];

        $fraction = static fn( int $pid ): float => min( 1.0, ( $played[ $pid ] ?? 0 ) / $available );

        $team = 0.0;
        foreach ( array_keys( $roster ) as $pid ) {
            $team += $fraction( (int) $pid );
        }
        $team /= count( $roster );

        $mine     = $roster[ $player_id ] ?? [];
        $position = null;
        if ( $mine !== [] ) {
            $group  = [];
            $shared = [];
            foreach ( $roster as $pid => $codes ) {
                if ( (int) $pid === $player_id ) continue;
                $common = array_values( array_intersect( $mine, $codes ) );
                if ( $common === [] ) continue;
                $group[] = (int) $pid;
                $shared  = array_merge( $shared, $common );
            }
            if ( $group !== [] ) {
                $sum = 0.0;
                foreach ( $group as $pid ) $sum += $fraction( $pid );
                $position = [
                    // The player's own positions that someone in the group
                    // shares, in the player's order.
                    'positions' => array_values( array_intersect( $mine, array_unique( $shared ) ) ),
                    'count'     => count( $group ),
                    'share'     => (int) round( $sum / count( $group ) * 100 ),
                ];
            }
        }

        return [
            'share'      => (int) round( $fraction( $player_id ) * 100 ),
            'comparison' => [
                'team'             => (int) round( $team * 100 ),
                'position'         => $position,
                'player_positions' => $mine,
            ],
        ];
    }

    /**
     * #3991 — the comparison as the PDF prints it, a line each, so the printed
     * lines and the layout estimate that counts them cannot differ. Numbers
     * only: the player's own share stands in the figures right above, and a
     * half-width cell beside attendance has no room for a sentence.
     *
     * @param array<string,mixed> $m the minutes block
     * @return list<string> plain text
     */
    public static function minutesComparisonLines( array $m ): array {
        $share = $m['share'] ?? null;
        $cmp   = is_array( $m['comparison'] ?? null ) ? $m['comparison'] : null;
        if ( ! is_int( $share ) || $cmp === null ) return [];

        $out      = [];
        $position = is_array( $cmp['position'] ?? null ) ? $cmp['position'] : null;
        if ( $position !== null ) {
            $out[] = self::positionGroupLabel( $position ) . ': ' . self::percent( (int) ( $position['share'] ?? 0 ) );
        } elseif ( ( $cmp['player_positions'] ?? [] ) === [] ) {
            $out[] = __( 'No profile position to compare with.', 'talenttrack' );
        }
        $out[] = __( 'Team average', 'talenttrack' ) . ': ' . self::percent( (int) ( $cmp['team'] ?? 0 ) );
        return $out;
    }

    /** A whole percentage as the report prints it. Shared by screen and PDF. */
    public static function percent( int $value ): string {
        /* translators: %d: a whole percentage, e.g. 62 */
        return sprintf( __( '%d%%', 'talenttrack' ), $value );
    }

    /**
     * "Same position · CB, RB · 4 players" — the position group's label.
     * Shared by screen and PDF.
     *
     * @param array<string,mixed> $position a `comparison.position`
     */
    public static function positionGroupLabel( array $position ): string {
        $codes = array_map( 'strval', is_array( $position['positions'] ?? null ) ? $position['positions'] : [] );
        $count = (int) ( $position['count'] ?? 0 );
        return sprintf(
            /* translators: 1: position codes, e.g. "CB, RB"; 2: number of teammates in the group */
            _n( 'Same position · %1$s · %2$d player', 'Same position · %1$s · %2$d players', $count, 'talenttrack' ),
            implode( ', ', $codes ),
            $count
        );
    }

    /**
     * Where the player's share sits against a comparison, in words. Shared
     * by screen and PDF.
     */
    public static function relativeShare( int $player_share, int $other ): string {
        $diff = $player_share - $other;
        if ( $diff > 0 ) {
            /* translators: %d: percentage points */
            return sprintf( _n( 'this player %d point above', 'this player %d points above', $diff, 'talenttrack' ), $diff );
        }
        if ( $diff < 0 ) {
            /* translators: %d: percentage points */
            return sprintf( _n( 'this player %d point below', 'this player %d points below', -$diff, 'talenttrack' ), -$diff );
        }
        return __( 'this player level with it', 'talenttrack' );
    }

    /**
     * A stored `preferred_positions` value as its position codes: a JSON
     * array on modern rows, a comma-separated string on legacy ones.
     *
     * @return list<string>
     */
    private static function positionCodes( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        $codes   = is_array( $decoded ) ? $decoded : explode( ',', $raw );
        $out     = [];
        foreach ( $codes as $code ) {
            $code = is_scalar( $code ) ? strtoupper( trim( (string) $code ) ) : '';
            if ( $code !== '' && ! in_array( $code, $out, true ) ) $out[] = $code;
        }
        return $out;
    }

    /**
     * @param array<int|string,mixed> $events
     * @return list<array<string,mixed>>
     */
    private static function journey( array $events ): array {
        $out = [];
        foreach ( $events as $e ) {
            if ( ! is_object( $e ) ) continue;
            $out[] = [
                'id'         => (int) ( $e->id ?? 0 ),
                'event_type' => (string) ( $e->event_type ?? '' ),
                'date'       => substr( (string) ( $e->event_date ?? '' ), 0, 10 ),
                'summary'    => (string) ( $e->summary ?? '' ),
                'activity'   => isset( $e->activity ) && is_array( $e->activity ) ? $e->activity : null,
            ];
        }
        return $out;
    }

    /**
     * A journey entry as the PDF prints it: the entry, then what it was about.
     * One method so the printed line and the layout estimate that measures it
     * cannot differ.
     *
     * @param array<string,mixed> $item a journey item.
     */
    public static function journeyPrintText( array $item ): string {
        $text     = (string) ( $item['summary'] ?? '' );
        $activity = is_array( $item['activity'] ?? null ) ? $item['activity'] : null;
        if ( $activity === null ) return $text;
        $label = self::activityLabel( $activity );
        return $label === '' ? $text : $text . ' — ' . $label;
    }

    /**
     * An activity in one line, the way a coach names it: its type, who it was
     * against (or its title when there was no opponent), and the day. Shared by
     * the screen and the PDF so they name the same match the same way.
     *
     * @param array<string,mixed> $activity a journey item's `activity`.
     */
    public static function activityLabel( array $activity ): string {
        $opponent = trim( (string) ( $activity['opponent'] ?? '' ) );
        $what     = $opponent !== ''
            /* translators: %s: the opponent of a match */
            ? sprintf( __( 'against %s', 'talenttrack' ), $opponent )
            : trim( (string) ( $activity['title'] ?? '' ) );
        $parts = array_filter( [
            trim( (string) ( $activity['type'] ?? '' ) ),
            $what,
            (string) ( $activity['date'] ?? '' ) !== '' ? \TT\Shared\Dates\TTDate::date( (string) $activity['date'] ) : '',
        ], static fn( string $s ): bool => $s !== '' );
        return implode( ' · ', $parts );
    }

    /**
     * Repository rows as plain arrays, so the payload is the same JSON whether
     * it is read by REST or a view.
     *
     * @param array<int|string,mixed> $rows
     * @return list<array<string,mixed>>
     */
    private static function rows( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            if ( is_object( $row ) ) $out[] = get_object_vars( $row );
            elseif ( is_array( $row ) ) $out[] = $row;
        }
        return $out;
    }

    private static function isDate( string $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
    }
}
