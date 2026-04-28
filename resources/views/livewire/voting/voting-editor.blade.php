<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <a href="{{ route('votings.index') }}" wire:navigate class="text-sm font-semibold text-sky-700 hover:text-sky-900">
                    ← Späť na hlasovania
                </a>
                <h1 class="mt-3 text-4xl font-semibold tracking-tight text-slate-900">{{ $name }}</h1>
                <p class="mt-2 text-base text-slate-500">Uprav hlavičku, nadpis, logo, predvolené časy a priprav otázky pre živé hlasovanie.</p>
            </div>

            <div class="rounded-[1.5rem] bg-white px-5 py-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center gap-3">
                    <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-white">
                        {{ $voting->status }}
                    </span>
                    <span>{{ count($questionRows) }} otázok</span>
                </div>
                <a href="{{ route('votings.presentation', $voting) }}" target="_blank" class="mt-3 inline-flex text-sm font-semibold text-sky-700 hover:text-sky-900">
                    Otvoriť prezentačné okno →
                </a>
                @if (count($questionRows) > 0)
                    <a href="{{ route('votings.console', $voting) }}" class="mt-2 inline-flex text-sm font-semibold text-emerald-700 hover:text-emerald-900">
                        Otvoriť operátorskú konzolu →
                    </a>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-[1.5rem] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <div class="grid gap-8 xl:grid-cols-[1.15fr,0.85fr]">
            <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-2xl font-semibold text-slate-900">Detail hlasovania</h2>
                        <p class="mt-1 text-sm text-slate-500">Základné nastavenia použiteľné pre projekciu aj administráciu.</p>
                    </div>
                </div>

                <form wire:submit="saveVoting" class="mt-6 grid gap-5">
                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-slate-700">Názov hlasovania</label>
                            <input type="text" wire:model.blur="name" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            @error('name') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium text-slate-700">Nadpis hlasovania</label>
                            <input type="text" wire:model.blur="title" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            @error('title') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-slate-700">Hlavička hlasovania</label>
                        <textarea wire:model.blur="headerText" rows="3" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300"></textarea>
                        @error('headerText') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-slate-700">Predvolený čas odpovede</label>
                            <div class="mt-2 flex items-center gap-3 rounded-2xl border border-slate-300 bg-white px-4 py-3">
                                <input type="number" min="5" max="600" wire:model.blur="defaultResponseTimeSeconds" class="w-full border-0 p-0 text-slate-900 focus:ring-0">
                                <span class="text-sm text-slate-500">sekúnd</span>
                            </div>
                            @error('defaultResponseTimeSeconds') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium text-slate-700">Logo hlasovania</label>
                            <input type="file" wire:model="logoUpload" accept="image/*" class="mt-2 block w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-700">
                            @error('logoUpload') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                            <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                @if ($logoUpload)
                                    <img src="{{ $logoUpload->temporaryUrl() }}" alt="Náhľad loga" class="h-24 w-full rounded-xl object-contain bg-white">
                                @elseif ($logoPath)
                                    <img src="{{ route('votings.logo', $voting) }}" alt="Aktuálne logo" class="h-24 w-full rounded-xl object-contain bg-white">
                                @else
                                    <div class="flex h-24 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white text-sm text-slate-400">
                                        Logo zatiaľ nie je nahrané
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <input type="checkbox" wire:model.live="autoShowResults" class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                        <span>
                            <span class="block text-sm font-semibold text-slate-800">Automaticky zobraziť výsledok po otázke</span>
                            <span class="mt-1 block text-sm text-slate-500">Ak je vypnuté, konzola po ukončení otázky preskočí rovno na ďalšiu otázku.</span>
                        </span>
                    </label>

                    <div class="flex justify-end">
                        <button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                            Uložiť hlasovanie
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-[2rem] bg-[linear-gradient(160deg,_#eff6ff,_#ffffff_45%,_#ecfeff)] p-6 shadow-sm ring-1 ring-sky-100">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-sky-500">Generator</p>
                    <h2 class="mt-3 text-2xl font-semibold text-slate-900">Rýchla príprava otázok</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Vygenerované otázky sa pridajú za už existujúce poradie, takže vieš pripravovať schôdzu postupne.</p>
                </div>

                <form wire:submit="generateQuestions" class="mt-6 space-y-5">
                    <div>
                        <label class="text-sm font-medium text-slate-700">Názov otázok</label>
                        <input type="text" wire:model.blur="questionLabel" class="mt-2 w-full rounded-2xl border-sky-200 bg-white/90 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                        <p class="mt-2 text-sm text-slate-500">Použije sa pri ručnom pridaní aj pri hromadnom generovaní otázok.</p>
                        @error('questionLabel') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-slate-700">Počet otázok</label>
                            <input type="number" min="1" max="200" wire:model.blur="generationCount" class="mt-2 w-full rounded-2xl border-sky-200 bg-white/90 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            @error('generationCount') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium text-slate-700">Čas pre vygenerované otázky</label>
                            <input type="number" min="5" max="600" wire:model.blur="generationResponseTimeSeconds" class="mt-2 w-full rounded-2xl border-sky-200 bg-white/90 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            @error('generationResponseTimeSeconds') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="rounded-2xl bg-sky-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-600">
                            Vygenerovať otázky
                        </button>
                        <button type="button" wire:click="addQuestion" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50">
                            Pridať jednu otázku
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Počet hlasov zariadení</h2>
                    <p class="mt-1 text-sm text-slate-500">Váha 0 znamená, že zariadenie sa do výsledku nezapočíta. Ostatné čísla sa pripočítajú k zvolenej odpovedi.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="exportDeviceWeights" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
                        Export váh
                    </button>
                    <div class="rounded-full bg-slate-900 px-4 py-3 text-xs font-semibold uppercase tracking-[0.25em] text-white">
                        {{ count($deviceWeightRows) }} zariadení
                    </div>
                </div>
            </div>

            <div class="mt-6 grid gap-5 lg:grid-cols-2">
                <form wire:submit="assignBulkDeviceWeights" class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                    <h3 class="text-base font-semibold text-slate-900">Hromadné priradenie váh</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-slate-700">Váha</label>
                            <input type="number" min="0" step="0.01" wire:model.blur="bulkDeviceWeight" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            @error('bulkDeviceWeight') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium text-slate-700">Počet zariadení od 1</label>
                            <input type="number" min="1" wire:model.blur="bulkDeviceCount" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            @error('bulkDeviceCount') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button type="submit" class="mt-4 rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700">
                        Priradiť váhu zariadeniam
                    </button>
                </form>

                <form wire:submit="importDeviceWeights" class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                    <h3 class="text-base font-semibold text-slate-900">Import váh zariadení</h3>
                    <label class="mt-4 block text-sm font-medium text-slate-700">CSV súbor</label>
                    <input type="file" wire:model="deviceWeightsImport" accept=".csv,text/csv,text/plain" class="mt-2 block w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-700">
                    @error('deviceWeightsImport') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    <button type="submit" class="mt-4 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                        Importovať váhy
                    </button>
                </form>
            </div>

            <form wire:submit="saveDeviceWeights" class="mt-6">
                <div class="max-h-[34rem] overflow-auto rounded-[1.5rem] border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Zariadenie</th>
                                <th class="w-44 px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Počet hlasov</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($deviceWeightRows as $index => $deviceRow)
                                <tr wire:key="voting-device-weight-{{ $deviceRow['id'] }}">
                                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">
                                        {{ $deviceRow['device_number'] }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="number" min="0" step="0.01" wire:model.blur="deviceWeightRows.{{ $index }}.weight" class="w-full rounded-xl border-slate-300 px-3 py-2 text-right text-sm text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-6 py-10 text-center text-sm text-slate-500">
                                        Zatiaľ nie sú vytvorené žiadne zariadenia.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @error('deviceWeightRows.*.weight') <p class="mt-3 text-sm text-rose-600">{{ $message }}</p> @enderror
                @error('rows.*.weight') <p class="mt-3 text-sm text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-5 flex justify-end">
                    <button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                        Uložiť počty hlasov
                    </button>
                </div>
            </form>
        </section>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Otázky hlasovania</h2>
                    <p class="mt-1 text-sm text-slate-500">Každá otázka má vlastný čas odpovede. Texty môžeš meniť priebežne pred spustením.</p>
                </div>
                <div class="rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-[0.25em] text-white">
                    {{ count($questionRows) }} pripravených
                </div>
            </div>

            <div class="mt-6 space-y-4">
                @forelse ($questionRows as $index => $question)
                    <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                        <div class="grid gap-5 xl:grid-cols-[0.8fr,2fr,0.8fr,auto] xl:items-start">
                            <div>
                                <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Poradie</label>
                                <input type="number" min="1" wire:model.live.number="questionRows.{{ $index }}.order" class="mt-2 w-1/2 rounded-2xl border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-900 focus:border-sky-500 focus:ring-sky-300">
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="text-sm font-medium text-slate-700">Text otázky</label>
                                    <textarea wire:model.blur="questionRows.{{ $index }}.text" rows="3" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-slate-900 focus:border-sky-500 focus:ring-sky-300"></textarea>
                                </div>
                            </div>

                            <div>
                                <label class="text-sm font-medium text-slate-700">Čas</label>
                                <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-4 py-3">
                                    <input type="number" min="5" max="600" wire:model.blur="questionRows.{{ $index }}.response_time_seconds" class="w-full border-0 p-0 text-slate-900 focus:ring-0">
                                    <span class="text-sm text-slate-500">s</span>
                                </div>
                            </div>

                            <div class="flex gap-3 xl:justify-end">
                                <button type="button" wire:click="saveQuestion({{ $question['id'] }})" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                                    Uložiť
                                </button>
                                <button type="button" wire:click="deleteQuestion({{ $question['id'] }})" wire:confirm="Naozaj chceš vymazať túto otázku?" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                                    Vymazať
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-slate-500">
                        Zatiaľ nemáš pripravené žiadne otázky. Použi generátor vyššie alebo pridaj otázku ručne.
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
