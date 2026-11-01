<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Shared\Frontend\Components\CrossViewLinkRegistry;

/**
 * #4497 — a line of the "Follow-ups due this week" section below the list:
 * a count with a link to the view it comes from. A line carries a value
 * only when its count is above 0 and the viewer may open that view, so a
 * client never offers a link that would be refused.
 */
abstract class FollowUpBlock extends AbstractWeekBlock {

    /**
     * Persona => [ desktop, phone ] when the academy has not chosen. Any
     * other persona gets the line only once the academy switches it on.
     *
     * @return array<string, array{0: bool, 1: bool}>
     */
    abstract protected function defaults(): array;

    public function group(): string { return WeekBlockGroup::FOLLOWUPS; }

    public function defaultFor( string $persona, string $device ): bool {
        $on = $this->defaults()[ $persona ] ?? [ false, false ];
        return $device === 'phone' ? $on[1] : $on[0];
    }

    /** Keeps the older persona list's full-desktop fallback from handing the line to other roles. */
    public function optInFor( string $persona ): bool {
        return ! isset( $this->defaults()[ $persona ] );
    }

    /**
     * The view is switched on and its cross-view gate admits this user: the
     * check the dispatcher and every cross-view link ask, for the week's
     * viewer rather than whoever happens to be logged in.
     */
    protected static function surfaceOpen( string $slug, int $user_id ): bool {
        return $user_id > 0 && CrossViewLinkRegistry::allows( $slug, $user_id );
    }
}
