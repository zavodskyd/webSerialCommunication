<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;

/**
 * @return array{0: User, 1: Voting, 2: VotingQuestion, 3: Device}
 */
function createExportFixture(string $deviceNumber = '001'): array
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

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
        'device_number' => $deviceNumber,
        'code_a' => '2081a1'.$deviceNumber,
        'code_b' => '2091b1'.$deviceNumber,
        'code_c' => '20a181'.$deviceNumber,
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

    return [$user, $voting, $question, $device];
}

test('vote events CSV export streams expected header and rows for the question', function () {
    [, $voting, $question, $device] = createExportFixture();

    VoteEvent::query()->create([
        'voting_id' => $voting->id,
        'voting_question_id' => $question->id,
        'device_id' => $device->id,
        'raw_hex' => '2081a1',
        'source' => 'web-serial',
        'button_name' => 'A',
        'accepted' => true,
        'rejection_reason' => null,
        'received_at' => now()->subSeconds(3),
    ]);

    VoteEvent::query()->create([
        'voting_id' => $voting->id,
        'voting_question_id' => $question->id,
        'device_id' => null,
        'raw_hex' => 'deadbe',
        'source' => 'node-helper',
        'button_name' => null,
        'accepted' => false,
        'rejection_reason' => 'unknown_code',
        'received_at' => now()->subSeconds(2),
    ]);

    VoteEvent::query()->create([
        'voting_id' => $voting->id,
        'voting_question_id' => $question->id,
        'device_id' => $device->id,
        'raw_hex' => '2091b1',
        'source' => 'node-helper',
        'button_name' => 'B',
        'accepted' => true,
        'rejection_reason' => null,
        'received_at' => now()->subSeconds(1),
    ]);

    $response = $this->get(route('votings.questions.events-export', [
        'voting' => $voting,
        'question' => $question,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader(
        'Content-Disposition',
        sprintf('attachment; filename="voting-%d-question-%d-events.csv"', $voting->id, $question->order),
    );

    $body = $response->streamedContent();
    $lines = array_values(array_filter(preg_split("/\r?\n/", $body)));

    expect($lines[0])->toBe('received_at,source,device_number,button_name,raw_hex,accepted,rejection_reason');
    expect($lines)->toHaveCount(4);
    expect($lines[1])->toContain(',web-serial,001,A,2081a1,1,');
    expect($lines[2])->toContain(',node-helper,,,deadbe,0,unknown_code');
    expect($lines[3])->toContain(',node-helper,001,B,2091b1,1,');
});

test('vote events CSV export 404s if the question does not belong to the voting', function () {
    [, , $questionA] = createExportFixture('001');
    [, $votingB] = createExportFixture('002');

    $response = $this->get(route('votings.questions.events-export', [
        'voting' => $votingB,
        'question' => $questionA,
    ]));

    $response->assertNotFound();
});
