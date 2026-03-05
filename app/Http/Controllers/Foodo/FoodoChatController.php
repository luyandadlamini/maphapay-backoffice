<?php

declare(strict_types=1);

namespace App\Http\Controllers\Foodo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FoodoChatController extends Controller
{
    public function index(): View
    {
        return view('foodo.chat');
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userMessage = $request->input('message');

        // Demo response — in production this would call an AI service
        $reply = $this->generateDemoReply($userMessage);

        return response()->json([
            'reply' => $reply,
        ]);
    }

    private function generateDemoReply(string $message): string
    {
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'sales') || str_contains($lower, 'revenue')) {
            return '<p class="mb-3">Based on the latest data, here\'s your sales summary:</p>'
                . '<div class="bg-stone-50 p-3 rounded-lg border border-stone-100 mb-3">'
                . '<div class="flex justify-between"><span class="font-semibold">Weekly Sales</span><span class="font-black text-foodo-primary">€377,309</span></div>'
                . '<div class="flex justify-between mt-1"><span class="font-semibold">February Total</span><span class="font-black">€1,607,082</span></div>'
                . '</div>'
                . '<p class="text-stone-600">Sales are down 10% week-over-week. Saturday remains the strongest day across all locations.</p>';
        }

        if (str_contains($lower, 'campaign') || str_contains($lower, 'marketing')) {
            return '<h4 class="font-bold mb-2">Campaign Performance Summary</h4>'
                . '<div class="space-y-3">'
                . '<div class="bg-stone-50 p-3 rounded-lg border-l-4 border-emerald-500">'
                . '<div class="font-bold">Wolt — <span class="text-emerald-600">High Performer</span></div>'
                . '<p class="text-xs text-stone-600 mt-1">+15% orders, €18.50 avg basket, 8.2% conversion</p>'
                . '</div>'
                . '<div class="bg-stone-50 p-3 rounded-lg border-l-4 border-rose-500">'
                . '<div class="font-bold">Bolt — <span class="text-rose-600">Needs Attention</span></div>'
                . '<p class="text-xs text-stone-600 mt-1">+8% orders, CAC 40% higher, 3.1% conversion</p>'
                . '</div>'
                . '</div>'
                . '<p class="mt-3 text-sm font-medium">Recommendation: Consider shifting 50% of Bolt budget to Wolt.</p>';
        }

        if (str_contains($lower, 'labor') || str_contains($lower, 'staff')) {
            return '<p>Current labor cost sits at <strong>36.3% of revenue</strong> — above the 33% target.</p>'
                . '<p class="mt-2 text-stone-600">Saturday shifts are the most efficient. Consider optimizing Tuesday-Wednesday scheduling where labor-to-sales ratio exceeds 40%.</p>';
        }

        return '<p>I can help you analyze sales, campaigns, labor costs, and more. Try asking:</p>'
            . '<ul class="list-disc pl-5 mt-2 space-y-1 text-stone-600">'
            . '<li>"What were this week\'s sales?"</li>'
            . '<li>"Compare Wolt vs Bolt campaigns"</li>'
            . '<li>"How is our labor cost trending?"</li>'
            . '</ul>';
    }
}
