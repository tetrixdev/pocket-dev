<?php

namespace App\Services;

use App\Models\Credential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphService
{
    /**
     * Token cache: keyed by account name, stores ['token' => string, 'expires_at' => int]
     */
    private static array $tokenCache = [];

    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const SCOPE = 'https://graph.microsoft.com/.default';

    /**
     * Discover all Azure accounts by scanning credentials.
     * Looks for AZURE_{NAME}_CLIENT_ID patterns.
     *
     * @return array<int, array{name: string, client_id: string, client_secret: string, tenant_id: string, email: string}>
     */
    public static function discoverAccounts(?string $workspaceId = null): array
    {
        // When no workspace specified, get ALL credentials (matches database panel behavior).
        // getEnvArrayForWorkspace(null) only returns global creds, but most users store
        // credentials at the workspace level.
        $credentials = $workspaceId
            ? Credential::getEnvArrayForWorkspace($workspaceId)
            : Credential::getAllAsEnvArray();
        $accounts = [];
        $found = [];

        // Scan for AZURE_{NAME}_CLIENT_ID patterns
        foreach ($credentials as $envVar => $value) {
            if (preg_match('/^AZURE_(.+)_CLIENT_ID$/', $envVar, $matches)) {
                $name = $matches[1]; // e.g., "PERSONAL", "WORK"
                $found[$name]['client_id'] = $value;
            } elseif (preg_match('/^AZURE_(.+)_CLIENT_SECRET$/', $envVar, $matches)) {
                $found[$matches[1]]['client_secret'] = $value;
            } elseif (preg_match('/^AZURE_(.+)_TENANT_ID$/', $envVar, $matches)) {
                $found[$matches[1]]['tenant_id'] = $value;
            } elseif (preg_match('/^AZURE_(.+)_EMAIL$/', $envVar, $matches)) {
                $found[$matches[1]]['email'] = $value;
            }
        }

        // Only return accounts where all four credentials are present
        foreach ($found as $name => $creds) {
            if (isset($creds['client_id'], $creds['client_secret'], $creds['tenant_id'], $creds['email'])) {
                $accounts[] = [
                    'name' => $name,
                    'client_id' => $creds['client_id'],
                    'client_secret' => $creds['client_secret'],
                    'tenant_id' => $creds['tenant_id'],
                    'email' => $creds['email'],
                ];
            }
        }

        // Sort alphabetically by name
        usort($accounts, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $accounts;
    }

    /**
     * Get a specific account by name.
     */
    public static function getAccount(string $name, ?string $workspaceId = null): ?array
    {
        $accounts = self::discoverAccounts($workspaceId);

        foreach ($accounts as $account) {
            if (strcasecmp($account['name'], $name) === 0) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Get OAuth2 access token for an account (with caching).
     *
     * @throws \RuntimeException If token acquisition fails
     */
    public static function getAccessToken(array $account): string
    {
        $cacheKey = strtoupper($account['name']);

        // Check cache (valid until 5 minutes before expiry)
        if (isset(self::$tokenCache[$cacheKey])) {
            $cached = self::$tokenCache[$cacheKey];
            if ($cached['expires_at'] > time() + 300) {
                return $cached['token'];
            }
        }

        $tokenUrl = sprintf(self::TOKEN_URL_TEMPLATE, $account['tenant_id']);

        $response = Http::asForm()->post($tokenUrl, [
            'client_id' => $account['client_id'],
            'client_secret' => $account['client_secret'],
            'scope' => self::SCOPE,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? $response->body();
            Log::error('Microsoft Graph token acquisition failed', [
                'account' => $account['name'],
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \RuntimeException("Failed to acquire access token for account '{$account['name']}': {$error}");
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException(
                "Token response missing access_token for account '{$account['name']}'"
            );
        }
        $token = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3600;

        // Cache the token
        self::$tokenCache[$cacheKey] = [
            'token' => $token,
            'expires_at' => time() + $expiresIn,
        ];

        return $token;
    }

    /**
     * Make an authenticated Graph API request.
     *
     * @param string $method HTTP method (GET, POST, PATCH, DELETE)
     * @param string $endpoint API endpoint path (e.g., /users/{email}/messages)
     * @param array $account Account credentials
     * @param array|null $body Request body for POST/PATCH
     * @param array $queryParams Query parameters
     * @return array Parsed JSON response
     * @throws \RuntimeException On API errors
     */
    public static function request(
        string $method,
        string $endpoint,
        array $account,
        ?array $body = null,
        array $queryParams = [],
    ): array {
        $token = self::getAccessToken($account);

        // Replace {email} placeholder with account email
        $endpoint = str_replace('{email}', $account['email'], $endpoint);

        $url = self::GRAPH_BASE_URL . $endpoint;

        $request = Http::withToken($token)
            ->timeout(30)
            ->acceptJson();

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $body ?? []),
            'PATCH' => $request->patch($url, $body ?? []),
            'DELETE' => $request->delete($url),
            'PUT' => $request->put($url, $body ?? []),
            default => throw new \RuntimeException("Unsupported HTTP method: {$method}"),
        };

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->json('error') ?? $response->body();
            $code = $response->json('error.code') ?? 'unknown';

            throw new \RuntimeException("Graph API error ({$code}): {$error}");
        }

        // DELETE returns 204 No Content
        if ($response->status() === 204) {
            return ['success' => true];
        }

        return $response->json() ?? [];
    }

    /**
     * Download binary content (e.g., attachment content).
     *
     * @return string Raw binary content
     */
    public static function downloadBinary(string $endpoint, array $account): string
    {
        $token = self::getAccessToken($account);
        $endpoint = str_replace('{email}', $account['email'], $endpoint);
        $url = self::GRAPH_BASE_URL . $endpoint;

        $response = Http::withToken($token)
            ->timeout(60)
            ->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to download binary content: ' . $response->status());
        }

        return $response->body();
    }

    /**
     * Decode the JWT access token and extract granted application permissions (roles).
     *
     * @return string[] e.g., ['Mail.Read', 'Mail.ReadWrite', 'Mail.Send']
     */
    public static function getTokenPermissions(array $account): array
    {
        $token = self::getAccessToken($account);

        // JWT = header.payload.signature (base64url-encoded)
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return [];
        }

        // Decode the payload (base64url → base64 → JSON)
        $payload = strtr($parts[1], '-_', '+/');
        $decoded = json_decode(base64_decode($payload), true);

        return $decoded['roles'] ?? [];
    }

    // =========================================================================
    // Convenience methods for common email operations
    // =========================================================================

    /**
     * List messages in a folder.
     */
    public static function listMessages(
        array $account,
        string $folderId = 'inbox',
        int $top = 25,
        int $skip = 0,
        ?string $search = null,
        ?string $filter = null,
        string $orderBy = 'receivedDateTime desc',
    ): array {
        $params = [
            '$top' => $top,
            '$skip' => $skip,
            '$orderby' => $orderBy,
            '$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,isRead,hasAttachments,bodyPreview,importance,flag',
            '$count' => 'true',
        ];

        if ($search) {
            $params['$search'] = '"' . str_replace('"', '', $search) . '"';
            // Graph API doesn't support $orderby or $skip with $search
            unset($params['$orderby'], $params['$skip']);
        }

        if ($filter) {
            $params['$filter'] = $filter;
        }

        $endpoint = "/users/{email}/mailFolders/{$folderId}/messages";

        return self::request('GET', $endpoint, $account, null, $params);
    }

    /**
     * Get a single message with body.
     */
    public static function getMessage(array $account, string $messageId): array
    {
        return self::request('GET', "/users/{email}/messages/{$messageId}", $account);
    }

    /**
     * List mail folders.
     */
    public static function listFolders(array $account): array
    {
        return self::request('GET', '/users/{email}/mailFolders', $account, null, [
            '$top' => 100,
            '$select' => 'id,displayName,totalItemCount,unreadItemCount,parentFolderId,childFolderCount',
        ]);
    }

    /**
     * Send a new email.
     */
    public static function sendMail(array $account, array $message): array
    {
        return self::request('POST', '/users/{email}/sendMail', $account, [
            'message' => $message,
        ]);
    }

    /**
     * Reply to a message.
     *
     * When $bodyHtml is provided, the reply body is sent as HTML via the message.body
     * property (full control over formatting). Otherwise, the plain text $comment is used
     * and Graph auto-generates the reply body with quoted original.
     */
    public static function reply(
        array $account,
        string $messageId,
        string $comment,
        bool $replyAll = false,
        ?string $bodyHtml = null,
    ): array {
        $action = $replyAll ? 'replyAll' : 'reply';

        $body = [];

        if ($bodyHtml !== null) {
            $body['message'] = [
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $bodyHtml,
                ],
            ];
        } else {
            $body['comment'] = $comment;
        }

        return self::request('POST', "/users/{email}/messages/{$messageId}/{$action}", $account, $body);
    }

    /**
     * Forward a message.
     *
     * When $bodyHtml is provided, the forward body is sent as HTML via the message.body
     * property. Otherwise, the plain text $comment is used.
     */
    public static function forward(
        array $account,
        string $messageId,
        array $toRecipients,
        ?string $comment = null,
        ?string $bodyHtml = null,
    ): array {
        $body = [
            'toRecipients' => array_map(fn($email) => [
                'emailAddress' => ['address' => $email],
            ], $toRecipients),
        ];

        if ($bodyHtml !== null) {
            $body['message'] = [
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $bodyHtml,
                ],
            ];
        } elseif ($comment) {
            $body['comment'] = $comment;
        }

        return self::request('POST', "/users/{email}/messages/{$messageId}/forward", $account, $body);
    }

    /**
     * Move a message to a folder.
     */
    public static function moveMessage(array $account, string $messageId, string $destinationFolderId): array
    {
        return self::request('POST', "/users/{email}/messages/{$messageId}/move", $account, [
            'destinationId' => $destinationFolderId,
        ]);
    }

    /**
     * Delete a message.
     */
    public static function deleteMessage(array $account, string $messageId): array
    {
        return self::request('DELETE', "/users/{email}/messages/{$messageId}", $account);
    }

    /**
     * Update message properties (e.g., mark read/unread).
     */
    public static function updateMessage(array $account, string $messageId, array $properties): array
    {
        return self::request('PATCH', "/users/{email}/messages/{$messageId}", $account, $properties);
    }

    /**
     * List attachments for a message (includes contentId and contentBytes for file attachments).
     * No $select filter — the base attachment type doesn't expose fileAttachment-specific
     * properties like contentId via $select, but the default response includes everything.
     */
    public static function listAttachments(array $account, string $messageId): array
    {
        return self::request('GET', "/users/{email}/messages/{$messageId}/attachments", $account);
    }

    /**
     * Get a specific attachment (includes contentBytes as base64).
     */
    public static function getAttachment(array $account, string $messageId, string $attachmentId): array
    {
        return self::request('GET', "/users/{email}/messages/{$messageId}/attachments/{$attachmentId}", $account);
    }

    /**
     * Export a message as .eml (RFC 2822 MIME) file to /tmp.
     * Returns the file path.
     */
    public static function exportMessageToTmp(array $account, string $messageId): string
    {
        // Fetch the raw MIME content directly from Graph API
        $token = self::getAccessToken($account);
        $email = $account['email'];

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->get("https://graph.microsoft.com/v1.0/users/{$email}/messages/{$messageId}/\$value");

        if (!$response->successful()) {
            throw new \RuntimeException("Failed to fetch MIME content: {$response->status()}");
        }

        $message = self::getMessage($account, $messageId);
        $subject = $message['subject'] ?? 'no-subject';
        $safeSubject = preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($subject, 0, 60));
        $date = isset($message['receivedDateTime'])
            ? date('Y-m-d', strtotime($message['receivedDateTime']))
            : date('Y-m-d');

        $exportDir = "/tmp/email-exports";
        if (!is_dir($exportDir) && !mkdir($exportDir, 0777, true)) {
            throw new \RuntimeException("Failed to create export directory: {$exportDir}");
        }

        $filePath = "{$exportDir}/{$date}_{$safeSubject}.eml";
        if (file_put_contents($filePath, $response->body()) === false) {
            throw new \RuntimeException("Failed to write export file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Download an attachment to /tmp and return the file path.
     */
    public static function downloadAttachmentToTmp(
        array $account,
        string $messageId,
        string $attachmentId,
    ): string {
        $attachment = self::getAttachment($account, $messageId, $attachmentId);

        $name = $attachment['name'] ?? 'unnamed';
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);

        $dir = '/tmp/email-attachments';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new \RuntimeException("Failed to create attachment directory: {$dir}");
        }

        $path = "{$dir}/{$safeName}";

        // Add suffix if file exists
        if (file_exists($path)) {
            $ext = pathinfo($safeName, PATHINFO_EXTENSION);
            $base = pathinfo($safeName, PATHINFO_FILENAME);
            $path = "{$dir}/{$base}_" . substr(md5($attachmentId), 0, 6) . ($ext ? ".{$ext}" : '');
        }

        if (!isset($attachment['contentBytes'])) {
            throw new \RuntimeException('Attachment has no content bytes');
        }

        $decoded = base64_decode($attachment['contentBytes'], true);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode attachment content (invalid base64)');
        }

        if (file_put_contents($path, $decoded) === false) {
            throw new \RuntimeException("Failed to write attachment file: {$path}");
        }

        return $path;
    }
}
