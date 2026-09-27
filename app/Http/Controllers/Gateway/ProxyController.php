<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Services\Gateway\GatewayContext;
use App\Services\Gateway\GatewayError;
use App\Services\Gateway\GatewayException;
use App\Services\Gateway\GatewayService;
use App\Services\Gateway\SseParser;
use App\Services\Gateway\StreamCollector;
use App\Support\TokenUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as UpstreamResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Forwards a request to the upstream provider with the platform's key, streams or
 * returns the answer untouched, then bills the app for the reported token usage.
 */
abstract class ProxyController extends Controller
{
    public function __construct(protected GatewayService $gateway) {}

    abstract protected function endpoint(): string;

    abstract protected function nativeFormat(): ?string;

    abstract protected function upstream(GatewayContext $ctx, Request $request): array; // [PendingRequest, url]

    abstract protected function usageFromResponse(array $json): TokenUsage;

    abstract protected function collector(): StreamCollector;

    protected function prepareBody(stdClass $body, bool $stream): stdClass
    {
        return $body;
    }

    public function __invoke(Request $request): Response
    {
        // Decode to objects so `{}` and `[]` survive the round trip (tool schemas depend on it).
        $body = json_decode($request->getContent());

        if (! $body instanceof stdClass || ! is_string($body->model ?? null)) {
            return GatewayError::response($request, 400, 'A JSON body with a `model` field is required.');
        }

        $stream = (bool) ($body->stream ?? false);

        try {
            $ctx = $this->gateway->context($request, $body->model, $this->endpoint(), $stream, $this->nativeFormat());
        } catch (GatewayException $e) {
            return GatewayError::response($request, $e->status, $e->getMessage(), $e->type);
        }

        $body->model = $ctx->model->upstream_id;
        $payload = json_encode($this->prepareBody($body, $stream), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        /** @var PendingRequest $http */
        [$http, $url] = $this->upstream($ctx, $request);

        try {
            $response = $http
                ->timeout(config('billing.upstream_timeout'))
                ->withOptions(['stream' => $stream])
                ->withBody($payload, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            $this->gateway->record($ctx, new TokenUsage, 502, $e->getMessage());

            return GatewayError::response($request, 502, 'Could not reach the upstream provider.', 'api_error');
        }

        if ($response->failed()) {
            $raw = $response->body();
            $this->gateway->record($ctx, new TokenUsage, $response->status(), $raw);

            return response($raw, $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?: 'application/json');
        }

        if (! $stream) {
            $this->gateway->record($ctx, $this->usageFromResponse($response->json() ?? []), $response->status());

            return response($response->body(), $response->status())->header('Content-Type', 'application/json');
        }

        return $this->stream($ctx, $response, $payload);
    }

    protected function upstreamHttp(): PendingRequest
    {
        return Http::acceptJson();
    }

    private function stream(GatewayContext $ctx, UpstreamResponse $response, string $payload): StreamedResponse
    {
        return response()->stream(function () use ($ctx, $response, $payload) {
            // Keep running after a client disconnect so the request is still billed.
            ignore_user_abort(true);

            $collector = $this->collector();
            $parser = new SseParser($collector->onData(...));
            $body = $response->toPsrResponse()->getBody();
            $error = null;

            try {
                // Read byte-wise: a buffered fread() on the upstream stream waits to fill its
                // buffer, which would hold back tokens. Each complete SSE line is forwarded at once.
                $line = '';
                while (! $body->eof()) {
                    $byte = $body->read(1);
                    $line .= $byte;

                    if ($byte !== "\n" && ! $body->eof()) {
                        continue;
                    }

                    $parser->feed($line);

                    if (connection_aborted()) {
                        $error = 'Client disconnected.';
                        break;
                    }

                    echo $line;
                    $line = '';
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $parser->finish();

            $usage = $collector->usage();
            if ($usage === null) {
                $usage = $this->gateway->estimate($payload, $collector->text());
                $error = trim(($error ?? '').' Usage not reported by provider; estimated.');
            }

            $this->gateway->record($ctx, $usage, 200, $error);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
