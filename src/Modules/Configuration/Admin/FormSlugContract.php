<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;

/**
 * FormSlugContract — the single source of truth for native form
 * field slugs per entity type.
 *
 * Sprint 1H (v2.11.0). Powers two things:
 *
 *   1. The "Insert after" dropdown on the Custom Fields admin page:
 *      populated from the slug list for the currently-selected entity.
 *
 *   2. The render-time slot contract used by each module's edit
 *      page: the page calls CustomFieldsSlot::render($entity, $id, $slug)
 *      at the position of each slug listed here, so custom fields
 *      with insert_after=$slug appear inline with the native form.
 *
 * Each entity advertises an ORDERED map of [ slug => label ]. The
 * order matters — it's what a user sees in the "Insert after"
 * dropdown and determines the visual flow of the form.
 *
 * If a module's edit form adds or renames a native field, update
 * this contract at the same time. Custom fields that reference a
 * removed slug fall through to "(at end)" by default via the
 * getActiveGroupedByInsertAfter() layer — the admin page surfaces
 * such orphans with "(missing)" next to the slug in the list.
 */
class FormSlugContract {

    /**
     * @return array<string, string> slug => label
     */
    public static function slugsForEntity( string $entity ): array {
        switch ( $entity ) {
            case CustomFieldsRepository::ENTITY_PLAYER:     return self::playerSlugs();
            case CustomFieldsRepository::ENTITY_PERSON:     return self::personSlugs();
            case CustomFieldsRepository::ENTITY_TEAM:       return self::teamSlugs();
            case CustomFieldsRepository::ENTITY_SESSION:    return self::sessionSlugs();
            case CustomFieldsRepository::ENTITY_GOAL:       return self::goalSlugs();
            case CustomFieldsRepository::ENTITY_EVALUATION: return self::evaluationSlugs();
        }
        return [];
    }

    private static function playerSlugs(): array {
        return [
            'first_name'          => __( 'First name', 'talenttrack' ),
            'last_name'           => __( 'Last name', 'talenttrack' ),
            'date_of_birth'       => __( 'Date of birth', 'talenttrack' ),
            'nationality'         => __( 'Nationality', 'talenttrack' ),
            'height_cm'           => __( 'Height (cm)', 'talenttrack' ),
            'weight_kg'           => __( 'Weight (kg)', 'talenttrack' ),
            'preferred_foot'      => __( 'Preferred foot', 'talenttrack' ),
            'preferred_positions' => __( 'Preferred positions', 'talenttrack' ),
            'jersey_number'       => __( 'Jersey number', 'talenttrack' ),
            'team_id'             => __( 'Team', 'talenttrack' ),
            'date_joined'         => __( 'Date joined', 'talenttrack' ),
            'photo_url'           => __( 'Photo URL', 'talenttrack' ),
            'guardian_name'       => __( 'Guardian name', 'talenttrack' ),
            'guardian_email'      => __( 'Guardian email', 'talenttrack' ),
            'guardian_phone'      => __( 'Guardian phone', 'talenttrack' ),
            'wp_user_id'          => __( 'WordPress user', 'talenttrack' ),
            'status'              => __( 'Status', 'talenttrack' ),
        ];
    }

    private static function personSlugs(): array {
        return [
            'first_name' => __( 'First name', 'talenttrack' ),
            'last_name'  => __( 'Last name', 'talenttrack' ),
            'email'      => __( 'Email', 'talenttrack' ),
            'phone'      => __( 'Phone', 'talenttrack' ),
            'role_type'  => __( 'Role type', 'talenttrack' ),
            'wp_user_id' => __( 'WordPress user', 'talenttrack' ),
            'status'     => __( 'Status', 'talenttrack' ),
        ];
    }

    private static function teamSlugs(): array {
        return [
            'name'          => __( 'Name', 'talenttrack' ),
            'age_group'     => __( 'Age group', 'talenttrack' ),
            'head_coach_id' => __( 'Head coach', 'talenttrack' ),
            'notes'         => __( 'Notes', 'talenttrack' ),
        ];
    }

    private static function sessionSlugs(): array {
        // Note: coach_id is not listed. The Sessions edit form doesn't
        // render a coach picker — coach_id is set to get_current_user_id()
        // at save time. Exposing it in the "Insert after" dropdown would
        // be a footgun since custom fields anchored to it would never render.
        return [
            'title'        => __( 'Title', 'talenttrack' ),
            'session_date' => __( 'Session date', 'talenttrack' ),
            'location'     => __( 'Location', 'talenttrack' ),
            'team_id'      => __( 'Team', 'talenttrack' ),
            'notes'        => __( 'Notes', 'talenttrack' ),
        ];
    }

    private static function goalSlugs(): array {
        return [
            'player_id'   => __( 'Player', 'talenttrack' ),
            'title'       => __( 'Title', 'talenttrack' ),
            'description' => __( 'Description', 'talenttrack' ),
            'status'      => __( 'Status', 'talenttrack' ),
            'priority'    => __( 'Priority', 'talenttrack' ),
            'due_date'    => __( 'Due date', 'talenttrack' ),
        ];
    }

    private static function evaluationSlugs(): array {
        // v2.12.0 — Evaluations got custom-field support in Sprint 1I.
        // The ratings grid is NOT a slug (it's its own UI, not a "field").
        return [
            'player_id'      => __( 'Player', 'talenttrack' ),
            'eval_type_id'   => __( 'Evaluation type', 'talenttrack' ),
            'eval_date'      => __( 'Evaluation date', 'talenttrack' ),
            'opponent'       => __( 'Opponent', 'talenttrack' ),
            'competition'    => __( 'Competition', 'talenttrack' ),
            'match_result'   => __( 'Match result', 'talenttrack' ),
            'home_away'      => __( 'Home/Away', 'talenttrack' ),
            'minutes_played' => __( 'Minutes played', 'talenttrack' ),
            'notes'          => __( 'Notes', 'talenttrack' ),
        ];
    }
}
