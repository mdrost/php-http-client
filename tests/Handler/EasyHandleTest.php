<?php
namespace Mdrost\HttpClient\Test\Handler;

use Mdrost\HttpClient\Handler\EasyHandle;
use GuzzleHttp\Psr7;

/**
 * @covers \Mdrost\HttpClient\Handler\EasyHandle
 */
class EasyHandleTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessage The EasyHandle has been released
     */
    public function testEnsuresHandleExists()
    {
        $easy = new EasyHandle;
        unset($easy->handle);
        $easy->handle;
    }
}
