<?php

use App\Livewire\Election\ElectionEditor;
use App\Livewire\Voting\VotingEditor;
use App\Models\Device;
use App\Models\Election;
use App\Models\Voting;
use App\Models\VotingAttendee;
use Livewire\Livewire;

it('initializes every election with 340 zero weight devices when its editor opens', function () {
    $voting = Voting::query()->create([
        'name' => 'Voľby bez zariadení',
        'voting_type' => 'election',
    ]);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();

    $component = Livewire::test(ElectionEditor::class, ['voting' => $voting]);

    expect(Device::query()->count())->toBe(340)
        ->and($voting->attendees()->count())->toBe(340)
        ->and($voting->attendees()->where('weight', '!=', 0)->count())->toBe(0)
        ->and($component->get('deviceWeightRows'))->toHaveCount(340)
        ->and($component->get('deviceWeightRows.0.device_number'))->toBe('001')
        ->and($component->get('deviceWeightRows.339.device_number'))->toBe('340');
});

it('preserves existing device weights while completing the standard voting roster', function () {
    $voting = Voting::query()->create(['name' => 'Existujúce hlasovanie']);
    $device = Device::query()->create([
        'device_number' => '001',
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
        'weight' => 7,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $component = Livewire::test(VotingEditor::class, ['voting' => $voting]);

    expect(Device::query()->count())->toBe(340)
        ->and($voting->attendees()->count())->toBe(340)
        ->and((float) $voting->attendees()->where('device_id', $device->id)->value('weight'))->toBe(7.0)
        ->and($component->get('deviceWeightRows'))->toHaveCount(340)
        ->and((float) $component->get('deviceWeightRows.0.weight'))->toBe(7.0);
});
