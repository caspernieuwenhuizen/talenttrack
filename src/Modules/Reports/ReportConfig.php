<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReportConfig — every decision the report wizard captures.
 *
 * Sprint 3 (#0014). The renderer is deliberately small: it consumes
 * one of these and emits HTML. Construction lives in the wizard
 * (Sprint 4) or in `::standard()` (preserves the legacy ?print=1
 * behaviour byte-for-byte).
 *
 * `tone_variant` is added in Sprint 4. Left here so the renderer can
 * branch without growing the API later.
 *
 * Section keys: 'profile', 'ratings', 'goals', 'sessions',
 *               'attendance', 'coach_notes'.
 *
 * Date filter keys: 'date_from' (YYYY-MM-DD or empty), 'date_to' (idem),
 *                   'eval_type_id' (int, 0 = all). Same shape as
 *                   `PlayerStatsService::sanitizeFilters`.
 */
final class ReportConfig {

    /** Audience constant from {@see AudienceType}. */
    public string           $audience;

    /** @var array{date_from:string, date_to:string, eval_type_id:int} */
    public array            $filters;

    /** @var string[] Whitelist of sections to include. */
    public array            $sections;

    public PrivacySettings  $privacy;

    public int              $player_id;

    public int              $generated_by;

    public \DateTimeImmutable $generated_at;

    /** Tone variant — 'default' | 'warm' | 'formal' | 'fun'. Used in Sprint 4. */
    public string           $tone_variant;

    /**
     * @param array{date_from:string, date_to:string, eval_type_id:int} $filters
     * @param string[] $sections
     */
    public function __construct(
        string           $audience,
        array            $filters,
        array            $sections,
        PrivacySettings  $privacy,
        int              $player_id,
        int              $generated_by,
        ?\DateTimeImmutable $generated_at = null,
        string           $tone_variant = 'default'
    ) {
        $this->audience     = AudienceType::isValid( $audience ) ? $audience : AudienceType::STANDARD;
        $this->filters      = self::normaliseFilters( $filters );
        $this->sections     = self::normaliseSections( $sections );
        $this->privacy      = $privacy;
        $this->player_id    = $player_id;
        $this->generated_by = $generated_by;
        $this->generated_at = $generated_at ?? new \DateTimeImmutable( 'now' );
        $this->tone_variant = in_array( $tone_variant, [ 'default', 'warm', 'formal', 'fun' ], true ) ? $tone_variant : 'default';
    }

    /**
     * The legacy default — preserves `PlayerReportView::render`'s output.
     * Every section on, no privacy redaction beyond the conservative
     * defaults that match what the legacy view rendered (everything
     * shown, no contact details surfaced because the legacy view never
     * surfaced them anyway).
     *
     * @param array<string, mixed> $filters Raw $_GET-shaped filters.
     */
    public static function standard( int $player_id, int $generated_by, array $filters = [] ): self {
        return new self(
            AudienceType::STANDARD,
            self::sanitizeRawFilters( $filters ),
            self::allSections(),
            new PrivacySettings(
                false, // contact details — legacy never showed them
                false, // full DOB       — legacy never showed it
                true,  // photo          — legacy showed it
                false, // coach notes    — legacy never showed them
                0.0
            ),
            $player_id,
            $generated_by,
            null,
            'default'
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray( array $data ): self {
        $generated_at = null;
        if ( ! empty( $data['generated_at'] ) ) {
            try {
                $generated_at = new \DateTimeImmutable( (string) $data['generated_at'] );
            } catch ( \Exception $e ) {
                $generated_at = null;
            }
        }
        return new self(
            (string) ( $data['audience'] ?? AudienceType::STANDARD ),
            self::sanitizeRawFilters( (array) ( $data['filters'] ?? [] ) ),
            (array) ( $data['sections'] ?? self::allSections() ),
            isset( $data['privacy'] ) && is_array( $data['privacy'] )
                ? PrivacySettings::fromArray( $data['privacy'] )
                : new PrivacySettings(),
            (int) ( $data['player_id'] ?? 0 ),
            (int) ( $data['generated_by'] ?? 0 ),
            $generated_at,
            (string) ( $data['tone_variant'] ?? 'default' )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'audience'     => $this->audience,
            'filters'      => $this->filters,
            'sections'     => $this->sections,
            'privacy'      => $this->privacy->toArray(),
            'player_id'    => $this->player_id,
            'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at->format( DATE_ATOM ),
            'tone_variant' => $this->tone_variant,
        ];
    }

    public function includesSection( string $section ): bool {
        return in_array( $section, $this->sections, true );
    }

    /**
     * @return string[]
     */
    public static function allSections(): array {
        return [ 'profile', 'ratings', 'goals', 'sessions', 'attendance', 'coach_notes' ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{date_from:string, date_to:string, eval_type_id:int}
     */
    private static function sanitizeRawFilters( array $raw ): array {
        $from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        $type = isset( $raw['eval_type_id'] ) ? (int) $raw['eval_type_id'] : 0;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to = '';
        return [
            'date_from'    => $from,
            'date_to'      => $to,
            'eval_type_id' => max( 0, $type ),
        ];
    }

    /**
     * @param array{date_from?:string, date_to?:string, eval_type_id?:int}|array<string,mixed> $filters
     * @return array{date_from:string, date_to:string, eval_type_id:int}
     */
    private static function normaliseFilters( array $filters ): array {
        return self::sanitizeRawFilters( $filters );
    }

    /**
     * @param string[] $sections
     * @return string[]
     */
    private static function normaliseSections( array $sections ): array {
        $valid = self::allSections();
        $clean = [];
        foreach ( $sections as $s ) {
            $s = (string) $s;
            if ( in_array( $s, $valid, true ) && ! in_array( $s, $clean, true ) ) {
                $clean[] = $s;
            }
        }
        return $clean ?: $valid;
    }
}
