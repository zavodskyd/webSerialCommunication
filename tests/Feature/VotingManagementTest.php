<?php

use App\Livewire\Voting\VotingConsole;
use App\Livewire\Voting\VotingEditor;
use App\Livewire\Voting\VotingIndex;
use App\Livewire\Voting\VotingPresentation;
use App\Models\Device;
use App\Models\User;
use App\Models\Voting;
use App\Models\VotingAttendee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('verified user can view voting pages', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schválenie programu',
        responseTimeSeconds: 30,
    );

    $this->actingAs($user)
        ->get(route('votings.index'))
        ->assertOk()
        ->assertSeeLivewire(VotingIndex::class)
        ->assertSeeText('Správa hlasovaní a otázok');

    $this->actingAs($user)
        ->get(route('votings.edit', $voting))
        ->assertOk()
        ->assertSeeLivewire(VotingEditor::class)
        ->assertSeeText('Detail hlasovania');

    $this->actingAs($user)
        ->get(route('votings.presentation', $voting))
        ->assertOk()
        ->assertSeeLivewire(VotingPresentation::class)
        ->assertSeeText('Schválenie programu')
        ->assertDontSeeText('Čas');

    $this->actingAs($user)
        ->get(route('votings.console', $voting))
        ->assertOk()
        ->assertSeeLivewire(VotingConsole::class)
        ->assertSeeText('Operátorská konzola')
        ->assertSeeText('Otvoriť prezentačné okno')
        ->assertSee(route('votings.presentation', $voting));
});

test('guest cannot access voting management pages', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $this->get(route('votings.index'))
        ->assertRedirect('/login');

    $this->get(route('votings.edit', $voting))
        ->assertRedirect('/login');
});

test('user can create a new voting from the index', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(VotingIndex::class)
        ->set('name', 'Zhromaždenie delegátov 2026')
        ->call('createVoting')
        ->assertRedirect();

    expect(Voting::query()->where('name', 'Zhromaždenie delegátov 2026')->exists())->toBeTrue();
});

test('user can update voting details and generate questions', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Storage::fake('public');

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $logo = UploadedFile::fake()->image('skau.png');

    Livewire::actingAs($user)
        ->test(VotingEditor::class, ['voting' => $voting])
        ->set('title', 'Hlasovanie delegátov')
        ->set('headerText', 'DoubleTree by Hilton, Bratislava')
        ->set('questionLabel', 'Hlasovanie')
        ->set('defaultResponseTimeSeconds', 40)
        ->set('autoShowResults', false)
        ->set('logoUpload', $logo)
        ->call('saveVoting')
        ->set('generationCount', 3)
        ->set('generationResponseTimeSeconds', 25)
        ->call('generateQuestions')
        ->assertSet('questionRows.0.text', 'Hlasovanie 1')
        ->assertSet('questionRows.2.response_time_seconds', 25);

    $voting->refresh();

    expect($voting->title)->toBe('Hlasovanie delegátov');
    expect($voting->header_text)->toBe('DoubleTree by Hilton, Bratislava');
    expect($voting->default_response_time_seconds)->toBe(40);
    expect($voting->auto_show_results)->toBeFalse();
    expect($voting->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($voting->logo_path);
    expect($voting->questions()->count())->toBe(3);
    expect($voting->questions()->first()->options()->pluck('key')->all())->toBe(['A', 'B', 'C']);
});

test('voting logo is served through the application in native compatible storage', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Storage::fake('public');

    $path = UploadedFile::fake()
        ->image('skau.png')
        ->store('voting-logos', 'public');

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'logo_path' => $path,
    ]);

    $this->actingAs($user)
        ->get(route('votings.logo', $voting))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('user can edit and delete an existing question', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $question = $voting->questions()->create([
        'order' => 1,
        'label' => 'Hlasovanie 1',
        'text' => 'Pôvodná otázka',
        'response_time_seconds' => 30,
    ]);

    $question->options()->createMany([
        ['key' => 'A', 'label' => 'ZA', 'sort_order' => 1],
        ['key' => 'B', 'label' => 'PROTI', 'sort_order' => 2],
        ['key' => 'C', 'label' => 'ZDRŽAL SA', 'sort_order' => 3],
    ]);

    Livewire::actingAs($user)
        ->test(VotingEditor::class, ['voting' => $voting])
        ->set('questionRows.0.text', 'Schválenie programu')
        ->set('questionRows.0.response_time_seconds', 45)
        ->call('saveQuestion', $question->id)
        ->call('deleteQuestion', $question->id);

    expect($voting->questions()->count())->toBe(0);
});

