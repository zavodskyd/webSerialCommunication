<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-[2rem] bg-[linear-gradient(135deg,_#0f172a,_#1e293b_55%,_#334155)] p-8 text-white shadow-xl shadow-slate-900/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.35em] text-emerald-200">Voľby</p>
                    <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">Správa volieb</h1>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-200">
                        Priprav jednotlivé súťaže, kandidátky a skupiny zariadení nezávisle od bežných hlasovaní.
                    </p>
                </div>

                <form wire:submit="createElection" class="w-full max-w-xl rounded-[1.5rem] border border-white/10 bg-white/10 p-5 backdrop-blur">
                    <label for="election-name" class="text-sm font-medium text-slate-100">Názov volieb</label>
                    <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                        <input
                            id="election-name"
                            type="text"
                            wire:model.blur="name"
                            placeholder="Voľby orgánov 2026"
                            class="w-full rounded-2xl border border-white/15 bg-white/90 px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-300/50"
                        >
                        <button type="submit" class="rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">
                            Vytvoriť
                        </button>
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
                    <h2 class="whitespace-nowrap text-2xl font-semibold text-slate-900">Pripravené voľby</h2>
                    <p class="text-3xl font-semibold text-slate-900">{{ $votings->count() }}</p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <form method="POST" action="{{ route('elections.configuration.import-new') }}" enctype="multipart/form-data">
                        @csrf
                        <label for="election-import-new" class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-semibold text-cyan-800 transition hover:bg-cyan-100 focus-within:ring-2 focus-within:ring-cyan-500 focus-within:ring-offset-2">
                            <x-ui.icon name="import" />
                            Importovať voľby
                        </label>
                        <input
                            id="election-import-new"
                            type="file"
                            name="configuration_file"
                            accept="application/json,.json"
                            required
                            class="sr-only"
                            x-on:change="$el.form.requestSubmit()"
                        >
                    </form>

                    <button
                        type="button"
                        wire:click="toggleShowAll"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100"
                    >
                        <x-ui.icon name="filter" />
                        {{ $showAll ? 'Len aktívne' : 'Všetky' }}
                    </button>
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
                    <article wire:key="election-{{ $voting->id }}" class="rounded-[1.5rem] border border-slate-200 bg-slate-50/80 p-5">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="text-xl font-semibold text-slate-900">{{ $voting->name }}</h3>
                                    @if ($voting->archived_at)
                                        <span class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                                            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                                            Archivované
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">
                                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                            Aktívne
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm text-slate-500">{{ $voting->title ?: 'Bez nadpisu volieb' }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div class="min-w-28 rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Súťaže</p>
                                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ $voting->election?->contests_count ?? 0 }}</p>
                                </div>
                                <div class="min-w-28 rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Skupiny</p>
                                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ $voting->election?->device_groups_count ?? 0 }}</p>
                                </div>
                                <div class="col-span-2 min-w-32 rounded-2xl bg-emerald-50 px-4 py-3 ring-1 ring-emerald-200 sm:col-span-1">
                                    <p class="text-xs uppercase tracking-[0.16em] text-emerald-600">Uzavreté kolá</p>
                                    <p class="mt-1 text-lg font-semibold text-emerald-900">{{ $closedRoundCount }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 xl:flex-row xl:items-center xl:justify-between">
                            <div class="flex flex-wrap gap-3">
                                <a href="{{ route('elections.edit', $voting) }}" wire:navigate class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                                    <x-ui.icon name="edit" />
                                    Otvoriť editor
                                </a>
                                <a href="{{ route('elections.console', $voting) }}" wire:navigate class="inline-flex items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-800 transition hover:bg-indigo-100">
                                    <x-ui.icon name="console" />
                                    Otvoriť konzolu
                                </a>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        @disabled($closedRoundCount === 0)
                                        @class([
                                            'inline-flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold transition',
                                            'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' => $closedRoundCount > 0,
                                            'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400' => $closedRoundCount === 0,
                                        ])
                                        x-bind:aria-expanded="open"
                                        aria-haspopup="menu"
                                    >
                                        <x-ui.icon name="results" />
                                        Výsledky a audit
                                        <x-ui.icon name="chevron-down" class="h-3.5 w-3.5" />
                                    </button>

                                    @if ($closedRoundCount > 0)
                                        <template x-if="open">
                                            <div
                                                class="absolute right-0 z-30 mt-2 w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10"
                                                role="menu"
                                            >
                                                <a href="{{ route('elections.exports.results', $voting) }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50" role="menuitem">
                                                    <x-ui.icon name="results" />
                                                    Export výsledkov
                                                </a>
                                                <a href="{{ route('elections.exports.audit', $voting) }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50" role="menuitem">
                                                    <x-ui.icon name="export" />
                                                    Export auditu
                                                </a>
                                            </div>
                                        </template>
                                    @endif
                                </div>

                                <div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                        x-bind:aria-expanded="open"
                                        aria-haspopup="menu"
                                    >
                                        <x-ui.icon name="more" />
                                        Ďalšie možnosti
                                    </button>

                                    <template x-if="open">
                                        <div
                                            class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10"
                                            role="menu"
                                        >
                                            <button
                                                type="button"
                                                data-backup-export-url="{{ route('elections.configuration.export', $voting) }}"
                                                data-native-running="{{ config('nativephp-internal.running') ? 'true' : 'false' }}"
                                                data-exporting-label="Exportujem konfiguráciu…"
                                                data-export-saved-message="Konfigurácia bola uložená do:"
                                                data-export-unavailable-message="Native export konfigurácie nie je momentálne dostupný."
                                                data-export-empty-message="Export konfigurácie skončil bez potvrdenej cieľovej cesty."
                                                data-export-error-message="Export konfigurácie zlyhal."
                                                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-violet-700 transition hover:bg-violet-50 disabled:cursor-wait disabled:opacity-70"
                                                role="menuitem"
                                            >
                                                <x-ui.icon name="export" />
                                                Exportovať konfiguráciu
                                            </button>

                                            <form
                                                method="POST"
                                                action="{{ route('elections.configuration.import', $voting) }}"
                                                enctype="multipart/form-data"
                                                data-confirm-message="Import nahradí celú konfiguráciu a prevádzkové údaje týchto volieb. Názov zostane zachovaný. Pokračovať?"
                                            >
                                                @csrf
                                                <label for="election-import-{{ $voting->id }}" class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-teal-700 transition hover:bg-teal-50" role="menuitem">
                                                    <x-ui.icon name="import" />
                                                    Importovať konfiguráciu
                                                </label>
                                                <input
                                                    id="election-import-{{ $voting->id }}"
                                                    type="file"
                                                    name="configuration_file"
                                                    accept="application/json,.json"
                                                    required
                                                    class="sr-only"
                                                    x-on:change="$el.form.requestSubmit()"
                                                >
                                            </form>

                                            <div class="my-1 border-t border-slate-100"></div>

                                            @if (! $voting->archived_at)
                                                <button type="button" wire:click="archiveElection({{ $voting->id }})" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-amber-700 transition hover:bg-amber-50" role="menuitem">
                                                    <x-ui.icon name="archive" />
                                                    Archivovať
                                                </button>
                                            @else
                                                <button type="button" wire:click="activateElection({{ $voting->id }})" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-lime-700 transition hover:bg-lime-50" role="menuitem">
                                                    <x-ui.icon name="activate" />
                                                    Aktivovať
                                                </button>
                                            @endif
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-slate-500">
                        {{ $showAll ? 'Zatiaľ tu nie sú žiadne voľby.' : 'Zatiaľ tu nie sú žiadne aktívne voľby. Klikni na Všetky pre zobrazenie archívu.' }}
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
