<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClaudeSonnetProvider (#0016 Sprint 4) — vision extraction via
 * Anthropic's Claude Sonnet 4.x model.
 *
 * Speaks the Anthropic Messages API: the photo as an `image/jpeg`
 * content block plus a structured-extraction prompt.
 *
 * ## There is no endpoint default (#2695)
 *
 * An earlier version of this docblock described a Bedrock
 * `eu-central-1` default and three `TT_VISION_BEDROCK_*` constants.
 * None of that was ever real — Bedrock needs SigV4 request signing,
 * which this class does not do, and no code has ever read those
 * constants. What existed instead was a silent fallback to Anthropic's
 * direct API, so an install that had merely switched the feature on
 * was already sending photographs somewhere nobody had chosen.
 *
 * The fallback is gone. The operator states the destination and what
 * it means, and nothing is sent until they have:
 *
 *     define( 'TT_VISION_PROVIDER',    'claude_sonnet' );
 *     define( 'TT_VISION_API_KEY',     'sk-ant-...' );
 *     define( 'TT_VISION_ENDPOINT',    'https://…' );          // required
 *     define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // required
 *
 * Routing to Bedrock would still need SigV4 and is not supported;
 * point `TT_VISION_ENDPOINT` at something that speaks the Messages
 * API.
 *
 * **Extraction quality is unvalidated.** The provider shootout
 * (calendar-time, needs real coach photos) has not happened, so the
 * prompt and the matcher tuning are first-pass. Operator review across
 * 10–15 real photos is required before broad deployment.
 */
final class ClaudeSonnetProvider extends AbstractStubProvider {

    public function key(): string {
        return 'claude_sonnet';
    }

    /**
     * No region in the label. It used to say "via Bedrock, EU-Central",
     * which was not true of any code path and is exactly the kind of
     * reassurance an operator would reasonably rely on.
     */
    public function label(): string {
        return __( 'Claude Sonnet', 'talenttrack' );
    }

    public function extractSessionFromImage( string $image_bytes, array $context = [] ): ExtractedSession {
        if ( $image_bytes === '' ) {
            throw new \RuntimeException( 'Empty image payload — nothing to extract.' );
        }
        // Region first: an install with a key but no declared
        // destination should be told what is actually missing, not
        // handed a generic "not configured".
        VisionDataRegion::assertDeclared();

        if ( ! $this->isConfigured() ) {
            throw new \RuntimeException( 'Claude Sonnet provider is not configured. Set TT_VISION_PROVIDER and TT_VISION_API_KEY in wp-config.php.' );
        }

        // Defensive size cap — Anthropic's Messages API rejects
        // images > 5 MB. Coaches sometimes upload high-res phone
        // photos that exceed that; the wizard's pre-upload step
        // should resize, but we reject loudly here as a backstop.
        if ( strlen( $image_bytes ) > 5 * 1024 * 1024 ) {
            throw new \RuntimeException( 'Image is larger than 5 MB. Resize before upload.' );
        }

        $response = $this->callAnthropic( $image_bytes, $context );
        return $this->parseResponse( $response );
    }

    /**
     * Construct + dispatch the Messages API request. Returns the
     * decoded JSON body on success; throws on transport failure.
     *
     * @return array<string,mixed>
     */
    private function callAnthropic( string $image_bytes, array $context ): array {
        $body = [
            'model'      => 'claude-sonnet-4-20251020',  // pinned Claude 4.x; adjust at next model drop
            'max_tokens' => 2048,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'text',
                            'text'   => $this->buildPrompt( $context ),
                        ],
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'image/jpeg',
                                'data'       => base64_encode( $image_bytes ),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // #2695 — the declared endpoint, or nothing. `assertDeclared()`
        // in the caller has already refused an undeclared install, so
        // reaching here without one is impossible rather than defaulted.
        VisionDataRegion::assertDeclared();
        $endpoint = (string) VisionDataRegion::endpoint();

        $resp = wp_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => (string) constant( 'TT_VISION_API_KEY' ),
                'anthropic-version' => '2023-06-01',
            ],
            'body' => (string) wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) {
            throw new \RuntimeException( 'Vision provider transport error: ' . $resp->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $raw  = (string) wp_remote_retrieve_body( $resp );
        if ( $code < 200 || $code >= 300 ) {
            throw new \RuntimeException( sprintf( 'Vision provider returned HTTP %d: %s', $code, substr( $raw, 0, 200 ) ) );
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'Vision provider returned non-JSON body.' );
        }
        return $decoded;
    }

    /**
     * The structured-extraction prompt. Asks the model to return
     * JSON only — strict shape so `parseResponse()` can `json_decode`
     * the model output without freeform-text cleanup.
     *
     * Includes attendance instructions (Sprint 5) and "draft if
     * uncertain" guidance (Sprint 6) inline; the model is the same
     * across sprints, the prompt evolves.
     */
    private function buildPrompt( array $context ): string {
        $hint = '';
        if ( ! empty( $context['team_age_group'] ) ) {
            $hint .= sprintf( "\nThe team is %s.", (string) $context['team_age_group'] );
        }
        if ( ! empty( $context['language'] ) ) {
            $hint .= sprintf( "\nThe coach's primary language is %s.", (string) $context['language'] );
        }

        return <<<PROMPT
You are extracting a structured football training session from a photograph of a coach's hand-written training plan.{$hint}

Return ONLY a JSON object with this exact shape (no surrounding text, no markdown fences):

{
  "exercises": [
    {
      "name": "<short exercise name as written or paraphrased>",
      "duration_minutes": <integer>,
      "notes": "<any handwritten note next to the drill, or empty string>",
      "confidence": <float 0.0-1.0>
    }
  ],
  "attendance": [
    {
      "player_name": "<as written>",
      "marking": "<present|absent|late|injured>",
      "confidence": <float 0.0-1.0>
    }
  ],
  "overall_confidence": <float 0.0-1.0>,
  "notes": "<any handwritten margin notes the coach added — weather, mood, etc.>"
}

Rules:
- Order the exercises in the sequence they appear on the plan.
- If a duration is missing or illegible, set duration_minutes to 0.
- If you can't read part of an exercise name confidently, set confidence < 0.6.
- Player names belong ONLY in the "attendance" array. Never write a player's name into any "notes" field or into an exercise name, even if the coach wrote it there. If a note is about a specific player, describe it without the name — "one player working separately" rather than "Sem working separately". A name in the attendance array is attached to that player's record and can be found again; a name in free text cannot, so it can be neither exported nor erased when the player asks.
- attendance is optional — if no player names are visible, return an empty array.
- DO NOT invent exercises. DO NOT invent player names. Only extract what's actually on the photo.
- DO NOT wrap the JSON in any prose, markdown fences, or explanation.
PROMPT;
    }

    /**
     * Decode the model's text content into an `ExtractedSession`.
     * Anthropic's Messages API wraps the model output under
     * `content[0].text` for text-only completions.
     *
     * @param array<string,mixed> $response
     */
    private function parseResponse( array $response ): ExtractedSession {
        $text = '';
        foreach ( ( $response['content'] ?? [] ) as $block ) {
            if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' ) {
                $text = (string) ( $block['text'] ?? '' );
                break;
            }
        }
        if ( $text === '' ) {
            throw new \RuntimeException( 'Vision provider returned an empty completion.' );
        }

        // Strip code fences if the model added them despite the prompt.
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) );

        $data = json_decode( (string) $text, true );
        if ( ! is_array( $data ) ) {
            throw new \RuntimeException( 'Vision provider produced unparseable JSON.' );
        }

        $exercises = [];
        foreach ( ( $data['exercises'] ?? [] ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $exercises[] = new ExtractedExercise(
                (string) ( $row['name'] ?? '' ),
                (int) ( $row['duration_minutes'] ?? 0 ),
                (string) ( $row['notes'] ?? '' ),
                (float) ( $row['confidence'] ?? 0.0 )
            );
        }

        $attendance = [];
        foreach ( ( $data['attendance'] ?? [] ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $attendance[] = [
                'player_name' => (string) ( $row['player_name'] ?? '' ),
                'marking'     => (string) ( $row['marking'] ?? '' ),
                'confidence'  => (float) ( $row['confidence'] ?? 0.0 ),
            ];
        }

        return new ExtractedSession(
            $exercises,
            $attendance,
            (float) ( $data['overall_confidence'] ?? 0.0 ),
            (string) ( $data['notes'] ?? '' )
        );
    }
}
