<x-filament-panels::page>
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow dark:bg-gray-800 p-6">
            <h2 class="text-xl font-semibold mb-6">Send Broadcast Notification</h2>

            <form wire:submit.prevent="send">
                {{ $this->form }}
                
                <div class="mt-6">
                    {{ $this->actions }}
                </div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
