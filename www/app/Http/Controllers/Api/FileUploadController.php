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

        // Generate unique filename preserving extension (sanitize extension)
        $extension = preg_replace('/[^a-zA-Z0-9]+/', '', (string) $file->getClientOriginalExtension());
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName) ?: 'file';
        $uniqueName = $safeName . '-' . Str::random(8) . ($extension ? ('.' . $extension) : '');

        // Ensure upload directory exists
        if (!is_dir(self::UPLOAD_DIR)) {
            if (!mkdir(self::UPLOAD_DIR, 0755, true) && !is_dir(self::UPLOAD_DIR)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create upload directory',
                ], 500);
            }
        }

        $path = self::UPLOAD_DIR . '/' . $uniqueName;

        try {
            $file->move(self::UPLOAD_DIR, $uniqueName);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Upload failed',
            ], 500);
        }

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

        // Security: Use realpath() for canonical path validation (protects against symlink attacks)
        $uploadRoot = realpath(self::UPLOAD_DIR);
        $resolved = $path ? realpath($path) : false;

        if (!$uploadRoot || !$resolved || !str_starts_with($resolved, $uploadRoot . DIRECTORY_SEPARATOR)) {
            return response()->json([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        if (is_file($resolved)) {
            unlink($resolved);
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
