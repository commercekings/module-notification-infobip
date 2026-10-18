<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
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

    public function testWithoutCredentialsNothingIsCalled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->client([])->send('', 'key', '/sms/2/text/advanced', []);
    }
}
