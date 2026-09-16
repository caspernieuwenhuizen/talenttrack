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
 * ships two parent personas. Minting an account per player would mean a dozen
 * welcome emails per run, so instead each available parent account is given a
 * small, plausible family and the rest of the roster is left without a linked
 * guardian, which is what a real academy looks like anyway: not every parent
 * has registered.
 *
 * That is enough for the parent persona to log in and see a populated
 * dashboard, which is the point of the category.
 *
 * #3475 — the family sizes are a fixed plan, not `mt_rand`. The multi-child
 * guardian is the fragile path in this persona (the child picker #1991 and the
 * dashboard switcher #1992, and the #2804 bug where the picker stood in front
 * of all thirteen of a parent's destinations), and a die roll against a single
 * parent account left it absent from two generated academies in three — both
 * untestable and undemoable. Randomness in *which* players get a guardian is
 * realistic; randomness in whether a code path exists is not, and cuts against
 * the run-order-as-reproducibility contract from #2461.
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

        foreach ( array_values( $parent_ids ) as $index => $parent_user_id ) {
            if ( $cursor >= count( $roster ) ) break;

            $children = $this->familySize( $index, count( $roster ) - $cursor );
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
     * #3475 — how many children the Nth parent account gets.
     *
     * A fixed cycle rather than a roll: index 0 is the single-child
     * straight-through case, index 1 the multi-child case the picker and the
     * switcher need, and anything beyond keeps varying so a large people set
     * still looks like an academy. Clamped to what is left of the roster.
     */
    private function familySize( int $index, int $remaining ): int {
        $plan = [ 1, 2, 3 ];
        $size = $plan[ $index % count( $plan ) ];
        return max( 1, min( $size, $remaining ) );
    }

    /**
     * WP user ids of the demo parent personas, slot order first so the family
     * plan above lands on the same account every run. Falls back to any user
     * with the parent role so a club that generated its people set earlier
     * still gets guardians.
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

        // #3475 — ordered by id so the family plan lands on the same account
        // on every run of this path too; get_users() makes no order promise.
        $fallback = get_users( [
            'role'    => 'tt_parent',
            'fields'  => 'ID',
            'number'  => 12,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );
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
