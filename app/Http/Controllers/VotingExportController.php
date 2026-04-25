<?php

namespace App\Http\Controllers;

use App\Models\Voting;
use Illuminate\Contracts\View\View;

class VotingExportController extends Controller
{
    public function results(Voting $voting): View
    {
        $voting->load([
            'questions' => fn ($query) => $query
                ->where('status', 'closed')
                ->with(['options', 'votes.device']),
        ]);

        return view('voting-exports.results', [
            'voting' => $voting,
            'questions' => $voting->questions,
        ]);
    }

    public function pressedOptions(Voting $voting): View
    {
        $voting->load([
            'questions' => fn ($query) => $query
                ->where('status', 'closed')
                ->with(['votes.device']),
        ]);

        return view('voting-exports.pressed-options', [
            'voting' => $voting,
            'questions' => $voting->questions,
        ]);
    }
}
