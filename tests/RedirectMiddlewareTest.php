<?php
namespace Mdrost\HttpClient\Tests;

use function Clue\React\Block\await;
use Mdrost\HttpClient\Client;
use Mdrost\HttpClient\Handler\MockHandler;
use Mdrost\HttpClient\HandlerStack;
use Mdrost\HttpClient\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mdrost\HttpClient\RedirectMiddleware;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;

/**
 * @covers Mdrost\HttpClient\RedirectMiddleware
 */
class RedirectMiddlewareTest extends \PHPUnit_Framework_TestCase
{
    /** @var LoopInterface */
    private $loop;

    public function setUp()
    {
        $this->loop = LoopFactory::create();
    }

    public function testIgnoresNonRedirects()
    {
        $response = new Response(200);
        $stack = new HandlerStack(new MockHandler([$response]));
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, []);
        $response = await($promise, $this->loop);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testIgnoresWhenNoLocation()
    {
        $response = new Response(304);
        $stack = new HandlerStack(new MockHandler([$response]));
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, []);
        $response = await($promise, $this->loop);
        $this->assertEquals(304, $response->getStatusCode());
    }

    public function testRedirectsWithAbsoluteUri()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200)
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2]
        ]);
        $response = await($promise, $this->loop);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://test.com', $mock->getLastRequest()->getUri());
    }

    public function testRedirectsWithRelativeUri()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/foo']),
            new Response(200)
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2]
        ]);
        $response = await($promise, $this->loop);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://example.com/foo', $mock->getLastRequest()->getUri());
    }

    /**
     * @expectedException \Mdrost\HttpClient\Exception\TooManyRedirectsException
     * @expectedExceptionMessage Will not follow more than 3 redirects
     */
    public function testLimitsToMaxRedirects()
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://test.com']),
            new Response(302, ['Location' => 'http://test.com']),
            new Response(303, ['Location' => 'http://test.com']),
            new Response(304, ['Location' => 'http://test.com'])
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, ['allow_redirects' => ['max' => 3]]);
        await($promise, $this->loop);
    }

    /**
     * @expectedException \Mdrost\HttpClient\Exception\BadResponseException
     * @expectedExceptionMessage Redirect URI,
     */
    public function testEnsuresProtocolIsValid()
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'ftp://test.com'])
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        await($handler($request, ['allow_redirects' => ['max' => 3]]), $this->loop);
    }

    public function testAddsRefererHeader()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200)
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2, 'referer' => true]
        ]);
        await($promise, $this->loop);
        $this->assertEquals(
            'http://example.com?a=b',
            $mock->getLastRequest()->getHeaderLine('Referer')
        );
    }

    public function testAddsGuzzleRedirectHeader()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com']),
            new Response(302, ['Location' => 'http://example.com/foo']),
            new Response(302, ['Location' => 'http://example.com/bar']),
            new Response(200)
        ]);

        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['track_redirects' => true]
        ]);
        $response = await($promise, $this->loop);
        $this->assertEquals(
            [
                'http://example.com',
                'http://example.com/foo',
                'http://example.com/bar',
            ],
            $response->getHeader(RedirectMiddleware::HISTORY_HEADER)
        );
    }

    public function testAddsGuzzleRedirectStatusHeader()
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://example.com']),
            new Response(302, ['Location' => 'http://example.com/foo']),
            new Response(301, ['Location' => 'http://example.com/bar']),
            new Response(302, ['Location' => 'http://example.com/baz']),
            new Response(200)
        ]);

        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['track_redirects' => true]
        ]);
        $response = await($promise, $this->loop);
        $this->assertEquals(
            [
                301,
                302,
                301,
                302,
            ],
            $response->getHeader(RedirectMiddleware::STATUS_HISTORY_HEADER)
        );
    }

    public function testDoesNotAddRefererWhenGoingFromHttpsToHttp()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200)
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'https://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2, 'referer' => true]
        ]);
        await($promise, $this->loop);
        $this->assertFalse($mock->getLastRequest()->hasHeader('Referer'));
    }

    public function testInvokesOnRedirectForRedirects()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200)
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $call = false;
        $promise = $handler($request, [
            'allow_redirects' => [
                'max' => 2,
                'on_redirect' => function ($request, $response, $uri) use (&$call) {
                    $this->assertEquals(302, $response->getStatusCode());
                    $this->assertEquals('GET', $request->getMethod());
                    $this->assertEquals('http://test.com', (string) $uri);
                    $call = true;
                }
            ]
        ]);
        await($promise, $this->loop);
        $this->assertTrue($call);
    }

    public function testRemoveAuthorizationHeaderOnRedirect()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            function (RequestInterface $request) {
                $this->assertFalse($request->hasHeader('Authorization'));
                return new Response(200);
            }
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        await($client->request('GET', 'http://example.com?a=b', ['auth' => ['testuser', 'testpass']]), $this->loop);
    }

    public function testNotRemoveAuthorizationHeaderOnRedirect()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com/2']),
            function (RequestInterface $request) {
                $this->assertTrue($request->hasHeader('Authorization'));
                return new Response(200);
            }
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        await($client->request('GET', 'http://example.com?a=b', ['auth' => ['testuser', 'testpass']]), $this->loop);
    }
}
