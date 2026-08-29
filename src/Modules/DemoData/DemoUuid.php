<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoUuid (#3102) — a uuid for a generated row, drawn from outside the
 * seeded random stream.
 *
 * ## Why this exists
 *
 * Demo generation is reproducible on purpose: `DemoGenerator::seedStep()`
 * calls `mt_srand()` before every step, so the same preset and the same seed
 * produce the same dataset whether the run happens in one request or thirty.
 * That contract is what `DemoRunChunkingTest` asserts.
 *
 * Uuids were being drawn from that same stream — every generator's private
 * `uuid()` helper called `wp_generate_uuid4()`, which is eight `mt_rand()`
 * draws, and `TeamBlueprintsRepository` open-coded the same thing. So a
 * second run into the same install restarted the stream from an identical
 * state and re-minted **byte-for-byte the same uuid**, which then collided
 * with the `uk_uuid` unique key the first run had already filled:
 *
 *     WordPress database error Duplicate entry '…' for key 'uk_uuid'
 *
 * Reproducibility is a property of the *dataset* — how many rows, about which
 * players, saying what. It was never meant to extend to identities. A uuid
 * that is reproducible is a uuid that collides, which is the opposite of what
 * the column is for.
 *
 * `random_int()` draws from the CSPRNG, which `mt_srand()` does not reach, so
 * every run mints fresh identities while everything the test compares stays
 * fixed.
 */
final class DemoUuid {

    /**
     * A version-4 uuid, unaffected by the demo seed.
     */
    public static function mint(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int( 0, 0xffff ), random_int( 0, 0xffff ),
            random_int( 0, 0xffff ),
            random_int( 0, 0x0fff ) | 0x4000,
            random_int( 0, 0x3fff ) | 0x8000,
            random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
        );
    }
}
