<?php
namespace TT\Modules\Exercises;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Exercises\Vision\ClaudeSonnetProvider;
use TT\Modules\Exercises\Vision\GeminiProProvider;
use TT\Modules\Exercises\Vision\OpenAiProvider;
use TT\Modules\Exercises\Vision\VisionProviderInterface;

/**
 * ExercisesModule (#0016 Sprint 1) — owns the exercise library
 * (`tt_exercises`, `tt_exercise_categories`, `tt_exercise_principles`,
 * `tt_exercise_team_overrides`) and the vision-provider registry
 * that Sprints 3-4 consume for photo-to-session capture.
 *
 * Sprint 1 ships:
 *   - The four schema tables (migration 0088).
 *   - `ExercisesRepository` — fetch + create + edit-as-new-version + archive.
 *   - `VisionProviderInterface` + three stub adapters (Claude Sonnet,
 *     Gemini Pro, OpenAI). All three throw `RuntimeException` from
 *     `extractSessionFromImage()` until Sprint 4 lands.
 *   - `tt_manage_exercises` capability (granted to administrator,
 *     tt_club_admin, tt_head_dev, tt_coach).
 *   - `tt_vision_provider` filter — call sites resolve the active
 *     provider via `ExercisesModule::resolveProvider()`.
 *
 * Not yet:
 *   - Frontend admin CRUD UI for exercises (Sprint 1 deferred to a
 *     follow-up; the Repository is ready to consume).
 *   - Seeded 15-20 sample exercises (calendar-time copywriting).
 *   - Photo capture UI (Sprint 3).
 *   - Actual provider extraction logic (Sprint 4 + shootout).
 *   - Session-to-exercise linkage table (Sprint 2).
 *   - Provider shootout against real photos (calendar-time, requires
 *     coach-supplied photo set).
 *   - DPIA documentation template (calendar-time, legal review).
 */
class ExercisesModule implements ModuleInterface {

    public function getName(): string { return 'exercises'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
    }

    /**
     * `tt_manage_exercises` — granted to the four roles that author /
     * curate the exercise library. Coaches need it because they
     * create custom drills; head-of-development + club admin need
     * it for cross-club library curation.
     */
    public static function ensureCapabilities(): void {
        $roles = [
            'administrator' => [ 'tt_manage_exercises' ],
            'tt_club_admin' => [ 'tt_manage_exercises' ],
            'tt_head_dev'   => [ 'tt_manage_exercises' ],
            'tt_coach'      => [ 'tt_manage_exercises' ],
        ];
        foreach ( $roles as $role_key => $caps ) {
            $role = get_role( $role_key );
            if ( ! $role ) continue;
            foreach ( $caps as $cap ) {
                if ( ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
            }
        }
    }

    /**
     * Resolve the active vision provider for the current request.
     *
     * Resolution order:
     *   1. The `tt_vision_provider` filter — plugins / per-club
     *      overrides hook here to swap providers per request.
     *   2. The `TT_VISION_PROVIDER` wp-config constant.
     *   3. Default: `claude_sonnet` (subject to change post-shootout
     *      in Sprint 4).
     *
     * Returns null when the chosen provider isn't registered or
     * isn't configured (no API key on this install). Sprint 4
     * callers fall back to manual entry on null.
     */
    public static function resolveProvider(): ?VisionProviderInterface {
        $registry = self::providers();
        $key      = 'claude_sonnet';
        if ( defined( 'TT_VISION_PROVIDER' ) ) {
            $constant_key = (string) constant( 'TT_VISION_PROVIDER' );
            if ( $constant_key !== '' ) $key = $constant_key;
        }
        $key = (string) apply_filters( 'tt_vision_provider', $key );

        if ( ! isset( $registry[ $key ] ) ) return null;
        $provider = $registry[ $key ];
        if ( ! $provider->isConfigured() ) return null;
        return $provider;
    }

    /**
     * The full provider registry. Sprint 1 ships three stubs; Sprint 4
     * promotes the shootout winner's adapter from stub to real.
     *
     * @return array<string, VisionProviderInterface>
     */
    public static function providers(): array {
        static $registry = null;
        if ( $registry === null ) {
            $registry = [
                'claude_sonnet' => new ClaudeSonnetProvider(),
                'gemini_pro'    => new GeminiProProvider(),
                'openai'        => new OpenAiProvider(),
            ];
        }
        return $registry;
    }
}
