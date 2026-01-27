<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    /**
     * Upload a file to shared /tmp and return the path.
     */
    public function upload(Request $request): JsonResponse
    {
        $maxSizeKb = config('uploads.max_size_mb', 250) * 1024;

        $request->validate([
            'file' => 'required|file|max:' . $maxSizeKb,
        ]);

        $file = $request->file('file');

        // Generate unique filename preserving extension (sanitize extension)
        $extension = preg_replace('/[^a-zA-Z0-9]+/', '', (string) $file->getClientOriginalExtension());
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName) ?: 'file';
        $uniqueName = $safeName . '-' . Str::random(8) . ($extension ? ('.' . $extension) : '');

        // Get upload directory from config
        $uploadDir = config('uploads.directory', '/tmp/pocketdev-uploads');

        // Ensure upload directory exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create upload directory',
                ], 500);
            }
        }

        $path = $uploadDir . '/' . $uniqueName;

        try {
            $file->move($uploadDir, $uniqueName);
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
        $uploadDir = config('uploads.directory', '/tmp/pocketdev-uploads');
        $uploadRoot = realpath($uploadDir);
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
