<?php
namespace Mdrost\HttpClient\Tests\Event;

use Mdrost\HttpClient\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

/**
 * @covers Mdrost\HttpClient\Exception\ConnectException
 */
class ConnectExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasNoResponse()
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new ConnectException('foo', $req, $prev, ['foo' => 'bar']);
        $this->assertSame($req, $e->getRequest());
        $this->assertNull($e->getResponse());
        $this->assertFalse($e->hasResponse());
        $this->assertEquals('foo', $e->getMessage());
        $this->assertEquals('bar', $e->getHandlerContext()['foo']);
        $this->assertSame($prev, $e->getPrevious());
    }
}
