<?php

use App\Livewire\Voting\VotingIndex;
use App\Models\Device;
use App\Models\Election;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\Voting\VotingConfigurationTransfer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('archived voting configuration export contains portable setup and embedded logo only', function () {
    $logoContents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    Storage::disk('public')->put('voting-logos/logo.png', $logoContents);

    $voting = Voting::query()->create([
        'name' => 'Výročné hlasovanie',
        'status' => 'finished',
        'title' => 'Delegáti',
        'header_text' => 'Bratislava',
        'logo_path' => 'voting-logos/logo.png',
        'archived_at' => now(),
        'started_at' => now()->subHour(),
        'finished_at' => now(),
    ]);
    $question = $voting->createQuestionWithDefaults(1, 'Bod 1', 'Schválenie programu', 45);
    $question->update(['status' => 'closed', 'opened_at' => now()->subMinute(), 'closed_at' => now()]);
    $device = configurationDevice('001');
    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 5,
        'is_present' => true,
        'can_vote' => true,
        'registered_at' => now(),
    ]);

    $payload = app(VotingConfigurationTransfer::class)->export($voting);

    expect($payload)
        ->format->toBe(VotingConfigurationTransfer::FORMAT)
        ->version->toBe(1)
        ->type->toBe('voting')
        ->name->toBe('Výročné hlasovanie')
        ->and($payload['logo']['mime'])->toBe('image/png')
        ->and(base64_decode($payload['logo']['data'], true))->toBe($logoContents)
        ->and($payload['attendees'][0]['device_number'])->toBe('001')
        ->and($payload['questions'][0]['options'])->toHaveCount(3)
        ->and($payload)->not->toHaveKeys(['status', 'archived_at', 'started_at', 'finished_at'])
        ->and($payload['questions'][0])->not->toHaveKeys(['status', 'opened_at', 'closed_at']);
});

test('voting configuration can be imported as a new draft and skips unknown devices', function () {
    configurationDevice('001');
    $payload = votingConfigurationPayload();
    $payload['logo'] = [
        'mime' => 'image/png',
        'data' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    ];
    $payload['attendees'][] = [
        'device_number' => '999',
        'weight' => '9.00',
        'is_present' => true,
        'can_vote' => true,
    ];

    $response = $this->post(route('votings.configuration.import-new'), [
        'configuration_file' => configurationUpload($payload),
    ]);

    $response->assertRedirect(route('votings.index'));

    $voting = Voting::query()->where('name', 'Importované hlasovanie')->firstOrFail();
    expect($voting)
        ->status->toBe('draft')
        ->archived_at->toBeNull()
        ->runtime_timer_running->toBeFalse()
        ->logo_path->not->toBeNull()
        ->and($voting->questions)->toHaveCount(1)
        ->and($voting->questions->first()->status)->toBe('draft')
        ->and($voting->attendees)->toHaveCount(1)
        ->and($voting->attendees->first()->device->device_number)->toBe('001');
    Storage::disk('public')->assertExists($voting->logo_path);
});

