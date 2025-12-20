<?php

namespace Tests\Unit;

use App\Tools\ExecutionContext;
use App\Tools\FibonacciTool;
use PHPUnit\Framework\TestCase;

class FibonacciToolTest extends TestCase
{
    private FibonacciTool $tool;
    private ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new FibonacciTool();
        $this->context = new ExecutionContext('/tmp');
    }

    public function test_fibonacci_of_zero(): void
    {
        $result = $this->tool->execute(['n' => 0], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(0) = 0', $result->output);
    }

    public function test_fibonacci_of_one(): void
    {
        $result = $this->tool->execute(['n' => 1], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(1) = 1', $result->output);
    }

    public function test_fibonacci_of_two(): void
    {
        $result = $this->tool->execute(['n' => 2], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(2) = 1', $result->output);
    }

    public function test_fibonacci_of_ten(): void
    {
        $result = $this->tool->execute(['n' => 10], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(10) = 55', $result->output);
    }

    public function test_fibonacci_of_twenty(): void
    {
        $result = $this->tool->execute(['n' => 20], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(20) = 6765', $result->output);
    }

    public function test_fibonacci_of_fifty(): void
    {
        $result = $this->tool->execute(['n' => 50], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(50) = 12586269025', $result->output);
    }

    public function test_fibonacci_large_number(): void
    {
        $result = $this->tool->execute(['n' => 100], $this->context);

        $this->assertFalse($result->isError);
        $this->assertEquals('F(100) = 354224848179261915075', $result->output);
    }

    public function test_missing_parameter_returns_error(): void
    {
        $result = $this->tool->execute([], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Missing required parameter', $result->output);
    }

    public function test_negative_number_returns_error(): void
    {
        $result = $this->tool->execute(['n' => -5], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('non-negative integer', $result->output);
    }

    public function test_number_too_large_returns_error(): void
    {
        $result = $this->tool->execute(['n' => 1001], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('1000 or less', $result->output);
    }

    public function test_tool_has_correct_name(): void
    {
        $this->assertEquals('fibonacci', $this->tool->name);
    }

    public function test_tool_has_description(): void
    {
        $this->assertNotEmpty($this->tool->description);
    }

    public function test_tool_has_input_schema(): void
    {
        $this->assertArrayHasKey('type', $this->tool->inputSchema);
        $this->assertArrayHasKey('properties', $this->tool->inputSchema);
        $this->assertArrayHasKey('n', $this->tool->inputSchema['properties']);
    }
}
