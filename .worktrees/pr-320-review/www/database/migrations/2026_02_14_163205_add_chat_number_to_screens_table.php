<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add chat_number to screens table
        Schema::table('screens', function (Blueprint $table) {
            $table->unsignedInteger('chat_number')->nullable()->after('type');
        });

        // Add next_chat_number to pocketdev_sessions table
        Schema::table('pocketdev_sessions', function (Blueprint $table) {
            $table->unsignedInteger('next_chat_number')->default(1)->after('screen_order');
        });

        // Backfill existing chat screens with sequential numbers
        $sessions = DB::table('pocketdev_sessions')->get();

        foreach ($sessions as $session) {
            $screenOrder = json_decode($session->screen_order, true) ?? [];
            $chatNumber = 1;

            // Assign chat numbers based on screen_order
            foreach ($screenOrder as $screenId) {
                $screen = DB::table('screens')
                    ->where('id', $screenId)
                    ->where('type', 'chat')
                    ->first();

                if ($screen) {
                    DB::table('screens')
                        ->where('id', $screenId)
                        ->update(['chat_number' => $chatNumber]);
                    $chatNumber++;
                }
            }

            // Also handle any chat screens not in screen_order (edge case)
            $unorderedChats = DB::table('screens')
                ->where('session_id', $session->id)
                ->where('type', 'chat')
                ->whereNull('chat_number')
                ->orderBy('created_at')
                ->get();

            foreach ($unorderedChats as $screen) {
                DB::table('screens')
                    ->where('id', $screen->id)
                    ->update(['chat_number' => $chatNumber]);
                $chatNumber++;
            }

            // Set next_chat_number for the session
            DB::table('pocketdev_sessions')
                ->where('id', $session->id)
                ->update(['next_chat_number' => $chatNumber]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('chat_number');
        });

        Schema::table('pocketdev_sessions', function (Blueprint $table) {
            $table->dropColumn('next_chat_number');
        });
    }
};
