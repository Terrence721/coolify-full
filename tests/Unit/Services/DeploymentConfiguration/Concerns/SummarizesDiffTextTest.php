<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DeploymentConfiguration\Concerns;

use App\Services\DeploymentConfiguration\Concerns\SummarizesDiffText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SummarizesDiffTextTest extends TestCase
{
    /**
     * Anonymous class using the trait so we can test its private methods.
     */
    private function traitInstance(): object
    {
        return new class
        {
            use SummarizesDiffText;
        };
    }

    private function invokeExpandableText(object $instance, ?string $value): ?string
    {
        $ref = new \ReflectionClass($instance);
        $method = $ref->getMethod('expandableText');
        $method->setAccessible(true);

        return $method->invoke($instance, $value);
    }

    #[Test]
    public function expandable_text_returns_null_for_blank_values()
    {
        $instance = $this->traitInstance();

        $this->assertNull($this->invokeExpandableText($instance, null));
        $this->assertNull($this->invokeExpandableText($instance, ''));
        $this->assertNull($this->invokeExpandableText($instance, '   '));
    }

    #[Test]
    public function expandable_text_returns_null_for_short_single_line_values()
    {
        $instance = $this->traitInstance();

        $value = 'short text under limit';
        $this->assertNull($this->invokeExpandableText($instance, $value));
    }

    #[Test]
    public function expandable_text_returns_value_for_long_single_line_values()
    {
        $instance = $this->traitInstance();

        // SINGLE_LINE_LIMIT = 120
        $long = str_repeat('A', 121);

        $this->assertSame($long, $this->invokeExpandableText($instance, $long));
    }

    #[Test]
    public function expandable_text_returns_value_for_multi_line_values()
    {
        $instance = $this->traitInstance();

        $multi = "line1\nline2\nline3";

        $this->assertSame($multi, $this->invokeExpandableText($instance, $multi));
    }

    #[Test]
    public function expandable_text_trims_value_before_evaluating()
    {
        $instance = $this->traitInstance();

        $multi = "   line1\nline2   ";

        $this->assertSame("line1\nline2", $this->invokeExpandableText($instance, $multi));
    }
}
