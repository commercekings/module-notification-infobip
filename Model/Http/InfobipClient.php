<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\NotificationInfobip\Exception\InfobipException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Infobip's REST API ("Authorization: App <API key>", JSON). What a failure means for the sender:
 * - Infobip answered with an error, or accepted the request but rejected the message (status group REJECTED): nothing
 *   was sent - InfobipException with Infobip's name;
 * - no connection: nothing was sent - \RuntimeException;
 * - connected, then no answer (a timeout, an empty reply): it may have been sent - MessageSentException, so it is never
 *   sent twice.
 */
class InfobipClient
{
    /** Infobip's status group of a message it will not send. */
    public const GROUP_REJECTED = 'REJECTED';

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
            // an empty reply (cURL 52) comes after the request was sent: it may have been accepted
            if ((int)($error->getHandlerContext()['errno'] ?? 0) === 52) {
                throw new MessageSentException('Infobip sent no reply; the message may have been sent.', 0, $error);
            }
            throw new \RuntimeException('Infobip could not be reached; nothing was sent.', 0, $error);
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
}
