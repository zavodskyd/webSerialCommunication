<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-[2rem] bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.16),_transparent_35%),linear-gradient(135deg,_#0f172a,_#1e293b_55%,_#334155)] p-8 text-white shadow-xl shadow-slate-900/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.35em] text-sky-200">Hlasovanie</p>
                    <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">Správa hlasovaní a otázok</h1>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-200">Vytvor nové hlasovanie, priprav jeho hlavičku a otvor editáciu otázok, časov a ďalších detailov.</p>
                </div>

                <form wire:submit="createVoting" class="w-full max-w-xl rounded-[1.5rem] border border-white/10 bg-white/10 p-5 backdrop-blur">
                    <label for="name" class="text-sm font-medium text-slate-100">Názov hlasovania</label>
                    <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                        <input id="name" type="text" wire:model.blur="name" placeholder="Zhromaždenie delegátov 2026" class="w-full rounded-2xl border border-white/15 bg-white/90 px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-300/50">
                        <button type="submit" class="rounded-2xl bg-sky-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-sky-300">Vytvoriť</button>
                    </div>
                    @error('name')
                        <p class="mt-2 text-sm text-rose-200">{{ $message }}</p>
                    @enderror
                </form>
            </div>
        </div>

        <div class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-baseline gap-3">
                    <h2 class="text-2xl font-semibold text-slate-900">Pripravené hlasovania</h2>
                    <span class="text-2xl font-semibold text-slate-400">{{ $votings->count() }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('votings.configuration.import-new') }}" enctype="multipart/form-data">
                        @csrf
                        <label tabindex="0" x-on:keydown.enter="$el.click()" class="inline-flex cursor-pointer items-center gap-2 rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-semibold text-cyan-800 transition hover:bg-cyan-100 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2">
                            <x-ui.icon name="upload" class="h-5 w-5" />
                            Importovať hlasovanie
                            <input type="file" name="configuration_file" accept="application/json,.json" required class="sr-only" x-on:change="$el.form.requestSubmit()">
                        </label>
                    </form>
                    <button type="button" wire:click="toggleShowAll" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100">
                        <x-ui.icon name="filter" class="h-5 w-5" />
                        {{ $showAll ? 'Len aktívne' : 'Všetky' }}
                    </button>
                </div>
            </div>

            @if (session('status'))
                <div data-flash-message class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800 transition-opacity duration-300">{{ session('status') }}</div>
            @endif
            @error('configuration_file')
                <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">{{ $message }}</div>
            @enderror

            <div class="mt-6 space-y-4">
                @forelse ($votings as $voting)
                    <article wire:key="voting-{{ $voting->id }}" class="rounded-[1.5rem] border border-slate-200 bg-slate-50/80 p-5">
                        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="truncate text-xl font-semibold text-slate-900">{{ $voting->name }}</h3>
                                    <span @class([
                                        'rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em]',
                                        'bg-amber-100 text-amber-800' => $voting->archived_at,
                                        'bg-emerald-100 text-emerald-800' => ! $voting->archived_at,
                                    ])>{{ $voting->archived_at ? 'Archivované' : 'Aktívne' }}</span>
                                </div>
                                <p class="mt-2 text-sm text-slate-500">{{ $voting->title ?: 'Bez nadpisu hlasovania' }}</p>
                            </div>

                            <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4 xl:min-w-[34rem]">
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Otázky</dt>
                                    <dd class="mt-1 text-lg font-semibold text-slate-900">{{ $voting->questions_count }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Predvolený čas</dt>
                                    <dd class="mt-1 text-lg font-semibold text-slate-900">{{ $voting->default_response_time_seconds }} s</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Vytvorené</dt>
                                    <dd class="mt-1 font-semibold text-slate-900">{{ $voting->created_at->format('d.m.Y') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-600">Uzavreté</dt>
                                    <dd class="mt-1 text-lg font-semibold text-sky-900">{{ $voting->closed_questions_count }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                            <a href="{{ route('votings.edit', $voting) }}" wire:navigate class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                                <x-ui.icon name="edit" class="h-5 w-5" />
                                Otvoriť editor
                            </a>
                            <a href="{{ route('votings.console', $voting) }}" wire:navigate class="inline-flex items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-800 transition hover:bg-indigo-100">
                                <x-ui.icon name="console" class="h-5 w-5" />
                                Otvoriť konzolu
                            </a>

                            <div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" class="relative">
                                <button type="button" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open" @disabled($voting->closed_questions_count === 0) class="inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400">
                                    <x-ui.icon name="results" class="h-5 w-5" />
                                    Výsledky a exporty
                                    <x-ui.icon name="chevron-down" class="h-4 w-4" />
                                </button>
                                @if ($voting->closed_questions_count > 0)
                                    <template x-if="open">
                                        <div role="menu" class="absolute right-0 z-20 mt-2 w-72 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
                                            <a role="menuitem" href="{{ route('votings.exports.results', $voting) }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-emerald-50 hover:text-emerald-800">
                                                <x-ui.icon name="download" class="h-5 w-5 text-emerald-600" />
                                                Export výsledkov
                                            </a>
                                            <a role="menuitem" href="{{ route('votings.exports.pressed-options', $voting) }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-800">
                                                <x-ui.icon name="download" class="h-5 w-5 text-sky-600" />
                                                Export stlačených možností
                                            </a>
                                        </div>
                                    </template>
                                @endif
                            </div>

                            <div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" class="relative sm:ml-auto">
                                <button type="button" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
                                    <x-ui.icon name="more" class="h-5 w-5" />
                                    Ďalšie možnosti
                                </button>
                                <template x-if="open">
                                    <div role="menu" class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
                                        <button type="button" role="menuitem" wire:click="copyVoting({{ $voting->id }})" x-on:click="open = false" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-800">
                                            <x-ui.icon name="copy" class="h-5 w-5 text-sky-600" />
                                            Kopírovať
                                        </button>
                                        <button type="button" role="menuitem" data-backup-export-url="{{ route('votings.configuration.export', $voting) }}" data-native-running="{{ config('nativephp-internal.running') ? 'true' : 'false' }}" data-exporting-label="Exportujem konfiguráciu…" data-export-saved-message="Konfigurácia bola uložená do:" data-export-unavailable-message="Native export konfigurácie nie je momentálne dostupný." data-export-empty-message="Export konfigurácie skončil bez potvrdenej cieľovej cesty." data-export-error-message="Export konfigurácie zlyhal." class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-700 hover:bg-violet-50 hover:text-violet-800 disabled:cursor-wait disabled:opacity-70">
                                            <x-ui.icon name="download" class="h-5 w-5 text-violet-600" />
                                            Exportovať konfiguráciu
                                        </button>
                                        <form method="POST" action="{{ route('votings.configuration.import', $voting) }}" enctype="multipart/form-data" data-confirm-message="Import nahradí celú konfiguráciu a prevádzkové údaje tohto hlasovania. Názov zostane zachovaný. Pokračovať?">
                                            @csrf
                                            <label role="menuitem" tabindex="0" x-on:keydown.enter="$el.click()" class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-teal-50 hover:text-teal-800 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-teal-500">
                                                <x-ui.icon name="upload" class="h-5 w-5 text-teal-600" />
                                                Importovať konfiguráciu
                                                <input type="file" name="configuration_file" accept="application/json,.json" required class="sr-only" x-on:change="$el.form.requestSubmit()">
                                            </label>
                                        </form>
                                        <div class="my-1 border-t border-slate-100"></div>
                                        @if (! $voting->archived_at)
                                            <button type="button" role="menuitem" wire:click="archiveVoting({{ $voting->id }})" x-on:click="open = false" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-amber-800 hover:bg-amber-50">
                                                <x-ui.icon name="archive" class="h-5 w-5" />
                                                Archivovať
                                            </button>
                                        @else
                                            <button type="button" role="menuitem" wire:click="activateVoting({{ $voting->id }})" x-on:click="open = false" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-lime-800 hover:bg-lime-50">
                                                <x-ui.icon name="activate" class="h-5 w-5" />
                                                Aktivovať
                                            </button>
                                        @endif
                                    </div>
                                </template>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center">
                        <p class="font-medium text-slate-700">{{ $showAll ? 'Zatiaľ tu nie je žiadne hlasovanie.' : 'Zatiaľ tu nie je žiadne aktívne hlasovanie.' }}</p>
                        @if (! $showAll)
                            <button type="button" wire:click="toggleShowAll" class="mt-3 text-sm font-semibold text-sky-700 hover:text-sky-900">Zobraziť archív</button>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
