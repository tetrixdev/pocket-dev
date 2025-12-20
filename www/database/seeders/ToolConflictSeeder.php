<?php

namespace Database\Seeders;

use App\Models\ToolConflict;
use Illuminate\Database\Seeder;

class ToolConflictSeeder extends Seeder
{
    public function run(): void
    {
        $conflicts = [
            // PocketDev file operation tools conflict with Claude Code native tools
            // Format: [tool_a_slug, tool_b_slug, conflict_type, resolution_hint]
            [
                'pocketdev-bash',
                'native:Bash',
                ToolConflict::TYPE_EQUIVALENT,
                'Use Claude Code native Bash tool. PocketDev Bash is for Anthropic/OpenAI providers.',
            ],
            [
                'pocketdev-read',
                'native:Read',
                ToolConflict::TYPE_EQUIVALENT,
                'Use Claude Code native Read tool. PocketDev Read is for Anthropic/OpenAI providers.',
            ],
            [
                'pocketdev-write',
                'native:Write',
                ToolConflict::TYPE_EQUIVALENT,
                'Use Claude Code native Write tool. PocketDev Write is for Anthropic/OpenAI providers.',
            ],
            [
                'pocketdev-edit',
                'native:Edit',
                ToolConflict::TYPE_EQUIVALENT,
                'Use Claude Code native Edit tool. PocketDev Edit is for Anthropic/OpenAI providers.',
            ],
            [
                'pocketdev-glob',
                'native:Glob',
                ToolConflict::TYPE_EQUIVALENT,
                'Use Claude Code native Glob tool. PocketDev Glob is for Anthropic/OpenAI providers.',
            ],
            [
                'pocketdev-grep',
                'native:Grep',
                ToolConflict::TYPE_EQUIVALENT,
                'Use Claude Code native Grep tool. PocketDev Grep is for Anthropic/OpenAI providers.',
            ],
        ];

        foreach ($conflicts as $conflictData) {
            ToolConflict::updateOrCreate(
                [
                    'tool_a_slug' => $conflictData[0],
                    'tool_b_slug' => $conflictData[1],
                ],
                [
                    'conflict_type' => $conflictData[2],
                    'resolution_hint' => $conflictData[3],
                ]
            );
        }
    }
}
