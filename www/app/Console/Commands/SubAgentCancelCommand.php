Author: Claude <noreply@anthropic.com>
Date:   Tue Apr 7 11:54:28 2026 +0000

    feat(subagents): end parent turn immediately after background dispatch + cancel tool
    
    Closes #280.
    
    **End-turn signal**
    - `ToolResult` gains `$endTurn` / `$endTurnMessage` and a `successEndTurn()` factory.
    - `SubAgentTool` returns `successEndTurn()` in background mode instead of plain `success()`.
    - `ProcessConversationStream::executeTools()` propagates the flag to its result array.
    - `ProcessConversationStream::streamWithToolLoop()` checks for the flag after saving
      tool results; when set, saves a synthetic closing assistant message and returns early
      without a second AI round-trip. The parent conversation becomes interactive as soon as
      the child job is dispatched.
    
    A synthetic assistant message is required because the last saved message is a ROLE_USER
    tool-result row; without a closing ROLE_ASSISTANT row the next turn would present two
    consecutive user messages to the API provider.
    
    **SubAgentCancel tool**
    - `SubAgentCancelTool` (auto-discovered, registers as `SubAgentCancel`).
    - Sets `StreamManager::setAbortFlag()` on the child conversation UUID — same mechanism
      used by user-initiated stop; child detects flag within ~1 s and shuts down cleanly.
    - Ownership guard: only the conversation that spawned the task can cancel it
      (skipped when called via CLI where no parent UUID is available).
    - No-op with a clear message if the task is already terminal.
    - `SubAgentCancelCommand` exposes `pd subagent:cancel --task-id=<uuid>`.
    
    Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>

diff --git a/www/app/Console/Commands/SubAgentCancelCommand.php b/www/app/Console/Commands/SubAgentCancelCommand.php
new file mode 100644
index 0000000..0851231
--- /dev/null
+++ b/www/app/Console/Commands/SubAgentCancelCommand.php
@@ -0,0 +1,41 @@
+<?php
+
+namespace App\Console\Commands;
+
+use App\Tools\ExecutionContext;
+use App\Tools\SubAgentCancelTool;
+use Illuminate\Console\Command;
+
+class SubAgentCancelCommand extends Command
+{
+    protected $signature = 'subagent:cancel
+        {--task-id= : The task UUID returned by subagent:run --background}';
+
+    protected $description = 'Cancel a running background sub-agent task';
+
+    public function handle(): int
+    {
+        $tool = new SubAgentCancelTool();
+
+        $input = [
+            'task_id' => $this->option('task-id') ?? '',
+        ];
+
+        // Note: when called from CLI (not from within a conversation), there is no
+        // parent conversation UUID — the ownership guard in the tool is skipped.
+        $context = new ExecutionContext(
+            getcwd() ?: '/var/www',
+        );
+
+        $result = $tool->execute($input, $context);
+
+        $this->outputJson($result->toArray());
+
+        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
+    }
+
+    private function outputJson(array $data): void
+    {
+        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
+    }
+}
