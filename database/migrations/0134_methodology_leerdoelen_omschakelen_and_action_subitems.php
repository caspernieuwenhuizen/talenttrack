<?php
/**
 * Migration 0134 — methodology seed top-up (#1061).
 *
 * Two seed gaps closed against the source PDF
 * `07. Voetbalmethode.pdf`:
 *
 * 1. Football actions (sheet 3, Introductie) — the `Spelinzicht` and
 *    `Communicatie` "Ondersteunend" buckets were seeded as single
 *    flat rows in 0018. The PDF decomposes each into sub-items; this
 *    migration adds 7 new `support`-category actions alongside the
 *    existing 11. Flat structure (no parent_action_id) per locked
 *    decision 2026-05-31.
 *
 * 2. Leerdoelen (sheet 6-7, Voetbalmodel) — 10 attacking + defending
 *    leerdoelen were seeded by 0018. The 2 Omschakelen leerdoelen
 *    visible on the same pages were missing. Per the source PDF
 *    these intentionally stay short ("op hoofdlijnen"); short bullet
 *    lists, no per-line drilldown.
 *
 * Idempotent UPSERT on (slug) for football actions and
 * (primer_id, slug) for leerdoelen — matches the 0018 pattern so
 * re-runs are safe.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0134_methodology_leerdoelen_omschakelen_and_action_subitems';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->seedFootballActionSubItems( $p );

        $primer_id = (int) $wpdb->get_var(
            "SELECT id FROM {$p}tt_methodology_framework_primers
              WHERE is_shipped = 1 AND club_scope IS NULL LIMIT 1"
        );
        if ( $primer_id > 0 ) {
            $this->seedOmschakelenLeerdoelen( $p, $primer_id );
        }
    }

    /* ─────────────────────── Football actions ─────────────────────── */

    private function seedFootballActionSubItems( string $p ): void {
        global $wpdb;
        foreach ( $this->footballActionSubItemsData() as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_football_actions WHERE slug = %s LIMIT 1",
                $row['slug']
            ) );
            $payload = [
                'category_key'     => $row['category_key'],
                'name_json'        => wp_json_encode( $row['name'] ),
                'description_json' => wp_json_encode( $row['description'] ),
                'sort_order'       => (int) $row['sort_order'],
            ];
            if ( $existing > 0 ) {
                $wpdb->update( "{$p}tt_football_actions", $payload, [ 'id' => $existing ] );
            } else {
                $payload['slug']       = $row['slug'];
                $payload['is_shipped'] = 1;
                $wpdb->insert( "{$p}tt_football_actions", $payload );
            }
        }
    }

    private function footballActionSubItemsData(): array {
        return [
            // Spelinzicht sub-items (continues sort_order from 11).
            [
                'slug' => 'spelinzicht-individueel', 'category_key' => 'support', 'sort_order' => 12,
                'name' => [ 'nl' => 'Individuele voetballer', 'en' => 'Individual reading' ],
                'description' => [
                    'nl' => 'De individuele voetballer leest de fase: ruimte, tegenstander, medespeler, eigen positie.',
                    'en' => 'The individual player reads the phase: space, opponent, teammate, own position.',
                ],
            ],
            [
                'slug' => 'spelinzicht-keuzes', 'category_key' => 'support', 'sort_order' => 13,
                'name' => [ 'nl' => 'Keuzes', 'en' => 'Decisions' ],
                'description' => [
                    'nl' => 'Op basis van wat hij ziet de juiste beslissing nemen: kort of lang, dribbelen of passen, doorgaan of inhouden.',
                    'en' => 'Pick the right decision from what is observed: short or long, dribble or pass, continue or hold.',
                ],
            ],
            [
                'slug' => 'spelinzicht-waarnemen', 'category_key' => 'support', 'sort_order' => 14,
                'name' => [ 'nl' => 'Waarnemen', 'en' => 'Scanning' ],
                'description' => [
                    'nl' => 'Scannen vóór de bal komt; weten waar ruimte, tegenstander en medespeler staan.',
                    'en' => 'Scan before the ball arrives; know where space, opponent and teammate are.',
                ],
            ],
            [
                'slug' => 'spelinzicht-koppeling-teamtaak', 'category_key' => 'support', 'sort_order' => 15,
                'name' => [ 'nl' => 'Koppeling teamtaak / teamfunctie', 'en' => 'Link to team-task / team-function' ],
                'description' => [
                    'nl' => 'Het eigen handelen koppelen aan de actieve teamtaak en teamfunctie van het moment.',
                    'en' => 'Link your own action to the active team task and team function of the moment.',
                ],
            ],

            // Communicatie sub-items.
            [
                'slug' => 'communicatie-spelinzicht-team', 'category_key' => 'support', 'sort_order' => 16,
                'name' => [ 'nl' => 'Spelinzicht op teamniveau', 'en' => 'Team-level reading' ],
                'description' => [
                    'nl' => 'Het collectieve beeld delen — wat ziet de groep, wat is de afspraak?',
                    'en' => 'Share the collective view — what does the group see, what is the agreement?',
                ],
            ],
            [
                'slug' => 'communicatie-afstemmen-handelingen', 'category_key' => 'support', 'sort_order' => 17,
                'name' => [ 'nl' => 'Afstemmen voetbalhandelingen', 'en' => 'Align football actions' ],
                'description' => [
                    'nl' => 'Verbaal en non-verbaal afstemmen wie wat doet en wanneer.',
                    'en' => 'Coordinate verbally and non-verbally who does what and when.',
                ],
            ],
            [
                'slug' => 'communicatie-waarnemen', 'category_key' => 'support', 'sort_order' => 18,
                'name' => [ 'nl' => 'Waarnemen', 'en' => 'Scanning (team)' ],
                'description' => [
                    'nl' => 'Risico\'s signaleren voor de groep: loop, vrije man, druk, kaart.',
                    'en' => 'Signal risks to the group: runs, free player, pressure, cards.',
                ],
            ],
        ];
    }

    /* ─────────────────────── Omschakelen leerdoelen ─────────────────────── */

    private function seedOmschakelenLeerdoelen( string $p, int $primer_id ): void {
        global $wpdb;
        foreach ( $this->omschakelenLeerdoelenData() as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_methodology_learning_goals
                  WHERE primer_id = %d AND slug = %s LIMIT 1",
                $primer_id, $row['slug']
            ) );
            $payload = [
                'side'          => $row['side'],
                'team_task_key' => $row['team_task_key'],
                'title_json'    => wp_json_encode( $row['title'] ),
                'bullets_json'  => wp_json_encode( $row['bullets'] ),
                'sort_order'    => (int) $row['sort_order'],
            ];
            if ( $existing > 0 ) {
                $wpdb->update( "{$p}tt_methodology_learning_goals", $payload, [ 'id' => $existing ] );
            } else {
                $payload['primer_id']  = $primer_id;
                $payload['slug']       = $row['slug'];
                $payload['is_shipped'] = 1;
                $wpdb->insert( "{$p}tt_methodology_learning_goals", $payload );
            }
        }
    }

    private function omschakelenLeerdoelenData(): array {
        return [
            [
                'slug' => 'omschakelen-na-balwinst', 'side' => 'transition',
                'team_task_key' => 'overgang_balwinst', 'sort_order' => 11,
                'title' => [ 'nl' => 'Omschakelen na balwinst', 'en' => 'Transition after winning the ball' ],
                'bullets' => [
                    'nl' => [
                        'Ruimte herkennen',
                        'Positie kiezen',
                        'De vrije man vinden',
                    ],
                    'en' => [
                        'Recognise space',
                        'Pick a position',
                        'Find the free player',
                    ],
                ],
            ],
            [
                'slug' => 'omschakelen-na-balverlies', 'side' => 'transition',
                'team_task_key' => 'overgang_balverlies', 'sort_order' => 12,
                'title' => [ 'nl' => 'Omschakelen na balverlies', 'en' => 'Transition after losing the ball' ],
                'bullets' => [
                    'nl' => [
                        'Gevaar herkennen',
                        'Positie kiezen',
                        'Communiceren',
                    ],
                    'en' => [
                        'Recognise danger',
                        'Pick a position',
                        'Communicate',
                    ],
                ],
            ],
        ];
    }
};
