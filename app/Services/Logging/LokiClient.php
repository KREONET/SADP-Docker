<?php

namespace App\Services\Logging;

use App\Models\InstanceSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends log streams to the Loki push API (`/loki/api/v1/push`).
 *
 * [한국어] Loki 에 로그를 보내는 HTTP 클라이언트입니다. 주소·인증은 인스턴스 설정(loki_*)에서 읽습니다.
 * 보낼 데이터의 모양은 LokiStreamBuilder, 언제 보낼지는 ShipLogsToLokiJob 이 정합니다.
 */
class LokiClient
{
    public const PUSH_PATH = '/loki/api/v1/push';

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private string $url,
        private ?string $username = null,
        private ?string $password = null,
        private ?string $tenantId = null,
    ) {}

    public static function fromSettings(InstanceSettings $settings): self
    {
        return new self(
            url: (string) $settings->loki_url,
            username: $settings->loki_username,
            password: $settings->loki_password,
            tenantId: $settings->loki_tenant_id,
        );
    }

    /**
     * Accept both the Loki base URL and the full push URL.
     */
    public static function pushUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return str_ends_with($url, self::PUSH_PATH) ? $url : $url.self::PUSH_PATH;
    }

    /**
     * @param  list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>  $streams
     */
    public function push(array $streams): Response
    {
        return $this->request()->post(self::pushUrl($this->url), ['streams' => $streams]);
    }

    /**
     * Pushes one line so the URL, credentials and tenant are checked together.
     *
     * @return string|null The error, or null when Loki accepted the line.
     */
    public function testConnection(string $instance): ?string
    {
        try {
            $response = $this->push([
                LokiStreamBuilder::stream(
                    ['source' => 'connection_test', 'level' => 'info', 'instance' => $instance],
                    [[LokiStreamBuilder::nanoseconds(now()), json_encode(['msg' => 'Coolify Loki connection test'])]],
                ),
            ]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return $response->successful() ? null : self::describeFailure($response);
    }

    public static function describeFailure(Response $response): string
    {
        return trim('Loki responded with HTTP '.$response->status().'. '.str($response->body())->limit(300));
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->asJson();

        if (filled($this->username)) {
            $request = $request->withBasicAuth((string) $this->username, (string) $this->password);
        }

        if (filled($this->tenantId)) {
            $request = $request->withHeaders(['X-Scope-OrgID' => (string) $this->tenantId]);
        }

        return $request;
    }
}