test('import into an existing voting replaces configuration and runtime data but preserves its name', function () {
    $oldLogo = 'voting-logos/old.png';
    Storage::disk('public')->put($oldLogo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

    $voting = Voting::query()->create([
        'name' => 'Zachovaný názov',
        'status' => 'finished',
        'logo_path' => $oldLogo,
        'archived_at' => now(),
        'runtime_timer_running' => true,
    ]);
    $oldQuestion = $voting->createQuestionWithDefaults(1, 'Starý bod', 'Stará otázka', 30);
    $voting->update(['current_voting_question_id' => $oldQuestion->id]);
    configurationDevice('001');

    $response = $this->post(route('votings.configuration.import', $voting), [
        'configuration_file' => configurationUpload(votingConfigurationPayload()),
    ]);

    $response->assertRedirect(route('votings.index'));
    $voting->refresh();

    expect($voting)
        ->name->toBe('Zachovaný názov')
        ->status->toBe('draft')
        ->archived_at->toBeNull()
        ->current_voting_question_id->toBeNull()
        ->runtime_timer_running->toBeFalse()
        ->logo_path->toBeNull()
        ->and($voting->questions()->count())->toBe(1)
        ->and($voting->questions()->first()->text)->toBe('Nová otázka');
    Storage::disk('public')->assertMissing($oldLogo);
});

test('election configuration round trip recreates prepared groups contests candidates and weights', function () {
    $source = Voting::query()->create([
        'name' => 'Voľby 2026',
        'voting_type' => 'election',
        'status' => 'finished',
        'archived_at' => now(),
    ]);
    $election = Election::query()->create([
        'voting_id' => $source->id,
        'status' => 'finished',
        'weight_one_device_count' => 2,
        'quorum_participant_count' => 120,
        'candidate_admissions_locked' => true,
    ]);
    $group = $election->deviceGroups()->create([
        'name' => 'Hliny',
        'sort_order' => 1,
        'is_active' => true,
        'quorum_participant_count' => 40,
    ]);
    $group->ranges()->create(['start_number' => 1, 'end_number' => 50]);
    $contest = $election->contests()->create([
        'device_group_id' => $group->id,
        'key' => 'chairperson',
        'name' => 'Predseda',
        'seat_count' => 1,
        'sort_order' => 1,
    ]);
    $contest->candidates()->create([
        'first_name' => 'Ján',
        'last_name' => 'Novák',
        'status' => 'approved',
    ]);
    $device = configurationDevice('001');
    $source->attendees()->create([
        'device_id' => $device->id,
        'weight' => 3,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $payload = app(VotingConfigurationTransfer::class)->export($source);
    $imported = app(VotingConfigurationTransfer::class)->import($payload, 'election');
    $imported->load('election.deviceGroups.ranges', 'election.contests.candidates', 'attendees.device');

    expect($payload)->not->toHaveKeys(['status', 'archived_at'])
        ->and($imported->name)->toBe('Voľby 2026')
        ->and($imported->status)->toBe('draft')
        ->and($imported->archived_at)->toBeNull()
        ->and($imported->election->status)->toBe('preparation')
        ->and($imported->election->candidate_admissions_locked)->toBeFalse()
        ->and($imported->election->deviceGroups)->toHaveCount(1)
        ->and($imported->election->deviceGroups->first()->ranges)->toHaveCount(1)
        ->and($imported->election->contests->first()->device_group_id)->toBe($imported->election->deviceGroups->first()->id)
        ->and($imported->election->contests->first()->candidates->first()->last_name)->toBe('Novák')
        ->and($imported->attendees->first()->device->device_number)->toBe('001');
});

test('unsupported configuration is rejected without changing the target', function () {
    $voting = Voting::query()->create([
        'name' => 'Pôvodné hlasovanie',
        'title' => 'Pôvodný nadpis',
    ]);
    $payload = votingConfigurationPayload();
    $payload['version'] = 999;

    $response = $this->from(route('votings.index'))->post(route('votings.configuration.import', $voting), [
        'configuration_file' => configurationUpload($payload),
    ]);

    $response->assertRedirect(route('votings.index'))
        ->assertSessionHasErrors('configuration_file');
    expect($voting->fresh()->title)->toBe('Pôvodný nadpis');
});

test('configuration actions are visible for active and archived records', function () {
    $activeVoting = Voting::query()->create(['name' => 'Aktívne hlasovanie']);
    $archivedVoting = Voting::query()->create(['name' => 'Archivované hlasovanie', 'archived_at' => now()]);

    $this->get(route('votings.index'))
        ->assertSuccessful()
        ->assertSeeText('Importovať hlasovanie')
        ->assertSee(route('votings.configuration.export', $activeVoting));

    $this->get(route('votings.index').'?showAll=1');

    $component = Livewire\Livewire::test(VotingIndex::class)
        ->call('toggleShowAll')
        ->assertSee(route('votings.configuration.export', $archivedVoting), false)
        ->assertSeeText('Exportovať konfiguráciu')
        ->assertSeeText('Importovať konfiguráciu');
});

function configurationDevice(string $number): Device
{
    return Device::query()->create([
        'device_number' => $number,
        'code_a' => '',
        'code_b' => '',
        'code_c' => '',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);
}

/**
 * @return array<string, mixed>
 */
function votingConfigurationPayload(): array
{
    return [
        'format' => VotingConfigurationTransfer::FORMAT,
        'version' => VotingConfigurationTransfer::VERSION,
        'type' => 'voting',
        'name' => 'Importované hlasovanie',
        'settings' => [
            'question_label' => 'Bod',
            'title' => 'Nový nadpis',
            'header_text' => 'Nová hlavička',
            'default_response_time_seconds' => 45,
            'auto_show_results' => false,
        ],
        'logo' => null,
        'attendees' => [[
            'device_number' => '001',
            'weight' => '5.00',
            'is_present' => true,
            'can_vote' => true,
        ]],
        'questions' => [[
            'order' => 1,
            'label' => 'Bod 1',
            'text' => 'Nová otázka',
            'response_time_seconds' => 30,
            'options' => [
                ['key' => 'A', 'label' => 'ZA', 'color' => '#16a34a', 'sort_order' => 1],
                ['key' => 'B', 'label' => 'PROTI', 'color' => '#dc2626', 'sort_order' => 2],
            ],
        ]],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function configurationUpload(array $payload): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'configuration.json',
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}
