<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationInfobip\Exception\InfobipException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Infobip's REST API ("Authorization: App <API key>", JSON). What a failure means for the sender:
 * - Infobip answered with an error, or accepted the request but rejected the message (status group REJECTED): nothing
 *   was sent - InfobipException with Infobip's name;
 * - HTTP 429, or 503 with Retry-After: nothing was sent, try again soon - TemporaryFailureException;
 * - any other 5xx: it may have been sent - MessageSentException;
 * - no connection (proven by cURL's report): nothing was sent - \RuntimeException;
 * - anything else, e.g. a timeout waiting for the answer or an empty reply: it may have been sent -
 *   MessageSentException, so it is never sent twice.
 */
class InfobipClient
{
    /** Infobip's status group of a message it will not send. */
    public const GROUP_REJECTED = 'REJECTED';

    /** cURL errors before the request left: proxy or host not resolved, connection refused, TLS handshake. */
    private const NOT_SENT_ERRNOS = [5, 6, 7, 35];
    private const ERRNO_TIMEOUT = 28;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * Sends one message request.
     *
     * @param string $path e.g. "/sms/2/text/advanced"
     * @param array<string, mixed> $body the request's JSON body
     * @return array<string, mixed> Infobip's answer
     */
    public function send(string $baseUrl, string $apiKey, string $path, array $body): array
    {
        if ($baseUrl === '' || $apiKey === '') {
            throw new \RuntimeException('Infobip is not configured for this store: the base URL and API key are needed.');
        }
        try {
            $response = $this->http->request('POST', $baseUrl . $path, [
                'headers' => ['Authorization' => 'App ' . $apiKey, 'Accept' => 'application/json'],
                'json' => $body,
                'connect_timeout' => $this->connectTimeout,
                'timeout' => $this->timeout,
                'http_errors' => false,
            ]);
        } catch (ConnectException $error) {
            if (self::neverSent($error->getHandlerContext())) {
                throw new \RuntimeException('Infobip could not be reached; nothing was sent.', 0, $error);
            }
            // a timeout waiting for the answer, an empty reply (cURL 52), …: it may have been accepted
            throw new MessageSentException('Infobip did not answer; the message may have been sent.', 0, $error);
        } catch (RequestException $error) {
            if ($error->getResponse() === null) {
                throw new MessageSentException('Infobip did not answer in time; the message may have been sent.', 0, $error);
            }
            throw new \RuntimeException('Infobip request failed.', 0, $error);
        } catch (GuzzleException $error) {
            throw new MessageSentException('Infobip did not answer; the message may have been sent.', 0, $error);
        }
        $status = $response->getStatusCode();
        $answer = json_decode((string)$response->getBody(), true);
        $answer = is_array($answer) ? $answer : [];
        $retryAfter = TemporaryFailureException::parseRetryAfter($response->getHeaderLine('Retry-After'));
        if ($status === 429 || ($status === 503 && $retryAfter !== null)) {
            // too many requests, or briefly unavailable and Infobip said for how long: nothing was sent, try again soon
            throw new TemporaryFailureException(sprintf('Infobip refused the request for now (HTTP %d); nothing was'
                . ' sent.', $status), $retryAfter);
        }
        if ($status >= 500) {
            // a server error may come after Infobip took the message: never send it twice
            throw new MessageSentException(sprintf('Infobip answered HTTP %d; the message may have been sent.', $status));
        }
        if ($status < 200 || $status >= 300) {
            $name = strtoupper((string)($answer['requestError']['serviceException']['messageId']
                ?? $answer['requestError']['policyException']['messageId'] ?? $answer['errorCode'] ?? ''));
            throw new InfobipException(sprintf('Infobip refused the request (HTTP %d%s).', $status,
                $name !== '' ? ', ' . $name : ''), $name, $status);
        }
        foreach ((array)($answer['messages'] ?? []) as $message) {
            $group = strtoupper((string)($message['status']['groupName'] ?? ''));
            if ($group === self::GROUP_REJECTED) {
                $name = strtoupper((string)($message['status']['name'] ?? ''));
                throw new InfobipException(sprintf('Infobip rejected the message (%s).', $name ?: $group), $name, $status);
            }
        }
        return $answer;
    }

    /**
     * Whether cURL's report proves the request never left: the proxy or host was not found (5, 6), the connection was
     * refused (7) or TLS failed (35) - or a timeout (28) before any transfer began (pretransfer_time 0, nothing sent).
     * cURL reports a timeout while connecting and while waiting for the answer alike, so a timeout without that proof,
     * and any other failure, may have been sent.
     */
    private static function neverSent(array $context): bool
    {
        $errno = (int)($context['errno'] ?? 0);
        if (in_array($errno, self::NOT_SENT_ERRNOS, true)) {
            return true;
        }
        return $errno === self::ERRNO_TIMEOUT && isset($context['pretransfer_time'])
            && (float)$context['pretransfer_time'] <= 0.0 && (int)($context['request_size'] ?? 0) === 0
            && (float)($context['size_upload'] ?? 0) <= 0.0;
    }
}
