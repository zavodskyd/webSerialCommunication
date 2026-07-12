<div wire:poll.2s class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8">
        <a href="{{ route('elections.edit', $voting) }}" wire:navigate class="text-sm font-semibold text-emerald-700">← Späť na voľby</a>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm">
            <h1 class="text-3xl font-semibold text-slate-900">Doplnenie kandidáta</h1>
            <form wire:submit="createAndOpenAdmission" class="mt-6 grid gap-4 md:grid-cols-2">
                <input wire:model.blur="firstName" placeholder="Meno" class="rounded-xl border-slate-300 px-3 py-2">
                <input wire:model.blur="lastName" placeholder="Priezvisko" class="rounded-xl border-slate-300 px-3 py-2">
                <select wire:model="deviceGroupId" class="rounded-xl border-slate-300 px-3 py-2 md:col-span-2">
                    <option value="">Bez lokality — Kontrolná komisia, všetky zariadenia</option>
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }} — príslušné predstavenstvo</option>
                    @endforeach
                </select>
                <button class="rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white md:col-span-2">Otvoriť doplnenie</button>
            </form>
        </section>

        @if (session('status'))
            <p class="rounded-xl bg-emerald-50 px-4 py-3 font-medium text-emerald-800">{{ session('status') }}</p>
        @endif

        @if ($activeAdmission)
            <section class="rounded-[2rem] bg-slate-900 p-6 text-white shadow-sm">
                <p class="text-sm font-semibold text-emerald-300">Prebieha hlasovanie</p>
                <h2 class="mt-1 text-2xl font-semibold">{{ $activeAdmission->first_name }} {{ $activeAdmission->last_name }}</h2>
                <p class="mt-1 text-slate-300">{{ $activeAdmission->contest->name }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    @foreach($activeResults as $result)
                        <div class="rounded-xl bg-white/10 px-4 py-3">
                            <p class="text-sm text-slate-300">{{ $result['label'] }}</p>
                            <p class="text-2xl font-semibold">{{ $result['weighted_total'] }}</p>
                            <p class="text-xs text-slate-400">{{ $result['vote_count'] }} zariadení</p>
                        </div>
                    @endforeach
                </div>
                <button wire:click="resolveAdmission({{ $activeAdmission->id }})" wire:confirm="Naozaj chcete vyhodnotiť doplnenie kandidáta?" class="mt-5 rounded-xl bg-emerald-500 px-4 py-3 font-semibold text-white">Vyhodnotiť doplnenie</button>
            </section>
        @endif

        <section class="rounded-[2rem] bg-white p-6 shadow-sm">
            <h2 class="text-xl font-semibold">Návrhy</h2>
            @forelse($admissions as $admission)
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3">
                    <div>
                        <p class="font-semibold">{{ $admission->first_name }} {{ $admission->last_name }}</p>
                        <p class="text-sm text-slate-600">{{ $admission->contest->name }} · {{ $admission->votes_count }} zariadení</p>
                    </div>
                    <span class="rounded-full bg-slate-200 px-3 py-1 text-sm font-semibold text-slate-700">{{ $admission->status }}</span>
                </div>
            @empty
                <p class="mt-3 text-slate-600">Zatiaľ nebol vytvorený žiadny návrh.</p>
            @endforelse
        </section>
    </div>
</div>
