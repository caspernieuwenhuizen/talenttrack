<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GitHubPromoter — commits an approved staged idea directly to the
 * `ideas/` folder of the GitHub repo.
 *
 * Transport: REST API via `wp_remote_*()`. No git binary, no shelling
 * out. Auth: fine-grained PAT in `wp-config.php` constant
 * `TT_GITHUB_TOKEN` with `contents: write` on the one repo.
 *
 * The promotion sequence:
 *   1. Fetch ideas/, specs/, specs/shipped/ contents → max NNNN.
 *   2. Allocate next ID = max + 1.
 *   3. PUT `ideas/<NNNN>-<type>-<slug>.md` with branch=main.
 *   4. On 422 ("already exists") refetch and retry once.
 *   5. On any other failure store the error and surface it in the UI.
 *
 * Branch protection on `main` is currently OFF (verified during #0009
 * shaping). If protection is re-enabled later, the PUT will fail with
 * 422 in a way the retry can't recover from — admin sees the error
 * banner with the GitHub message, decides whether to relax protection
 * or switch this class to a branch + PR flow.
 */
class GitHubPromoter {

    private const API = 'https://api.github.com';

    private IdeaRepository $repo;

    public function __construct( ?IdeaRepository $repo = null ) {
        $this->repo = $repo ?? new IdeaRepository();
    }

    public static function tokenAvailable(): bool {
        return defined( 'TT_GITHUB_TOKEN' ) && is_string( TT_GITHUB_TOKEN ) && TT_GITHUB_TOKEN !== '';
    }

    public static function repoSlug(): string {
        if ( defined( 'TT_IDEAS_REPO' ) && is_string( TT_IDEAS_REPO ) && TT_IDEAS_REPO !== '' ) {
            return (string) TT_IDEAS_REPO;
        }
        return 'caspernieuwenhuizen/talenttrack';
    }

    public static function baseBranch(): string {
        if ( defined( 'TT_IDEAS_BASE_BRANCH' ) && is_string( TT_IDEAS_BASE_BRANCH ) && TT_IDEAS_BASE_BRANCH !== '' ) {
            return (string) TT_IDEAS_BASE_BRANCH;
        }
        return 'main';
    }

    /**
     * Promote an idea row by id. Caller is expected to have already
     * called `IdeaRepository::claimForPromotion()` to flip status to
     * `promoting`. Returns ['ok'=>bool, 'commit_url'=>string|null,
     * 'filename'=>string|null, 'error'=>string|null].
     *
     * @return array{ok:bool, commit_url:?string, filename:?string, error:?string, assigned_id:?int}
     */
    public function promote( int $ideaId ): array {
        $idea = $this->repo->find( $ideaId );
        if ( ! $idea ) {
            return $this->fail( $ideaId, 'idea_not_found', __( 'Idea row not found.', 'talenttrack' ) );
        }
        if ( ! self::tokenAvailable() ) {
            return $this->fail( $ideaId, 'token_missing', __( 'TT_GITHUB_TOKEN is not configured in wp-config.php.', 'talenttrack' ) );
        }

        $type = (string) ( $idea->type ?? IdeaType::NEEDS_TRIAGE );
        if ( ! IdeaType::isValid( $type ) ) {
            return $this->fail( $ideaId, 'bad_type', sprintf( /* translators: %s = type value */ __( 'Invalid type "%s".', 'talenttrack' ), $type ) );
        }
        $slug = $this->slugify( (string) ( $idea->slug ?: $idea->title ) );
        if ( $slug === '' ) {
            return $this->fail( $ideaId, 'bad_slug', __( 'Slug is empty — set a title or slug before promoting.', 'talenttrack' ) );
        }

        // Try with the next free ID, retry once on 422 collision.
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $nextId = $this->nextFreeId();
            if ( $nextId === null ) {
                return $this->fail( $ideaId, 'list_failed', __( 'Could not list existing ideas/specs to allocate an ID.', 'talenttrack' ) );
            }
            $filename = sprintf( '%04d-%s-%s.md', $nextId, $type, $slug );
            $body     = $this->renderFileBody( $idea, $type );
            $message  = sprintf( 'Add idea #%04d: %s', $nextId, (string) $idea->title );

            $put = $this->putFile( "ideas/{$filename}", $body, $message );
            if ( $put['status'] === 201 || $put['status'] === 200 ) {
                $commitUrl = $this->extractCommitUrl( $put['body'] );
                $this->repo->update( $ideaId, [
                    'status'              => IdeaStatus::PROMOTED,
                    'promoted_filename'   => $filename,
                    'promoted_commit_url' => $commitUrl,
                    'promotion_error'     => null,
                    'promoted_at'         => current_time( 'mysql' ),
                ] );
                do_action( 'tt_dev_idea_status_changed', $ideaId, IdeaStatus::PROMOTED );
                return [
                    'ok'          => true,
                    'commit_url'  => $commitUrl,
                    'filename'    => $filename,
                    'error'       => null,
                    'assigned_id' => $nextId,
                ];
            }

            if ( $put['status'] === 422 ) {
                // Race: another commit landed between our list + put.
                // Loop once with the freshly-fetched max + 1.
                continue;
            }

            // Any other error — give up immediately.
            return $this->fail( $ideaId, 'put_failed', sprintf(
                /* translators: 1: HTTP status code, 2: error body */
                __( 'GitHub API returned %1$d: %2$s', 'talenttrack' ),
                $put['status'],
                $this->summariseError( $put['body'] )
            ) );
        }

