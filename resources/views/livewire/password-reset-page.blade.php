<section class="ppm-auth">
    <div class="ppm-auth__card">
        <div class="ppm-auth__brand">
            <img class="ppm-brand-logo" src="{{ asset('img/fynix_logo_dark.png') }}" alt="{{ config('app.name') }}">
            <h1>Reset your password</h1>
            <p>You must change your password before proceeding.</p>
        </div>
        <form wire:submit="create" class="flex flex-col gap-4">
            {{ $this->form }}
            <x-filament::button type="submit" class="w-full">
                Change password
            </x-filament::button>
        </form>
    </div>
</section>
