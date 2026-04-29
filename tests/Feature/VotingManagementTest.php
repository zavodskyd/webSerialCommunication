<?php

use App\Livewire\Voting\VotingConsole;
use App\Livewire\Voting\VotingEditor;
use App\Livewire\Voting\VotingIndex;
use App\Livewire\Voting\VotingPresentation;
use App\Models\Device;
use App\Models\Voting;
use App\Models\VotingAttendee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('visitor can view voting pages', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schválenie programu',
        responseTimeSeconds: 30,
    );

    $this->get(route('votings.index'))
        ->assertOk()
        ->assertSeeLivewire(VotingIndex::class)
        ->assertSeeText('Správa hlasovaní a otázok');

    $this->get(route('votings.edit', $voting))
        ->assertOk()
        ->assertSeeLivewire(VotingEditor::class)
        ->assertSeeText('Detail hlasovania');

    $this->get(route('votings.presentation', $voting))
        ->assertOk()
        ->assertSeeLivewire(VotingPresentation::class)
        ->assertSeeText('Schválenie programu')
        ->assertDontSeeText('Čas');

    $this->get(route('votings.console', $voting))
        ->assertOk()
        ->assertSeeLivewire(VotingConsole::class)
        ->assertSeeText('Operátorská konzola')
        ->assertSeeText('Otvoriť prezentačné okno')
        ->assertSee(route('votings.presentation', $voting));
});

test('user can create a new voting from the index', function () {
    Livewire::test(VotingIndex::class)
        ->set('name', 'Zhromaždenie delegátov 2026')
        ->call('createVoting')
        ->assertRedirect();

    expect(Voting::query()->where('name', 'Zhromaždenie delegátov 2026')->exists())->toBeTrue();
});

test('user can copy a prepared voting with questions options and device weights', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'title' => 'Hlasovanie delegátov',
        'header_text' => 'Bratislava',
        'question_label' => 'Bod',
        'logo_path' => 'voting-logos/logo.png',
        'default_response_time_seconds' => 45,
        'auto_show_results' => false,
        'status' => 'live',
    ]);

    $question = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Bod 1',
        text: 'Schválenie programu',
        responseTimeSeconds: 35,
    );

    $question->update([
        'status' => 'closed',
    ]);

    $device = createVotingDevice('001');

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 6,
        'is_present' => true,
        'can_vote' => true,
    ]);

    Livewire::test(VotingIndex::class)
        ->call('copyVoting', $voting->id)
        ->assertRedirect();

    $copiedVoting = Voting::query()
        ->where('name', 'Valné zhromaždenie - kópia')
        ->firstOrFail();

    expect($copiedVoting)
        ->status->toBe('draft')
        ->title->toBe('Hlasovanie delegátov')
        ->header_text->toBe('Bratislava')
        ->question_label->toBe('Bod')
        ->logo_path->toBe('voting-logos/logo.png')
        ->default_response_time_seconds->toBe(45)
        ->auto_show_results->toBeFalse();

    $copiedQuestion = $copiedVoting->questions()->firstOrFail();

    expect($copiedQuestion)
        ->status->toBe('draft')
        ->order->toBe(1)
        ->label->toBe('Bod 1')
        ->text->toBe('Schválenie programu')
        ->response_time_seconds->toBe(35);

    expect($copiedQuestion->options()->pluck('key')->all())->toBe(['A', 'B', 'C']);

    expect((float) VotingAttendee::query()
        ->where('voting_id', $copiedVoting->id)
        ->where('device_id', $device->id)
        ->value('weight'))->toBe(6.0);
});

test('user can update voting details and generate questions', function () {
    Storage::fake('public');

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $logo = UploadedFile::fake()->image('skau.png');

    Livewire::test(VotingEditor::class, ['voting' => $voting])
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
    Storage::fake('public');

    $path = UploadedFile::fake()
        ->image('skau.png')
        ->store('voting-logos', 'public');

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'logo_path' => $path,
    ]);

    $this->get(route('votings.logo', $voting))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('user can edit and delete an existing question', function () {
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

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->set('questionRows.0.text', 'Schválenie programu')
        ->set('questionRows.0.response_time_seconds', 45)
        ->call('saveQuestion', $question->id)
        ->call('deleteQuestion', $question->id);

    expect($voting->questions()->count())->toBe(0);
});

test('user can change voting question order', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $firstQuestion = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Prvá otázka',
        responseTimeSeconds: 30,
    );

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 30,
    );

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->set('questionRows.1.order', 10)
        ->call('saveQuestion', $secondQuestion->id);

    expect($secondQuestion->fresh()->order)->toBe(10);
    expect($firstQuestion->fresh()->order)->toBe(1);
});

test('question order input syncs spinner changes immediately', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Prvá otázka',
        responseTimeSeconds: 30,
    );

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->assertSeeHtml('wire:model.live.number="questionRows.0.order"')
        ->assertSeeHtml('w-1/2 rounded-2xl');
});

