<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Vote;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
use App\Support\SerialHelperTokens;

beforeEach(function () {
    @unlink(SerialHelperTokens::tokenPath());
    @unlink(SerialHelperTokens::portPath());
});

afterEach(function () {
    @unlink(SerialHelperTokens::tokenPath());
    @unlink(SerialHelperTokens::portPath());
});

test('it rejects requests without a token (401)', function () {
    SerialHelperTokens::current();

    $response = $this->postJson(route('internal.serial-frame'), [
        'hex' => '2081a1',
    ]);

    $response->assertStatus(401);
});

test('it rejects requests with the wrong token (401)', function () {
    SerialHelperTokens::current();

    $response = $this->withHeader('X-Internal-Token', 'wrong')
        ->postJson(route('internal.serial-frame'), [
            'hex' => '2081a1',
        ]);

    $response->assertStatus(401);
});

test('it rejects requests from non-loopback IPs (403)', function () {
    $token = SerialHelperTokens::current();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->withHeader('X-Internal-Token', $token)
        ->postJson(route('internal.serial-frame'), [
            'hex' => '2081a1',
        ]);

    $response->assertStatus(403);
});

test('it accepts a valid frame and returns the recording result', function () {
    [$voting, , $device] = createInternalSerialFrameFixture();

    $token = SerialHelperTokens::current();

    $response = $this
        ->withHeader('X-Internal-Token', $token)
        ->postJson(route('internal.serial-frame'), [
            'hex' => $device->code_a,
            'received_at' => '2026-04-28T13:42:11.123Z',
        ]);

    $response->assertOk();
    $response->assertJson([
        'accepted' => true,
        'lastMatchedDeviceNumber' => '001',
        'lastButtonName' => 'A',
    ]);

    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');

    $event = VoteEvent::query()->first();
    expect($event)->not->toBeNull();
    expect($event->source)->toBe('node-helper');
    expect($event->voting_id)->toBe($voting->id);
    expect($event->accepted)->toBeTrue();
    expect($event->raw_hex)->toBe($device->code_a);
});

test('it returns accepted=false when no voting is active', function () {
    $token = SerialHelperTokens::current();

    $response = $this
        ->withHeader('X-Internal-Token', $token)
        ->postJson(route('internal.serial-frame'), [
            'hex' => '2081a1',
        ]);

    $response->assertOk();
    $response->assertJson([
        'accepted' => false,
        'message' => 'No active voting',
    ]);

    expect(Vote::query()->count())->toBe(0);
    expect(VoteEvent::query()->count())->toBe(0);
});

/**
 * @return array{0: Voting, 1: VotingQuestion, 2: Device}
 */
function createInternalSerialFrameFixture(): array
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

    return [$voting->refresh(), $question->refresh(), $device];
}
