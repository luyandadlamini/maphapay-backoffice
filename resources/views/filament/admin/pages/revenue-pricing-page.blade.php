<x-filament-panels::page>
    <form wire:submit="saveFees">
        {{ $this->form }}

        <div class="mt-6 max-w-3xl">
            <x-filament-forms::field-wrapper
                id="governanceReason"
                :label="__('Change reason')"
                :hint="__('Required for governed platform changes (same rule as Platform Settings).')"
            >
                <x-filament::input.wrapper
                    :valid="! $errors->has('governanceReason')"
                >
                    <textarea
                        wire:model="governanceReason"
                        rows="4"
                        @class([
                            'block h-full min-h-[6rem] w-full resize-y border-none bg-transparent px-3 py-1.5 text-base text-gray-950 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6',
                        ])
                    ></textarea>
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
        </div>

        <x-filament::button type="submit" class="mt-6">
            {{ __('Save fee settings') }}
        </x-filament::button>
    </form>

    @if (auth()->user()?->hasRole('super-admin'))
        <x-filament::section class="mt-8" :heading="__('Full platform settings')" :description="__('Super-admin: open the complete settings surface (all groups).')">
            <x-filament::button tag="a" :href="\App\Filament\Admin\Pages\Settings::getUrl()" outlined>
                {{ __('Open Platform Settings') }}
            </x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
