<?php
namespace Mdrost\HttpClient\Test\Handler;

use function Clue\React\Block\await;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mdrost\HttpClient\TransferStats;
use Mdrost\HttpClient\Handler\MockHandler;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * @covers \Mdrost\HttpClient\Handler\MockHandler
 */
class MockHandlerTest extends \PHPUnit_Framework_TestCase
{
    /** @var LoopInterface */
    private $loop;

    public function setUp()
    {
        $this->loop = LoopFactory::create();
    }

    public function testReturnsMockResponse()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        $this->assertSame($res, await($p, $this->loop));
    }

    public function testIsCountable()
    {
        $res = new Response();
        $mock = new MockHandler([$res, $res]);
        $this->assertCount(2, $mock);
    }

    public function testEmptyHandlerIsCountable()
    {
        $this->assertCount(0, new MockHandler());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresEachAppendIsValid()
    {
        $mock = new MockHandler(['a']);
        $request = new Request('GET', 'http://example.com');
        $mock($request, []);
    }

    public function testCanQueueExceptions()
    {
        $e = new \Exception('a');
        $mock = new MockHandler([$e]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        try {
            await($p, $this->loop);
            $this->fail();
        } catch (\Exception $e2) {
            $this->assertSame($e, $e2);
        }
    }

    public function testCanGetLastRequestAndOptions()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['foo' => 'bar']);
        $this->assertSame($request, $mock->getLastRequest());
        $this->assertEquals(['foo' => 'bar'], $mock->getLastOptions());
    }

    public function testSinkFilename()
    {
        $filename = sys_get_temp_dir().'/mock_test_'.uniqid();
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $filename]);
        await($p, $this->loop);

        $this->assertFileExists($filename);
        $this->assertEquals('TEST CONTENT', file_get_contents($filename));

        unlink($filename);
    }

    public function testSinkResource()
    {
        $file = tmpfile();
        $meta = stream_get_meta_data($file);
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $file]);
        await($p, $this->loop);

        $this->assertFileExists($meta['uri']);
        $this->assertEquals('TEST CONTENT', file_get_contents($meta['uri']));
    }

    public function testSinkStream()
    {
        $stream = new \GuzzleHttp\Psr7\Stream(tmpfile());
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $stream]);
        await($p, $this->loop);

        $this->assertFileExists($stream->getMetadata('uri'));
        $this->assertEquals('TEST CONTENT', file_get_contents($stream->getMetadata('uri')));
    }

    public function testCanEnqueueCallables()
    {
        $r = new Response();
        $fn = function ($req, $o) use ($r) { return $r; };
        $mock = new MockHandler([$fn]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, ['foo' => 'bar']);
        $this->assertSame($r, await($p, $this->loop));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresOnHeadersIsCallable()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['on_headers' => 'error!']);
    }

    /**
     * @expectedException \Mdrost\HttpClient\Exception\RequestException
     * @expectedExceptionMessage An error was encountered during the on_headers event
     * @expectedExceptionMessage test
     */
    public function testRejectsPromiseWhenOnHeadersFails()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $promise = $mock($request, [
            'on_headers' => function () {
                throw new \Exception('test');
            }
        ]);

        await($promise, $this->loop);
    }
    public function testInvokesOnFulfilled()
    {
        $res = new Response();
        $mock = new MockHandler([$res], function ($v) use (&$c) {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        await($mock($request, []), $this->loop);
        $this->assertSame($res, $c);
    }

    public function testInvokesOnRejected()
    {
        $this->markTestSkipped();
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, function ($v) use (&$c) { $c = $v; });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait(false);
        $this->assertSame($e, $c);
    }

    /**
     * @expectedException \OutOfBoundsException
     */
    public function testThrowsWhenNoMoreResponses()
    {
        $mock = new MockHandler();
        $request = new Request('GET', 'http://example.com');
        $mock($request, []);
    }

    /**
     * @expectedException \Mdrost\HttpClient\Exception\BadResponseException
     */
    public function testCanCreateWithDefaultMiddleware()
    {
        $r = new Response(500);
        $mock = MockHandler::createWithMiddleware([$r]);
        $request = new Request('GET', 'http://example.com');
        await($mock($request, ['http_errors' => true]), $this->loop);
    }

    public function testInvokesOnStatsFunctionForResponse()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $onStats = function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $p = $mock($request, ['on_stats' => $onStats]);
        await($p, $this->loop);
        $this->assertSame($res, $stats->getResponse());
        $this->assertSame($request, $stats->getRequest());
    }

    public function testInvokesOnStatsFunctionForError()
    {
        $this->markTestSkipped();
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, function ($v) use (&$c) { $c = $v; });
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $onStats = function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats])->wait(false);
        $this->assertSame($e, $stats->getHandlerErrorData());
        $this->assertSame(null, $stats->getResponse());
        $this->assertSame($request, $stats->getRequest());
    }
}
