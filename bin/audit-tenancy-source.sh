#!/usr/bin/env bash
#
# audit-tenancy-source.sh (#0052 PR-A) — static check that every PHP file
# touching a tenant-scoped tt_* table also references club_id scoping.
#
# Companion to bin/audit-tenancy.php (which is a runtime data-integrity
# check). This script runs in CI without a WordPress bootstrap and
# catches regressions where a new query is added without the club_id
# filter.
#
# Heuristic: any PHP file under src/ that contains
#
#     $wpdb->prefix . 'tt_<tenant_scoped_table>'
#
# (or the equivalent string interpolation) MUST also reference either
# `club_id` or `CurrentClub::` somewhere in the same file. False
# positives are possible (e.g. a SHOW TABLES LIKE check), but the
# whitelist below covers the legitimate exceptions.
#
# Exit 0 on pass, 1 on fail. Run from the plugin root:
#
#     bash bin/audit-tenancy-source.sh
#
set -e

# Tenant-scoped tables — must match the list in
# database/migrations/0038_tenancy_scaffold.php and bin/audit-tenancy.php.
tenant_tables=(
    'tt_players' 'tt_teams' 'tt_people' 'tt_team_people'
    'tt_user_role_scopes' 'tt_player_parents' 'tt_player_team_history'
    'tt_evaluations' 'tt_eval_ratings' 'tt_eval_categories'
    'tt_category_weights' 'tt_activities' 'tt_attendance' 'tt_goals'
    'tt_goal_links' 'tt_seasons'
    'tt_pdp_files' 'tt_pdp_conversations' 'tt_pdp_verdicts' 'tt_pdp_calendar_links'
    'tt_player_reports' 'tt_report_presets'
    'tt_team_formations' 'tt_team_playing_styles' 'tt_formation_templates'
    'tt_team_chemistry_pairings'
    'tt_custom_fields' 'tt_custom_values'
    'tt_invitations' 'tt_audit_log'
    'tt_authorization_changelog' 'tt_authorization_matrix'
    'tt_workflow_tasks' 'tt_workflow_triggers' 'tt_workflow_event_log' 'tt_workflow_template_config'
    'tt_module_state' 'tt_dev_ideas' 'tt_dev_tracks'
    'tt_functional_roles' 'tt_functional_role_auth_roles' 'tt_roles' 'tt_role_permissions'
    'tt_methodology_assets' 'tt_methodology_framework_primers'
    'tt_methodology_influence_factors' 'tt_methodology_learning_goals'
    'tt_methodology_phases' 'tt_methodology_principle_links'
    'tt_methodology_visions' 'tt_principles' 'tt_set_pieces'
    'tt_football_actions' 'tt_formation_positions' 'tt_formations'
    'tt_lookups'
    'tt_trial_cases' 'tt_trial_tracks' 'tt_trial_letter_templates'
    'tt_trial_case_staff' 'tt_trial_extensions' 'tt_trial_case_staff_inputs'
    'tt_translation_source_meta' 'tt_translations_cache' 'tt_translations_usage'
    'tt_usage_events' 'tt_demo_tags'
    'tt_player_events' 'tt_player_injuries'
)

# Files that legitimately reference a tenant table without club_id —
# install-time existence checks, schema introspection, etc.
allowlist=(
    'src/Core/Activator.php'
    'src/Infrastructure/Database/MigrationRunner.php'
)

failed=0
table_pattern=$( IFS='|' ; echo "${tenant_tables[*]}" )

# Find every PHP file under src/ that mentions a tenant-scoped table.
files=$( grep -rlE "(\\\$wpdb->prefix\s*\.|\\\$p\s*\.|\\\$wpdb->prefix})\s*['\"]?(${table_pattern})\b" src/ 2>/dev/null || true )

for file in $files; do
    # Skip allowlisted files.
    skip=0
    for allow in "${allowlist[@]}"; do
        if [ "$file" = "$allow" ]; then skip=1; break; fi
    done
    if [ $skip -eq 1 ]; then continue; fi

    # Pass if the file references club_id OR CurrentClub::.
    if grep -qE "club_id|CurrentClub::" "$file"; then
        continue
    fi

    echo "::error file=$file::Missing club_id / CurrentClub:: scope on tenant-scoped table query"
    failed=1
done

if [ $failed -eq 0 ]; then
    echo "audit-tenancy-source: pass — every src/ file touching a tenant-scoped table references club_id."
    exit 0
fi
echo "audit-tenancy-source: FAIL — see ::error lines above."
exit 1
