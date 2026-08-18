<?php

use App\Livewire\Election\ElectionCandidateAdmissionConsole;
use App\Livewire\Election\ElectionConsole;
use App\Livewire\Voting\VotingPresentation;
use App\Models\Device;
use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionCandidateAdmissionManager;
use App\Services\ElectionRoundManager;
use App\Support\PresentationRuntimeManager;
use App\Support\SerialAgentClient;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->serialAgent = Mockery::mock(SerialAgentClient::class);
    app()->instance(SerialAgentClient::class, $this->serialAgent);
});

test('starting candidate admission starts serial vote collection', function () {
    [$voting, $election] = electionVotingForVisibilityTest();
    $contest = $election->contests()->where('key', 'supervisory-committee')->firstOrFail();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);

    $this->serialAgent->shouldReceive('command')->once()->with('start')->andReturn(['ok' => true]);

    Livewire::test(ElectionCandidateAdmissionConsole::class, ['voting' => $voting])
        ->call('startAdmission', $admission->id)
        ->assertHasNoErrors();

    expect($admission->fresh()->status)->toBe('live')
        ->and(app(PresentationRuntimeManager::class)->current()->context['admission_id'])->toBe($admission->id);
});

test('presentation does not expose election votes until results are shown', function () {
    [$voting, $election] = electionVotingForVisibilityTest();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);
    $device = electionVisibilityDevice($voting, '001', 4321);
    $rounds = app(ElectionRoundManager::class);
    $round = $rounds->open($rounds->create($contest));
    $candidate = $round->candidates()->firstOrFail();
    $rounds->recordVote($round, $candidate, $device);
    app(PresentationRuntimeManager::class)->activate($voting, 'election_round', [
        'round_id' => $round->id,
        'candidate_id' => $candidate->id,
    ]);

    $presentation = app(VotingPresentation::class);
    $presentation->mount($voting);
    $hiddenView = $presentation->render(
        app(PresentationRuntimeManager::class),
        app(ElectionCandidateAdmissionManager::class),
        $rounds,
    );
    $hiddenViewData = $hiddenView->getData();
    $hiddenView->with('voting', $voting)->render();

    expect($hiddenViewData['roundResults'])->toBeNull()
        ->and($hiddenViewData['displayRoundCandidates'][0]['weighted_total'])->toBeNull()
        ->and($hiddenViewData['roundAcceptedDeviceCount'])->toBe(1);

    $round->update(['status' => 'closed', 'closed_at' => now()]);
    $voting->update(['runtime_results_visible' => true]);

    $visibleViewData = $presentation->render(
        app(PresentationRuntimeManager::class),
        app(ElectionCandidateAdmissionManager::class),
        $rounds,
    )->getData();

    expect($visibleViewData['roundResults']['candidates'][0]['weighted_total'])->toBe(4321.0);
});

test('showing candidate admission results resolves and presents the decision and majority', function () {
    [$voting, $election] = electionVotingForVisibilityTest();
    $election->update(['quorum_participant_count' => 1]);
    $contest = $election->contests()->where('key', 'supervisory-committee')->firstOrFail();
    $device = electionVisibilityDevice($voting, '001', 1);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $manager = app(ElectionCandidateAdmissionManager::class);
    $manager->start($admission);
    $manager->recordVote($admission, $device, 'A');
    $manager->stop($admission);

    Livewire::test(ElectionCandidateAdmissionConsole::class, ['voting' => $voting])
        ->assertDontSee('Vyhodnotiť')
        ->call('showAdmissionResults', $admission->id)
        ->assertHasNoErrors();

    expect($admission->fresh()->status)->toBe('accepted');

    $presentation = app(VotingPresentation::class);
    $presentation->mount($voting);
    $view = $presentation->render(
        app(PresentationRuntimeManager::class),
        $manager,
        app(ElectionRoundManager::class),
    );
    $viewData = $view->getData();
    $html = $view->with('voting', $voting)->render();

    expect($viewData['admissionAcceptedDeviceCount'])->toBe(1)
        ->and($viewData['admissionMajorityThreshold'])->toBe(1)
        ->and($html)->toContain('Zvolený')
        ->and($html)->toContain('Nadpolovičná väčšina:');
});

test('finishing the last candidate automatically shows the round result', function () {
    [$voting, $election] = electionVotingForVisibilityTest(autoShowResults: false);
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);
    app(ElectionRoundManager::class)->create($contest);

    $this->serialAgent->shouldReceive('health')->atLeast()->once()->andReturn([
        'ok' => true,
        'connected' => true,
    ]);
    $this->serialAgent->shouldReceive('command')->once()->with('start')->andReturn(['ok' => true]);
    $this->serialAgent->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('startRoundViaHelper')
        ->call('stopRoundViaHelper')
        ->assertSet('resultsVisible', true);

    expect($voting->fresh()->runtime_results_visible)->toBeTrue();
});

function electionVotingForVisibilityTest(bool $autoShowResults = true): array
{
    $voting = Voting::query()->create([
        'name' => 'Voľby',
        'voting_type' => 'election',
        'auto_show_results' => $autoShowResults,
    ]);
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'quorum_participant_count' => 100,
    ]);
    $election->createDefaultContests();

    return [$voting, $election];
}

function electionVisibilityDevice(Voting $voting, string $number, int $weight): Device
{
    $device = Device::query()->create([
        'device_number' => $number,
        'code_a' => '',
        'code_b' => '',
        'code_c' => '',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);
    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => $weight,
        'is_present' => true,
        'can_vote' => true,
    ]);

    return $device;
}
