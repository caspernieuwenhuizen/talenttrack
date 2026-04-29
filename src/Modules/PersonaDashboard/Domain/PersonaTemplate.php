<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PersonaTemplate — the resolved layout for a (persona, club) pair.
 *
 * Three bands compose the dashboard top-down:
 *   - hero     — XL widget rendered first (rate card, today, kpi strip…).
 *   - task     — optional sub-hero panel (coach nudge, pending PDP ack…).
 *   - grid     — the 12-col bento.
 *
 * `status` distinguishes draft vs published when sprint 2 lands. Sprint 1
 * always emits 'published' (defaults are immediately live behind the flag).
 */
final class PersonaTemplate {

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public string $persona_slug;
    public int $club_id;
    public ?WidgetSlot $hero;
    public ?WidgetSlot $task;
    public GridLayout $grid;
    public string $status;
    public int $version;

    public function __construct(
        string $persona_slug,
        int $club_id,
        ?WidgetSlot $hero,
        ?WidgetSlot $task,
        GridLayout $grid,
        string $status = self::STATUS_PUBLISHED,
        int $version = 1
    ) {
        $this->persona_slug = $persona_slug;
        $this->club_id      = $club_id;
        $this->hero         = $hero;
        $this->task         = $task;
        $this->grid         = $grid;
        $this->status       = $status;
        $this->version      = $version;
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'version'      => $this->version,
            'persona_slug' => $this->persona_slug,
            'club_id'      => $this->club_id,
            'status'       => $this->status,
            'hero'         => $this->hero ? $this->hero->toArray() : null,
            'task'         => $this->task ? $this->task->toArray() : null,
            'grid'         => $this->grid->toArray(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray( string $persona_slug, int $club_id, array $payload ): self {
        $hero_raw = $payload['hero'] ?? null;
        $task_raw = $payload['task'] ?? null;
        return new self(
            $persona_slug,
            $club_id,
            is_array( $hero_raw ) ? WidgetSlot::fromArray( $hero_raw ) : null,
            is_array( $task_raw ) ? WidgetSlot::fromArray( $task_raw ) : null,
            GridLayout::fromArray( is_array( $payload['grid'] ?? null ) ? $payload['grid'] : [] ),
            (string) ( $payload['status']  ?? self::STATUS_PUBLISHED ),
            (int) ( $payload['version'] ?? 1 )
        );
    }
}