test('user can assign vote weights to devices for a voting', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $firstDevice = Device::query()->create([
        'device_number' => '001',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    $secondDevice = Device::query()->create([
        'device_number' => '002',
        'code_a' => '2082a2',
        'code_b' => '2092b2',
        'code_c' => '20a282',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    Livewire::actingAs($user)
        ->test(VotingEditor::class, ['voting' => $voting])
        ->assertSet('deviceWeightRows.0.weight', '0.00')
        ->set('deviceWeightRows.0.weight', '5')
        ->set('deviceWeightRows.1.weight', '0')
        ->call('saveDeviceWeights');

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $firstDevice->id)
        ->value('weight'))->toBe(5.0);

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $secondDevice->id)
        ->value('weight'))->toBe(0.0);
});

test('presentation reads runtime state and displays voting result chart', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'title' => 'Hlasovanie delegátov',
        'runtime_remaining_seconds' => 0,
        'runtime_results_visible' => true,
    ]);

    $question = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schválenie programu',
        responseTimeSeconds: 30,
    );

    $device = Device::query()->create([
        'device_number' => '001',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    $attendee = VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 5,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $question->update(['status' => 'live']);
    $question->recordVote($attendee, 'A');
    $question->update(['status' => 'closed']);
    $voting->update(['current_voting_question_id' => $question->id]);

    $this->actingAs($user)
        ->get(route('votings.presentation', $voting))
        ->assertOk()
        ->assertDontSee('bg-slate-100')
        ->assertSee('backdrop-blur-2xl')
        ->assertSee('data-presentation-result-overlay="blurred"', false)
        ->assertSee('data-presentation-result-chart="vertical"', false)
        ->assertSee('data-presentation-result-bar-space', false)
        ->assertSee('data-presentation-result-option', false)
        ->assertSee('data-presentation-result-participants', false)
        ->assertSeeText('Výsledok hlasovania')
        ->assertSeeText('ZA')
        ->assertSeeText('5');
});

test('user can open printable exports for closed voting questions', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $closedQuestion = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schválenie programu',
        responseTimeSeconds: 30,
    );

    $openQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Otvorená otázka',
        responseTimeSeconds: 30,
    );

    $device = Device::query()->create([
        'device_number' => '001',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    $attendee = VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 5,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $closedQuestion->update(['status' => 'live']);
    $closedQuestion->recordVote($attendee, 'A');
    $closedQuestion->update(['status' => 'closed']);
    $openQuestion->update(['status' => 'live']);

    $this->actingAs($user)
        ->get(route('votings.index'))
        ->assertOk()
        ->assertSeeText('Export výsledkov')
        ->assertSeeText('Export stlačených možností');

    $this->actingAs($user)
        ->get(route('votings.exports.results', $voting))
        ->assertOk()
        ->assertDontSeeText('Valné zhromaždenie')
        ->assertSee('print:h-[186mm]')
        ->assertSee('print-color-adjust: exact')
        ->assertSeeText('Výsledok hlasovania')
        ->assertSeeText('Schválenie programu')
        ->assertSeeText('ZA')
        ->assertSeeText('5')
        ->assertDontSeeText('Otvorená otázka');

    $this->actingAs($user)
        ->get(route('votings.exports.pressed-options', $voting))
        ->assertOk()
        ->assertSeeText('Export stlačených možností')
        ->assertSeeText('Názov hlasovania')
        ->assertSeeText('Schválenie programu')
        ->assertSeeText('001')
        ->assertSeeText('5')
        ->assertSeeText('A')
        ->assertDontSeeText('Otvorená otázka');
});
