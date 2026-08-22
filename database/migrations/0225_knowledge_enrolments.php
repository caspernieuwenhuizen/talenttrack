<?php
/**
 * Migration 0225 — knowledge-library enrolment and progress (#2644, epic #2641).
 *
 * Four tables. Courses themselves are not here: they ship as markdown
 * under `courses/` and are registered by existing (#2642). What the
 * database holds is a person's relationship to a course, which is the
 * part a file cannot carry.
 *
 *   tt_course_enrolments      one person on one course. Root entity, so
 *                             it carries `uuid`.
 *   tt_course_progress        one row per lesson per enrolment.
 *   tt_course_quiz_attempts   every attempt, not just the last.
 *   tt_course_submissions     a practical assignment and its review.
 *                             Root entity, so it carries `uuid`.
 *
 * `course_slug` and `lesson_slug` are strings, not foreign keys. There is
 * no courses table to point at, and there should not be one: the corpus is
 * versioned with the plugin and reviewed in a pull request, which is worth
 * more than referential integrity against rows an operator could edit. The
 * consequence is that a slug can stop resolving — a course withdrawn in a
 * later release. Those rows are shown as retired rather than deleted,
 * because a coach's completion history has to survive the retirement of
 * the course that produced it.
 *
 * Attempts live apart from progress deliberately. `tt_course_progress`
 * answers "did they pass", which is what the gate needs; the attempt log
 * answers "how many tries", which is what a head of development reviewing
 * a coach's development actually wants to see. Folding the two would keep
 * only the last attempt and quietly lose the second question.
 *
 * Assignment attachments are not in `tt_course_submissions`. They ride
 * `tt_media_links` with `entity_type = 'course_submission'` (#2589), so a
 * photo of a whiteboard plan goes through the same private store, the same
 * visibility rules and the same lifecycle as every other file. A second
 * upload path here would be a second set of all three.
 *
 * Every table carries `club_id`; both root entities carry `uuid`. Every
 * person reference is `person_id` → `tt_people.id`, never `wp_user_id`:
 * the WordPress user is one authentication backend, and the identity that
 * has to survive a SaaS migration is the person (CLAUDE.md §4).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0225_knowledge_enrolments';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // One person on one course.
        //
        // `status` is stored rather than derived. Deriving it would mean
        // joining progress and submissions on every row of the library
        // index and the statistics report, and the report is the surface
        // that has to stay fast as the academy grows. CourseCompletionService
        // owns the transitions.
        //
        // `due_at` is nullable because self-enrolment has no deadline; only
        // an assigned course does. The overdue roll-up keys off it, hence
        // the (club_id, status, due_at) index.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_course_enrolments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            course_slug VARCHAR(100) NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'not_started',
            assigned_by BIGINT UNSIGNED DEFAULT NULL,
            assigned_at DATETIME DEFAULT NULL,
            due_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_person_course (club_id, course_slug, person_id),
            KEY idx_club_person (club_id, person_id),
            KEY idx_club_course (club_id, course_slug),
            KEY idx_club_status_due (club_id, status, due_at)
        ) $charset;" );

        // One lesson's progress within an enrolment.
        //
        // `tool_state` is JSON: where a `tt-zeropoint` measurement and the
        // other stateful blocks from #2643 persist. A coach who measured
        // their squad's nulpunt in lesson 4 must still have it in lesson 11,
        // where the final assignment asks for it. Storing it per lesson
        // rather than per enrolment keeps it next to the block that wrote
        // it, so a lesson revision cannot orphan another lesson's state.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_course_progress (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            enrolment_id BIGINT UNSIGNED NOT NULL,
            lesson_slug VARCHAR(100) NOT NULL,
            read_at DATETIME DEFAULT NULL,
            quiz_passed_at DATETIME DEFAULT NULL,
            assignment_approved_at DATETIME DEFAULT NULL,
            tool_state LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_enrolment_lesson (enrolment_id, lesson_slug),
            KEY idx_club_enrolment (club_id, enrolment_id)
        ) $charset;" );

        // Every quiz attempt, kept for the record.
        //
        // `answers` is the submitted payload, not the marked one. Re-marking
        // an old attempt after a quiz is corrected should be possible; storing
        // only the score would make that impossible to audit.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_course_quiz_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            enrolment_id BIGINT UNSIGNED NOT NULL,
            lesson_slug VARCHAR(100) NOT NULL,
            answers LONGTEXT NULL,
            score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_enrolment_lesson (enrolment_id, lesson_slug, created_at),
            KEY idx_club_enrolment (club_id, enrolment_id)
        ) $charset;" );

        // A practical assignment and the verdict on it.
        //
        // `outcome` is empty while the submission is awaiting review, which
        // is also what the reviewer queue selects on. `reviewer_person_id`
        // is set on assignment to a reviewer, not on submission, so an
        // unrouted submission is visible to anyone holding the capability
        // rather than invisible to everyone.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_course_submissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            enrolment_id BIGINT UNSIGNED NOT NULL,
            lesson_slug VARCHAR(100) NOT NULL,
            assignment_key VARCHAR(100) NOT NULL DEFAULT '',
            body LONGTEXT NULL,
            submitted_at DATETIME DEFAULT NULL,
            reviewer_person_id BIGINT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            outcome VARCHAR(20) NOT NULL DEFAULT '',
            feedback LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_enrolment_lesson (enrolment_id, lesson_slug),
            KEY idx_club_outcome (club_id, outcome, submitted_at),
            KEY idx_reviewer (club_id, reviewer_person_id, outcome)
        ) $charset;" );
    }
};
