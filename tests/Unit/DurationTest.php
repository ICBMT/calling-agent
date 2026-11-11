<?php

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_duration_formatting_does_not_wrap_after_an_hour(): void
    {
        $this->assertSame('—', Duration::format(null));
        $this->assertSame('0:00', Duration::format(0));
        $this->assertSame('2:05', Duration::format(125));
        $this->assertSame('61:10', Duration::format(3670));
        $this->assertSame('0:00', Duration::format(-1));
    }
}
