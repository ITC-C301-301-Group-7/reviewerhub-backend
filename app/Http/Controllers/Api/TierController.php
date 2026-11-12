<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ContributorTier;
use Illuminate\Http\Request;

class TierController extends Controller
{
    // GET /api/tier
    // The signed-in user's tier badge, points, allowances and progress to the next tier.
    public function show(Request $request)
    {
        return response()->json(ContributorTier::summary($request->user()));
    }
}
