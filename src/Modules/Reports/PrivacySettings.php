<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PrivacySettings — opt-in flags for what a generated report includes.
 *
 * Sprint 3 (#0014). Conservative defaults: photo on, everything else
 * off. The wizard surfaces these explicitly so a coach can opt back
 * into things per report.
 *
 * Persisted via `toArray` for the scout-flow `tt_player_reports` row.
 */
final class PrivacySettings {

    public bool  $include_contact_details;
    public bool  $include_full_dob;
    public bool  $include_photo;
    public bool  $include_coach_notes;
    public float $min_rating_threshold;

    public function __construct(
        bool $include_contact_details = false,
        bool $include_full_dob        = false,
        bool $include_photo           = true,
        bool $include_coach_notes     = false,
        float $min_rating_threshold   = 0.0
    ) {
        $this->include_contact_details = $include_contact_details;
        $this->include_full_dob        = $include_full_dob;
        $this->include_photo           = $include_photo;
        $this->include_coach_notes     = $include_coach_notes;
        $this->min_rating_threshold    = $min_rating_threshold;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray( array $data ): self {
        return new self(
            ! empty( $data['include_contact_details'] ),
            ! empty( $data['include_full_dob'] ),
            ! isset( $data['include_photo'] ) || ! empty( $data['include_photo'] ),
            ! empty( $data['include_coach_notes'] ),
            isset( $data['min_rating_threshold'] ) ? (float) $data['min_rating_threshold'] : 0.0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'include_contact_details' => $this->include_contact_details,
            'include_full_dob'        => $this->include_full_dob,
            'include_photo'           => $this->include_photo,
            'include_coach_notes'     => $this->include_coach_notes,
            'min_rating_threshold'    => $this->min_rating_threshold,
        ];
    }
}
