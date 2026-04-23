<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
            {{ __('Import SQLite') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    @if (session('success'))
                        {{ session('success') }}
                    @endif
                    @if ($errors->any())
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <form action="{{ route('load.external.db') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <label for="db_file">Vyberte SQLite súbor</label>
                        <input type="file" class="form-control-file" id="db_file" name="db_file"
                            accept=".sqlite,.db,.sqlite3">
                        <x-primary-button type="submit">Načítať databázu</x-primary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
