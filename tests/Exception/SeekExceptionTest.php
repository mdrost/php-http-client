<?php
namespace Mdrost\HttpClient\Tests\Exception;

use Mdrost\HttpClient\Exception\SeekException;
use GuzzleHttp\Psr7;

class SeekExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasStream()
    {
        $s = Psr7\stream_for('foo');
        $e = new SeekException($s, 10);
        $this->assertSame($s, $e->getStream());
        $this->assertContains('10', $e->getMessage());
    }
}
