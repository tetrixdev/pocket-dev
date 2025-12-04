# OpenAI Whisper Integration

Voice transcription for voice-to-text input in the chat interface.

## Service

**File:** `app/Services/OpenAIService.php`

## Configuration

### API Key Storage

API key stored encrypted in database via `AppSettingsService`:

```php
// Store key
$appSettings->set('openai_api_key', $apiKey);

// Retrieve key
$apiKey = $appSettings->get('openai_api_key');
```

### Config File

**File:** `config/services.php`

```php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
],
```

The service checks both config and database, preferring database.

## API Endpoints

### Check Key Status

```
GET /api/claude/openai-key/check
```

**Response:**
```json
{
    "configured": true
}
```

### Save Key

```
POST /api/claude/openai-key
Content-Type: application/json

{
    "api_key": "sk-..."
}
```

**Response:**
```json
{
    "success": true,
    "message": "OpenAI API key saved successfully"
}
```

### Delete Key

```
DELETE /api/claude/openai-key
```

**Response:**
```json
{
    "success": true,
    "message": "OpenAI API key removed"
}
```

### Transcribe Audio

```
POST /api/claude/transcribe
Content-Type: multipart/form-data

audio: <file>
```

**Response:**
```json
{
    "success": true,
    "text": "Transcribed text here"
}
```

## Service Methods

### `transcribe(string $audioFilePath): string`

Transcribes audio file using OpenAI Whisper.

```php
public function transcribe(string $audioFilePath): string
{
    $apiKey = $this->getApiKey();

    $response = Http::withToken($apiKey)
        ->attach('file', file_get_contents($audioFilePath), 'audio.webm')
        ->post('https://api.openai.com/v1/audio/transcriptions', [
            'model' => 'gpt-4o-transcribe',
        ]);

    if (!$response->successful()) {
        throw new \Exception('Transcription failed: ' . $response->body());
    }

    return $response->json('text');
}
```

### `getApiKey(): ?string`

Gets API key from config or database.

```php
private function getApiKey(): ?string
{
    // Check config first
    $key = config('services.openai.api_key');
    if ($key) {
        return $key;
    }

    // Fall back to database
    return app(AppSettingsService::class)->get('openai_api_key');
}
```

## Frontend Integration

### Voice Recording Flow

**File:** `resources/views/chat.blade.php`

```javascript
// Alpine.js state
{
    isRecording: false,
    isProcessing: false,
    mediaRecorder: null,
    audioChunks: [],
    openAiKeyConfigured: false,
    autoSendAfterTranscription: true
}
```

### Start Recording

```javascript
async startRecording() {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    this.mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
    this.audioChunks = [];

    this.mediaRecorder.ondataavailable = (e) => {
        this.audioChunks.push(e.data);
    };

    this.mediaRecorder.onstop = () => this.processRecording();

    this.mediaRecorder.start();
    this.isRecording = true;
}
```

### Process Recording

```javascript
async processRecording() {
    this.isProcessing = true;

    const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
    const formData = new FormData();
    formData.append('audio', blob, 'recording.webm');

    const response = await fetch('/api/claude/transcribe', {
        method: 'POST',
        body: formData
    });

    const data = await response.json();

    if (data.success) {
        document.getElementById('prompt').value = data.text;
        if (this.autoSendAfterTranscription) {
            document.getElementById('chat-form').dispatchEvent(new Event('submit'));
        }
    }

    this.isProcessing = false;
}
```

## Requirements

### Secure Context

Browser microphone API requires HTTPS or localhost:

- ✅ `http://localhost` - Works
- ✅ `https://example.com` - Works
- ❌ `http://192.168.1.100` - Blocked by browser

**Workaround:** Access via `localhost` for voice features, or set up HTTPS.

### Supported Formats

OpenAI Whisper supports:
- audio/webm (default from MediaRecorder)
- audio/wav
- audio/mp3
- audio/m4a
- audio/ogg

## Error Handling

### No API Key

```javascript
if (!this.openAiKeyConfigured) {
    this.showOpenAiModal = true;  // Prompt user to configure
    return;
}
```

### Transcription Error

```javascript
if (!data.success) {
    alert('Transcription failed: ' + data.error);
}
```

### Microphone Access Denied

```javascript
try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
} catch (err) {
    if (err.name === 'NotAllowedError') {
        alert('Microphone access denied');
    }
}
```

## UI Components

### OpenAI Key Modal

Shown when user tries to record without API key configured:

```html
<div x-show="showOpenAiModal" class="modal">
    <input type="password" x-model="openAiKey" placeholder="sk-...">
    <button @click="saveOpenAiKey()">Save</button>
</div>
```

### Recording Indicator

```html
<button @click="toggleRecording()"
        :class="{ 'bg-red-500': isRecording, 'animate-pulse': isProcessing }">
    <svg><!-- Mic icon --></svg>
</button>
```

## Debugging

### Test API Key

```bash
curl -X POST https://api.openai.com/v1/audio/transcriptions \
  -H "Authorization: Bearer sk-..." \
  -F file=@audio.webm \
  -F model=gpt-4o-transcribe
```

### Check Key in Database

```bash
docker compose exec pocket-dev-php php artisan tinker
>>> app(App\Services\AppSettingsService::class)->get('openai_api_key')
```

### View Logs

```bash
docker compose logs -f pocket-dev-php | grep -i openai
```
