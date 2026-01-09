<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    private const MAX_FILE_SIZE_KB = 10240; // 10MB
    private const UPLOAD_DIR = '/tmp/pocketdev-uploads'; // Shared via pocket-dev-shared-tmp volume

    /**
     * Upload a file to shared /tmp and return the path.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:' . self::MAX_FILE_SIZE_KB,
        ]);

        $file = $request->file('file');

        // Generate unique filename preserving extension
        $extension = $file->getClientOriginalExtension();
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName) ?: 'file';
        $uniqueName = $safeName . '-' . Str::random(8) . '.' . $extension;

        // Ensure upload directory exists
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $path = self::UPLOAD_DIR . '/' . $uniqueName;
        $file->move(self::UPLOAD_DIR, $uniqueName);

        return response()->json([
            'success' => true,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'size' => filesize($path),
            'size_formatted' => $this->formatFileSize(filesize($path)),
        ]);
    }

    /**
     * Delete a previously uploaded file.
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Security: Only allow deletion from upload directory
        if (!str_starts_with($path, self::UPLOAD_DIR . '/')) {
            return response()->json([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        // Prevent path traversal
        if (str_contains($path, '..')) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid path',
            ], 400);
        }

        if (file_exists($path)) {
            unlink($path);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Format bytes to human readable string.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
