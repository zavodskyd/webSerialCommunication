<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div
            class="rounded-[2rem] bg-[linear-gradient(135deg,_#0f172a,_#1e293b_55%,_#334155)] p-8 text-white shadow-xl shadow-slate-900/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.35em] text-emerald-200">Voľby</p>
                    <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">Správa volieb</h1>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-200">Priprav jednotlivé súťaže, kandidátky a
                        skupiny zariadení nezávisle od bežných hlasovaní.</p>
                </div>
                <form wire:submit="createElection"
                    class="w-full max-w-xl rounded-[1.5rem] border border-white/10 bg-white/10 p-5 backdrop-blur"><label
                        for="election-name" class="text-sm font-medium text-slate-100">Názov volieb</label>
                    <div class="mt-3 flex flex-col gap-3 sm:flex-row"><input id="election-name" type="text"
                            wire:model.blur="name" placeholder="Voľby orgánov 2026"
                            class="w-full rounded-2xl border border-white/15 bg-white/90 px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-300/50"><button
                            type="submit"
                            class="rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">Vytvoriť</button>
                    </div>
                    @error('name')
                        <p class="mt-2 text-sm text-rose-200">{{ $message }}</p>
                    @enderror
                </form>
            </div>
        </div>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold text-slate-900">Pripravené voľby</h2>
                    <p class="text-3xl font-semibold text-slate-900">{{ $votings->count() }}</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <form method="POST" action="{{ route('elections.configuration.import-new') }}" enctype="multipart/form-data" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        @csrf
                        <input type="file" name="configuration_file" accept="application/json,.json" required
                            class="block max-w-xs rounded-2xl border border-cyan-200 bg-cyan-50 text-sm text-cyan-900 file:mr-3 file:rounded-xl file:border-0 file:bg-cyan-600 file:px-4 file:py-3 file:text-sm file:font-semibold file:text-white hover:file:bg-cyan-700">
                        <button type="submit" class="rounded-2xl bg-cyan-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-cyan-700">Importovať ako nové</button>
                    </form>
                    <button type="button" wire:click="toggleShowAll"
                        class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100">{{ $showAll ? 'Len aktívne' : 'Všetky' }}</button>
                </div>
            </div>
            @if (session('status'))
                <div data-flash-message class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800 transition-opacity duration-300">
                    {{ session('status') }}
                </div>
            @endif
            @error('configuration_file')
                <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">
                    {{ $message }}
                </div>
            @enderror
            <div class="mt-6 space-y-4">
                @forelse ($votings as $voting)
                    @php($closedRoundCount = $voting->election?->contests->sum('closed_rounds_count') ?? 0)
                    <article wire:key="election-{{ $voting->id }}"
                        class="flex flex-col gap-4 rounded-[1.5rem] border border-slate-200 bg-slate-50/80 p-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="flex items-center gap-3">
                                <h3 class="text-xl font-semibold text-slate-900">{{ $voting->name }}</h3>
                                @if ($voting->archived_at)
                                    <span
                                        class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">Archivované</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-slate-500">{{ $voting->title ?: 'Bez nadpisu volieb' }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3"><span
                                class="rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">{{ $voting->election?->contests_count ?? 0 }}
                                súťaží</span><span
                                class="rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">{{ $voting->election?->device_groups_count ?? 0 }}
                                skupín</span><a href="{{ route('elections.edit', $voting) }}" wire:navigate
                                class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">Otvoriť
                                editor</a><a href="{{ route('elections.console', $voting) }}" wire:navigate
                                class="rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-500">Otvoriť
                                konzolu</a><a href="{{ route('elections.exports.results', $voting) }}" target="_blank"
                                rel="noopener" @class([
                                    'rounded-2xl px-4 py-3 text-sm font-semibold transition',
                                    'bg-emerald-600 text-white hover:bg-emerald-700' => $closedRoundCount > 0,
                                    'pointer-events-none bg-emerald-100 text-emerald-700' =>
                                        $closedRoundCount === 0,
                                ])
                                @if ($closedRoundCount === 0) aria-disabled="true" @endif>Export výsledkov</a><a
                                href="{{ route('elections.exports.audit', $voting) }}" target="_blank" rel="noopener"
                                @class([
                                    'rounded-2xl px-4 py-3 text-sm font-semibold transition',
                                    'bg-rose-600 text-white hover:bg-rose-700' => $closedRoundCount > 0,
                                    'pointer-events-none bg-rose-100 text-rose-700' =>
                                        $closedRoundCount === 0,
                                ])
                                @if ($closedRoundCount === 0) aria-disabled="true" @endif>Export auditu</a>
                            <button type="button"
                                data-backup-export-url="{{ route('elections.configuration.export', $voting) }}"
                                data-native-running="{{ config('nativephp-internal.running') ? 'true' : 'false' }}"
                                data-exporting-label="Exportujem konfiguráciu…"
                                data-export-saved-message="Konfigurácia bola uložená do:"
                                data-export-unavailable-message="Native export konfigurácie nie je momentálne dostupný."
                                data-export-empty-message="Export konfigurácie skončil bez potvrdenej cieľovej cesty."
                                data-export-error-message="Export konfigurácie zlyhal."
                                class="rounded-2xl bg-violet-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-wait disabled:opacity-70">Exportovať konfiguráciu</button>
                            <form method="POST" action="{{ route('elections.configuration.import', $voting) }}"
                                enctype="multipart/form-data"
                                data-confirm-message="Import nahradí celú konfiguráciu a prevádzkové údaje týchto volieb. Názov zostane zachovaný. Pokračovať?"
                                class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                @csrf
                                <input type="file" name="configuration_file" accept="application/json,.json" required
                                    class="block max-w-xs rounded-2xl border border-teal-200 bg-teal-50 text-xs text-teal-900 file:mr-2 file:rounded-xl file:border-0 file:bg-teal-600 file:px-3 file:py-3 file:text-xs file:font-semibold file:text-white hover:file:bg-teal-700">
                                <button type="submit" class="rounded-2xl bg-teal-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-700">Importovať konfiguráciu</button>
                            </form>
                            @if (!$voting->archived_at)
                                <button type="button" wire:click="archiveElection({{ $voting->id }})"
                                    class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">Archivovať</button>
                            @else
                                <button type="button" wire:click="activateElection({{ $voting->id }})"
                                    class="rounded-2xl border border-lime-200 bg-lime-50 px-4 py-3 text-sm font-semibold text-lime-800 transition hover:bg-lime-100">Aktivovať</button>
                            @endif
                        </div>
                    </article>
                @empty
                    <div
                        class="rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-slate-500">
                        Zatiaľ tu nie sú žiadne voľby.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
