<?php
/**
 * Mobile classification for every routable `?tt_view=` surface (#2807).
 *
 * `MobileSurfaceRegistry` decides what a phone gets for a given surface.
 * Until now its classifications were a hard-coded array in the middle of
 * `CoreSurfaceRegistration`, populated once in #0084 and untouched through
 * roughly twenty new modules — which is how 125 of 151 surfaces came to
 * resolve to `viewable` by default rather than by decision.
 *
 * This file is that decision, written down. It mirrors
 * `config/always_on_surfaces.php`, which makes the same argument for the
 * same reason: the point is not that every surface must be classified one
 * way, but that its class should be something somebody chose and recorded.
 *
 * THE FOUR CLASSES
 *
 * NATIVE — the phone is the primary device. The mobile pattern
 * library loads only here.
 *
 * VIEWABLE — readable and usable on a phone, built for a desktop.
 * No runtime effect; this is the honest middle and the common answer.
 *
 * READ_ONLY — readable on a phone, editable only at a desk.
 * Mutating controls are stripped on phone requests.
 *
 * DESKTOP_ONLY — a phone visitor is intercepted before the view
 * renders and lands on the prompt page.
 *
 * HOW A SURFACE GETS ITS CLASS — five questions, first match wins:
 *
 *   1. Triggered by something happening in the physical world, right now?
 *      -> native. This beats every other question: a surface that is both
 *      urgent and wide is a design problem, not one to gate, because
 *      gating it means the data never arrives.
 *   2. Is the screen you work on the page you will print?
 *      -> desktop_only. A4 portrait is roughly 794px. A record that can
 *      merely EMIT a PDF is unaffected — the surface has to BE the document.
 *   3. Does editing it reach beyond a single record — settings,
 *      permissions, schedules, rollovers, data operations?
 *      -> desktop_only. Blast radius, not legibility.
 *   4. Does the work need many rows or columns visible at once?
 *      -> read_only if it still reads as charts and summaries,
 *      desktop_only if reflowing to one column destroys it.
 *   5. Otherwise -> viewable.
 *
 * TABLETS ARE NEVER GATED. `MobileDetector::isPhone()` excludes iPad,
 * Android tablets, Kindle and PlayBook, so `desktop_only` only ever
 * affects handsets. A club can also switch the whole gate off with
 * `force_mobile_for_user_agents`, and a user can pass any single gate
 * with `?force_mobile=1`.
 *
 * ADDING A SURFACE: add it here. A routable slug with no entry silently
 * falls to `viewable`, which is exactly how the previous list rotted.
 * `tools/check-mobile-classes.php` (#2812) turns that silence into a
 * build failure, so a new surface cannot ship unclassified. It also
 * fails an entry with no reason text, and one naming a slug the
 * dispatcher no longer routes.
 *
 * @return array<string, array{0: string, 1: string}> slug => [class, why]
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [

    /* ---- native (31) -------------------------------------------------- */
    'accept-invite'                 => [ 'native', 'A parent or player accepting an invitation, from an email, on a phone.' ],
    'activities'                    => [ 'native', 'Attendance is three trainings and a match every week, taken standing up with a ball under one arm. The most repeated action in the product.' ],
    'evaluations'                   => [ 'native', 'Submitting a periodic evaluation — head-coach action 9.' ],
    'lost-password'                 => [ 'native', 'Recovery, reached from the login screen.' ],
    'match-execution'               => [ 'native', 'Live, during the match, on the touchline.' ],
    'match-executions'              => [ 'native', 'The list behind match-execution, which is native.' ],
    'measurements'                  => [ 'native', 'The player’s own test results — read-only, one player, a target flag and a trend line.' ],
    'measurements-entry'            => [ 'native', 'Recording test results at the testing session itself.' ],
    'mfa-prompt'                    => [ 'native', 'Second factor at sign-in. Everything is behind it.' ],
    'my-activities'                 => [ 'native', '"Am I playing on Saturday" — the question a parent opens the app to answer.' ],
    'my-development'                => [ 'native', 'The player and parent development home — the surface a parent opens to see how their child is doing.' ],
    'my-evaluations'                => [ 'native', 'A player’s own evaluations, read on a sofa as often as anywhere.' ],
    'my-goals'                      => [ 'native', 'A player’s own goals, checked between sessions.' ],
    'my-journey'                    => [ 'native', 'One player, chronological, read-only. The most phone-shaped surface in the product.' ],
    'my-pdp'                        => [ 'native', 'A player’s own development plan.' ],
    'my-tasks'                      => [ 'native', 'The personal task list, cleared in dead time on a phone.' ],
    'my-team'                       => [ 'native', 'A player’s own squad.' ],
    'overview'                      => [ 'native', 'The player’s own profile. One player, read-mostly, and the people who open it are phone-first.' ],
    'player-journey'                => [ 'native', 'The player timeline — the Me-group shape on a staff-reached surface.' ],
    'player-status-capture'         => [ 'native', 'Marking a player’s status in the moment it changes.' ],
    'players'                       => [ 'native', 'The player record is the spine of the product and is reached from the sideline constantly.' ],
    'profile'                       => [ 'native', 'A player’s own profile details.' ],
    'reset-password'                => [ 'native', 'The link from the recovery email.' ],
    'strava'                        => [ 'native', 'A player connecting their own running account. Strava lives on the same phone.' ],
    'team-behaviour-capture'        => [ 'native', 'Behaviour noted during a session, team-level.' ],
    'teammate'                      => [ 'native', 'A player looking at a squad-mate’s card.' ],
    'teams'                         => [ 'native', 'The squad a coach works from every session.' ],
    'tournament-match'              => [ 'native', 'One fixture within a tournament day. Several games, all of them pitch-side.' ],
    'training-photo'                => [ 'native', 'The camera is the phone.' ],
    'training-run'                  => [ 'native', 'The sideline view — running a training as it happens. Named as such in TrainingRunsRestController.' ],
    'wizard'                        => [ 'native', 'Every record-creation flow routes through this aggregator, and creation happens where the thing happened.' ],

    /* ---- viewable (36) ------------------------------------------------ */
    'alerts'                        => [ 'viewable', 'A queue, scannable one item at a time.' ],
    'attendance-leaderboard'        => [ 'viewable', 'A ranked table with nothing on it to edit — read_only would be a label with no behaviour behind it.' ],
    'docs'                          => [ 'viewable', 'Help topics. Long-form reading works on a phone.' ],
    'exercises'                     => [ 'viewable', 'The exercise library index.' ],
    'goals'                         => [ 'viewable', 'Goal records. Usable on a phone without design investment.' ],
    'holidays'                      => [ 'viewable', 'The academy holiday calendar. Read, not built.' ],
    'ideas-approval'                => [ 'viewable', 'Approving ideas — a queue.' ],
    'ideas-board'                   => [ 'viewable', 'The idea list — a queue, scannable one item at a time.' ],
    'ideas-refine'                  => [ 'viewable', 'Editing a submitted idea.' ],
    'courses'                       => [ 'viewable', 'The course library index.' ],
    'mail-compose'                  => [ 'viewable', 'Writing a message. Usable on a phone; not frequent enough to design for.' ],
    'match-analysis-share'          => [ 'viewable', 'A token-gated link, opened wherever the recipient is. Reading a document is not authoring it.' ],
    'match-prep-share'              => [ 'viewable', 'The same shape as match-analysis-share, and read on match day at the ground more often than at a desk (#2892).' ],
    'my-learning'                   => [ 'viewable', 'A coach’s own course progress.' ],
    'my-sessions'                   => [ 'viewable', 'Active sign-in sessions.' ],
    'my-settings'                   => [ 'viewable', 'Account preferences.' ],
    'my-staff-certifications'       => [ 'viewable', 'A coach’s own certifications.' ],
    'my-staff-evaluations'          => [ 'viewable', 'A coach’s own evaluations.' ],
    'my-staff-goals'                => [ 'viewable', 'A coach’s own goals.' ],
    'my-staff-pdp'                  => [ 'viewable', 'A coach’s own development plan — a few times a season.' ],
    'onboarding-pipeline'           => [ 'viewable', 'Scouts hit this 5-15 times a week from the pitch, which is why it is not gated (#918).' ],
    'pdp'                           => [ 'viewable', 'Development plans, read more often than edited.' ],
    'people'                        => [ 'viewable', 'Staff and contacts. Lower frequency than players and teams; the responsive CSS is honest enough.' ],
    'player-attributes'             => [ 'viewable', 'One player’s chemistry attributes. Bulk entry argues for a desk, but gating it means the attributes never get filled and the engine computes on nulls.' ],
    'prospect-edit'                 => [ 'viewable', 'Correcting a parent’s contact details or the consent date on one prospect (#2838). Four fields on one record, so none of the first three questions fires — but it is a correction made at a desk after the fact, not capture at the moment of seeing a player, which is what keeps it out of native.' ],
    'prospects-overview'            => [ 'viewable', 'A list view over the funnel.' ],
    'scout-history'                 => [ 'viewable', 'A scout’s own past activity.' ],
    'scout-my-players'              => [ 'viewable', 'A scout’s portfolio list.' ],
    'scouting-visit'                => [ 'viewable', 'One planned visit. Nothing on it needs width.' ],
    'scouting-visits'               => [ 'viewable', 'Planned visits, scanned in a list.' ],
    'staff-overview'                => [ 'viewable', 'Staff development at a glance.' ],
    'submission-review'             => [ 'viewable', 'Reviewing a submitted item.' ],
    'submit-idea'                   => [ 'viewable', 'Submitting an idea. Low frequency, no strong device pull.' ],
    'team-blueprint-share'          => [ 'viewable', 'The same: a link deliberately sent to someone, which must open where they are.' ],
    'tournaments'                   => [ 'viewable', 'Tournament records, consulted rather than worked in.' ],
    'trial-case'                    => [ 'viewable', 'One trialist’s case file.' ],
    'trial-parent-meeting'          => [ 'viewable', 'A single meeting record — one page of notes.' ],
    'trials'                        => [ 'viewable', 'Trial records; a list plus a record.' ],

    /* ---- read_only (10) ----------------------------------------------- */
    'analytics'                     => [ 'read_only', 'Charts and summaries read acceptably on a phone; building the query does not.' ],
    'attendance-report-player'      => [ 'read_only', 'One player’s attendance over time.' ],
    'attendance-report-team'        => [ 'read_only', 'Squad attendance summary.' ],
    'minutes-report-team'           => [ 'read_only', 'Minutes distribution across a squad.' ],
    'podium'                        => [ 'read_only', 'A leaderboard, read at a glance.' ],
    'reports'                       => [ 'read_only', 'Reading a report survives a phone. Assembling one does not.' ],
    'standard-report'               => [ 'read_only', 'The rendered output of a saved report.' ],
    'test-trends'                   => [ 'read_only', 'Trend lines read on a phone; the underlying table does not.' ],
    'player-bmi'                    => [ 'read_only', 'Percentiles and the change since the last measurement read fine on a phone; the six-column roster table is a desk job.' ],
    'usage-stats'                   => [ 'read_only', 'Summary figures, readable; the drill-down is separate and gated.' ],

    /* ---- desktop_only (75) -------------------------------------------- */
    'accounts'                      => [ 'desktop_only', 'Account and access management.' ],
    'alert-policy'                  => [ 'desktop_only', 'Alert rules. The worst surface in the audit — 57 undersized controls.' ],
    'alert-settings'                => [ 'desktop_only', 'Alert preferences: 88 checkboxes.' ],
    'attendance-grid'               => [ 'desktop_only', 'Spreadsheet entry across a full roster. The wizard is the phone path.' ],
    'audit-log'                     => [ 'desktop_only', 'A wide, filtered log.' ],
    'backups'                       => [ 'desktop_only', 'Backup and restore. Destructive, and never needed within five minutes.' ],
    'chemistry-config'              => [ 'desktop_only', 'Chemistry weighting.' ],
    'cohort-board'                  => [ 'desktop_only', 'A board, two-dimensional by construction.' ],
    'cohort-transitions'            => [ 'desktop_only', 'Moving players between age groups, in bulk.' ],
    'compare'                       => [ 'desktop_only', 'Players side by side — the whole point is the comparison.' ],
    'configuration'                 => [ 'desktop_only', 'The settings surface.' ],
    'course'                        => [ 'desktop_only', 'A study surface, not a touchline one — settled by the pilot review (#2872). A coach reads a course at a desk; attendance is the opposite case and stays phone-first.' ],
    'custom-css'                    => [ 'desktop_only', 'Operator CSS. 123 controls measured on it.' ],
    'custom-fields'                 => [ 'desktop_only', 'Field definitions, install-wide.' ],
    'data-browser'                  => [ 'desktop_only', 'A raw table browser over the live schema.' ],
    'dev-tracks'                    => [ 'desktop_only', 'Development-track definitions.' ],
    'eval-categories'               => [ 'desktop_only', 'Evaluation vocabulary.' ],
    'eval-category-weights'         => [ 'desktop_only', 'An age-group by category weighting matrix, and what it changes is every composite rating the academy reads.' ],
    'eval-coverage'                 => [ 'desktop_only', 'A coverage matrix: who has been evaluated, by whom, across a squad.' ],
    'explore'                       => [ 'desktop_only', 'A dimension explorer. The insight is in seeing many rows at once.' ],
    'exercises-import'              => [ 'desktop_only', 'CSV upload, then a wide check table of every row before it commits. The same call as players-import.' ],
    'exports'                       => [ 'desktop_only', 'Export construction.' ],
    'features'                      => [ 'desktop_only', 'Feature toggles, install-wide. What they change reaches past any one record.' ],
    'functional-roles'              => [ 'desktop_only', 'Functional-role assignment.' ],
    'import-history'                => [ 'desktop_only', 'Undoing a whole spreadsheet batch. A data operation reaching well past one record, and not one to fat-finger on a phone.' ],
    'injuries'                      => [ 'desktop_only', 'Sensitive medical data, permission-gated and audit-logged. A considered desk entry is the safeguarding position.' ],
    'invitations-config'            => [ 'desktop_only', 'Invitation settings.' ],
    'lesson'                        => [ 'desktop_only', 'Lesson reading. Gated with `course`, for the same reason, and both name that reason on the prompt rather than saying "best on desktop".' ],
    'lookup-normalisation'          => [ 'desktop_only', 'Merging vocabulary values across the database.' ],
    'match-analysis'                => [ 'desktop_only', 'IS the A4 review document, written up after the match.' ],
    'match-prep'                    => [ 'desktop_only', 'IS the A4 team sheet, authored the night before. At the pitch the coach opens the PDF export.' ],
    'matrix'                        => [ 'desktop_only', 'The authorization matrix: personas across, entities down. Reflowed to one column it is not a smaller matrix, it is nothing.' ],
    'measurement-tests'             => [ 'desktop_only', 'Test definitions — not results.' ],
    'measurements-coverage'         => [ 'desktop_only', 'A coverage matrix over a squad’s tests.' ],
    'media-retention'               => [ 'desktop_only', 'Retention policy for stored media.' ],
    'methodology'                   => [ 'desktop_only', 'The printed methodology reference.' ],
    'migrations'                    => [ 'desktop_only', 'Schema migrations. A phone is the wrong place to run one.' ],
    'minutes-audit'                 => [ 'desktop_only', 'Team, date range, type and gap filters over a wide table.' ],
    'minutes-grid'                  => [ 'desktop_only', 'The same, for minutes.' ],
    'mobile-settings'               => [ 'desktop_only', 'The mobile gate’s own switch. Configuration, ironically enough.' ],
    'modules'                       => [ 'desktop_only', 'Turning modules on and off, install-wide.' ],
    'parent-accounts'               => [ 'desktop_only', 'The same for parents.' ],
    'pdp-planning'                  => [ 'desktop_only', 'Planning development across a squad.' ],
    'persona-templates'             => [ 'desktop_only', 'A drag-and-drop canvas with a palette and a properties panel. There is no version of that which works under a thumb.' ],
    'player-accounts'               => [ 'desktop_only', 'Linking player records to sign-ins.' ],
    'player-status-methodology'     => [ 'desktop_only', 'Status vocabulary, academy-wide.' ],
    'players-import'                => [ 'desktop_only', 'CSV upload and column mapping.' ],
    'rate-cards'                    => [ 'desktop_only', 'A pricing grid — the rows and columns are the whole content.' ],
    'ratings-grid'                  => [ 'desktop_only', 'The same, for ratings.' ],
    'recycle-bin'                   => [ 'desktop_only', 'Restore and permanent purge, in bulk.' ],
    'report-wizard'                 => [ 'desktop_only', 'Building a report. The output is read_only; the builder is not.' ],
    'roles'                         => [ 'desktop_only', 'Role definitions and their capabilities. Getting this wrong locks people out.' ],
    'scheduled-reports'             => [ 'desktop_only', 'Schedule management across many reports.' ],
    'scout-access'                  => [ 'desktop_only', 'Which scouts may see which players. Permissions, so the blast radius is wide.' ],
    'season-rollover'               => [ 'desktop_only', 'Moving a whole academy between seasons. Irreversible in practice.' ],
    'seasons'                       => [ 'desktop_only', 'Season definitions, academy-wide. Everything else is dated against them.' ],
    'setup'                         => [ 'desktop_only', 'The install wizard. Run once, at a desk, before anything else exists.' ],
    'spond'                         => [ 'desktop_only', 'Team-to-group mapping. 323px of overflow before the Wave A fix.' ],
    'spond-monitor'                 => [ 'desktop_only', 'Integration diagnostics.' ],
    'strava-admin'                  => [ 'desktop_only', 'Operator view of player Strava connections. The player’s own connect flow is native.' ],
    'tasks-dashboard'               => [ 'desktop_only', 'The HoD-tier workflow overview: per-template totals and completion rates over 90 days.' ],
    'team-blueprints'               => [ 'desktop_only', 'Blueprint authoring.' ],
    'team-chemistry'                => [ 'desktop_only', 'A squad-wide chemistry board.' ],
    'team-planner'                  => [ 'desktop_only', 'A week grid. Editing it reaches past a single record.' ],
    'team-spond'                    => [ 'desktop_only', 'Per-team Spond account override.' ],
    'test-results'                  => [ 'desktop_only', 'A cross-squad results table.' ],
    'test-trainings'                => [ 'desktop_only', 'Recording a test-training outcome. Gated on review despite being HoD action 3 — revisit if it bites.' ],
    'training-coverage'             => [ 'desktop_only', 'Principle down the side, team across the top, counts in the cells.' ],
    'training-plan'                 => [ 'desktop_only', 'One session plan, with a TrainingPlanPrintRouter behind it.' ],
    'training-plans'                => [ 'desktop_only', 'The session plan as a document.' ],
    'translations'                  => [ 'desktop_only', 'A wide translation table.' ],
    'trial-letter-templates-editor' => [ 'desktop_only', 'Letter templates — a document editor, wide by nature.' ],
    'trial-tracks-editor'           => [ 'desktop_only', 'Trial track definitions.' ],
    'usage-stats-details'           => [ 'desktop_only', 'The wide drill-down behind the summary.' ],
    'vct-config'                    => [ 'desktop_only', 'Conditioning-training configuration.' ],
    'vct-library'                   => [ 'desktop_only', 'Measured at 935px in the audit — second-widest table in the product.' ],
    'vct-session'                   => [ 'desktop_only', 'The session designer.' ],
    'wizards-admin'                 => [ 'desktop_only', 'Wizard administration.' ],
    'workflow-config'               => [ 'desktop_only', 'Workflow templates, install-wide, driving tasks for everyone.' ],
];
