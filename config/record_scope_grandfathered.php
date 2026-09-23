<?php
/**
 * By-id surfaces that do not yet check the record (#4004).
 *
 * `tools/check-record-scope.php` fails on a by-id surface that neither calls a
 * per-record check nor carries a `record-scope-ok` marker. This file is the
 * set that was already in that state when the gate landed, so the gate could
 * ship in one PR instead of waiting on a sweep across forty files. Same
 * arrangement as the inline-style gate (#1389): the rule binds new work
 * immediately and the backlog is written down rather than ignored.
 *
 * **A line here is a finding, not a decision.** Some of these have no player
 * or team dimension at all â€” a holiday, a season, a measurement definition, a
 * methodology principle â€” and want a `record-scope-ok: no player dimension`
 * marker at the site rather than a line here. Others are real gaps of exactly
 * the shape #3987, #3998, #4000, #4001, #4002 and #4003 closed: `GET
 * /players/{id}` resolves the player and returns it without asking whose
 * player it is, and the staff-development routes take a `person_id` out of the
 * URL. Those want fixing.
 *
 * Either way the work is the same: fix it or mark it, then delete the line.
 * The gate fails on an entry that no longer names a surface, so the file
 * cannot quietly outlive its contents. When it is empty, delete the file.
 *
 * Keys are `path::method` â€” a REST route's callback and permission callback
 * joined with ` + `, because the gate judges the pair as one unit. Values say
 * what the surface is, and carry no line numbers: an entry outlives the lines
 * around it.
 *
 * @return array<string, string> surface => what it is
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    'src/Infrastructure/REST/ActivitiesRestController.php::get_planned_attendance + can_view' => 'rest route /activities/(?P<id>\d+)/planned-attendance',
    'src/Infrastructure/REST/ActivitiesRestController.php::get_principles + can_view' => 'rest route /activities/(?P<id>\d+)/principles',
    'src/Infrastructure/REST/ActivityExercisesRestController.php::list_for_activity + append' => 'rest route /activities/(?P<activity_id>\d+)/exercises',
    'src/Infrastructure/REST/ActivityExercisesRestController.php::replace' => 'rest route /activities/(?P<activity_id>\d+)/exercises/replace',
    'src/Infrastructure/REST/ActivityExercisesRestController.php::update + delete' => 'rest route /activities/(?P<activity_id>\d+)/exercises/(?P<id>\d+)',
    'src/Infrastructure/REST/BackupRestController.php::deleteBackup + canManage' => 'rest route /backups/(?P<id>[A-Za-z0-9._-]+)',
    'src/Infrastructure/REST/BackupRestController.php::previewRestore + canManage' => 'rest route /backups/(?P<id>[A-Za-z0-9._-]+)/preview',
    'src/Infrastructure/REST/BackupRestController.php::restore + canManage' => 'rest route /backups/(?P<id>[A-Za-z0-9._-]+)/restore',
    'src/Infrastructure/REST/CustomFieldsRestController.php::update_field + delete_field' => 'rest route /custom-fields/(?P<id>\d+)',
    'src/Infrastructure/REST/CustomFieldsRestController.php::move_field' => 'rest route /custom-fields/(?P<id>\d+)/move',
    'src/Infrastructure/REST/EvalCategoriesRestController.php::update_category + delete_category' => 'rest route /eval-categories/(?P<id>\d+)',
    'src/Infrastructure/REST/EvalCategoriesRestController.php::move_category' => 'rest route /eval-categories/(?P<id>\d+)/move',
    'src/Infrastructure/REST/ExerciseScenesRestController.php::list_scenes + create_scene' => 'rest route /exercises/(?P<id>\d+)/scenes',
    'src/Infrastructure/REST/ExerciseScenesRestController.php::get_scene + update_scene + delete_scene' => 'rest route /exercise-scenes/(?P<id>\d+)',
    'src/Infrastructure/REST/ExerciseScenesRestController.php::set_primary' => 'rest route /exercise-scenes/(?P<id>\d+)/primary',
    'src/Infrastructure/REST/ExercisesRestController.php::promote_exercise' => 'rest route /exercises/(?P<id>\d+)/promote',
    'src/Infrastructure/REST/ExercisesRestController.php::get_exercise + update_exercise + archive_exercise' => 'rest route /exercises/(?P<id>\d+)',
    'src/Infrastructure/REST/FunctionalRolesRestController.php::update_role_type + delete_role_type' => 'rest route /functional-roles/(?P<id>\d+)',
    'src/Infrastructure/REST/FunctionalRolesRestController.php::move_role_type' => 'rest route /functional-roles/(?P<id>\d+)/move',
    'src/Infrastructure/REST/GuardianContactRestController.php::requestFromFamily' => 'rest route /players/(?P<id>\d+)/guardian-contact-request',
    'src/Infrastructure/REST/InvitationsRestController.php::revoke + canRevoke' => 'rest route /invitations/(?P<id>\d+)',
    'src/Infrastructure/REST/MeasurementsRestController.php::update_definition' => 'rest route /measurements/definitions/(?P<id>\d+)',
    'src/Infrastructure/REST/MeasurementsRestController.php::list_team_sessions + can_read_team_sessions' => 'rest route /teams/(?P<team_id>\d+)/measurement-sessions',
    'src/Infrastructure/REST/MeasurementsRestController.php::get_team_coverage + can_read_team_sessions' => 'rest route /teams/(?P<team_id>\d+)/measurement-coverage',
    'src/Infrastructure/REST/ParentAccountRestController.php::list_parents + can_manage + link' => 'rest route /players/(?P<id>\d+)/parents',
    'src/Infrastructure/REST/ParentAccountRestController.php::unlink + can_manage' => 'rest route /players/(?P<id>\d+)/parents/(?P<parent_id>\d+)',
    'src/Infrastructure/REST/PeekRestController.php::player' => 'rest route /players/(?P<id>\d+)/summary',
    'src/Infrastructure/REST/PeekRestController.php::team' => 'rest route /teams/(?P<id>\d+)/summary',
    'src/Infrastructure/REST/PeekRestController.php::activity' => 'rest route /activities/(?P<id>\d+)/summary',
    'src/Infrastructure/REST/PeopleRestController.php::get_person + update_person + archive_person' => 'rest route /people/(?P<id>\d+)',
    'src/Infrastructure/REST/PlayerAccountRestController.php::link + can_manage + unlink' => 'rest route /players/(?P<id>\d+)/account',
    'src/Infrastructure/REST/PlayerJourneyRestController.php::delete_injury_permanently' => 'rest route /player-injuries/(?P<id>\d+)/permanent',
    'src/Infrastructure/REST/PlayersRestController.php::get_scout_card' => 'rest route /players/(?P<id>\d+)/scout-card',
    'src/Infrastructure/REST/PlayersRestController.php::get_report_snapshot' => 'rest route /player-report-snapshots/(?P<uuid>[0-9a-f-]{36})',
    'src/Infrastructure/REST/PlayersRestController.php::put_report_snapshot_note' => 'rest route /player-report-snapshots/(?P<uuid>[0-9a-f-]{36})/notes/(?P<section>[a-z_]+)',
    'src/Infrastructure/REST/PlayersRestController.php::get_player + update_player + delete_player' => 'rest route /players/(?P<id>\d+)',
    'src/Infrastructure/REST/PlayersRestController.php::restore_player' => 'rest route /players/(?P<id>\d+)/restore',
    'src/Infrastructure/REST/PlayersRestController.php::delete_player_permanently' => 'rest route /players/(?P<id>\d+)/permanent',
    'src/Infrastructure/REST/PlayersRestController.php::trash_player' => 'rest route /players/(?P<id>\d+)/trash',
    'src/Infrastructure/REST/PlayerStatusRestController.php::createBehaviourRating' => 'rest route /players/(?P<id>\d+)/behaviour-ratings',
    'src/Infrastructure/REST/PlayerStatusRestController.php::setPotential' => 'rest route /players/(?P<id>\d+)/potential',
    'src/Infrastructure/REST/PlayerStatusRestController.php::potentialHistory' => 'rest route /players/(?P<id>\d+)/potential',
    'src/Infrastructure/REST/PlayerStatusRestController.php::playerStatus' => 'rest route /players/(?P<id>\d+)/status',
    'src/Infrastructure/REST/PlayerStatusRestController.php::teamStatuses' => 'rest route /teams/(?P<id>\d+)/player-statuses',
    'src/Infrastructure/REST/RecycleBinRestController.php::preview' => 'rest route /recycle-bin/preview/(?P<entity>[a-z_]+)/(?P<id>\d+)',
    'src/Infrastructure/REST/SpondRestController.php::route_save_team_credentials + canManageTeamSpond + route_delete_team_credentials' => 'rest route /teams/(?P<id>\d+)/spond/credentials',
    'src/Infrastructure/REST/SpondRestController.php::route_test_team_credentials + canManageTeamSpond' => 'rest route /teams/(?P<id>\d+)/spond/test',
    'src/Infrastructure/REST/SpondRestController.php::route_list_team_groups + canManageTeamSpond + route_save_team_group' => 'rest route /teams/(?P<id>\d+)/spond/group',
    'src/Infrastructure/REST/TeamsRestController.php::get_team_stats' => 'rest route /teams/(?P<id>\d+)/stats',
    'src/Infrastructure/REST/TeamsRestController.php::restore_team' => 'rest route /teams/(?P<id>\d+)/restore',
    'src/Infrastructure/REST/TeamsRestController.php::delete_team_permanently' => 'rest route /teams/(?P<id>\d+)/permanent',
    'src/Infrastructure/REST/TeamsRestController.php::add_player_to_team + remove_player_from_team' => 'rest route /teams/(?P<id>\d+)/players/(?P<player_id>\d+)',
    'src/Infrastructure/REST/TournamentsRestController.php::get_tournament + update_tournament + delete_tournament' => 'rest route /tournaments/(?P<id>\d+)',
    'src/Infrastructure/REST/TournamentsRestController.php::restore_tournament' => 'rest route /tournaments/(?P<id>\d+)/restore',
    'src/Infrastructure/REST/TournamentsRestController.php::delete_tournament_permanently' => 'rest route /tournaments/(?P<id>\d+)/permanent',
    'src/Infrastructure/REST/TournamentsRestController.php::trash_tournament' => 'rest route /tournaments/(?P<id>\d+)/trash',
    'src/Infrastructure/REST/TournamentsRestController.php::get_totals' => 'rest route /tournaments/(?P<id>\d+)/totals',
    'src/Infrastructure/REST/TournamentsRestController.php::create_match' => 'rest route /tournaments/(?P<id>\d+)/matches',
    'src/Infrastructure/REST/TournamentsRestController.php::update_match + delete_match' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)',
    'src/Infrastructure/REST/TournamentsRestController.php::get_planner' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/planner',
    'src/Infrastructure/REST/TournamentsRestController.php::kickoff_match' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/kickoff',
    'src/Infrastructure/REST/TournamentsRestController.php::completion_preview' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/completion',
    'src/Infrastructure/REST/TournamentsRestController.php::complete_match' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/complete',
    'src/Infrastructure/REST/TournamentsRestController.php::auto_plan' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/auto-plan',
    'src/Infrastructure/REST/TournamentsRestController.php::update_assignments' => 'rest route /tournaments/(?P<id>\d+)/matches/(?P<match_id>\d+)/assignments',
    'src/Infrastructure/REST/TournamentsRestController.php::replace_squad' => 'rest route /tournaments/(?P<id>\d+)/squad',
    'src/Infrastructure/REST/TournamentsRestController.php::update_squad_member + remove_squad_member' => 'rest route /tournaments/(?P<id>\d+)/squad/(?P<player_id>\d+)',
    'src/Infrastructure/REST/TrainingExposureRestController.php::player_exposure' => 'rest route /players/(?P<id>\d+)/training-exposure',
    'src/Infrastructure/REST/TrainingExposureRestController.php::player_observations' => 'rest route /players/(?P<id>\d+)/observations',
    'src/Modules/AdminCenterClient/BroadcastsRestController.php::dismiss' => 'rest route /me/broadcasts/(?P<id>\d+)/dismiss',
    'src/Modules/Alerts/Rest/AlertsRestController.php::getOne' => 'rest route /alerts/(?P<uuid>[a-f0-9\-]{36})',
    'src/Modules/Alerts/Rest/AlertsRestController.php::markRead' => 'rest route /alerts/(?P<uuid>[a-f0-9\-]{36})/read',
    'src/Modules/Alerts/Rest/AlertsRestController.php::snooze' => 'rest route /alerts/(?P<uuid>[a-f0-9\-]{36})/snooze',
    'src/Modules/Alerts/Rest/AlertsRestController.php::dismiss' => 'rest route /alerts/(?P<uuid>[a-f0-9\-]{36})/dismiss',
    'src/Modules/CustomWidgets/Rest/CustomWidgetsRestController.php::delete_widget_permanently' => 'rest route /custom-widgets/(?P<id>[A-Za-z0-9_-]+)/permanent',
    'src/Modules/CustomWidgets/Rest/CustomWidgetsRestController.php::clear_cache + permWrite' => 'rest route /custom-widgets/(?P<id>[A-Za-z0-9_-]+)/clear-cache',
    'src/Modules/Development/Frontend/IdeaPromoteHandler.php::handle' => 'view',
    'src/Modules/Development/Frontend/IdeaRefineHandler.php::handle' => 'view',
    'src/Modules/Development/Frontend/IdeasRefineView.php::render' => 'view',
    'src/Modules/Export/Exporters/AttendanceRegisterCsvExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/EvaluationsXlsxExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/FederationJsonExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/GoalsCsvExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/MeasurementResultsXlsxExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/PdpPdfExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/PlayerEvaluationsCsvExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/PlayersListCsvExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/TeamActivitiesCsvExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/TeamPlannerXlsxExporter.php::collect' => 'exporter',
    'src/Modules/Export/Exporters/TeamRosterStatsCsvExporter.php::collect' => 'exporter',
    'src/Modules/Holidays/Rest/HolidaysRestController.php::get_holiday + update_holiday + delete_holiday' => 'rest route /holidays/(?P<id>\d+)',
    'src/Modules/Holidays/Rest/HolidaysRestController.php::delete_holiday_permanently' => 'rest route /holidays/(?P<id>\d+)/permanent',
    'src/Modules/Holidays/Rest/HolidaysRestController.php::restore_holiday' => 'rest route /holidays/(?P<id>\d+)/restore',
    'src/Modules/Holidays/Rest/HolidaysRestController.php::trash_holiday' => 'rest route /holidays/(?P<id>\d+)/trash',
    'src/Modules/Knowledge/Rest/KnowledgeRestController.php::update_enrolment + can_manage + withdraw' => 'rest route /enrolments/(?P<id>\d+)',
    'src/Modules/Knowledge/Rest/KnowledgeRestController.php::person_learning + can_view_person' => 'rest route /people/(?P<id>\d+)/learning',
    'src/Modules/Knowledge/Rest/KnowledgeRestController.php::review_submission + can_review' => 'rest route /submissions/(?P<id>\d+)',
    'src/Modules/Knowledge/Rest/KnowledgeRestController.php::team_learning + can_view_statistics' => 'rest route /teams/(?P<id>\d+)/learning',
    'src/Modules/Measurements/Frontend/FrontendPlayerBmiView.php::render' => 'view',
    'src/Modules/Measurements/Rest/MeasurementDefinitionsRestController.php::upsert_target + can_change' => 'rest route /measurement-definitions/(?P<id>\d+)/targets',
    'src/Modules/Measurements/Rest/MeasurementDefinitionsRestController.php::list_levels + can_read + upsert_levels + can_change' => 'rest route /measurement-definitions/(?P<id>\d+)/levels',
    'src/Modules/Measurements/Rest/MeasurementDefinitionsRestController.php::delete_permanently' => 'rest route /measurement-definitions/(?P<id>\d+)/permanent',
    'src/Modules/Methodology/Admin/FootballActionEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/FrameworkPrimerEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/InfluenceFactorEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/LearningGoalEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/PhaseEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/PrincipleEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/SetPieceEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Methodology/Admin/VisionEditPage.php::handleSave' => 'admin-post handler',
    'src/Modules/Pdp/Rest/PdpConversationsRestController.php::patch + can_view' => 'rest route /pdp-conversations/(?P<id>\d+)',
    'src/Modules/Pdp/Rest/PdpFilesRestController.php::restore + can_unarchive' => 'rest route /pdp-files/(?P<id>\d+)/restore',
    'src/Modules/Pdp/Rest/PdpFilesRestController.php::permanent_delete + can_delete' => 'rest route /pdp-files/(?P<id>\d+)/permanent-delete',
    'src/Modules/Pdp/Rest/PdpPrepRestController.php::update_question + can_configure + archive_question' => 'rest route /pdp-prep-questions/(?P<id>\d+)',
    'src/Modules/Pdp/Rest/PdpPrepRestController.php::get_prep + can_read_prep + save_prep' => 'rest route /pdp-conversations/(?P<id>\d+)/prep',
    'src/Modules/Pdp/Rest/SeasonsRestController.php::set_current + can_admin' => 'rest route /seasons/(?P<id>\d+)/current',
    'src/Modules/Pdp/Rest/SeasonsRestController.php::update + can_admin' => 'rest route /seasons/(?P<id>\d+)',
    'src/Modules/Players/Rest/DossierCompletenessRestController.php::get_team_completeness + can_read_team_dossiers' => 'rest route /teams/(?P<team_id>\d+)/dossier-completeness',
    'src/Modules/Prospects/Frontend/FrontendProspectEditView.php::render' => 'view',
    'src/Modules/Prospects/Frontend/FrontendScoutingPlanView.php::render' => 'view',
    'src/Modules/Prospects/Rest/ProspectConsentRequestsRestController.php::list_requests + can_view + create_request + can_edit' => 'rest route /prospects/(?P<id>\d+)/consent-requests',
    'src/Modules/Prospects/Rest/ProspectConsentRequestsRestController.php::update_request + can_edit' => 'rest route /prospects/(?P<id>\d+)/consent-requests/(?P<entry_id>\d+)',
    'src/Modules/Prospects/Rest/ProspectsRestController.php::get_prospect + can_view + update_prospect + can_log' => 'rest route /prospects/(?P<id>\d+)',
    'src/Modules/Prospects/Rest/ProspectsRestController.php::propose_test_training + can_log' => 'rest route /prospects/(?P<id>\d+)/test-training-proposal',
    'src/Modules/Prospects/Rest/TestTrainingsRestController.php::delete_permanently' => 'rest route /test-trainings/(?P<id>\d+)/permanent',
    'src/Modules/Reports/Frontend/FrontendScoutAccessView.php::render' => 'view',
    'src/Modules/Reports/Frontend/FrontendScoutMyPlayersView.php::render' => 'view',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::list_goals + can_view + create_goal + can_manage_target' => 'rest route /staff/(?P<person_id>\d+)/goals',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::update_goal + can_manage + delete_goal' => 'rest route /staff-goals/(?P<id>\d+)',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::list_evaluations + can_view + create_evaluation + can_evaluate_target' => 'rest route /staff/(?P<person_id>\d+)/evaluations',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::update_evaluation + can_manage + delete_evaluation' => 'rest route /staff-evaluations/(?P<id>\d+)',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::list_certifications + can_view + create_certification + can_manage_target' => 'rest route /staff/(?P<person_id>\d+)/certifications',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::update_certification + can_manage + delete_certification' => 'rest route /staff-certifications/(?P<id>\d+)',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::get_pdp + can_view + upsert_pdp + can_manage_target' => 'rest route /staff/(?P<person_id>\d+)/pdp',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::list_mentorships + can_view + create_mentorship + can_manage' => 'rest route /staff/(?P<person_id>\d+)/mentorships',
    'src/Modules/StaffDevelopment/Rest/StaffDevelopmentRestController.php::delete_mentorship + can_manage' => 'rest route /staff-mentorships/(?P<id>\d+)',
    'src/Modules/Trials/Rest/TrialsRestController.php::delete_case_permanently' => 'rest route /trial-cases/(?P<id>\d+)/permanent',
    'src/Modules/Trials/Rest/TrialsRestController.php::extend_case + can_manage' => 'rest route /trial-cases/(?P<id>\d+)/extend',
    'src/Modules/Trials/Rest/TrialsRestController.php::delete_track_permanently' => 'rest route /trial-tracks/(?P<id>\d+)/permanent',
    'src/Modules/Vct/Rest/VctAgeProfilesRestController.php::patch + can_admin + remove' => 'rest route /vct/age-profiles/(?P<id>\d+)',
    'src/Modules/Vct/Rest/VctExercisesRestController.php::find + can_read + patch + can_admin + archive' => 'rest route /vct/exercises/(?P<id>\d+)',
    'src/Modules/Vct/Rest/VctExercisesRestController.php::delete_permanently' => 'rest route /vct/exercises/(?P<id>\d+)/permanent',
    'src/Shared/Frontend/FrontendMailComposeView.php::render' => 'view',
    'src/Shared/Frontend/FrontendSeasonsView.php::renderForm' => 'view',
    'src/Shared/Frontend/FrontendTrialParentMeetingView.php::render' => 'view',
    'src/Shared/Frontend/FrontendTrialTracksEditorView.php::render' => 'view',
];
