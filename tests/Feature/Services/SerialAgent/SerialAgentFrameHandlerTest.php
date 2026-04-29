<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Vote;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\SerialAgent\SerialAgentFrameHandler;

test('it returns null when no voting is active', function () {
    $result = app(SerialAgentFrameHandler::class)->handle('2081a1');

    expect($result)->toBeNull();
    expect(Vote::query()->count())->toBe(0);
    expect(VoteEvent::query()->count())->toBe(0);
});

test('it records rust-agent frames into the active voting question', function () {
    [$voting, $device] = createSerialAgentFrameFixture();

    $result = app(SerialAgentFrameHandler::class)->handle($device->code_a);

    expect($result)->not->toBeNull();
    expect($result->accepted)->toBeTrue();
    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');

    $event = VoteEvent::query()->first();
    expect($event)->not->toBeNull();
    expect($event->source)->toBe('rust-agent');
    expect($event->voting_id)->toBe($voting->id);
    expect($event->accepted)->toBeTrue();
    expect($event->raw_hex)->toBe($device->code_a);
});

/**
 * @return array{0: Voting, 1: Device}
 */
function createSerialAgentFrameFixture(): array
{
    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'auto_show_results' => true,
    ]);

    $question = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schváliť program?',
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

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 5,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $question->forceFill(['status' => 'live'])->save();
    $voting->forceFill([
        'current_voting_question_id' => $question->id,
        'runtime_collector_enabled' => true,
        'runtime_timer_running' => true,
        'runtime_remaining_seconds' => 30,
        'runtime_results_visible' => false,
    ])->save();

    return [$voting->refresh(), $device];
}
