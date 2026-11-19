<?php

declare(strict_types=1);

namespace SimpleDevis\Tools\SuperPdp;

use RuntimeException;

/** Temporary, standalone capture. Never persist raw session IDs or OAuth values. */
final class OAuthHttpTrace
{
    public const TTL = 1800;

    private $file;

    private array $state;

    private array $event;

    public function __construct(string $directory, int $now)
    {
        $path = $directory.'/trace-state.json';
        if (! is_dir($directory) || is_link($directory) || is_link($path)) {
            throw new RuntimeException('trace_directory_invalid');
        }
        $this->file = fopen($path, 'c+b');
        if ($this->file === false || ! flock($this->file, LOCK_EX)) {
            throw new RuntimeException('trace_lock_unavailable');
        }
        if (fstat($this->file)['size'] > 1048576) {
            throw new RuntimeException('trace_size_invalid');
        }
        $text = stream_get_contents($this->file);
        $this->state = $text === '' ? ['key' => bin2hex(random_bytes(32)), 'sequence' => 0, 'forms' => [], 'events' => []]
            : json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        $this->state['forms'] = array_filter($this->state['forms'], static fn (array $form): bool => $form['at'] > $now - self::TTL);
        $this->state['events'] = array_values(array_filter($this->state['events'], static fn (array $event): bool => $event['at'] > $now - self::TTL));
        $this->state['forms'] = array_slice($this->state['forms'], -256, null, true);
        $this->state['events'] = array_slice($this->state['events'], -511);
    }

    public function begin(array $server, array $post, array $session, #[\SensitiveParameter] string $sessionId,
        #[\SensitiveParameter] mixed $cookie, string $revision, int $now): ?string
    {
        $method = $server['REQUEST_METHOD'] ?? '';
        $uri = $server['REQUEST_URI'] ?? '';
        $tag = hash_hmac('sha256', $sessionId, $this->state['key']);
        $latest = null;
        foreach ($this->state['forms'] as $id => $form) {
            if (hash_equals($form['session_tag'], $tag)) {
                $latest = $id;
            }
        }
        $submittedId = $post['diagnostic_id'] ?? null;
        // Never echo an arbitrary submitted value: only IDs generated here.
        $id = is_string($submittedId) && isset($this->state['forms'][$submittedId]) ? $submittedId : null;
        $reference = $id === null ? null : $this->state['forms'][$id];
        $newForm = $method === 'GET' && $uri === '/';
        if ($newForm) {
            $id = bin2hex(random_bytes(16));
            $this->state['forms'][$id] = ['at' => $now, 'session_tag' => $tag, 'revision' => $revision];
        }
        unset($post['diagnostic_id']); // Metadata never participates in OAuth validation.
        $observation = AuthorizationCodeWeb::startObservation($server, $post, $session);
        $this->event = [
            'at' => $now, 'request_order' => ++$this->state['sequence'],
            'diagnostic_id' => $id, 'previous_form_id' => $latest,
            'method' => in_array($method, ['GET', 'POST', 'HEAD'], true) ? $method : 'other',
            'route' => in_array($uri, ['/', '/oauth/start', '/favicon.ico'], true) ? $uri : 'other',
            'code_revision' => $revision,
            'form_revision_matches' => $reference === null ? null : hash_equals($reference['revision'], $revision),
            'cookie_present' => $cookie !== null,
            'incoming_cookie_retained' => $cookie === null ? null : (is_string($cookie) && hash_equals($sessionId, $cookie)),
            'session_continuity' => $reference === null ? 'unknown' : (hash_equals($reference['session_tag'], $tag) ? 'same' : 'different'),
            'nonce_server_present' => $observation['checks']['session_csrf_present'],
            'nonce_submitted_present' => $observation['checks']['submitted_csrf_present'],
            'csrf_matches' => $observation['checks']['csrf_matches'],
        ];

        return $id;
    }

    /** The extra field is removed only by this isolated capture adapter. */
    public static function oauthPost(array $post): array
    {
        unset($post['diagnostic_id']);

        return $post;
    }

    public static function decorate(string $html, string $id, string $revision): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id) || ! preg_match('/^[a-f0-9]{16}$/D', $revision)) {
            throw new RuntimeException('trace_metadata_invalid');
        }

        return str_replace('</form>', '<input type="hidden" name="diagnostic_id" value="'.$id.'"></form>'
            .'<p>Diagnostic local : <code>'.$id.'</code> — version <code>'.$revision.'</code>.'
            .' Capture active : aucune navigation SUPER PDP.</p>', $html);
    }

    /** Return an enum, never a raw response or exception. */
    public static function reason(array $server, array $post, array $before, int $status): string
    {
        if (($server['REQUEST_METHOD'] ?? '') === 'GET' && ($server['REQUEST_URI'] ?? '') === '/' && $status === 200) {
            return 'form_created';
        }
        if (($server['REQUEST_URI'] ?? '') === '/oauth/start') {
            $observation = AuthorizationCodeWeb::startObservation($server, self::oauthPost($post), $before);

            return $observation['accepted'] ? 'form_accepted_navigation_paused' : $observation['reason'];
        }

        return 'other_local_request';
    }

    public function finish(array $after, string $reason): array
    {
        $allowed = ['form_created', 'form_accepted_navigation_paused', 'other_local_request',
            'post_required', 'start_uri_invalid', 'origin_invalid', 'form_missing_or_already_consumed',
            'form_nonce_missing', 'form_stale_or_changed', 'unexpected_form_fields'];
        if (! in_array($reason, $allowed, true)) {
            throw new RuntimeException('trace_reason_invalid');
        }
        $this->event['nonce_server_after'] = is_string($after['csrf'] ?? null);
        $this->event['reason'] = $reason;
        $this->state['events'][] = $this->event;
        $json = json_encode($this->state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        rewind($this->file);
        if (fwrite($this->file, $json) !== strlen($json) || ! ftruncate($this->file, strlen($json)) || ! fflush($this->file)) {
            throw new RuntimeException('trace_write_unavailable');
        }
        flock($this->file, LOCK_UN);
        fclose($this->file);

        return $this->event;
    }

    public function __destruct()
    {
        if (is_resource($this->file)) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
        }
    }
}
