<div wire:poll.1s="liveTick" class="min-h-screen bg-slate-100 p-6">
    <div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[18rem_1fr]">
        <aside class="rounded-3xl bg-white p-4 shadow-sm">
            @foreach ($contests as $item)
                <button wire:click="selectContest({{ $item->id }})"
                    class="mt-2 block w-full rounded-xl px-3 py-2 text-left {{ $contest->id === $item->id ? 'bg-emerald-600 text-white' : 'bg-slate-100' }}">{{ $item->name }}</button>
            @endforeach
        </aside>
        <main class="rounded-3xl bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">{{ $contest->name }}</h1><span
                    class="text-sm font-semibold {{ $serialConnected ? 'text-emerald-700' : 'text-rose-700' }}">{{ $serialConnected ? 'Serial Agent pripojený' : 'Serial Agent nepripojený' }}</span>
            </div>
            @if (session('status'))
                <p class="mt-3 rounded-xl bg-rose-50 p-3 text-rose-800">{{ session('status') }}</p>
                @endif@if (!$round)<button wire:click="createRound"
                        class="mt-5 rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white">Vytvoriť
                    kolo</button>@else<div class="mt-5 flex gap-3"><button wire:click="openRound"
                            @disabled(!$serialConnected)
                            class="rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white disabled:opacity-50">Spustiť</button><button
                            wire:click="closeRound"
                            class="rounded-xl bg-rose-600 px-4 py-3 font-semibold text-white">Zastaviť</button></div>
                    <p class="mt-4">Kolo {{ $round->round_number }} · {{ $round->status }} · väčšina
                        {{ $results['majority_threshold'] }}</p>
                    @foreach ($results['candidates'] as $candidate)
                        <button wire:click="selectCandidate({{ $candidate['id'] }})"
                            class="mt-2 flex w-full justify-between rounded-xl px-4 py-3 {{ $candidateId === $candidate['id'] ? 'bg-emerald-600 text-white' : 'bg-slate-100' }}"><span>{{ $candidate['first_name'] }}
                                {{ $candidate['last_name'] }}</span><strong>{{ $candidate['weighted_total'] }}</strong></button>
                    @endforeach
                @endif
        </main>
    </div>
</div>
