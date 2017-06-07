<?php
namespace Mdrost\HttpClient\Handler;

use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use React\HttpClient\Client;
use React\HttpClient\Response as ResponseStream;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class ReactHandler
{
    /**
     * @var Client
     */
    private $client;

    public static function createFromLoop(LoopInterface $loop): self
    {
        $client = new Client($loop);
        return new ReactHandler($client);
    }

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $deferred = new Deferred();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $requestStream = $this->client->request($request->getMethod(), $request->getUri(), $headers, $request->getProtocolVersion());
        $requestStream->on('error', function($error) use ($deferred) {
            $deferred->reject($error);
        });
        $requestStream->on('response', function (ResponseStream $response) use ($deferred) {
            $body = '';
            $response->on('data', function (string $data) use (&$body) {
                $body .= $data;
            });
            $response->on('end', function () use ($deferred, $response, &$body) {
                $deferred->resolve(new Psr7\Response(
                    $response->getCode(),
                    $response->getHeaders(),
                    $body,
                    $response->getVersion(),
                    $response->getReasonPhrase()
                ));
            });
        });
        $requestStream->end((string)$request->getBody());
        return $deferred->promise();
    }
}
