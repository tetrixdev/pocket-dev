<?php

namespace App\Panels;

use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailPanel extends Panel
{
    public string $slug = 'email';
    public string $name = 'Outlook';
    public string $description = 'View and manage email from Microsoft 365 accounts via Graph API';
    public string $icon = 'fa-solid fa-envelope';
    public string $category = 'communication';

    public array $parameters = [
        'account' => [
            'type' => 'string',
            'description' => 'Azure account name (from AZURE_{NAME}_* credentials). Omit to auto-select first account.',
            'default' => null,
        ],
    ];

    public array $panelDependencies = [
        ['type' => 'stylesheet', 'url' => 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css'],
        ['type' => 'script', 'url' => 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js'],
    ];

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        $accounts = MicrosoftGraphService::discoverAccounts($this->workspaceId);

        return view('panels.email', [
            'accounts' => $accounts,
            'selectedAccount' => $params['account'] ?? null,
            'state' => $state,
            'panelStateId' => $panelStateId,
        ])->render();
    }

    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        try {
            return match ($action) {
                'listAccounts' => $this->listAccounts(),
                'listFolders' => $this->handleListFolders($params),
                'listMessages' => $this->handleListMessages($params),
                'getMessage' => $this->handleGetMessage($params),
                'archiveMessage' => $this->handleArchiveMessage($params),
                'deleteMessage' => $this->handleDeleteMessage($params),
                'moveMessage' => $this->handleMoveMessage($params),
                'markRead' => $this->handleMarkRead($params),
                'markUnread' => $this->handleMarkUnread($params),
                'replyMessage' => $this->handleReplyMessage($params),
                'forwardMessage' => $this->handleForwardMessage($params),
                'sendMail' => $this->handleSendMail($params),
                'listAttachments' => $this->handleListAttachments($params),
                'downloadAttachment' => $this->handleDownloadAttachment($params),
                'downloadToTmp' => $this->handleDownloadToTmp($params),
                'exportMessage' => $this->handleExportMessage($params),
                default => parent::handleAction($action, $params, $state, $panelParams),
            };
        } catch (\RuntimeException $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        } catch (\Exception $e) {
            Log::error('EmailPanel action failed', [
                'action' => $action,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return ['data' => null, 'error' => 'An unexpected error occurred while processing this action.'];
        }
    }

    /**
     * Resolve an account array from a name.
     */
    private function resolveAccount(array $params): array
    {
        $name = $params['account'] ?? null;

        if (!$name) {
            $accounts = MicrosoftGraphService::discoverAccounts($this->workspaceId);
            if (empty($accounts)) {
                throw new \RuntimeException('No Azure accounts configured. Add AZURE_{NAME}_CLIENT_ID, _CLIENT_SECRET, _TENANT_ID, and _EMAIL in Settings > Credentials.');
            }
            return $accounts[0];
        }

        $account = MicrosoftGraphService::getAccount($name, $this->workspaceId);
        if (!$account) {
            throw new \RuntimeException("Account '{$name}' not found.");
        }

        return $account;
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    private function listAccounts(): array
    {
        $accounts = MicrosoftGraphService::discoverAccounts($this->workspaceId);

        return [
            'data' => [
                'accounts' => array_map(fn($a) => [
                    'name' => $a['name'],
                    'email' => $a['email'],
                ], $accounts),
            ],
            'error' => null,
        ];
    }

    private function handleListFolders(array $params): array
    {
        $account = $this->resolveAccount($params);
        $result = MicrosoftGraphService::listFolders($account);
        $folders = $result['value'] ?? [];

        // Resolve well-known folder IDs so we can sort by role regardless of locale.
        // Cached per account for 24 hours — these IDs essentially never change.
        $wellKnownNames = ['inbox', 'sentitems', 'drafts', 'archive', 'deleteditems', 'junkemail'];
        $cacheKey = 'email_panel:well_known_folders:' . md5($account['email'] ?? $account['name'] ?? '');

        $wellKnownIdOrder = Cache::remember($cacheKey, now()->addHours(24), function () use ($account, $wellKnownNames) {
            $mapping = [];
            foreach ($wellKnownNames as $position => $wkn) {
                try {
                    $wkFolder = MicrosoftGraphService::request(
                        'GET',
                        "/users/{email}/mailFolders/{$wkn}",
                        $account,
                        null,
                        ['$select' => 'id'],
                    );
                    if (isset($wkFolder['id'])) {
                        $mapping[$wkFolder['id']] = $position;
                    }
                } catch (\Exception $e) {
                    // Folder may not exist (e.g., Archive not enabled) — skip
                }
            }
            return $mapping;
        });

        // Sort: well-known folders first (by their defined order), then alphabetical
        usort($folders, function ($a, $b) use ($wellKnownIdOrder) {
            $aIdx = $wellKnownIdOrder[$a['id']] ?? null;
            $bIdx = $wellKnownIdOrder[$b['id']] ?? null;

            if ($aIdx !== null && $bIdx !== null) return $aIdx - $bIdx;
            if ($aIdx !== null) return -1;
            if ($bIdx !== null) return 1;

            return strcasecmp($a['displayName'], $b['displayName']);
        });

        // Build a map of folder ID → well-known name for the frontend (icons, highlighting)
        $wellKnownIdToName = [];
        foreach ($wellKnownIdOrder as $folderId => $position) {
            $wellKnownIdToName[$folderId] = $wellKnownNames[$position];
        }

        $inboxId = array_search(0, $wellKnownIdOrder, true); // position 0 = inbox

        // Detect granted permissions from the JWT token
        $permissions = MicrosoftGraphService::getTokenPermissions($account);

        return [
            'data' => [
                'folders' => $folders,
                'inboxId' => $inboxId ?: null,
                'wellKnownMap' => $wellKnownIdToName,
                'permissions' => $permissions,
            ],
            'error' => null,
        ];
    }

    private function handleListMessages(array $params): array
    {
        $account = $this->resolveAccount($params);
        $folderId = $params['folderId'] ?? 'inbox';
        $top = min((int) ($params['top'] ?? 25), 50);
        $skip = (int) ($params['skip'] ?? 0);
        $search = $params['search'] ?? null;

        $result = MicrosoftGraphService::listMessages($account, $folderId, $top, $skip, $search);

        return [
            'data' => [
                'messages' => $result['value'] ?? [],
                'totalCount' => $result['@odata.count'] ?? null,
                'nextLink' => $result['@odata.nextLink'] ?? null,
            ],
            'error' => null,
        ];
    }

    private function handleGetMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        $message = MicrosoftGraphService::getMessage($account, $messageId);

        // Also mark as read when opening
        if (!($message['isRead'] ?? true)) {
            try {
                MicrosoftGraphService::updateMessage($account, $messageId, ['isRead' => true]);
                $message['isRead'] = true;
            } catch (\Exception $e) {
                // Non-critical, continue
                Log::warning('Failed to mark message as read', ['messageId' => $messageId, 'error' => $e->getMessage()]);
            }
        }

        // Get attachments. Also fetch when body has cid: references, since Graph API
        // may return hasAttachments=false for emails with only inline images.
        $bodyHtml = $message['body']['content'] ?? '';
        $hasCidRefs = ($message['body']['contentType'] ?? '') !== 'text'
            && $bodyHtml
            && preg_match('/cid:/i', $bodyHtml);

        $attachments = [];
        if (($message['hasAttachments'] ?? false) || $hasCidRefs) {
            try {
                $attResult = MicrosoftGraphService::listAttachments($account, $messageId);
                $attachments = $attResult['value'] ?? [];
            } catch (\Exception $e) {
                Log::warning('Failed to list attachments', ['messageId' => $messageId, 'error' => $e->getMessage()]);
            }
        }

        // Resolve cid: inline images into data: URIs so they render in the sandboxed iframe.
        // listAttachments returns contentId and contentBytes for all file attachments (no $select),
        // so we can match and replace in one pass without any extra API calls.
        if ($attachments && $hasCidRefs) {
            if (preg_match_all('/cid:([^\s"\'<>]+)/i', $bodyHtml, $cidMatches)) {
                $cidRefs = array_map('strtolower', array_unique($cidMatches[1]));

                foreach ($attachments as $key => $att) {
                    $contentId = trim($att['contentId'] ?? '', '<>');
                    if ($contentId === '' || !in_array(strtolower($contentId), $cidRefs, true)) {
                        continue;
                    }

                    if (!empty($att['contentBytes']) && !empty($att['contentType'])) {
                        $dataUri = 'data:' . $att['contentType'] . ';base64,' . $att['contentBytes'];
                        $bodyHtml = str_ireplace('cid:' . $contentId, $dataUri, $bodyHtml);
                        $attachments[$key]['_cidResolved'] = true;
                    }
                }
                $message['body']['content'] = $bodyHtml;
            }
        }

        // Strip contentBytes from attachments before sending to frontend (can be megabytes).
        foreach (array_keys($attachments) as $key) {
            unset($attachments[$key]['contentBytes']);
        }

        $message['_attachments'] = $attachments;

        return [
            'data' => ['message' => $message],
            'error' => null,
        ];
    }

    private function handleArchiveMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        MicrosoftGraphService::moveMessage($account, $messageId, 'archive');

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleDeleteMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        MicrosoftGraphService::deleteMessage($account, $messageId);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleMoveMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;
        $destinationFolderId = $params['destinationFolderId'] ?? null;

        if (!$messageId || !$destinationFolderId) {
            throw new \RuntimeException('messageId and destinationFolderId are required');
        }

        MicrosoftGraphService::moveMessage($account, $messageId, $destinationFolderId);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleMarkRead(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        MicrosoftGraphService::updateMessage($account, $messageId, ['isRead' => true]);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleMarkUnread(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        MicrosoftGraphService::updateMessage($account, $messageId, ['isRead' => false]);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleReplyMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;
        $comment = $params['comment'] ?? '';
        $bodyHtml = $params['bodyHtml'] ?? null;
        $replyAll = $params['replyAll'] ?? false;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        MicrosoftGraphService::reply($account, $messageId, $comment, $replyAll, $bodyHtml);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleForwardMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;
        $to = $params['to'] ?? [];
        $comment = $params['comment'] ?? null;
        $bodyHtml = $params['bodyHtml'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }
        if (empty($to)) {
            throw new \RuntimeException('At least one recipient (to) is required');
        }

        // Support both array of strings and comma-separated string
        if (is_string($to)) {
            $to = array_map('trim', explode(',', $to));
        }

        MicrosoftGraphService::forward($account, $messageId, $to, $comment, $bodyHtml);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleSendMail(array $params): array
    {
        $account = $this->resolveAccount($params);
        $to = $params['to'] ?? [];
        $cc = $params['cc'] ?? [];
        $bcc = $params['bcc'] ?? [];
        $subject = $params['subject'] ?? '';
        $body = $params['body'] ?? '';
        $contentType = $params['contentType'] ?? 'Text';

        if (empty($to)) {
            throw new \RuntimeException('At least one recipient (to) is required');
        }

        // Support comma-separated strings
        if (is_string($to)) $to = array_filter(array_map('trim', explode(',', $to)));
        if (is_string($cc)) $cc = array_filter(array_map('trim', explode(',', $cc)));
        if (is_string($bcc)) $bcc = array_filter(array_map('trim', explode(',', $bcc)));

        $toRecipients = fn($emails) => array_map(fn($e) => ['emailAddress' => ['address' => $e]], $emails);

        $message = [
            'subject' => $subject,
            'body' => [
                'contentType' => $contentType,
                'content' => $body,
            ],
            'toRecipients' => $toRecipients($to),
        ];

        if (!empty($cc)) $message['ccRecipients'] = $toRecipients($cc);
        if (!empty($bcc)) $message['bccRecipients'] = $toRecipients($bcc);

        MicrosoftGraphService::sendMail($account, $message);

        return ['data' => ['success' => true], 'error' => null];
    }

    private function handleListAttachments(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        $result = MicrosoftGraphService::listAttachments($account, $messageId);

        // Strip contentBytes before sending to frontend (can be megabytes of base64).
        $attachments = $result['value'] ?? [];
        foreach (array_keys($attachments) as $key) {
            unset($attachments[$key]['contentBytes']);
        }

        return [
            'data' => ['attachments' => $attachments],
            'error' => null,
        ];
    }

    private function handleDownloadAttachment(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;
        $attachmentId = $params['attachmentId'] ?? null;

        if (!$messageId || !$attachmentId) {
            throw new \RuntimeException('messageId and attachmentId are required');
        }

        $attachment = MicrosoftGraphService::getAttachment($account, $messageId, $attachmentId);

        return [
            'data' => [
                'name' => $attachment['name'] ?? 'unnamed',
                'contentType' => $attachment['contentType'] ?? 'application/octet-stream',
                'size' => $attachment['size'] ?? 0,
                'contentBytes' => $attachment['contentBytes'] ?? null,
            ],
            'error' => null,
        ];
    }

    private function handleDownloadToTmp(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;
        $attachmentId = $params['attachmentId'] ?? null;

        if (!$messageId || !$attachmentId) {
            throw new \RuntimeException('messageId and attachmentId are required');
        }

        $path = MicrosoftGraphService::downloadAttachmentToTmp($account, $messageId, $attachmentId);

        return [
            'data' => ['path' => $path, 'success' => true],
            'error' => null,
        ];
    }

    private function handleExportMessage(array $params): array
    {
        $account = $this->resolveAccount($params);
        $messageId = $params['messageId'] ?? null;

        if (!$messageId) {
            throw new \RuntimeException('messageId is required');
        }

        $path = MicrosoftGraphService::exportMessageToTmp($account, $messageId);

        return [
            'data' => ['path' => $path, 'success' => true],
            'error' => null,
        ];
    }

    // =========================================================================
    // Panel metadata
    // =========================================================================

    public function peek(array $params, array $state): string
    {
        try {
            $accounts = MicrosoftGraphService::discoverAccounts($this->workspaceId);

            if (empty($accounts)) {
                return "## Outlook\n\nNo Azure accounts configured.";
            }

            $accountName = $params['account'] ?? $accounts[0]['name'];
            $account = MicrosoftGraphService::getAccount($accountName, $this->workspaceId);

            if (!$account) {
                return "## Outlook\n\nAccount '{$accountName}' not found.";
            }

            $output = "## Outlook ({$account['email']})\n\n";

            // Get inbox preview
            $result = MicrosoftGraphService::listMessages($account, 'inbox', 5);
            $messages = $result['value'] ?? [];

            if (empty($messages)) {
                $output .= "Inbox is empty.";
                return $output;
            }

            $unread = count(array_filter($messages, fn($m) => !($m['isRead'] ?? true)));
            $output .= "**Inbox** ({$unread} unread)\n\n";

            foreach ($messages as $msg) {
                $read = ($msg['isRead'] ?? false) ? '  ' : '* ';
                $from = $msg['from']['emailAddress']['name'] ?? $msg['from']['emailAddress']['address'] ?? '?';
                $subject = $msg['subject'] ?? '(no subject)';
                $date = isset($msg['receivedDateTime'])
                    ? date('M j H:i', strtotime($msg['receivedDateTime']))
                    : '';
                $output .= "{$read}{$from} — {$subject} ({$date})\n";
            }

            return $output;
        } catch (\Exception $e) {
            Log::warning('EmailPanel peek failed', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return "## Outlook\n\nFailed to load inbox preview.";
        }
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Opens an interactive Outlook panel for viewing and managing Microsoft 365 email via Graph API.

## What It Shows
- Inbox and other mail folders with unread counts
- Email list with sender, subject, date, and read status
- Full email preview with HTML rendering
- Attachment management

## Features
- **Multi-account**: Switch between configured Azure accounts (dropdown)
- **Folder navigation**: Inbox, Sent, Drafts, Archive, Deleted Items, custom folders
- **Read emails**: Click to preview with full HTML body rendering
- **Reply / Reply All / Forward**: Rich text compose with WYSIWYG editor and original message preview
- **Compose**: Write and send new emails with formatting (bold, italic, lists, links)
- **Archive / Delete**: Quick actions on messages
- **Attachments**: View and download email attachments
- **Export to path**: Download email as .eml to /tmp and copy path to clipboard (useful for discussing emails in conversations)
- **Search**: Search within the current folder

## Account Setup
Add credential pairs in Settings > Credentials:
- AZURE_{NAME}_CLIENT_ID
- AZURE_{NAME}_CLIENT_SECRET
- AZURE_{NAME}_TENANT_ID
- AZURE_{NAME}_EMAIL

## CLI Example
```bash
pd tool:run email
pd tool:run email -- --account=PERSONAL
```

Use `pd panel:peek email` to see current inbox state.
PROMPT;
    }
}
