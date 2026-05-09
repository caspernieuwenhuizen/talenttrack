<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClaudeSonnetProvider (#0016 Sprint 4) — vision extraction via
 * Anthropic's Claude Sonnet 4.x model.
 *
 * Production endpoint default: AWS Bedrock `eu-central-1` for EU
 * data residency (DPIA hard requirement — minor athletes' data
 * cannot leave the EU). Per the DPIA scope: photos are passed to
 * the provider, the model is asked to extract structured fields,
 * the response is returned and the source photo is deleted from
 * server storage within 7 days (no persistence at the provider
 * side per Anthropic's Bedrock terms).
 *
 * Configuration via wp-config.php:
 *
 *     define( 'TT_VISION_PROVIDER', 'claude_sonnet' );
 *     define( 'TT_VISION_API_KEY', 'sk-ant-...' );           // direct API
 *     define( 'TT_VISION_ENDPOINT', '...' );                  // override
 *     // OR for Bedrock:
 *     define( 'TT_VISION_BEDROCK_REGION', 'eu-central-1' );
 *     define( 'TT_VISION_BEDROCK_ACCESS_KEY', '...' );
 *     define( 'TT_VISION_BEDROCK_SECRET_KEY', '...' );
 *
 * **Status (v3.110.40 / Sprint 4 ship)**: this implementation calls
 * the Anthropic Messages API with the photo as a `image/jpeg`
 * content block + a structured-extraction prompt. The wp-config
 * indirection means the same code routes to Bedrock or direct
 * Anthropic depending on which constants are set. The shootout
 * (calendar-time, requires real coach photos) has not happened
 * yet, so the prompt + the matcher tuning are first-pass best-
 * effort — operator review of extraction quality across 10-15
 * real photos must validate before broad deployment per the spec.
 */
final class ClaudeSonnetProvider extends AbstractStubProvider {

    public function key(): string {
        return 'claude_sonnet';
    }

    public function label(): string {
        return __( 'Claude Sonnet (via Bedrock, EU-Central)', 'talenttrack' );
    }

    public function extractSessionFromImage( string $image_bytes, array $context = [] ): ExtractedSession {
        if ( $image_bytes === '' ) {
            throw new \RuntimeException( 'Empty image payload — nothing to extract.' );
        }
        if ( ! $this->isConfigured() ) {
            throw new \RuntimeException( 'Claude Sonnet provider is not configured. Set TT_VISION_API_KEY in wp-config.php (or the Bedrock equivalent).' );
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

        $endpoint = defined( 'TT_VISION_ENDPOINT' ) && constant( 'TT_VISION_ENDPOINT' ) !== ''
            ? (string) constant( 'TT_VISION_ENDPOINT' )
            : 'https://api.anthropic.com/v1/messages';

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
