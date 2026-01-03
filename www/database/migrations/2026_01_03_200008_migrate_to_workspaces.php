<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\MemoryDatabase;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create default workspace
        $defaultWorkspace = Workspace::create([
            'name' => 'Default',
            'directory' => 'default',
            'description' => 'Default workspace (migrated from legacy)',
        ]);

        Log::info('Created default workspace', ['id' => $defaultWorkspace->id]);

        // 2. Create default memory database record
        $defaultMemoryDb = MemoryDatabase::create([
            'name' => 'Default',
            'schema_name' => 'default',
            'description' => 'Default memory database (migrated from legacy)',
        ]);

        Log::info('Created default memory database', ['id' => $defaultMemoryDb->id]);

        // 3. Rename existing memory schema if it exists
        try {
            $schemaExists = DB::connection('pgsql')
                ->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'memory'");

            if (!empty($schemaExists)) {
                DB::connection('pgsql')->statement('ALTER SCHEMA memory RENAME TO memory_default');
                Log::info('Renamed memory schema to memory_default');
            }
        } catch (\Exception $e) {
            Log::warning('Could not rename memory schema', ['error' => $e->getMessage()]);
        }

        // 4. Associate workspace with memory database
        $defaultWorkspace->memoryDatabases()->attach($defaultMemoryDb->id, [
            'enabled' => true,
            'is_default' => true,
        ]);

        // 5. Associate existing agents with default workspace
        Agent::whereNull('workspace_id')->update(['workspace_id' => $defaultWorkspace->id]);
        Log::info('Associated agents with default workspace', ['count' => Agent::where('workspace_id', $defaultWorkspace->id)->count()]);

        // 6. Associate existing conversations with default workspace
        Conversation::whereNull('workspace_id')->update(['workspace_id' => $defaultWorkspace->id]);
        Log::info('Associated conversations with default workspace', ['count' => Conversation::where('workspace_id', $defaultWorkspace->id)->count()]);

        // 7. Give all agents access to default memory database
        $agents = Agent::all();
        foreach ($agents as $agent) {
            $agent->memoryDatabases()->attach($defaultMemoryDb->id, ['permission' => 'write']);
        }
        Log::info('Granted agents access to default memory database', ['count' => $agents->count()]);

        // 8. Create default workspace directory
        $path = '/workspace/default';
        if (!is_dir($path)) {
            if (@mkdir($path, 0755, true)) {
                Log::info('Created default workspace directory', ['path' => $path]);
            } else {
                Log::warning('Could not create default workspace directory', ['path' => $path]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get default workspace
        $defaultWorkspace = Workspace::where('directory', 'default')->first();

        if ($defaultWorkspace) {
            // Disassociate agents and conversations
            Agent::where('workspace_id', $defaultWorkspace->id)->update(['workspace_id' => null]);
            Conversation::where('workspace_id', $defaultWorkspace->id)->update(['workspace_id' => null]);

            // Delete workspace (cascades to workspace_memory_databases)
            $defaultWorkspace->forceDelete();
        }

        // Delete default memory database
        $defaultMemoryDb = MemoryDatabase::where('schema_name', 'default')->first();
        if ($defaultMemoryDb) {
            $defaultMemoryDb->forceDelete();
        }

        // Rename schema back
        try {
            $schemaExists = DB::connection('pgsql')
                ->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'memory_default'");

            if (!empty($schemaExists)) {
                DB::connection('pgsql')->statement('ALTER SCHEMA memory_default RENAME TO memory');
            }
        } catch (\Exception $e) {
            Log::warning('Could not rename memory schema back', ['error' => $e->getMessage()]);
        }
    }
};
