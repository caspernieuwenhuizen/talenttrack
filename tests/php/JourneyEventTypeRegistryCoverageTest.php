<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Journey\EventTypeRegistry;

/**
 * #3470 — a journey event type that exists in code and not in `tt_lookups`
 * has no name, no filter entry, no operator-editable visibility and no
 * payload validation, and nothing says so.
 *
 * `goal_set` was in that state from #0053 until #3470: declared on
 * `JourneyEventType`, given a payload schema by #3131, emitted by
 * `GoalsRepository`, backfilled by `JourneyBackfillService` — and never
 * seeded. `EventTypeRegistry::all()` reads the lookup table and nothing else,
 * so `find()` returned null and `FrontendJourneyView` printed the raw machine
 * key on the player's own timeline.
 *
 * `JourneyEventType` is a closed list, so the assertion is cheap: every
 * constant on it must resolve through the registry on a freshly-migrated
 * install. That is what makes the next unseeded type a red build rather than
 * a `goal_set` on somebody's journey.
 */
final class JourneyEventTypeRegistryCoverageTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // all() memoises; the seed migration ran in bootstrap, but an earlier
        // test may have primed the cache before its own inserts.
        EventTypeRegistry::clearCache();
    }

    /** @return array<string, array{0:string}> */
    public static function journeyEventTypes(): array {
        $out = [];
        foreach ( ( new \ReflectionClass( JourneyEventType::class ) )->getConstants() as $const => $value ) {
            if ( ! is_string( $value ) || $value === '' ) continue;
            $out[ $value ] = [ $value ];
        }
        return $out;
    }

    /**
     * @dataProvider journeyEventTypes
     */
    public function test_every_declared_type_has_a_lookup_row( string $type ): void {
        $def = EventTypeRegistry::find( $type );

        $this->assertNotNull(
            $def,
            "JourneyEventType '{$type}' has no tt_lookups row, so the journey will print the raw key, "
            . 'the type is missing from the filter list, its visibility is not operator-editable and '
            . 'its payload schema is never enforced. Seed it in a migration.'
        );
        $this->assertNotSame(
            $type,
            $def->label ?? $type,
            "JourneyEventType '{$type}' resolves to a row whose description is the machine key."
        );
    }

    /** The one this issue was about, pinned by name. */
    public function test_goal_set_resolves(): void {
        $def = EventTypeRegistry::find( JourneyEventType::GOAL_SET );

        $this->assertNotNull( $def, 'goal_set must resolve after migration 0265.' );
        $this->assertSame(
            'public',
            EventTypeRegistry::defaultVisibilityFor( JourneyEventType::GOAL_SET ),
            'goal_set visibility must come from the seeded row, not the unknown-type fallback.'
        );
    }
}
