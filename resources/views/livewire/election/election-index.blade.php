<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-[2rem] bg-[linear-gradient(135deg,_#0f172a,_#1e293b_55%,_#334155)] p-8 text-white shadow-xl shadow-slate-900/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div><p class="text-sm font-semibold uppercase tracking-[0.35em] text-emerald-200">Voľby</p><h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">Správa volieb</h1><p class="mt-4 max-w-2xl text-base leading-7 text-slate-200">Priprav jednotlivé súťaže, kandidátky a skupiny zariadení nezávisle od bežných hlasovaní.</p></div>
                <form wire:submit="createElection" class="w-full max-w-xl rounded-[1.5rem] border border-white/10 bg-white/10 p-5 backdrop-blur"><label for="election-name" class="text-sm font-medium text-slate-100">Názov volieb</label><div class="mt-3 flex flex-col gap-3 sm:flex-row"><input id="election-name" type="text" wire:model.blur="name" placeholder="Voľby orgánov 2026" class="w-full rounded-2xl border border-white/15 bg-white/90 px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-300/50"><button type="submit" class="rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">Vytvoriť</button></div>@error('name')<p class="mt-2 text-sm text-rose-200">{{ $message }}</p>@enderror</form>
            </div>
        </div>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-4"><h2 class="text-2xl font-semibold text-slate-900">Pripravené voľby</h2><p class="text-3xl font-semibold text-slate-900">{{ $votings->count() }}</p></div><button type="button" wire:click="toggleShowAll" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100">{{ $showAll ? 'Len aktívne' : 'Všetky' }}</button></div>
            <div class="mt-6 space-y-4">
                @forelse ($votings as $voting)
                    <article wire:key="election-{{ $voting->id }}" class="flex flex-col gap-4 rounded-[1.5rem] border border-slate-200 bg-slate-50/80 p-5 lg:flex-row lg:items-center lg:justify-between"><div><div class="flex items-center gap-3"><h3 class="text-xl font-semibold text-slate-900">{{ $voting->name }}</h3>@if ($voting->archived_at)<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">Archivované</span>@endif</div><p class="mt-2 text-sm text-slate-500">{{ $voting->title ?: 'Bez nadpisu volieb' }}</p></div><div class="flex flex-wrap items-center gap-3"><span class="rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">{{ $voting->election?->contests_count ?? 0 }} súťaží</span><span class="rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">{{ $voting->election?->device_groups_count ?? 0 }} skupín</span><a href="{{ route('elections.edit', $voting) }}" wire:navigate class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">Otvoriť editor</a>@if (! $voting->archived_at)<button type="button" wire:click="archiveElection({{ $voting->id }})" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">Archivovať</button>@endif</div></article>
                @empty
                    <div class="rounded-[1.5rem] border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-slate-500">Zatiaľ tu nie sú žiadne voľby.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
