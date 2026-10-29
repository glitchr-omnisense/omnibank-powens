<?php

namespace Omnibank\Powens;

use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Exception\ProviderException;
use Omnibank\Exception\UnavailableException;
use Omnibank\Model\Connection;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Powens' API 2.0 on the client's domain (https://<x>-sandbox.biapi.pro/2.0/),
 * a user's calls with the user's permanent token (Bearer), and its Webview.
 *
 * Webhooks: Powens signs them with the webhook secret of the console. This
 * client reads the signature as Powens documents it - BI-Signature: base64
 * of the HMAC-SHA256 of "POST.<webhook path>.<BI-Signature-Date>.<body>",
 * compared exactly - and refuses a webhook whose BI-Signature-Date is more
 * than the tolerance away from now (a webhook captured and sent again). Not
 * yet checked against a real sandbox webhook.
 */
final class Api
{
    public const WEBVIEW = 'https://webview.powens.com';

    /** How far a webhook's signed date may be from now, in seconds (the webhook_tolerance option). */
    public const WEBHOOK_TOLERANCE = 300;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $domain,
        public readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $webhookSecret = null,
        private readonly ?string $webhookUrl = null,
        private readonly string $webview = self::WEBVIEW,
        private readonly ?string $language = null,
        private readonly int $timeout = 30,
        private readonly int $webhookTolerance = self::WEBHOOK_TOLERANCE,
    ) {
    }

    /** The user's permanent token, from the connection's state: connect() puts it there. */
    public static function token(Connection $connection): string
    {
        $token = $connection->get('auth_token');
        if (!\is_string($token) || '' === $token) {
            throw new ProviderException('powens', 'The connection has no Powens user yet: connect() first.');
        }

        return $token;
    }

    /** A new Powens user: its permanent token and its id. @return array{auth_token: string, id_user: int|string|null} */
    public function createUser(): array
    {
        $data = $this->call('POST', '/auth/init', null, ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
        if (!\is_string($data['auth_token'] ?? null) || '' === $data['auth_token']) {
            throw new ProviderException('powens', 'Powens gave no user token.');
        }

        return ['auth_token' => $data['auth_token'], 'id_user' => $data['id_user'] ?? null];
    }

    /** A temporary code for the user's token: what the Webview is opened with. */
    public function temporaryCode(string $token): string
    {
        $data = $this->call('GET', '/auth/token/code', $token);
        if (!\is_string($data['code'] ?? null) || '' === $data['code']) {
            throw new ProviderException('powens', 'Powens gave no temporary code.');
        }

        return $data['code'];
    }

    /** The Webview's page: connect a bank, or - with a connection id - renew that connection. */
    public function webviewUrl(string $code, string $redirectUri, ?string $connectionId = null): string
    {
        $query = ['domain' => $this->domain, 'client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'code' => $code];
        if (null !== $connectionId) {
            $query['connection_id'] = $connectionId;
        }

        return rtrim($this->webview, '/').($this->language ? '/'.$this->language : '').'/'.(null !== $connectionId ? 'reconnect' : 'connect').'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed>|null $body JSON
     *
     * @return array<mixed>
     *
     * @throws ProviderException   when Powens refuses (4xx)
     * @throws UnavailableException when Powens cannot be reached or fails (network, 5xx, 429)
     */
    public function call(string $method, string $path, ?string $token = null, ?array $body = null, array $query = []): array
    {
        $url = match (true) {
            str_starts_with($path, 'https://') => $path,
            str_starts_with($path, '/2.0/') => 'https://'.$this->domain.$path,
            default => 'https://'.$this->domain.'/2.0/'.ltrim($path, '/'),
        };
        if (parse_url($url, \PHP_URL_HOST) !== $this->domain) {
            throw new ProviderException('powens', \sprintf('A link out of the client\'s domain (%s) is not followed: the user\'s token stays home.', $url));
        }
        try {
            $response = $this->http->request($method, $url, array_filter([
                'headers' => array_filter(['Accept' => 'application/json', 'Authorization' => null !== $token ? 'Bearer '.$token : null]),
                'query' => $query,
                'json' => $body,
                'timeout' => $this->timeout,
            ], static fn ($v) => null !== $v && [] !== $v));
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new UnavailableException('powens', 'Powens cannot be reached: '.$e->getMessage(), null, $e);
        }
        if ($status >= 500 || 429 === $status) {
            throw new UnavailableException('powens', \sprintf('Powens answered HTTP %d: try again later.', $status), (string) $status);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if (!\is_array($data)) {
            throw new UnavailableException('powens', \sprintf('Powens answered HTTP %d with a body that is not JSON.', $status), (string) $status);
        }
        if ($status >= 400) {
            throw new ProviderException('powens', (string) ($data['description'] ?? $data['message'] ?? $data['code'] ?? \sprintf('HTTP %d', $status)), isset($data['code']) ? (string) $data['code'] : (string) $status);
        }

        return $data;
    }

    /** @throws InvalidNotificationException */
    public function verify(string $body, ?string $signature, ?string $date): void
    {
        if (null === $this->webhookSecret || '' === $this->webhookSecret) {
            throw new InvalidNotificationException('powens', 'No webhook secret: the notification cannot be checked.');
        }
        if (null === $this->webhookUrl || '' === $this->webhookUrl) {
            throw new InvalidNotificationException('powens', 'No webhook_url: its path is part of what Powens signs, the notification cannot be checked.');
        }
        $signature = trim((string) $signature);
        if ('' === $signature) {
            throw new InvalidNotificationException('powens', 'The notification is unsigned (no BI-Signature).');
        }
        $date = trim((string) $date);
        if ('' === $date) {
            throw new InvalidNotificationException('powens', 'The notification is undated (no BI-Signature-Date).');
        }
        $expected = self::signatures($body, $this->webhookSecret, $date, $this->webhookUrl)[0];
        if (!hash_equals($expected, $signature)) {
            throw new InvalidNotificationException('powens', 'The notification\'s signature does not match.');
        }
        $signedAt = strtotime($date);
        if (false === $signedAt) {
            throw new InvalidNotificationException('powens', 'The notification\'s BI-Signature-Date cannot be read.');
        }
        if (abs(time() - $signedAt) > $this->webhookTolerance) {
            throw new InvalidNotificationException('powens', \sprintf('The notification is too old (signed %s, more than %d seconds from now): a webhook sent again is refused.', $date, $this->webhookTolerance));
        }
    }

    /**
     * The signature Powens sends for a body (BI-Signature): the base64 of the HMAC-SHA256 of
     * "POST.<webhook path>.<date>.<body>". A list (of one, when the date and the URL are given)
     * for the callers that took it so; without a date or a URL there is nothing to sign.
     *
     * @return list<string>
     */
    public static function signatures(string $body, string $secret, ?string $date = null, ?string $webhookUrl = null): array
    {
        if (null === $date || '' === $date || null === $webhookUrl || '' === $webhookUrl) {
            return [];
        }

        return [base64_encode(hash_hmac('sha256', 'POST.'.(parse_url($webhookUrl, \PHP_URL_PATH) ?: '/').'.'.$date.'.'.$body, $secret, true))];
    }
}
