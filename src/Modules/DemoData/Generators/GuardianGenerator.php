<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * GuardianGenerator — writes tt_player_parents + tt_player_parent_visibility.
 *
 * A guardian link needs a WP user (`parent_user_id`), and the demo user set
 * ships exactly one parent persona. Minting an account per player would mean
 * a dozen welcome emails per run, so instead each available parent account is
 * given a small, plausible family — one to three children — and the rest of
 * the roster is left without a linked guardian, which is what a real academy
 * looks like anyway: not every parent has registered.
 *
 * That is enough for the parent persona to log in and see a populated
 * dashboard, which is the point of the category.
 *
 * Visibility is deliberately uneven. Most families see the everyday sections
 * and not the development plan; a few see everything. A demo where every
 * player has identical grants can't show that the permission gate does
 * anything.
 */
class GuardianGenerator implements DependentGeneratorInterface {

    /** Sections a guardian sees by default. */
    private const OPEN_BY_DEFAULT = [ 'evaluations', 'goals', 'journey' ];

    /** Sections held back unless the club opens them. */
    private const RESTRICTED = [ 'measurements', 'pdp' ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var array<string,int> */
    private array $users;

    public static function category(): string {
        return 'guardians';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->players, $ctx->users );
    }

    /**
     * @param object[] $players
     * @param array<string,int> $users slot => WP user id
     */
    public function __construct( DemoBatchRegistry $registry, array $players, array $users ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->users    = $users;
    }

    public function generate(): int {
        global $wpdb;

        $parent_ids = $this->parentUserIds();
        if ( ! $parent_ids ) return 0;

        $visibility = new PlayerParentVisibilityRepository();
        $table      = $wpdb->prefix . 'tt_player_parents';
        $total      = 0;

        // Walk the roster once, handing each parent account a family before
        // moving to the next. Players past the last family keep no guardian.
        $roster = $this->players;
        $cursor = 0;

        foreach ( $parent_ids as $parent_user_id ) {
            if ( $cursor >= count( $roster ) ) break;

            $children = min( mt_rand( 1, 3 ), count( $roster ) - $cursor );
            for ( $i = 0; $i < $children; $i++ ) {
                $p         = $roster[ $cursor++ ];
                $player_id = (int) ( $p->id ?? 0 );
                if ( $player_id <= 0 ) continue;

                // PK is (player_id, parent_user_id); INSERT IGNORE keeps a
                // re-run from erroring on a pair that already exists.
                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (player_id, parent_user_id, is_primary, club_id)
                     VALUES (%d, %d, 1, %d)",
                    $player_id, (int) $parent_user_id, CurrentClub::id()
                ) );
                if ( ! $ok ) continue;
                $total++;

                // No surrogate id to tag — the wipe reaches this table via
                // the tagged player id (DemoCoverage::TABLE_QUIRKS).
                $this->registry->tag( 'player_parent', $player_id, [ 'parent_user_id' => (int) $parent_user_id ] );

                $generous = mt_rand( 1, 100 ) <= 20;
                foreach ( self::OPEN_BY_DEFAULT as $section ) {
                    $visibility->setVisibility( $player_id, $section, true );
                }
                foreach ( self::RESTRICTED as $section ) {
                    $visibility->setVisibility( $player_id, $section, $generous );
                }

                foreach ( $this->visibilityRowIds( $player_id ) as $row_id ) {
                    $this->registry->tag( 'player_parent_visibility', $row_id );
                }
            }
        }

        return $total;
    }

    /**
     * WP user ids of the demo parent personas. Falls back to any user with
     * the parent role so a club that generated its people set earlier still
     * gets guardians.
     *
     * @return int[]
     */
    private function parentUserIds(): array {
        $ids = [];
        foreach ( $this->users as $slot => $user_id ) {
            if ( strpos( (string) $slot, 'parent' ) === 0 && (int) $user_id > 0 ) {
                $ids[] = (int) $user_id;
            }
        }
        if ( $ids ) return array_values( array_unique( $ids ) );

        $fallback = get_users( [ 'role' => 'tt_parent', 'fields' => 'ID', 'number' => 12 ] );
        foreach ( (array) $fallback as $id ) {
            $ids[] = (int) $id;
        }
        return array_values( array_unique( $ids ) );
    }

    /** @return int[] */
    private function visibilityRowIds( int $player_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT v.id FROM {$wpdb->prefix}tt_player_parent_visibility v
              LEFT JOIN {$wpdb->prefix}tt_demo_tags d
                     ON d.entity_type = 'player_parent_visibility' AND d.entity_id = v.id AND d.club_id = %d
              WHERE v.player_id = %d AND v.club_id = %d AND d.id IS NULL",
            CurrentClub::id(), $player_id, CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $rows );
    }
}
