<div x-data x-on:focus-candidate-first-name.window="$nextTick(() => $refs['candidate-first-' + $event.detail.contestId]?.focus())" class="min-h-screen bg-slate-100">
    @if (session('status'))
        <div data-flash-message class="fixed left-1/2 top-4 z-[100] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 rounded-[1.5rem] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-900 shadow-xl transition-opacity duration-300" role="status">{{ session('status') }}</div>
    @endif

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div><a href="{{ route('elections.index') }}" wire:navigate class="text-sm font-semibold text-emerald-700 hover:text-emerald-900">← Späť na voľby</a><h1 class="mt-3 text-4xl font-semibold tracking-tight text-slate-900">{{ $name }}</h1><div class="mt-3 flex gap-4"><a href="{{ route('elections.console', $voting) }}" wire:navigate class="text-sm font-semibold text-emerald-700">Volebná konzola →</a><a href="{{ route('elections.candidate-admissions', $voting) }}" wire:navigate class="text-sm font-semibold text-emerald-700">Doplnenie kandidáta →</a></div></div>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <h2 class="text-2xl font-semibold text-slate-900">Detail volieb</h2>
            <form wire:submit="saveElection" class="mt-6 grid gap-5">
                <div class="grid gap-5 md:grid-cols-2"><div><label class="text-sm font-medium text-slate-700">Názov volieb</label><input type="text" wire:model.blur="name" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3">@error('name')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror</div><div><label class="text-sm font-medium text-slate-700">Predvolený čas</label><input type="number" wire:model.blur="defaultResponseTimeSeconds" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3"></div></div>
                <div><label class="text-sm font-medium text-slate-700">Nadpis volieb</label><textarea wire:model.blur="title" rows="2" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3"></textarea></div>
                <div><label class="text-sm font-medium text-slate-700">Hlavička volieb</label><textarea wire:model.blur="headerText" rows="2" class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3"></textarea></div>
                <div class="grid gap-5 md:grid-cols-2"><div><label class="text-sm font-medium text-slate-700">Logo volieb</label><input type="file" wire:model="logoUpload" accept="image/*" class="mt-2 block w-full rounded-2xl border border-slate-300 bg-white px-4 py-3">@if ($logoUpload)<img src="{{ $logoUpload->temporaryUrl() }}" class="mt-3 h-20 rounded-xl bg-slate-50 object-contain">@elseif ($logoPath)<img src="{{ route('votings.logo', $voting) }}" class="mt-3 h-20 rounded-xl bg-slate-50 object-contain">@endif</div><label class="mt-6 flex items-center gap-3"><input type="checkbox" wire:model.live="autoShowResults">Automaticky zobraziť výsledok</label></div>
                <div class="flex justify-end"><button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Uložiť voľby</button></div>
            </form>
        </section>

        <section><h2 class="mb-5 text-2xl font-semibold text-slate-900">Kandidátky súťaží</h2><div class="grid gap-5 xl:grid-cols-2">
            @foreach ($contestRows as $contest)
                @if ($contest['name'] === 'Predseda')
                    @include('livewire.election.partials.contest-card', ['contest' => $contest])
                    <div class="hidden xl:block" aria-hidden="true"></div>
                @endif
            @endforeach
            @foreach ($contestRows as $contest)
                @if (in_array($contest['name'], ['Predstavenstvo Hliny', 'Predstavenstvo Solinky'], true))
                    @include('livewire.election.partials.contest-card', ['contest' => $contest])
                @endif
            @endforeach
            @foreach ($contestRows as $contest)
                @if (in_array($contest['name'], ['Predstavenstvo Vlčince', 'Predstavenstvo Rozptyl/Staré Mesto'], true))
                    @include('livewire.election.partials.contest-card', ['contest' => $contest])
                @endif
            @endforeach
            @foreach ($contestRows as $contest)
                @if ($contest['name'] === 'Kontrolná komisia')
                    @include('livewire.election.partials.contest-card', ['contest' => $contest])
                @endif
            @endforeach
        </div></section>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Aktívne zariadenia a váhy</h2>
                    <p class="mt-1 text-sm text-slate-500">Do väčšiny sa rátajú zariadenia 1 až nastavený limit s váhou aspoň 1.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="exportDeviceWeights"
                        class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
                        Export váh
                    </button>
                    <div class="rounded-full bg-slate-900 px-4 py-3 text-xs font-semibold uppercase tracking-[0.25em] text-white">
                        {{ count($deviceWeightRows) }} zariadení
                    </div>
                </div>
            </div>

            <form wire:submit="saveVotingWeights" class="mt-6 space-y-4">
                <div class="flex flex-wrap items-end gap-4">
                    <label class="grid max-w-xs gap-2 text-sm font-semibold text-slate-700">
                        Aktívne zariadenia 1 až
                        <input type="number" min="1" wire:model.blur="activeDeviceLimit" class="rounded-xl border-slate-300 px-3 py-2">
                    </label>
                    <button type="button" wire:click="fillActiveDeviceWeights" class="rounded-2xl border border-emerald-300 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800">
                        Vyplniť váhu 1
                    </button>
                </div>
                @error('activeDeviceLimit')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
                <div class="max-h-80 overflow-auto rounded-[1.5rem] border border-slate-200">
                    <table class="w-full divide-y divide-slate-200">
                        <tbody>
                            @foreach ($deviceWeightRows as $index => $row)
                                <tr wire:key="election-device-weight-{{ $row['id'] }}">
                                    <td class="px-3 py-2 text-sm font-medium text-slate-700">{{ $row['device_number'] }}</td>
                                    <td class="px-3 py-2">
                                        <input type="number" min="0" step="0.01" wire:model.blur="deviceWeightRows.{{ $index }}.weight" class="w-full rounded-xl border-slate-300 px-3 py-2">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Uložiť limit a váhy</button>
            </form>

            <form wire:submit="importDeviceWeights" class="mt-6 rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                <h3 class="text-base font-semibold text-slate-900">Import váh zariadení</h3>
                <label class="mt-4 block text-sm font-medium text-slate-700">CSV súbor</label>
                <input type="file" wire:model="deviceWeightsImport" accept=".csv,text/csv,text/plain"
                    class="mt-2 block w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-700">
                @error('deviceWeightsImport')
                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                @enderror
                <button type="submit" class="mt-4 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                    Importovať váhy
                </button>
            </form>
        </section>

        <section class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200/80"><div class="flex items-center justify-between"><div><h2 class="text-2xl font-semibold text-slate-900">Skupiny a rozsahy zariadení</h2><p class="mt-1 text-sm text-slate-500">Každá skupina má presne jeden rozsah.</p></div><button type="button" wire:click="addDeviceGroup" @disabled($this->availableGroupNames() === []) class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 disabled:opacity-50">Pridať skupinu</button></div><form wire:submit="saveDeviceGroups" class="mt-6 space-y-4">@foreach ($groupRows as $groupIndex => $group)<article wire:key="group-{{ $group['id'] ?? $groupIndex }}" class="grid gap-3 rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5 md:grid-cols-[1fr_1fr_1fr_auto]"><select wire:model="groupRows.{{ $groupIndex }}.name" class="rounded-xl border-slate-300 px-3 py-2"><option value="{{ $group['name'] }}">{{ $group['name'] }}</option>@foreach ($this->availableGroupNames() as $groupName)<option value="{{ $groupName }}">{{ $groupName }}</option>@endforeach</select><input type="number" min="1" wire:model.blur="groupRows.{{ $groupIndex }}.range.start_number" placeholder="Od zariadenia" class="rounded-xl border-slate-300 px-3 py-2"> <input type="number" min="1" wire:model.blur="groupRows.{{ $groupIndex }}.range.end_number" placeholder="Po zariadenie" class="rounded-xl border-slate-300 px-3 py-2"><button type="button" wire:click="removeDeviceGroup({{ $groupIndex }})" class="text-sm font-semibold text-rose-700">Odstrániť</button></article>@endforeach<div class="flex justify-end"><button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Uložiť skupiny</button></div></form></section>
    </div>
</div>
