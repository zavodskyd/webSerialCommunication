<div wire:poll.2s="liveTick" class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-[96rem] space-y-6 px-4 py-8">
        <a href="{{ route('elections.edit', $voting) }}" wire:navigate class="text-sm font-semibold text-emerald-700">← Späť na voľby</a>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-6">

        @if (session('status'))
            <p class="rounded-xl bg-emerald-50 px-4 py-3 font-medium text-emerald-800">{{ session('status') }}</p>
        @endif

        @if ($activeAdmission)
            <section class="rounded-[2rem] bg-slate-900 p-6 text-white shadow-sm">
                <p class="text-sm font-semibold text-emerald-300">{{ $activeAdmission->status === 'live' ? 'Prebieha hlasovanie' : ($activeAdmission->results_visible ? 'Zobrazený výsledok' : 'Hlasovanie je zastavené') }}</p>
                <h2 class="mt-1 text-2xl font-semibold">{{ $activeAdmission->first_name }} {{ $activeAdmission->last_name }}</h2>
                <p class="mt-1 text-slate-300">{{ $activeAdmission->contest->name }}</p>
                <p class="mt-3 text-4xl font-semibold tabular-nums">{{ sprintf('%02d:%02d', intdiv($activeRemainingSeconds ?? 0, 60), ($activeRemainingSeconds ?? 0) % 60) }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    @foreach($activeResults as $result)
                        <div class="rounded-xl bg-white/10 px-4 py-3">
                            <p class="text-sm text-slate-300">{{ $result['label'] }}</p>
                            <p class="text-2xl font-semibold">{{ $result['weighted_total'] }}</p>
                            <p class="text-xs text-slate-400">{{ $result['vote_count'] }} zariadení</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-[2rem] bg-white p-6 shadow-sm">
            <h2 class="text-xl font-semibold">Návrhy</h2>
            @forelse($admissions as $admission)
                <div wire:key="admission-{{ $admission->id }}" class="mt-3 rounded-xl bg-slate-50 px-4 py-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2"><input wire:change="updateAdmissionName({{ $admission->id }}, $event.target.value, '{{ addslashes($admission->last_name) }}')" value="{{ $admission->first_name }}" @disabled($admission->status === 'live') class="w-36 rounded-lg border-slate-300 px-2 py-1 font-semibold"><input wire:change="updateAdmissionName({{ $admission->id }}, '{{ addslashes($admission->first_name) }}', $event.target.value)" value="{{ $admission->last_name }}" @disabled($admission->status === 'live') class="w-40 rounded-lg border-slate-300 px-2 py-1 font-semibold"><input wire:change="updateAdmissionTime({{ $admission->id }}, $event.target.value)" value="{{ $admission->response_time_seconds }}" type="number" min="1" max="3600" @disabled(! in_array($admission->status, ['draft', 'closed'])) class="w-24 rounded-lg border-slate-300 px-2 py-1 text-sm"><span class="text-sm text-slate-500">sek.</span></div>
                        <p class="text-sm text-slate-600">{{ $admission->contest->name }} · {{ $admission->votes_count }} zariadení</p>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($admission->status === 'live')
                            <button wire:click="stopAdmission({{ $admission->id }})" class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white">Zastaviť</button>
                        @elseif (in_array($admission->status, ['draft', 'open', 'closed']))
                            <button wire:click="selectAdmission({{ $admission->id }})" class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-semibold text-white">Zobraziť</button>
                            <button wire:click="startAdmission({{ $admission->id }})" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">Spustiť</button>
                            @if (in_array($admission->status, ['open', 'closed']))
                                <button wire:click="showAdmissionResults({{ $admission->id }})" class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-semibold text-white">Zobraziť výsledok</button>
                                <button wire:click="restartAdmission({{ $admission->id }})" class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white">Reštartovať</button>
                                @if ($admission->results_visible)
                                    <button wire:click="resolveAdmission({{ $admission->id }})" wire:confirm="Potvrdiť výsledok a vyhodnotiť doplnenie?" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Vyhodnotiť</button>
                                @endif
                            @endif
                            <button wire:click="deleteAdmission({{ $admission->id }})" wire:confirm="Vymazať návrh kandidáta?" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-200">Vymazať</button>
                        @else
                            <button wire:click="selectAdmission({{ $admission->id }})" class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-semibold text-white">Zobraziť</button>
                            <button wire:click="restartAdmission({{ $admission->id }})" wire:confirm="Reštart vymaže hlasy tohto návrhu. Pokračovať?" class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white">Reštartovať</button>
                            <button wire:click="deleteAdmission({{ $admission->id }})" wire:confirm="Vymazať záznam návrhu?" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-200">Vymazať</button>
                        @endif
                        <span class="rounded-full bg-slate-200 px-3 py-1 text-sm font-semibold text-slate-700">{{ $admission->status }}</span>
                    </div>
                </div>
            @empty
                <p class="mt-3 text-slate-600">Zatiaľ nebol vytvorený žiadny návrh.</p>
            @endforelse
        </section>
            </div>

            <section class="h-fit rounded-[2rem] bg-white p-6 shadow-sm">
                <h1 class="text-3xl font-semibold text-slate-900">Doplnenie kandidáta</h1>
                <form wire:submit="createAndOpenAdmission" class="mt-6 grid gap-4">
                    <input wire:model.blur="firstName" placeholder="Meno" class="rounded-xl border-slate-300 px-3 py-2">
                    <input wire:model.blur="lastName" placeholder="Priezvisko" class="rounded-xl border-slate-300 px-3 py-2">
                    <input wire:model.blur="responseTimeSeconds" type="number" min="1" max="3600" placeholder="Čas v sekundách" class="rounded-xl border-slate-300 px-3 py-2">
                    <select wire:model="deviceGroupId" class="rounded-xl border-slate-300 px-3 py-2">
                        <option value="">Bez lokality — Kontrolná komisia, všetky zariadenia</option>
                        @foreach($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }} — príslušné predstavenstvo</option>
                        @endforeach
                    </select>
                    <button class="rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white">Pridať návrh</button>
                </form>
            </section>
        </div>
    </div>
</div>
