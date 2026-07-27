<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-[2rem] bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.16),_transparent_35%),linear-gradient(135deg,_#0f172a,_#1e293b_55%,_#334155)] p-8 text-white shadow-xl shadow-slate-900/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.35em] text-sky-200">Hlasovanie</p>
                    <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">Správa hlasovaní a otázok</h1>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-200">
                        Vytvor nové hlasovanie, priprav jeho hlavičku a otvor editáciu otázok, časov a ďalších detailov.
                    </p>
                </div>

                <form wire:submit="createVoting" class="w-full max-w-xl rounded-[1.5rem] border border-white/10 bg-white/10 p-5 backdrop-blur">
                    <label for="name" class="text-sm font-medium text-slate-100">Názov hlasovania</label>
                    <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                        <input
                            id="name"
                            type="text"
                            wire:model.blur="name"
                            placeholder="Zhromaždenie delegátov 2026"
                            class="w-full rounded-2xl border border-white/15 bg-white/90 px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-300/50"
                        >
                        <button type="submit" class="rounded-2xl bg-sky-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-sky-300">
                            Vytvoriť
                        </button>
                    </div>
                    @error('name')
                        <p class="mt-2 text-sm text-rose-200">{{ $message }}</p>
                    @enderror
                </form>
            </div>
        </div>

        <div class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <h2 class="inline-block whitespace-nowrap text-2xl font-semibold text-slate-900">Pripravené hlasovania</h2>
                    <p class="inline-block whitespace-nowrap text-3xl font-semibold text-slate-900">{{ $votings->count() }}</p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <form method="POST" action="{{ route('votings.configuration.import-new') }}" enctype="multipart/form-data" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        @csrf
                        <input
                            type="file"
                            name="configuration_file"
                            accept="application/json,.json"
                            required
                            class="block max-w-xs rounded-2xl border border-cyan-200 bg-cyan-50 text-sm text-cyan-900 file:mr-3 file:rounded-xl file:border-0 file:bg-cyan-600 file:px-4 file:py-3 file:text-sm file:font-semibold file:text-white hover:file:bg-cyan-700"
                        >
                        <button type="submit" class="rounded-2xl bg-cyan-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-cyan-700">
                            Importovať ako nové
                        </button>
                    </form>
                    <button
                        type="button"
                        wire:click="toggleShowAll"
                        class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100"
                    >
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
                    <div class="flex flex-col gap-4 rounded-[1.5rem] border border-slate-200 bg-slate-50/80 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <div class="flex items-center gap-3">
                                    <h3 class="text-xl font-semibold text-slate-900">{{ $voting->name }}</h3>
                                    @if ($voting->archived_at)
                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">
                                            Archivované
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm text-slate-500">
                                    {{ $voting->title ?: 'Bez nadpisu hlasovania' }}
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3 text-sm text-slate-600 sm:grid-cols-4">
                                <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Otázky</p>
                                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ $voting->questions_count }}</p>
                                </div>
                                <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Predvolený čas</p>
                                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ $voting->default_response_time_seconds }} s</p>
                                </div>
                                <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200">
                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Vytvorené</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $voting->created_at->format('d.m.Y') }}</p>
                                </div>
                                <div class="rounded-2xl bg-sky-50 px-4 py-3 ring-1 ring-sky-200">
                                    <p class="text-xs uppercase tracking-[0.2em] text-sky-500">Uzavreté</p>
                                    <p class="mt-1 text-lg font-semibold text-sky-900">{{ $voting->closed_questions_count }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3 border-t border-slate-200 pt-4">
                            <a href="{{ route('votings.edit', $voting) }}" wire:navigate class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                                Otvoriť editor
                            </a>
                            <a href="{{ route('votings.console', $voting) }}" wire:navigate class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100">
                                Operátorská konzola
                            </a>
                            <a
                                href="{{ route('votings.exports.results', $voting) }}"
                                target="_blank"
                                rel="noopener"
                                @class([
                                    'rounded-2xl px-4 py-3 text-sm font-semibold transition',
                                    'bg-emerald-600 text-white hover:bg-emerald-700' => $voting->closed_questions_count > 0,
                                    'pointer-events-none bg-slate-200 text-slate-500' => $voting->closed_questions_count === 0,
                                ])
                                @if ($voting->closed_questions_count === 0) aria-disabled="true" @endif
                            >
                                Export výsledkov
                            </a>
                            <a
                                href="{{ route('votings.exports.pressed-options', $voting) }}"
                                target="_blank"
                                rel="noopener"
                                @class([
                                    'rounded-2xl px-4 py-3 text-sm font-semibold transition',
                                    'bg-sky-600 text-white hover:bg-sky-700' => $voting->closed_questions_count > 0,
                                    'pointer-events-none bg-slate-200 text-slate-500' => $voting->closed_questions_count === 0,
                                ])
                                @if ($voting->closed_questions_count === 0) aria-disabled="true" @endif
                            >
                                Export stlačených možností
                            </a>
                            <button type="button" wire:click="copyVoting({{ $voting->id }})" class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-800 transition hover:bg-sky-100">
                                Kopírovať
                            </button>
                            <button
                                type="button"
                                data-backup-export-url="{{ route('votings.configuration.export', $voting) }}"
                                data-native-running="{{ config('nativephp-internal.running') ? 'true' : 'false' }}"
                                data-exporting-label="Exportujem konfiguráciu…"
                                data-export-saved-message="Konfigurácia bola uložená do:"
                                data-export-unavailable-message="Native export konfigurácie nie je momentálne dostupný."
                                data-export-empty-message="Export konfigurácie skončil bez potvrdenej cieľovej cesty."
                                data-export-error-message="Export konfigurácie zlyhal."
                                class="rounded-2xl bg-violet-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-wait disabled:opacity-70"
                            >
                                Exportovať konfiguráciu
                            </button>
                            <form
                                method="POST"
                                action="{{ route('votings.configuration.import', $voting) }}"
                                enctype="multipart/form-data"
                                data-confirm-message="Import nahradí celú konfiguráciu a prevádzkové údaje tohto hlasovania. Názov zostane zachovaný. Pokračovať?"
                                class="flex flex-col gap-2 sm:flex-row sm:items-center"
                            >
                                @csrf
                                <input
                                    type="file"
                                    name="configuration_file"
                                    accept="application/json,.json"
                                    required
                                    class="block max-w-xs rounded-2xl border border-teal-200 bg-teal-50 text-xs text-teal-900 file:mr-2 file:rounded-xl file:border-0 file:bg-teal-600 file:px-3 file:py-3 file:text-xs file:font-semibold file:text-white hover:file:bg-teal-700"
                                >
                                <button type="submit" class="rounded-2xl bg-teal-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-700">
                                    Importovať konfiguráciu
                                </button>
                            </form>
                            @if (! $voting->archived_at)
                                <button type="button" wire:click="archiveVoting({{ $voting->id }})" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">
                                    Archivovať
                                </button>
                            @else
                                <button type="button" wire:click="activateVoting({{ $voting->id }})" class="rounded-2xl border border-lime-200 bg-lime-50 px-4 py-3 text-sm font-semibold text-lime-800 transition hover:bg-lime-100">
                                    Aktivovať
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-slate-500">
                        {{ $showAll ? 'Zatiaľ tu nie je žiadne hlasovanie.' : 'Zatiaľ tu nie je žiadne aktívne hlasovanie. Klikni na Všetky pre zobrazenie archívu.' }}
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
