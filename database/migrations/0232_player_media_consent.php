<?php
/**
 * Migration 0232 — photo and video consent on the player (#2744).
 *
 * The media library stores photographs of children indefinitely and had
 * no concept of consent at all. `tt_prospects` already carries a
 * `consent_given_at`, and the photo-capture DPIA lists a consent surface
 * as an open prerequisite — for a feature that never persists an image.
 * The library, which does, had less around it.
 *
 * Three columns, on the **player** rather than on the media item. A
 * likeness belongs to the child, not to the file, so anything else that
 * captures one reads the same answer instead of inventing its own.
 *
 *   media_consent      whether consent is on record
 *   media_consent_at   when it was recorded
 *   media_consent_by   which staff member recorded it
 *
 * The provenance columns are the point. A bare boolean is an assertion;
 * a boolean with a date and a name is evidence, and evidence is what a
 * consent record exists to be. They are written together with the flag
 * and cleared together with it.
 *
 * `media_consent_by` is the staff member who entered the record, NOT the
 * person who gave consent — for a minor that is a parent or guardian who
 * signed something on paper. If distinguishing the signatory ever
 * matters, that is a further column and a further decision.
 *
 * Deliberately NOT an enforcement mechanism. Nothing in the upload path
 * reads these columns; a coach can add a photo of a child whose parent
 * refused, and the product will not stop them. That was decided
 * knowingly: the academy's real control is the paper form, and a hard
 * block at the side of a pitch is worked around by photographing on a
 * personal phone instead, which is worse for the child than a recorded
 * gap. `MediaConsentTest` pins the absence of a gate so nobody later
 * reads it as an oversight and "fixes" it by accident.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0232_player_media_consent';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_players';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'media_consent', 'TINYINT(1) NOT NULL DEFAULT 0' );
        MigrationHelpers::addColumnIfMissing( $table, 'media_consent_at', 'DATETIME DEFAULT NULL' );
        MigrationHelpers::addColumnIfMissing( $table, 'media_consent_by', 'BIGINT UNSIGNED DEFAULT NULL' );
    }
};
