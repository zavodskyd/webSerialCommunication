<?php

namespace App\Http\Controllers;

use App\Models\Voting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VotingLogoController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Voting $voting): StreamedResponse|Response
    {
        abort_if($voting->logo_path === null, 404);
        abort_unless(Storage::disk('public')->exists($voting->logo_path), 404);

        return Storage::disk('public')->response($voting->logo_path);
    }
}
