<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
            {{ __('Záloha a obnova') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
                    {{ session('success') }}
                </div>
            @endif

            <section class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                <div class="grid gap-6 p-6 lg:grid-cols-2">
                    <div class="grid gap-4">
                        <div class="grid gap-1">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Zálohovať') }}</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __('Stiahnite si buď celý SQLite súbor aplikácie, alebo JSON export aplikačných dát vrátane používateľov.') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <x-primary-button
                                type="button"
                                data-backup-export-url="{{ route('settings.backup.database') }}"
                                data-native-running="{{ config('nativephp-internal.running') ? 'true' : 'false' }}"
                                data-exporting-label="{{ __('Exportujem DB...') }}"
                            >
                                {{ __('Stiahnuť celú DB') }}
                            </x-primary-button>

                            <x-secondary-button
                                type="button"
                                data-backup-export-url="{{ route('settings.backup.data') }}"
                                data-native-running="{{ config('nativephp-internal.running') ? 'true' : 'false' }}"
                                data-exporting-label="{{ __('Exportujem JSON...') }}"
                            >
                                {{ __('Stiahnuť dáta JSON') }}
                            </x-secondary-button>
                        </div>
                    </div>

                    <div class="grid gap-4">
                        <div class="grid gap-1">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Obnoviť') }}</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __('Obnova SQLite nahradí aktuálnu databázu. Obnova JSON prepíše aplikačné tabuľky a ponechá schému aktuálnej verzie aplikácie.') }}
                            </p>
                        </div>

                        <div class="grid gap-4">
                            <form action="{{ route('settings.backup.database.restore') }}" method="POST" enctype="multipart/form-data" class="grid gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                @csrf

                                <div class="grid gap-2">
                                    <label for="database_backup" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ __('Obnoviť zo SQLite súboru') }}
                                    </label>
                                    <input id="database_backup" name="database_backup" type="file" accept=".sqlite,.db,.sqlite3" class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-800 dark:text-gray-200 dark:file:bg-gray-100 dark:file:text-gray-900">
                                    @error('database_backup')
                                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <x-primary-button type="submit">{{ __('Obnoviť celú DB') }}</x-primary-button>
                            </form>

                            <form action="{{ route('settings.backup.data.restore') }}" method="POST" enctype="multipart/form-data" class="grid gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                @csrf

                                <div class="grid gap-2">
                                    <label for="data_backup" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ __('Obnoviť z JSON súboru') }}
                                    </label>
                                    <input id="data_backup" name="data_backup" type="file" accept=".json" class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-800 dark:text-gray-200 dark:file:bg-gray-100 dark:file:text-gray-900">
                                    @error('data_backup')
                                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <x-primary-button type="submit">{{ __('Obnoviť dáta JSON') }}</x-primary-button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
