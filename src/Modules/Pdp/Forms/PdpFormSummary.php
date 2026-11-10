<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpFormSummary (#4979, epic #4975) — one author's part of a PDP
 * conversation form, in short: how many items are scored, the average per
 * section, and whether the text fields are answered.
 *
 * The player's preparation summary composes it, and so can any other
 * surface or a REST consumer, from the same `PdpFormReader` output. It reads
 * nothing itself.
 */
final class PdpFormSummary {

    /**
     * @param array<string,mixed>    $definition a form definition (`PdpFormRegistry::definition()`)
     * @param array<string,int|null> $scores     item key => 1-5 or null
     * @param array<string,string>   $answers    field key => text
     * @param string                 $author     `player` or `coach`: whose text fields count
     * @return array{
     *   items:int, scored:int,
     *   sections:list<array{key:string, title:string, items:int, scored:int, average:float|null}>,
     *   fields:int, answered:int, complete:bool
     * }
     */
    public static function of( array $definition, array $scores, array $answers, string $author ): array {
        $sections = [];
        $items    = 0;
        $scored   = 0;
        foreach ( (array) $definition['sections'] as $section ) {
            $sum = 0;
            $n   = 0;
            $all = 0;
            foreach ( (array) $section['items'] as $item ) {
                $all++;
                $score = $scores[ (string) $item['key'] ] ?? null;
                if ( $score === null ) continue;
                $sum += (int) $score;
                $n++;
            }
            $sections[] = [
                'key'     => (string) $section['key'],
                'title'   => (string) $section['title'],
                'items'   => $all,
                'scored'  => $n,
                'average' => $n > 0 ? round( $sum / $n, 1 ) : null,
            ];
            $items  += $all;
            $scored += $n;
        }

        $fields   = PdpFormRegistry::answerFields( $definition, $author );
        $answered = 0;
        foreach ( $fields as $field ) {
            if ( trim( (string) ( $answers[ $field ] ?? '' ) ) !== '' ) $answered++;
        }

        return [
            'items'    => $items,
            'scored'   => $scored,
            'sections' => $sections,
            'fields'   => count( $fields ),
            'answered' => $answered,
            'complete' => $scored === $items && $answered === count( $fields ),
        ];
    }
}
