<?php
namespace Mdrost\HttpClient\Test\Handler;

use Mdrost\HttpClient\Handler\MockHandler;
use Mdrost\HttpClient\Handler\Proxy;
use GuzzleHttp\Psr7\Request;
use Mdrost\HttpClient\RequestOptions;

/**
 * @covers \Mdrost\HttpClient\Handler\Proxy
 */
class ProxyTest extends \PHPUnit_Framework_TestCase
{
    public function testSendsToNonSync()
    {
        $a = $b = null;
        $m1 = new MockHandler([function ($v) use (&$a) { $a = $v; }]);
        $m2 = new MockHandler([function ($v) use (&$b) { $b = $v; }]);
        $h = Proxy::wrapSync($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);
        $this->assertNotNull($a);
        $this->assertNull($b);
    }

    public function testSendsToSync()
    {
        $a = $b = null;
        $m1 = new MockHandler([function ($v) use (&$a) { $a = $v; }]);
        $m2 = new MockHandler([function ($v) use (&$b) { $b = $v; }]);
        $h = Proxy::wrapSync($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), [RequestOptions::SYNCHRONOUS => true]);
        $this->assertNull($a);
        $this->assertNotNull($b);
    }

    public function testSendsToStreaming()
    {
        $a = $b = null;
        $m1 = new MockHandler([function ($v) use (&$a) { $a = $v; }]);
        $m2 = new MockHandler([function ($v) use (&$b) { $b = $v; }]);
        $h = Proxy::wrapStreaming($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);
        $this->assertNotNull($a);
        $this->assertNull($b);
    }

    public function testSendsToNonStreaming()
    {
        $a = $b = null;
        $m1 = new MockHandler([function ($v) use (&$a) { $a = $v; }]);
        $m2 = new MockHandler([function ($v) use (&$b) { $b = $v; }]);
        $h = Proxy::wrapStreaming($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), ['stream' => true]);
        $this->assertNull($a);
        $this->assertNotNull($b);
    }
}
