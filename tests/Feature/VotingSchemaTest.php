<?php

use App\Models\Device;
use App\Models\Vote;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingOption;
use App\Models\VotingQuestion;
use Illuminate\Database\QueryException;

test('it stores the voting domain model with per-question response times', function () {
    $voting = Voting::query()->create([
        'name' => 'Zhromaždenie delegátov',
        'question_label' => 'Hlasovanie',
        'title' => 'Hlasovanie delegátov',
        'header_text' => 'DoubleTree by Hilton, Bratislava',
        'default_response_time_seconds' => 45,
    ]);

    $question = VotingQuestion::query()->create([
        'voting_id' => $voting->id,
        'order' => 1,
        'label' => 'Hlasovanie 1',
        'text' => 'Schválenie programu',
        'response_time_seconds' => 20,
    ]);

    $option = VotingOption::query()->create([
        'voting_question_id' => $question->id,
        'key' => 'A',
        'label' => 'ZA',
        'color' => '#16a34a',
        'sort_order' => 1,
    ]);

    expect($voting->questions)->toHaveCount(1);
    expect($question->response_time_seconds)->toBe(20);
    expect($option->question->is($question))->toBeTrue();
});

test('it allows only one attendee record per device in a voting', function () {
    $voting = Voting::query()->create([
        'name' => 'Zhromaždenie delegátov',
    ]);

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
    ]);

    expect(function () use ($voting, $device): void {
        VotingAttendee::query()->create([
            'voting_id' => $voting->id,
            'device_id' => $device->id,
            'weight' => 3,
        ]);
    })->toThrow(QueryException::class);
});

test('attendee vote weight defaults to zero', function () {
    $voting = Voting::query()->create([
        'name' => 'Zhromaždenie delegátov',
    ]);

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
    ]);

    expect((float) $attendee->weight)->toBe(0.0);
});

test('it overwrites the previous vote when the same device votes again before closing', function () {
    $voting = Voting::query()->create([
        'name' => 'Zhromaždenie delegátov',
    ]);

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

    $question = VotingQuestion::query()->create([
        'voting_id' => $voting->id,
        'order' => 1,
        'status' => 'live',
        'text' => 'Schváliť program?',
        'response_time_seconds' => 30,
    ]);

    $optionA = VotingOption::query()->create([
        'voting_question_id' => $question->id,
        'key' => 'A',
        'label' => 'ZA',
        'sort_order' => 1,
    ]);

    $optionB = VotingOption::query()->create([
        'voting_question_id' => $question->id,
        'key' => 'B',
        'label' => 'PROTI',
        'sort_order' => 2,
    ]);

    $firstVote = $question->recordVote($attendee, 'A');
    $secondVote = $question->recordVote($attendee, 'B');

    expect(Vote::query()->count())->toBe(1);
    expect($firstVote->id)->toBe($secondVote->id);
    expect($secondVote->option_key)->toBe('B');
    expect((float) $secondVote->weight_snapshot)->toBe(5.0);
    expect($secondVote->option->is($optionB))->toBeTrue();
    expect($secondVote->option->is($optionA))->toBeFalse();
});
