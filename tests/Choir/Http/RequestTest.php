<?php

declare(strict_types=1);

namespace Tests\Choir\Http;

use Choir\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class RequestTest extends TestCase
{
    public function testConstruct()
    {
        $req = new Request('GET', '/', [], 'nihao');
        $this->assertEquals('nihao', $req->getBody()->getContents());
    }
}
