<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Vote;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\Voting\VoteRecorder;
use App\Services\Voting\VoteRecordingResult;

function createRecorderFixture(int $weight = 5): array
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
        'code_ruka' => '20e151',
    ]);

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => $weight,
        'is_present' => true,
        'can_vote' => true,
    ]);

    return [$voting, $question, $device];
}

test('records an accepted vote and returns the device + button details', function () {
    [$voting, $question, $device] = createRecorderFixture();

    // Move voting/question into the live-collecting state.
    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result)->toBeInstanceOf(VoteRecordingResult::class);
    expect($result->accepted)->toBeTrue();
    expect($result->deviceNumber)->toBe('001');
    expect($result->buttonName)->toBe('A');
    expect($result->rejectionReason)->toBeNull();
    expect(Vote::query()->count())->toBe(1);
});

test('rejects when collector is off and DB also says not collecting', function () {
    [$voting, $question, $device] = createRecorderFixture();

    // Voting is in the default "not collecting" state.
    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: false,
    );

    expect($result->accepted)->toBeFalse();
    expect($result->rejectionReason)->toBe('collector_disabled');
    expect(Vote::query()->count())->toBe(0);
});

test('rejects unknown hex codes silently', function () {
    [$voting, $question] = createRecorderFixture();

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: 'deadbe',
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->accepted)->toBeFalse();
    expect($result->rejectionReason)->toBe('unknown_code');
    expect($result->deviceNumber)->toBeNull();
    expect(Vote::query()->count())->toBe(0);
});

test('resolves the device by decoded device number instead of stored code columns', function () {
    [$voting, $question, $device] = createRecorderFixture();

    $device->update([
        'code_a' => 'legacy-a',
        'code_b' => 'legacy-b',
        'code_c' => 'legacy-c',
    ]);

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'B'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->accepted)->toBeTrue();
    expect($result->deviceNumber)->toBe('001');
    expect($result->buttonName)->toBe('B');
    expect(Vote::query()->first()?->option_key)->toBe('B');
});

test('rejects Ruka button (non-voting button)', function () {
    [$voting, $question, $device] = createRecorderFixture();

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'Ruka'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->accepted)->toBeFalse();
    expect($result->rejectionReason)->toBe('non_voting_button');
    expect($result->deviceNumber)->toBe('001');
    expect($result->buttonName)->toBe('Ruka');
    expect(Vote::query()->count())->toBe(0);
});

test('rejects when attendee weight is 0', function () {
    [$voting, $question, $device] = createRecorderFixture(weight: 0);

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->accepted)->toBeFalse();
    expect($result->rejectionReason)->toBe('zero_weight');
    expect(Vote::query()->count())->toBe(0);
});

test('accepts a vote even when collector hint is false but DB says collecting', function () {
    [$voting, $question, $device] = createRecorderFixture();

    // DB says collecting but caller's in-memory hint is stale (false).
    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: false,
    );

    expect($result->accepted)->toBeTrue();
    expect(Vote::query()->count())->toBe(1);
});

test('every call writes one vote_events row regardless of accept/reject', function () {
    [$voting, $question, $device] = createRecorderFixture();

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    // Accepted vote
    app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    // Rejected vote (valid non-voting button)
    app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'Ruka'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect(VoteEvent::query()->count())->toBe(2);

    $accepted = VoteEvent::query()->where('accepted', true)->first();
    expect($accepted->source)->toBe('rust-agent');
    expect($accepted->raw_hex)->toBe(qomoFrameFor(1, 'A'));
    expect($accepted->button_name)->toBe('A');
    expect($accepted->device_id)->toBe($device->id);

    $rejected = VoteEvent::query()->where('accepted', false)->first();
    expect($rejected->source)->toBe('rust-agent');
    expect($rejected->rejection_reason)->toBe('non_voting_button');
    expect($rejected->button_name)->toBe('Ruka');
    expect($rejected->device_id)->toBe($device->id);
});

test('records votes for devices above 255 using the decoded device number', function () {
    [$voting, $question] = createRecorderFixture();

    $highDevice = Device::query()->create([
        'device_number' => '341',
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
        'device_id' => $highDevice->id,
        'weight' => 9,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(341, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->accepted)->toBeTrue();
    expect($result->deviceNumber)->toBe('341');
    expect($result->buttonName)->toBe('A');
    expect($result->results[0]['weighted_total'])->toBe(9.0);
    expect(Vote::query()->where('device_id', $highDevice->id)->first()?->option_key)->toBe('A');
});

test('returns unknown_device for a valid frame without configured voting device', function () {
    [$voting, $question] = createRecorderFixture();

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(341, 'B'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->accepted)->toBeFalse();
    expect($result->deviceNumber)->toBe('341');
    expect($result->buttonName)->toBe('B');
    expect($result->rejectionReason)->toBe('unknown_device');
});

test('rejects a frame received after the question deadline', function () {
    [$voting, $question] = createRecorderFixture();
    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update([
        'status' => 'live',
        'opened_at' => now()->startOfSecond(),
    ]);
    $receivedAt = $question->fresh()->opened_at->toImmutable()->addSeconds($question->response_time_seconds + 1);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
        receivedAt: $receivedAt,
    );

    expect($result->accepted)->toBeFalse();
    expect($result->rejectionReason)->toBe('after_deadline');
    expect(Vote::query()->count())->toBe(0);
    expect(VoteEvent::query()->latest('id')->value('received_at')->getTimestamp())->toBe($receivedAt->getTimestamp());
});

test('result toArray returns the same shape as the legacy Livewire response', function () {
    [$voting, $question, $device] = createRecorderFixture();

    $voting->forceFill(['runtime_collector_enabled' => true])->save();
    $question->update(['status' => 'live']);

    $result = app(VoteRecorder::class)->record(
        code: qomoFrameFor(1, 'A'),
        voting: $voting,
        question: $question,
        collectorEnabledHint: true,
    );

    expect($result->toArray())->toHaveKeys([
        'accepted',
        'message',
        'lastMatchedDeviceNumber',
        'lastButtonName',
        'results',
    ]);
});
