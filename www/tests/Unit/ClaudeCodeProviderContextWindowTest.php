<?php

namespace Tests\Unit;

use App\Services\ModelRepository;
use App\Services\Providers\ClaudeCodeProvider;
use ReflectionMethod;
use Tests\TestCase;

class ClaudeCodeProviderContextWindowTest extends TestCase
{
    public function test_extracts_effective_context_window_from_result_payload(): void
    {
        $provider = new ClaudeCodeProvider(new ModelRepository());

        $payload = [
            'type' => 'result',
            'modelUsage' => [
                'primary' => [
                    'contextWindow' => 200000,
                ],
                'secondary' => [
                    'nested' => [
                        'contextWindow' => 1000000,
                    ],
                ],
            ],
        ];

        $method = new ReflectionMethod($provider, 'extractEffectiveContextWindow');
        $method->setAccessible(true);
        $window = $method->invoke($provider, $payload);

        $this->assertSame(1000000, $window);
    }

    public function test_returns_null_when_result_payload_has_no_context_window(): void
    {
        $provider = new ClaudeCodeProvider(new ModelRepository());

        $payload = [
            'type' => 'result',
            'modelUsage' => [
                'primary' => [
                    'tokens' => 123,
                ],
            ],
        ];

        $method = new ReflectionMethod($provider, 'extractEffectiveContextWindow');
        $method->setAccessible(true);
        $window = $method->invoke($provider, $payload);

        $this->assertNull($window);
    }
}
