<?php

declare(strict_types=1);

namespace App\Http\Controllers\Foodo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FoodoDishAnalysisController extends Controller
{
    public function show(string $id): View
    {
        // Demo data — in production this loads from DB
        return view('foodo.dish-analysis', [
            'dishName' => 'Siciliečių Pizza',
            'dishId'   => $id,
        ]);
    }

    public function demo(): View
    {
        return view('foodo.dish-analysis', [
            'dishName' => 'Siciliečių Pizza',
            'dishId'   => 'demo',
        ]);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'correct' => 'required|boolean',
        ]);

        return response()->json([
            'message' => 'Verification recorded. Thank you for your feedback.',
            'dish_id' => $id,
            'correct' => $request->boolean('correct'),
        ]);
    }
}
