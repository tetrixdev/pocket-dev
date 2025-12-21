{{-- Chat Modals --}}
{{-- TODO: Refactor @include statements to use Laravel anonymous components (e.g., <x-chat.modals.agent-selector />) for consistency with coding guidelines --}}
@include('partials.chat.modals.openai-key')
@include('partials.chat.modals.claude-code-auth')
@include('partials.chat.modals.agent-selector')
@include('partials.chat.modals.pricing-settings')
@include('partials.chat.modals.cost-breakdown')
@include('partials.chat.modals.shortcuts')
@include('partials.chat.modals.error')
