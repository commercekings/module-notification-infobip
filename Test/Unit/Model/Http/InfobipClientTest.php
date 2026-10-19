<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationInfobip\Exception\InfobipException;
use CommerceKings\NotificationInfobip\Model\Http\InfobipClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a failure means: refused (nothing sent), unreachable (nothing sent) or no answer (maybe sent: never again). */
class InfobipClientTest extends TestCase
{
    private array $history = [];

    private function client(array $responses): InfobipClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new InfobipClient(new Client(['handler' => $stack]));
    }

    private function send(InfobipClient $client): array
    {
        return $client->send('https://abc.api.infobip.com', 'key-1', '/sms/2/text/advanced',
            ['messages' => [['destinations' => [['to' => '381601234567']], 'from' => 'Acme', 'text' => 'Hi']]]);
    }

    public function testTheMessageIsPostedAsJsonWithTheAppKey(): void
    {
        $answer = $this->send($this->client([new Response(200, [],
            '{"messages":[{"messageId":"m1","status":{"groupName":"PENDING","name":"PENDING_ACCEPTED"}}]}')]));
        $this->assertSame('m1', $answer['messages'][0]['messageId']);
        $request = $this->history[0]['request'];
        $this->assertSame('https://abc.api.infobip.com/sms/2/text/advanced', (string)$request->getUri());
        $this->assertSame('App key-1', $request->getHeaderLine('Authorization'));
        $this->assertSame(['messages' => [['destinations' => [['to' => '381601234567']], 'from' => 'Acme', 'text' => 'Hi']]],
            json_decode((string)$request->getBody(), true));
    }

    public function testAnErrorAnswerIsARefusalWithInfobipsName(): void
    {
        try {
            $this->send($this->client([new Response(400, [],
                '{"requestError":{"serviceException":{"messageId":"BAD_REQUEST","text":"Bad request"}}}')]));
            $this->fail('Expected a refusal');
        } catch (InfobipException $error) {
            $this->assertSame(['BAD_REQUEST', 400], [$error->getInfobipName(), $error->getCode()]);
        }
    }

    /** Infobip answers 200 for a rejected message too: its status group says so. */
    public function testARejectedMessageInAnAcceptedRequestIsARefusal(): void
    {
        try {
            $this->send($this->client([new Response(200, [],
                '{"messages":[{"status":{"groupName":"REJECTED","name":"REJECTED_DESTINATION_BLOCKED"}}]}')]));
            $this->fail('Expected a refusal');
        } catch (InfobipException $error) {
            $this->assertSame('REJECTED_DESTINATION_BLOCKED', $error->getInfobipName());
        }
    }

    public function testUnreachableIsNotSentButNoAnswerMayBe(): void
    {
        $request = new Request('POST', 'https://abc.api.infobip.com');
        try {
            $this->send($this->client([new ConnectException('refused', $request, null, ['errno' => 7])]));
            $this->fail('Expected an exception');
        } catch (MessageSentException) {
            $this->fail('A refused connection sent nothing');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('could not be reached', $error->getMessage());
        }
        foreach ([new ConnectException('empty reply', $request, null, ['errno' => 52]),
            new RequestException('timed out', $request)] as $failure) {
            try {
                $this->send($this->client([$failure]));
                $this->fail('Expected MessageSentException');
            } catch (MessageSentException $error) {
                $this->assertStringContainsString('may have been sent', $error->getMessage());
            }
        }
    }

    /** @return array<string, array{array<string, mixed>, bool}> cURL's report => whether the message may have been sent */
    public static function connectFailures(): array
    {
        return [
            'connection refused' => [['errno' => 7], false],
            'host not found' => [['errno' => 6], false],
            'proxy not found' => [['errno' => 5], false],
            'TLS handshake failed' => [['errno' => 35], false],
            'timeout while connecting' => [['errno' => 28, 'pretransfer_time' => 0.0, 'request_size' => 0, 'size_upload' => 0.0], false],
            'timeout after the request went out' => [['errno' => 28, 'pretransfer_time' => 0.21, 'request_size' => 412,
                'size_upload' => 380.0], true],
            'timeout without a transfer report' => [['errno' => 28], true],
            'timeout, headers sent' => [['errno' => 28, 'pretransfer_time' => 0.0, 'request_size' => 412], true],
            'empty reply' => [['errno' => 52], true],
            'no report at all' => [[], true],
        ];
    }

    /**
     * cURL reports a timeout while connecting and while waiting for the answer alike: only a failure that provably
     * happened before the request left is "nothing sent" (and retried); anything else may have been sent – never again.
     */
    #[DataProvider('connectFailures')]
    public function testOnlyAProvenConnectFailureIsNotSent(array $context, bool $maybeSent): void
    {
        $failure = new ConnectException('cURL error', new Request('POST', 'https://abc.api.infobip.com'), null, $context);
        try {
            $this->send($this->client([$failure]));
            $this->fail('Expected an exception');
        } catch (MessageSentException $error) {
            $this->assertTrue($maybeSent, 'reported as maybe sent: ' . $error->getMessage());
        } catch (\RuntimeException $error) {
            $this->assertFalse($maybeSent, 'reported as not sent: ' . $error->getMessage());
            $this->assertStringContainsString('nothing was sent', $error->getMessage());
        }
    }

    public function testWithoutCredentialsNothingIsCalled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->client([])->send('', 'key', '/sms/2/text/advanced', []);
    }

    /** A rate limit, or a short outage with a stated wait: nothing was sent, send it again soon. */
    public function testA429OrA503WithRetryAfterIsATemporaryRefusal(): void
    {
        foreach ([[new Response(429, ['Retry-After' => '30'], '{"requestError":{"serviceException":{"messageId":"TOO_MANY_REQUESTS"}}}'), 30],
            [new Response(429, [], '{"requestError":{"serviceException":{"messageId":"TOO_MANY_REQUESTS"}}}'), null],
            [new Response(503, ['Retry-After' => 'Mon, 19 Oct 2099 10:05:00 GMT'], ''), 'date'],
            [new Response(503, ['Retry-After' => '120'], ''), 120]] as [$response, $wait]) {
            try {
                $this->send($this->client([$response]));
                $this->fail('Expected a temporary refusal');
            } catch (TemporaryFailureException $error) {
                $this->assertStringContainsString('HTTP ' . $response->getStatusCode(), $error->getMessage());
                $wait === 'date' ? $this->assertGreaterThan(0, $error->getRetryAfter())
                    : $this->assertSame($wait, $error->getRetryAfter());
            }
        }
    }

    /** A server error may come after the message was taken: never send it twice. A 503 without a wait is one too. */
    public function testOtherServerErrorsMayHaveBeenSent(): void
    {
        foreach ([500, 502, 503, 504] as $status) {
            try {
                $this->send($this->client([new Response($status, [], '')]));
                $this->fail('Expected maybe sent for ' . $status);
            } catch (MessageSentException $error) {
                $this->assertStringContainsString('HTTP ' . $status, $error->getMessage());
            }
        }
    }

    public function testOtherClientErrorsStayRefusals(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            try {
                $this->send($this->client([new Response($status, ['Retry-After' => '30'], '{}')]));
                $this->fail('Expected a refusal for ' . $status);
            } catch (InfobipException $error) {
                $this->assertSame($status, $error->getCode());
            }
        }
    }
}
