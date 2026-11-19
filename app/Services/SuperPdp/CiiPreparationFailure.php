<?php

namespace App\Services\SuperPdp;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Response;
use Throwable;

/** Safe diagnostic only: never retain the upstream exception, headers or body. */
final class CiiPreparationFailure extends RuntimeException
{
    public function __construct(public readonly array $diagnostic)
    {
        parent::__construct('CII preparation failed; see private diagnostic.');
    }

    public static function responseMetadata(Response $response): array
    {
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();
        return ['http_status' => $response->status(),
            // Do not retain arbitrary header text or multipart parameters.
            'content_type' => in_array($type, ['application/xml', 'text/xml', 'application/json',
                'application/problem+json', 'text/plain', 'text/html', 'application/octet-stream', ''], true) ? $type : '[filtered]',
            'response_size' => strlen($body), 'response_sha256' => hash('sha256', $body),
            'source' => $response->isMocked() ? 'simulated' : ($response->isCached() ? 'cached' : 'live')];
    }

    public static function remoteMessage(Response $response): ?string
    {
        return CiiRemoteErrorFilter::extract($response->body())['remote_error_message'];
    }

    public static function withoutResponse(string $operation, Throwable $exception): self
    {
        $transport = $exception instanceof FatalRequestException || $exception instanceof ConnectException
            || $exception instanceof RequestException;
        $timeout = false;
        for ($cause = $exception, $depth = 0; $cause !== null && $depth < 10; $cause = $cause->getPrevious(), $depth++) {
            if (($cause instanceof ConnectException || $cause instanceof RequestException)
                && ($cause->getHandlerContext()['errno'] ?? null) === 28) {
                $timeout = true;
            }
        }
        return new self(['operation' => $operation, 'failure_stage' => $operation.'.'.($transport ? 'transport' : 'request_setup'),
            'failure_kind' => $timeout ? 'timeout' : ($transport ? 'transport_error' : 'request_setup_failed'),
            'http_status' => null, 'content_type' => null, 'response_size' => null, 'response_sha256' => null,
            'remote_error_message' => null]);
    }
}