test('user can move a question into an occupied order', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $firstQuestion = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Prvá otázka',
        responseTimeSeconds: 30,
    );

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 30,
    );

    $thirdQuestion = $voting->createQuestionWithDefaults(
        order: 3,
        label: 'Hlasovanie 3',
        text: 'Tretia otázka',
        responseTimeSeconds: 30,
    );

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->set('questionRows.2.order', 1)
        ->call('saveQuestion', $thirdQuestion->id)
        ->assertHasNoErrors();

    expect($thirdQuestion->fresh()->order)->toBe(1);
    expect($firstQuestion->fresh()->order)->toBe(2);
    expect($secondQuestion->fresh()->order)->toBe(3);
});

test('user can assign vote weights to devices for a voting', function () {
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

    Livewire::test(VotingEditor::class, ['voting' => $voting])
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

test('user can bulk assign vote weights by device number range', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $firstDevice = createVotingDevice('001');
    $secondDevice = createVotingDevice('002');
    $thirdDevice = createVotingDevice('003');

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->set('bulkDeviceWeight', '7')
        ->set('bulkDeviceCount', 2)
        ->call('assignBulkDeviceWeights');

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $firstDevice->id)
        ->value('weight'))->toBe(7.0);

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $secondDevice->id)
        ->value('weight'))->toBe(7.0);

    expect(VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $thirdDevice->id)
        ->exists())->toBeFalse();
});

test('user can export and import device vote weights', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
    ]);

    $firstDevice = createVotingDevice('001');
    $secondDevice = createVotingDevice('002');

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $firstDevice->id,
        'weight' => 5,
        'is_present' => true,
        'can_vote' => true,
    ]);

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->call('exportDeviceWeights')
        ->assertFileDownloaded('valne-zhromazdenie-vahy-zariadeni.csv');

    $import = UploadedFile::fake()->createWithContent(
        'weights.csv',
        "device_number,weight\n001,3\n002,8\n999,10\n",
    );

    Livewire::test(VotingEditor::class, ['voting' => $voting])
        ->set('deviceWeightsImport', $import)
        ->call('importDeviceWeights');

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $firstDevice->id)
        ->value('weight'))->toBe(3.0);

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $secondDevice->id)
        ->value('weight'))->toBe(8.0);
});

test('presentation reads runtime state and displays voting result chart', function () {
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

    $this->get(route('votings.presentation', $voting))
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

test('presentation uses the updated presentation layout proportions', function () {
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'title' => 'Hlasovanie delegátov',
        'header_text' => 'Hlasovanie 1',
    ]);

    $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schválenie programu',
        responseTimeSeconds: 30,
    );

    $this->get(route('votings.presentation', $voting))
        ->assertOk()
        ->assertSee('flex items-start gap-10', false)
        ->assertSee('flex min-h-36 flex-1 items-center justify-end text-right', false)
        ->assertSee('relative min-h-0 flex-1 pt-8', false)
        ->assertSee('absolute left-0 top-[12%] max-w-5xl text-6xl font-light leading-tight tracking-normal text-slate-800', false)
        ->assertSee('absolute left-1/2 top-[40%] w-full max-w-[54rem] -translate-x-1/2', false)
        ->assertSee('mx-auto mb-12 max-w-5xl text-center text-5xl font-medium leading-tight text-slate-950', false)
        ->assertSee('mx-auto w-full max-w-6xl', false)
        ->assertSee('mx-auto w-fit space-y-4', false)
        ->assertSee('flex items-center gap-14 text-5xl font-medium text-slate-950', false)
        ->assertSee('inline-flex h-10 w-16', false)
        ->assertSee('grid grid-cols-3 items-end border-t border-slate-200 pt-2', false)
        ->assertSee('min-w-52 items-center justify-center px-5 py-2 text-4xl', false)
        ->assertSee('text-3xl font-semibold text-slate-950', false);
});

test('user can open printable exports for closed voting questions', function () {
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

    $this->get(route('votings.index'))
        ->assertOk()
        ->assertSeeText('Export výsledkov')
        ->assertSeeText('Export stlačených možností');

    $this->get(route('votings.exports.results', $voting))
        ->assertOk()
        ->assertDontSeeText('Valné zhromaždenie')
        ->assertSee('print:h-[186mm]')
        ->assertSee('print-color-adjust: exact')
        ->assertSeeText('Výsledok hlasovania')
        ->assertSeeText('Schválenie programu')
        ->assertSeeText('ZA')
        ->assertSeeText('5')
        ->assertDontSeeText('Otvorená otázka');

    $this->get(route('votings.exports.pressed-options', $voting))
        ->assertOk()
        ->assertSeeText('Export stlačených možností')
        ->assertSeeText('Názov hlasovania')
        ->assertSeeText('Schválenie programu')
        ->assertSeeText('001')
        ->assertSeeText('5')
        ->assertSeeText('A')
        ->assertDontSeeText('Otvorená otázka');
});

function createVotingDevice(string $deviceNumber): Device
{
    return Device::query()->create([
        'device_number' => $deviceNumber,
        'code_a' => '20'.$deviceNumber.'a1',
        'code_b' => '20'.$deviceNumber.'b1',
        'code_c' => '20'.$deviceNumber.'c1',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);
}
