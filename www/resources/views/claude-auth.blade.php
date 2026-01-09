<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Claude Code Authentication</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="antialiased bg-gray-900 text-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-3xl w-full space-y-6">
            <!-- Header -->
            <div class="text-center">
                <h1 class="text-3xl font-bold mb-2">Claude Code Authentication</h1>
                <p class="text-gray-400">Manage your Claude subscription credentials</p>
            </div>

            <!-- Status Card -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Authentication Status</h2>
                <div id="status-display">
                    @if($status["authenticated"])
                        <div class="bg-green-900/30 border border-green-700 rounded p-4 mb-4">
                            <div class="flex items-center mb-2">
                                <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-green-400 font-semibold">Authenticated</span>
                            </div>
                            <div class="text-sm space-y-1">
                                <p><strong>Subscription:</strong> {{ ucfirst($status["subscription_type"]) }}</p>
                                <p><strong>Expires:</strong> {{ $status["expires_at"] }} ({{ $status["days_until_expiry"] }} days)</p>
                                <p><strong>Scopes:</strong> {{ implode(", ", $status["scopes"]) }}</p>
                            </div>
                        </div>
                        <button onclick="logout()" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded text-sm">
                            Logout (Clear Credentials)
                        </button>
                    @else
                        <div class="bg-yellow-900/30 border border-yellow-700 rounded p-4">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-yellow-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-yellow-400 font-semibold">Not Authenticated</span>
                            </div>
                            <p class="text-sm mt-2 text-gray-300">{{ $status["message"] }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Authentication Methods -->
            @if(!$status["authenticated"])
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Authentication Methods</h2>
                
                <!-- Tabs -->
                <div class="flex space-x-2 mb-6 border-b border-gray-700">
                    <button onclick="switchTab('upload')" id="tab-upload" class="tab-btn px-4 py-2 -mb-px border-b-2 border-blue-500 text-blue-400">
                        Upload File
                    </button>
                    <button onclick="switchTab('json')" id="tab-json" class="tab-btn px-4 py-2 -mb-px border-b-2 border-transparent text-gray-400 hover:text-gray-300">
                        Paste JSON
                    </button>
                    <button onclick="switchTab('docker')" id="tab-docker" class="tab-btn px-4 py-2 -mb-px border-b-2 border-transparent text-gray-400 hover:text-gray-300">
                        Docker Exec
                    </button>
                </div>

                <!-- Upload File Tab -->
                <div id="content-upload" class="tab-content">
                    <p class="text-gray-300 mb-4">Upload your .credentials.json file from Claude Code</p>
                    <form id="upload-form" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <input type="file" id="credentials-file" accept=".json" class="block w-full text-sm text-gray-400
                                file:mr-4 file:py-2 file:px-4
                                file:rounded file:border-0
                                file:text-sm file:font-semibold
                                file:bg-blue-600 file:text-white
                                hover:file:bg-blue-700 cursor-pointer">
                        </div>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded">
                            Upload Credentials
                        </button>
                    </form>
                    <div id="upload-result" class="mt-4"></div>
                </div>

                <!-- Paste JSON Tab -->
                <div id="content-json" class="tab-content hidden">
                    <p class="text-gray-300 mb-4">Paste the contents of your .credentials.json file</p>
                    <form id="json-form" class="space-y-4">
                        <div>
                            <textarea id="json-input" rows="8" placeholder='{"claudeAiOauth":{"accessToken":"...","refreshToken":"..."}}' 
                                class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-sm font-mono focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded">
                            Save Credentials
                        </button>
                    </form>
                    <div id="json-result" class="mt-4"></div>
                </div>

                <!-- Docker Exec Tab -->
                <div id="content-docker" class="tab-content hidden">
                    <p class="text-gray-300 mb-4">Run this command on your host machine to authenticate:</p>
                    <div class="bg-gray-900 rounded p-4 mb-4">
                        <code class="text-sm text-green-400">docker exec -it -u {{ config('backup.exec_user') }} pocket-dev-queue claude</code>
                    </div>
                    <ol class="list-decimal list-inside space-y-2 text-sm text-gray-300 mb-4">
                        <li>Copy the command above</li>
                        <li>Run it in your terminal</li>
                        <li>Follow the authentication flow in your browser</li>
                        <li>Come back here and refresh the page</li>
                    </ol>
                    <button onclick="window.location.reload()" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded">
                        Refresh Page
                    </button>
                </div>
            </div>

            <!-- Instructions -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 text-sm">
                <h3 class="font-semibold mb-2">How to get credentials:</h3>
                <ul class="list-disc list-inside space-y-1 text-gray-300">
                    <li>Run <code class="text-blue-400">claude setup-token</code> on a machine with Claude Code installed</li>
                    <li>Find credentials at: <code class="text-blue-400">~/.claude/.credentials.json</code></li>
                    <li>Or use the Docker exec method above to authenticate directly</li>
                </ul>
            </div>
            @endif

            <!-- Back to Chat -->
            @if($status["authenticated"])
            <div class="text-center">
                <a href="{{ url('/') }}" class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg">
                    Go to Chat
                </a>
            </div>
            @endif
        </div>
    </div>

    <script>
        function switchTab(tab) {
            // Hide all content
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            // Reset all tabs
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('border-blue-500', 'text-blue-400');
                el.classList.add('border-transparent', 'text-gray-400');
            });
            // Show selected content
            document.getElementById('content-' + tab).classList.remove('hidden');
            // Highlight selected tab
            const activeTab = document.getElementById('tab-' + tab);
            activeTab.classList.remove('border-transparent', 'text-gray-400');
            activeTab.classList.add('border-blue-500', 'text-blue-400');
        }

        // Upload form
        document.getElementById('upload-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('credentials-file');
            const file = fileInput.files[0];
            
            if (!file) {
                showResult('upload-result', 'error', 'Please select a file');
                return;
            }

            const formData = new FormData();
            formData.append('credentials', file);

            try {
                const response = await fetch('{{ url('/claude/auth/upload') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: formData
                });

                const data = await response.json();
                
                if (data.success) {
                    showResult('upload-result', 'success', data.message);
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showResult('upload-result', 'error', data.message);
                }
            } catch (err) {
                showResult('upload-result', 'error', 'Upload failed: ' + err.message);
            }
        });

        // JSON form
        document.getElementById('json-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const jsonInput = document.getElementById('json-input').value;
            
            if (!jsonInput.trim()) {
                showResult('json-result', 'error', 'Please paste JSON content');
                return;
            }

            try {
                const response = await fetch('{{ url('/claude/auth/upload-json') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ json: jsonInput })
                });

                const data = await response.json();
                
                if (data.success) {
                    showResult('json-result', 'success', data.message);
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showResult('json-result', 'error', data.message);
                }
            } catch (err) {
                showResult('json-result', 'error', 'Save failed: ' + err.message);
            }
        });

        // Logout
        async function logout() {
            if (!confirm('Are you sure you want to clear your credentials?')) {
                return;
            }

            try {
                const response = await fetch('{{ url('/claude/auth/logout') }}', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Logout failed: ' + data.message);
                }
            } catch (err) {
                alert('Logout failed: ' + err.message);
            }
        }

        // Helper function
        function showResult(elementId, type, message) {
            const element = document.getElementById(elementId);
            const bgColor = type === 'success' ? 'bg-green-900/30 border-green-700 text-green-400' : 'bg-red-900/30 border-red-700 text-red-400';
            element.innerHTML = `<div class="border rounded p-3 ${bgColor}">${message}</div>`;
        }
    </script>
</body>
</html>