        return $this->fail( $ideaId, 'race_persistent', __( 'Two ID-allocation collisions in a row — promotion aborted. Retry from the approval queue.', 'talenttrack' ) );
    }

    /**
     * Allocate the next free NNNN by listing the three folders we care
     * about and taking max + 1. Returns null on a list error.
     */
    private function nextFreeId(): ?int {
        $maxes = [];
        foreach ( [ 'ideas', 'specs', 'specs/shipped' ] as $path ) {
            $list = $this->listFolder( $path );
            if ( $list === null ) {
                if ( $path === 'specs/shipped' ) {
                    // 404 here is fine — folder is optional.
                    continue;
                }
                return null;
            }
            foreach ( $list as $entry ) {
                $name = (string) ( $entry['name'] ?? '' );
                if ( preg_match( '/^(\d{4})-/', $name, $m ) ) {
                    $maxes[] = (int) $m[1];
                }
            }
        }
        $max = $maxes ? max( $maxes ) : 0;
        return $max + 1;
    }

    /**
     * GET /repos/:slug/contents/:path. Returns the parsed JSON array of
     * entries, or null on network failure / 404.
     *
     * @return list<array<string,mixed>>|null
     */
    private function listFolder( string $path ): ?array {
        $url = sprintf( '%s/repos/%s/contents/%s?ref=%s',
            self::API,
            self::repoSlug(),
            $path,
            rawurlencode( self::baseBranch() )
        );
        $resp = wp_remote_get( $url, [
            'headers' => $this->headers(),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $resp ) ) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code === 404 ) {
            return null;
        }
        if ( $code !== 200 ) {
            return null;
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) return [];
        return $body;
    }

    /**
     * PUT /repos/:slug/contents/:path. Returns ['status'=>int,
     * 'body'=>array|string].
     *
     * @return array{status:int, body:mixed}
     */
    private function putFile( string $path, string $content, string $message ): array {
        $url = sprintf( '%s/repos/%s/contents/%s',
            self::API,
            self::repoSlug(),
            $path
        );
        $payload = [
            'message' => $message,
            'content' => base64_encode( $content ),
            'branch'  => self::baseBranch(),
        ];
        $resp = wp_remote_request( $url, [
            'method'  => 'PUT',
            'headers' => $this->headers( true ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'status' => 0, 'body' => $resp->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        return [ 'status' => $code, 'body' => $body ?: (string) wp_remote_retrieve_body( $resp ) ];
    }

    /**
     * @return array<string,string>
     */
    private function headers( bool $withContentType = false ): array {
        $h = [
            'Accept'        => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . TT_GITHUB_TOKEN,
            'User-Agent'    => 'TalentTrack-Plugin',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ( $withContentType ) {
            $h['Content-Type'] = 'application/json';
        }
        return $h;
    }

    private function renderFileBody( object $idea, string $type ): string {
        $title = (string) $idea->title;
        $body  = (string) ( $idea->body ?? '' );
        $author = '';
        $u = get_userdata( (int) ( $idea->author_user_id ?? 0 ) );
        if ( $u ) {
            $author = sprintf( '%s <%s>', $u->display_name, $u->user_email );
        }
        $approver = '';
        $a = get_userdata( get_current_user_id() );
        if ( $a ) {
            $approver = sprintf( '%s <%s>', $a->display_name, $a->user_email );
        }

        $out  = sprintf( "<!-- type: %s -->\n\n", $type );
        $out .= sprintf( "# %s\n\n", $title );
        $out .= trim( $body ) . "\n\n";
        $out .= "---\n\n";
        $out .= "_Promoted from TalentTrack staging._\n\n";
        if ( $author )   $out .= "Author: " . $author . "\n";
        if ( $approver ) $out .= "Approver: " . $approver . "\n";
        $out .= "Staging row: #" . (int) $idea->id . "\n";
        return $out;
    }

    /**
     * @param mixed $body
     */
    private function extractCommitUrl( $body ): ?string {
        if ( is_array( $body ) && isset( $body['commit']['html_url'] ) ) {
            return (string) $body['commit']['html_url'];
        }
        return null;
    }

    /**
     * @param mixed $body
     */
    private function summariseError( $body ): string {
        if ( is_array( $body ) ) {
            $msg = (string) ( $body['message'] ?? '' );
            if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
                $msg .= ' (' . wp_json_encode( $body['errors'] ) . ')';
            }
            return $msg !== '' ? $msg : wp_json_encode( $body );
        }
        return (string) $body;
    }

    /**
     * @return array{ok:bool, commit_url:?string, filename:?string, error:?string, assigned_id:?int}
     */
    private function fail( int $ideaId, string $code, string $message ): array {
        $idea = $this->repo->find( $ideaId );
        if ( $idea && (string) $idea->status === IdeaStatus::PROMOTING ) {
            $this->repo->update( $ideaId, [
                'status'          => IdeaStatus::PROMOTION_FAILED,
                'promotion_error' => $code . ': ' . $message,
            ] );
            do_action( 'tt_dev_idea_status_changed', $ideaId, IdeaStatus::PROMOTION_FAILED );
        }
        return [
            'ok'          => false,
            'commit_url'  => null,
            'filename'    => null,
            'error'       => $code . ': ' . $message,
            'assigned_id' => null,
        ];
    }

    private function slugify( string $text ): string {
        $slug = sanitize_title( $text );
        // sanitize_title returns lowercase kebab — exactly what we want.
        // Trim to a sane length.
        if ( strlen( $slug ) > 80 ) {
            $slug = substr( $slug, 0, 80 );
            $slug = rtrim( $slug, '-' );
        }
        return $slug;
    }
}
