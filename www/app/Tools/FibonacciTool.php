<?php

namespace App\Tools;

/**
 * Tool for calculating Fibonacci numbers.
 */
class FibonacciTool extends Tool
{
    public string $name = 'fibonacci';

    public string $description = 'Calculate the nth Fibonacci number. Returns the Fibonacci number at position n (0-indexed, where F(0)=0, F(1)=1).';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'n' => [
                'type' => 'integer',
                'description' => 'The position in the Fibonacci sequence (0-indexed). Must be a non-negative integer.',
                'minimum' => 0,
            ],
        ],
        'required' => ['n'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        if (!isset($input['n'])) {
            return ToolResult::error('Missing required parameter: n');
        }

        $n = $input['n'];

        if (!is_int($n) && !ctype_digit((string) $n)) {
            return ToolResult::error('Parameter n must be a non-negative integer');
        }

        $n = (int) $n;

        if ($n < 0) {
            return ToolResult::error('Parameter n must be a non-negative integer');
        }

        // Limit to prevent excessive computation
        if ($n > 1000) {
            return ToolResult::error('Parameter n must be 1000 or less to prevent excessive computation');
        }

        $result = $this->calculateFibonacci($n);

        return ToolResult::success("F({$n}) = {$result}");
    }

    /**
     * Calculate the nth Fibonacci number using iterative approach.
     * Uses GMP for arbitrary precision if available, otherwise string arithmetic.
     */
    private function calculateFibonacci(int $n): string
    {
        if ($n === 0) {
            return '0';
        }

        if ($n === 1) {
            return '1';
        }

        // Use GMP if available for better performance with large numbers
        if (extension_loaded('gmp')) {
            $a = gmp_init(0);
            $b = gmp_init(1);

            for ($i = 2; $i <= $n; $i++) {
                $temp = gmp_add($a, $b);
                $a = $b;
                $b = $temp;
            }

            return gmp_strval($b);
        }

        // Fallback to string-based addition for arbitrary precision
        $a = '0';
        $b = '1';

        for ($i = 2; $i <= $n; $i++) {
            $temp = $this->addStrings($a, $b);
            $a = $b;
            $b = $temp;
        }

        return $b;
    }

    /**
     * Add two numeric strings together (for arbitrary precision without extensions).
     */
    private function addStrings(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $digitA = $i >= 0 ? (int) $a[$i] : 0;
            $digitB = $j >= 0 ? (int) $b[$j] : 0;

            $sum = $digitA + $digitB + $carry;
            $carry = (int) ($sum / 10);
            $result = ($sum % 10) . $result;

            $i--;
            $j--;
        }

        return $result;
    }
}
