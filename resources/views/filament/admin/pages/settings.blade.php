<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 max-w-3xl">
            <x-filament-forms::field-wrapper
                id="governanceReason"
                label="Change reason"
                hint="Required for governed platform changes."
            >
                <x-filament::input.wrapper>
                    <x-filament::input.textarea
                        wire:model="governanceReason"
                        rows="4"
                    />
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
        </div>

        <x-filament::button
            type="submit"
            class="mt-6"
        >
            Save Settings
        </x-filament::button>
    </form>
</x-filament-panels::page>
