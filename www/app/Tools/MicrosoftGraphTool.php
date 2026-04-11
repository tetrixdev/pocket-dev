<?php

namespace App\Tools;

use App\Services\MicrosoftGraphService;

/**
 * Built-in tool for making Microsoft Graph API calls.
 * Auto-discovered by AIServiceProvider from app/Tools/*Tool.php.
 */
class MicrosoftGraphTool extends Tool
{
    public string $name = 'MicrosoftGraph';

    public string $description = 'Make authenticated Microsoft Graph API calls for email, calendar, and other Microsoft 365 services. Supports multiple Azure accounts.';

    public string $category = 'custom';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'account' => [
                'type' => 'string',
                'description' => 'Account name matching AZURE_{NAME}_* credentials (e.g., "PERSONAL", "WORK"). Omit to list available accounts.',
            ],
            'method' => [
                'type' => 'string',
                'enum' => ['GET', 'POST', 'PATCH', 'DELETE'],
                'description' => 'HTTP method. Default: GET.',
            ],
            'endpoint' => [
                'type' => 'string',
                'description' => 'Graph API endpoint path (e.g., /users/{email}/messages). The {email} placeholder is auto-replaced with the account\'s configured email.',
            ],
            'body' => [
                'type' => 'object',
                'description' => 'JSON body for POST/PATCH requests.',
            ],
            'query' => [
                'type' => 'object',
                'description' => 'Query parameters (e.g., {"$top": 10, "$select": "subject,from"}).',
            ],
        ],
        'required' => [],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Thin wrapper around the Microsoft Graph REST API (v1.0). Supports any endpoint and operation available in the Graph API — email, calendar, OneDrive, etc.

## Account Discovery
Call without parameters to list available accounts.
Account names come from AZURE_{NAME}_* credential pairs (CLIENT_ID, CLIENT_SECRET, TENANT_ID, EMAIL).

## Key Details
- The `{email}` placeholder in endpoints is auto-replaced with the account's configured email
- Standard OData query parameters are supported: `$select`, `$top`, `$skip`, `$filter`, `$search`, `$orderby`

## Examples

List inbox:
```json
{"account": "PERSONAL", "endpoint": "/users/{email}/mailFolders/inbox/messages", "query": {"$top": 10, "$orderby": "receivedDateTime desc"}}
```

Send an email:
```json
{"account": "PERSONAL", "method": "POST", "endpoint": "/users/{email}/sendMail", "body": {"message": {"subject": "Hello", "body": {"contentType": "Text", "content": "Hi!"}, "toRecipients": [{"emailAddress": {"address": "user@example.com"}}]}}}
```
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Examples

```bash
# List available accounts
pd microsoft-graph

# List inbox messages
pd microsoft-graph --account=PERSONAL --method=GET --endpoint="/users/{email}/mailFolders/inbox/messages" --query='{"$top": 10}'

# Read a message
pd microsoft-graph --account=PERSONAL --method=GET --endpoint="/users/{email}/messages/MESSAGE_ID"

# Send an email
pd microsoft-graph --account=PERSONAL --method=POST --endpoint="/users/{email}/sendMail" --body='{"message":{"subject":"Test","body":{"contentType":"Text","content":"Hello"},"toRecipients":[{"emailAddress":{"address":"user@example.com"}}]}}'
```
CLI;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $accountName = $input['account'] ?? null;
        $method = $input['method'] ?? 'GET';
        $endpoint = $input['endpoint'] ?? null;
        $body = $input['body'] ?? null;
        $query = $input['query'] ?? [];

        // Get workspace for credential scoping
        $workspace = $context->getWorkspace();
        $workspaceId = $workspace?->id;

        // If no account specified, list available accounts
        if (!$accountName) {
            return $this->listAccounts($workspaceId);
        }

        // Find the account
        $account = MicrosoftGraphService::getAccount($accountName, $workspaceId);
        if (!$account) {
            $accounts = MicrosoftGraphService::discoverAccounts($workspaceId);
            $available = empty($accounts)
                ? 'No accounts configured.'
                : 'Available: ' . implode(', ', array_map(fn($a) => $a['name'], $accounts));

            return ToolResult::error("Account '{$accountName}' not found. {$available}");
        }

        // If no endpoint, show account info
        if (!$endpoint) {
            return ToolResult::success(json_encode([
                'account' => $account['name'],
                'email' => $account['email'],
                'hint' => 'Provide an endpoint to make an API call. Example: /users/{email}/messages',
            ], JSON_PRETTY_PRINT));
        }

        // Make the API call
        try {
            $result = MicrosoftGraphService::request($method, $endpoint, $account, $body, $query);

            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            // Truncate if too large
            if (strlen($json) > 30000) {
                $json = substr($json, 0, 30000) . "\n\n... (response truncated, use \$select and \$top to reduce size)";
            }

            return ToolResult::success($json);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\Exception $e) {
            return ToolResult::error('Unexpected error: ' . $e->getMessage());
        }
    }

    /**
     * List all discovered accounts.
     */
    private function listAccounts(?string $workspaceId): ToolResult
    {
        $accounts = MicrosoftGraphService::discoverAccounts($workspaceId);

        if (empty($accounts)) {
            return ToolResult::success(
                "No Microsoft Graph accounts found.\n\n" .
                "To set up an account, add these credentials in Settings > Credentials:\n" .
                "  AZURE_{NAME}_CLIENT_ID\n" .
                "  AZURE_{NAME}_CLIENT_SECRET\n" .
                "  AZURE_{NAME}_TENANT_ID\n" .
                "  AZURE_{NAME}_EMAIL\n\n" .
                "Replace {NAME} with an account identifier (e.g., PERSONAL, WORK)."
            );
        }

        $lines = ["Found " . count($accounts) . " account(s):\n"];
        foreach ($accounts as $account) {
            $lines[] = "  - {$account['name']}: {$account['email']}";
        }
        $lines[] = "\nUse --account=NAME to select an account.";

        return ToolResult::success(implode("\n", $lines));
    }
}
