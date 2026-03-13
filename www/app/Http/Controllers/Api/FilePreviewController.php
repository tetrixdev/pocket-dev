<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PathValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FilePreviewController extends Controller
{
    /**
     * Preview a file's contents.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Validate path is within allowed directories
        $validation = $this->validatePath($path);
        if ($validation['error']) {
            return response()->json($validation['response'], $validation['status']);
        }

        $realPath = $validation['realPath'];

        // Check if it's a file (not directory)
        if (is_dir($realPath)) {
            return response()->json([
                'exists' => true,
                'error' => 'Path is a directory, not a file',
            ], 400);
        }

        // Check if readable
        if (!is_readable($realPath)) {
            return response()->json([
                'exists' => true,
                'readable' => false,
                'error' => 'Permission denied: cannot read file',
            ], 403);
        }

        // Get file info
        $fileSize = filesize($realPath);
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $filename = basename($realPath);

        // Check file size
        $maxFileSize = config('ai.file_preview.max_file_size', 2 * 1024 * 1024);
        if ($fileSize > $maxFileSize) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'too_large' => true,
                'error' => 'File too large for preview (max ' . $this->formatBytes($maxFileSize) . ')',
            ], 200);
        }

        // Read file content
        $content = file_get_contents($realPath);

        if ($content === false) {
            return response()->json([
                'exists' => true,
                'readable' => false,
                'error' => 'Failed to read file',
            ], 500);
        }

        // Detect if content is binary
        $isBinary = $this->isBinaryContent($content);

        if ($isBinary) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'binary' => true,
                'error' => 'Binary file cannot be previewed as text',
            ], 200);
        }

        // Validate UTF-8 encoding (required for JSON response)
        if (!mb_check_encoding($content, 'UTF-8')) {
            return response()->json([
                'exists' => true,
                'readable' => true,
                'size' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'extension' => $extension,
                'filename' => $filename,
                'path' => $path,
                'encoding_error' => true,
                'error' => 'File contains invalid UTF-8 encoding',
            ], 200);
        }

        return response()->json([
            'exists' => true,
            'readable' => true,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'extension' => $extension,
            'filename' => $filename,
            'path' => $path,
            'content' => $content,
            'is_markdown' => in_array($extension, ['md', 'markdown']),
            'is_html' => in_array($extension, ['html', 'htm']),
        ]);
    }

    /**
     * Write content to a file.
     */
    public function write(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
            'content' => 'present|string', // Allow empty files
        ]);

        $path = $request->input('path');
        $content = $request->input('content');

        // For new files, we can't use realpath (file doesn't exist yet)
        // But for editing existing files via file preview, the file should exist
        $realPath = PathValidator::validate($path);

        if ($realPath === null) {
            // Return generic error to prevent information disclosure
            // (don't reveal whether file exists outside allowed paths)
            return response()->json([
                'success' => false,
                'error' => 'File not found or access denied',
            ], 403);
        }

        // Require a regular file (not a directory, device, pipe, etc.)
        if (!is_file($realPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Path is not a regular file',
            ], 400);
        }

        // Check if writable
        if (!is_writable($realPath)) {
            return response()->json([
                'success' => false,
                'error' => 'File is not writable',
            ], 403);
        }

        // Write the file with exclusive lock to prevent concurrent write corruption
        $result = file_put_contents($realPath, $content, LOCK_EX);

        if ($result === false) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to write file',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'bytes_written' => $result,
            'size_formatted' => $this->formatBytes($result),
        ]);
    }

    /**
     * Download a file.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        $validation = $this->validatePath($path);
        if ($validation['error']) {
            return response()->json($validation['response'], $validation['status']);
        }

        $realPath = $validation['realPath'];

        if (is_dir($realPath)) {
            return response()->json(['error' => 'Path is a directory, not a file'], 400);
        }

        if (!is_readable($realPath)) {
            return response()->json(['error' => 'Permission denied: cannot read file'], 403);
        }

        return response()->download($realPath);
    }

    /**
     * Check if a path exists and is readable (quick check without content).
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Validate path is within allowed directories
        $validation = $this->validatePath($path);
        if ($validation['error']) {
            // Don't reveal whether file exists outside allowed paths
            return response()->json(['exists' => false]);
        }

        $realPath = $validation['realPath'];

        return response()->json([
            'exists' => true,
            'is_file' => is_file($realPath),
            'readable' => is_readable($realPath),
        ]);
    }

    /**
     * Validate that a path exists and is within allowed directories.
     *
     * @return array{error: bool, realPath?: string, response?: array, status?: int}
     */
    private function validatePath(string $path): array
    {
        // Validate path is within allowed directories
        // PathValidator::validate() returns null for both non-existent paths
        // (realpath returns false) and paths outside allowed directories
        $realPath = PathValidator::validate($path);

        if ($realPath === null) {
            // Return generic error to prevent information disclosure
            // (don't reveal whether file exists outside allowed paths)
            return [
                'error' => true,
                'response' => ['exists' => false, 'error' => 'File not found or access denied'],
                'status' => 404,
            ];
        }

        return [
            'error' => false,
            'realPath' => $realPath,
        ];
    }

    /**
     * Format bytes to human readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Check if content appears to be binary.
     */
    private function isBinaryContent(string $content): bool
    {
        // Check first 8KB for null bytes or high concentration of non-printable chars
        $sample = substr($content, 0, 8192);

        // Null bytes are a strong indicator of binary
        if (strpos($sample, "\0") !== false) {
            return true;
        }

        // Count non-printable characters (excluding common whitespace)
        $nonPrintable = 0;
        $length = strlen($sample);

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($sample[$i]);
            // Allow: tab (9), newline (10), carriage return (13), and printable ASCII (32-126)
            // Also allow UTF-8 continuation bytes (128-255)
            if ($ord < 9 || ($ord > 13 && $ord < 32) || $ord === 127) {
                $nonPrintable++;
            }
        }

        // If more than 10% non-printable, consider it binary
        return ($nonPrintable / max(1, $length)) > 0.1;
    }
}
