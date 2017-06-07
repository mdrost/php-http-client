<?php
namespace Mdrost\HttpClient\Tests;

use function Clue\React\Block\await;
use Mdrost\HttpClient\Cookie\CookieJar;
use Mdrost\HttpClient\Cookie\SetCookie;
use Mdrost\HttpClient\Exception\RequestException;
use Mdrost\HttpClient\Handler\MockHandler;
use Mdrost\HttpClient\HandlerStack;
use Mdrost\HttpClient\MessageFormatter;
use Mdrost\HttpClient\Middleware;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class MiddlewareTest extends \PHPUnit_Framework_TestCase
{
    /** @var LoopInterface */
    private $loop;

    public function setUp()
    {
        $this->loop = LoopFactory::create();
    }

    public function testAddsCookiesToRequests()
    {
        $jar = new CookieJar();
        $m = Middleware::cookies($jar);
        $h = new MockHandler(
            [
                function (RequestInterface $request) {
                    return new Response(200, [
                        'Set-Cookie' => new SetCookie([
                            'Name'   => 'name',
                            'Value'  => 'value',
                            'Domain' => 'foo.com'
                        ])
                    ]);
                }
            ]
        );
        $f = $m($h);
        await($f(new Request('GET', 'http://foo.com'), ['cookies' => $jar]), $this->loop);
        $this->assertCount(1, $jar);
    }

    /**
     * @expectedException \Mdrost\HttpClient\Exception\ClientException
     */
    public function testThrowsExceptionOnHttpClientError()
    {
        $this->markTestSkipped();
        $m = Middleware::httpErrors();
        $h = new MockHandler([new Response(404)]);
        $f = $m($h);
        $p = $f(new Request('GET', 'http://foo.com'), ['http_errors' => true]);
        $this->assertEquals('pending', $p->getState());
        await($p, $this->loop);
        $this->assertEquals('rejected', $p->getState());
    }

    /**
     * @expectedException \Mdrost\HttpClient\Exception\ServerException
     */
    public function testThrowsExceptionOnHttpServerError()
    {
        $this->markTestSkipped();
        $m = Middleware::httpErrors();
        $h = new MockHandler([new Response(500)]);
        $f = $m($h);
        $p = $f(new Request('GET', 'http://foo.com'), ['http_errors' => true]);
        $this->assertEquals('pending', $p->getState());
        await($p, $this->loop);
        $this->assertEquals('rejected', $p->getState());
    }

    /**
     * @dataProvider getHistoryUseCases
     */
    public function testTracksHistory($container)
    {
        $m = Middleware::history($container);
        $h = new MockHandler([new Response(200), new Response(201)]);
        $f = $m($h);
        $p1 = $f(new Request('GET', 'http://foo.com'), ['headers' => ['foo' => 'bar']]);
        $p2 = $f(new Request('HEAD', 'http://foo.com'), ['headers' => ['foo' => 'baz']]);
        await($p1, $this->loop);
        await($p2, $this->loop);
        $this->assertCount(2, $container);
        $this->assertEquals(200, $container[0]['response']->getStatusCode());
        $this->assertEquals(201, $container[1]['response']->getStatusCode());
        $this->assertEquals('GET', $container[0]['request']->getMethod());
        $this->assertEquals('HEAD', $container[1]['request']->getMethod());
        $this->assertEquals('bar', $container[0]['options']['headers']['foo']);
        $this->assertEquals('baz', $container[1]['options']['headers']['foo']);
    }

    public function getHistoryUseCases()
    {
        return [
            [[]],                // 1. Container is an array
            [new \ArrayObject()] // 2. Container is an ArrayObject
        ];
    }

    public function testTracksHistoryForFailures()
    {
        $this->markTestSkipped();
        $container = [];
        $m = Middleware::history($container);
        $request = new Request('GET', 'http://foo.com');
        $h = new MockHandler([new RequestException('error', $request)]);
        $f = $m($h);
        $f($request, [])->wait(false);
        $this->assertCount(1, $container);
        $this->assertEquals('GET', $container[0]['request']->getMethod());
        $this->assertInstanceOf(RequestException::class, $container[0]['error']);
    }

    public function testTapsBeforeAndAfter()
    {
        $calls = [];
        $m = function ($handler) use (&$calls) {
            return function ($request, $options) use ($handler, &$calls) {
                $calls[] = '2';
                return $handler($request, $options);
            };
        };

        $m2 = Middleware::tap(
            function (RequestInterface $request, array $options) use (&$calls) {
                $calls[] = '1';
            },
            function (RequestInterface $request, array $options, PromiseInterface $p) use (&$calls) {
                $calls[] = '3';
            }
        );

        $h = new MockHandler([new Response()]);
        $b = new HandlerStack($h);
        $b->push($m2);
        $b->push($m);
        $comp = $b->resolve();
        $p = $comp(new Request('GET', 'http://foo.com'), []);
        $this->assertEquals('123', implode('', $calls));
        $this->assertInstanceOf(PromiseInterface::class, $p);
        $this->assertEquals(200, await($p, $this->loop)->getStatusCode());
    }

    public function testMapsRequest()
    {
        $h = new MockHandler([
            function (RequestInterface $request, array $options) {
                $this->assertEquals('foo', $request->getHeaderLine('Bar'));
                return new Response(200);
            }
        ]);
        $stack = new HandlerStack($h);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request->withHeader('Bar', 'foo');
        }));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        $this->assertInstanceOf(PromiseInterface::class, $p);
    }

    public function testMapsResponse()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            return $response->withHeader('Bar', 'foo');
        }));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        await($p, $this->loop);
        $this->assertEquals('foo', await($p, $this->loop)->getHeaderLine('Bar'));
    }

    public function testLogsRequestsAndResponses()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $logger = new Logger();
        $formatter = new MessageFormatter();
        $stack->push(Middleware::log($logger, $formatter));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        await($p, $this->loop);
        $this->assertContains('"PUT / HTTP/1.1" 200', $logger->output);
    }

    public function testLogsRequestsAndResponsesCustomLevel()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $logger = new Logger();
        $formatter = new MessageFormatter();
        $stack->push(Middleware::log($logger, $formatter, 'debug'));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        await($p, $this->loop);
        $this->assertContains('"PUT / HTTP/1.1" 200', $logger->output);
        $this->assertContains('[debug]', $logger->output);
    }

    public function testLogsRequestsAndErrors()
    {
        $this->markTestSkipped();
        $h = new MockHandler([new Response(404)]);
        $stack = new HandlerStack($h);
        $logger = new Logger();
        $formatter = new MessageFormatter('{code} {error}');
        $stack->push(Middleware::log($logger, $formatter));
        $stack->push(Middleware::httpErrors());
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), ['http_errors' => true]);
        $p->wait(false);
        $this->assertContains('PUT http://www.google.com', $logger->output);
        $this->assertContains('404 Not Found', $logger->output);
    }
}

/**
 * @internal
 */
class Logger implements LoggerInterface
{
    use LoggerTrait;
    public $output;

    public function log($level, $message, array $context = [])
    {
        $this->output .= "[{$level}] {$message}\n";
    }
}
