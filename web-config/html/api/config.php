<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Try to read from .env file first
$envFile = '/config/.env';
$openaiApiKey = null;

if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    $lines = explode("\n", $envContent);
    foreach ($lines as $line) {
        if (strpos($line, 'OPENAI_API_KEY=') === 0) {
            $openaiApiKey = trim(substr($line, 15));
            break;
        }
    }
}

// Fallback to environment variable
if (!$openaiApiKey) {
    $openaiApiKey = getenv('OPENAI_API_KEY');
}

// Return configuration
echo json_encode([
    'openaiApiKey' => $openaiApiKey ?: null
]);
?>