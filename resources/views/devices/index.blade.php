<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-600 dark:text-amber-400">
                    Evidencia
                </p>
                <h2 class="font-serif text-2xl font-semibold leading-tight text-gray-900 dark:text-gray-100">
                    Importované zariadenia
                </h2>
            </div>

            <p class="max-w-xl text-sm text-gray-500 dark:text-gray-400">
                Prehľad všetkých zariadení uložených v aplikácii vrátane kódov a rýchlej kontroly neúplných záznamov.
            </p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="mx-auto flex max-w-7xl flex-col gap-6 sm:px-6 lg:px-8">
            <section class="grid gap-4 px-4 sm:grid-cols-2 sm:px-0 xl:grid-cols-3">
                <article class="overflow-hidden bg-white px-8 py-7 shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="flex min-h-36 flex-col justify-between gap-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-stone-500 dark:text-gray-400">Spolu uložené</p>
                        <div class="space-y-2">
                            <p class="font-serif text-4xl text-stone-900 dark:text-white">{{ number_format($devicesCount, 0, ',', ' ') }}</p>
                            <p class="text-sm text-stone-600 dark:text-gray-300">Aktuálny počet zariadení v aplikačnej databáze.</p>
                        </div>
                    </div>
                </article>

                <article class="overflow-hidden bg-white px-8 py-7 shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="flex min-h-36 flex-col justify-between gap-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-stone-500 dark:text-gray-400">Na tejto strane</p>
                        <div class="space-y-2">
                            <p class="font-serif text-4xl text-stone-900 dark:text-white">{{ $devices->count() }}</p>
                            <p class="text-sm text-stone-600 dark:text-gray-300">Záznamy zobrazené v aktuálnej stránke výpisu.</p>
                        </div>
                    </div>
                </article>

                <article class="overflow-hidden bg-white px-8 py-7 shadow-sm dark:bg-gray-800 sm:col-span-2 sm:rounded-lg xl:col-span-1">
                    <div class="flex min-h-36 flex-col justify-between gap-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-stone-500 dark:text-gray-400">Neúplné záznamy</p>
                        <div class="space-y-2">
                            <p class="font-serif text-4xl text-stone-900 dark:text-white">{{ $incompleteDevicesCount }}</p>
                            <p class="text-sm text-stone-600 dark:text-gray-300">Zariadenia, kde aspoň jeden kód zostal prázdny.</p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                <div class="border-b border-gray-200 px-6 py-5 dark:border-gray-700">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="font-serif text-xl text-stone-900 dark:text-white">Zoznam zariadení</h3>
                            <p class="mt-1 text-sm text-stone-500 dark:text-gray-400">
                                Stránka {{ $devices->currentPage() }} z {{ $devices->lastPage() }}.
                            </p>
                        </div>

                        <div class="inline-flex items-center rounded-full border border-stone-200 bg-stone-50 px-4 py-2 text-xs font-medium uppercase tracking-[0.25em] text-stone-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300">
                            {{ $devices->firstItem() ?? 0 }} - {{ $devices->lastItem() ?? 0 }}
                        </div>
                    </div>
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="w-full table-fixed">
                        <thead class="bg-gray-50 dark:bg-gray-900/70">
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.25em] text-stone-500 dark:text-gray-400">
                                <th class="w-40 px-6 py-4">Zariadenie</th>
                                <th class="px-4 py-4">A</th>
                                <th class="px-4 py-4">B</th>
                                <th class="px-4 py-4">C</th>
                                <th class="px-4 py-4">D</th>
                                <th class="px-4 py-4">E</th>
                                <th class="px-4 py-4">F</th>
                                <th class="px-4 py-4">Ruka</th>
                                <th class="w-40 px-6 py-4 text-right">Stav</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-stone-700 dark:text-gray-200">
                            @foreach ($devices as $device)
                                @php
                                    $hasMissingCode = collect([
                                        $device->code_a,
                                        $device->code_b,
                                        $device->code_c,
                                        $device->code_d,
                                        $device->code_e,
                                        $device->code_f,
                                        $device->code_ruka,
                                    ])->contains('');
                                @endphp

                                <tr class="align-top border-t border-gray-200 transition even:bg-gray-50/40 hover:bg-gray-50 dark:border-gray-700 dark:even:bg-gray-900/30 dark:hover:bg-gray-900/70">
                                    <td class="px-6 py-5">
                                        <div class="flex flex-col gap-1">
                                            <span class="font-semibold text-stone-900 dark:text-white">{{ $device->device_number }}</span>
                                            <span class="text-xs uppercase tracking-[0.25em] text-stone-400 dark:text-gray-500">ID {{ $device->id }}</span>
                                        </div>
                                    </td>

                                    @foreach ([$device->code_a, $device->code_b, $device->code_c, $device->code_d, $device->code_e, $device->code_f, $device->code_ruka] as $code)
                                        <td class="px-4 py-5">
                                            @if ($code !== '')
                                                <span class="font-mono text-sm text-stone-700 dark:text-gray-200">
                                                    {{ $code }}
                                                </span>
                                            @else
                                                <span class="text-sm font-medium text-stone-300 dark:text-gray-600">
                                                    -
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach

                                    <td class="px-6 py-5 text-right">
                                        @if ($hasMissingCode)
                                            <span class="inline-flex rounded-full border border-orange-400 bg-orange-200 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-orange-900 dark:border-orange-600 dark:bg-orange-500/20 dark:text-orange-200">
                                                Nekompletné
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full border border-green-400 bg-green-200 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-green-900 dark:border-green-600 dark:bg-green-500/20 dark:text-green-200">
                                                Kompletné
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-6 py-5 dark:border-gray-700">
                    {{ $devices->onEachSide(1)->links() }}
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
