<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\BillSplit;
use App\Models\BillSplitParticipant;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThreadBillSplitController extends Controller
{
    public function store(Request $request, int $threadId): JsonResponse
    {
        $request->validate([
            'description'           => 'required|string|max:255',
            'totalAmount'           => 'required|numeric|min:0.01',
            'splitMethod'           => 'required|in:equal,custom',
            'participants'          => 'required|array|min:1',
            'participants.*.userId' => 'required|integer',
            'participants.*.amount' => 'required|numeric|min:0',
        ]);

        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        $participantUserIds = collect($request->input('participants'))
            ->pluck('userId')
            ->map(fn ($id) => (int) $id);

        $activeMemberIds = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->pluck('user_id');

        $nonMembers = $participantUserIds->diff($activeMemberIds);
        if ($nonMembers->isNotEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Selected users must be active group members',
            ], 422);
        }

        $result = DB::transaction(function () use ($request, $thread, $userId): array {
            $message = Message::create([
                'thread_id'  => $thread->id,
                'sender_id'  => $userId,
                'type'       => 'bill_split',
                'text'       => 'Bill split: ' . $request->input('description'),
                'created_at' => now(),
            ]);

            $split = BillSplit::create([
                'message_id'   => $message->id,
                'thread_id'    => $thread->id,
                'created_by'   => $userId,
                'description'  => $request->input('description'),
                'total_amount' => $request->input('totalAmount'),
                'split_method' => $request->input('splitMethod'),
            ]);

            foreach ($request->input('participants') as $participant) {
                BillSplitParticipant::create([
                    'bill_split_id' => $split->id,
                    'user_id'       => (int) $participant['userId'],
                    'amount'        => (float) $participant['amount'],
                ]);
            }

            $message->update(['payload' => [
                'billSplitId'  => $split->id,
                'description'  => $split->description,
                'totalAmount'  => (float) $split->total_amount,
                'splitMethod'  => $split->split_method,
                'participants' => collect($request->input('participants'))->map(fn ($participant) => [
                    'userId' => (string) $participant['userId'],
                    'amount' => (float) $participant['amount'],
                    'status' => 'pending',
                ])->all(),
            ]]);

            return [
                'messageId'   => $message->id,
                'billSplitId' => $split->id,
                'message'     => $message,
            ];
        });

        $this->broadcastMessage($thread, $result['message'], $request);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'messageId'   => (string) $result['messageId'],
                'billSplitId' => (string) $result['billSplitId'],
            ],
        ]);
    }

    public function markPaid(Request $request, int $billSplitId): JsonResponse
    {
        $request->validate(['participantUserId' => 'required|integer']);

        $split = BillSplit::with('thread')->findOrFail($billSplitId);
        abort_unless($split->thread instanceof Thread, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($split->thread, $userId);

        $participant = BillSplitParticipant::where('bill_split_id', $split->id)
            ->where('user_id', (int) $request->input('participantUserId'))
            ->firstOrFail();

        if (! $participant->isPaid()) {
            $participant->update(['status' => 'paid', 'paid_at' => now()]);
        }

        $allPaid = BillSplitParticipant::where('bill_split_id', $split->id)
            ->where('status', 'pending')
            ->doesntExist();

        if ($allPaid) {
            $split->update(['status' => 'settled']);
        }

        return response()->json(['status' => 'success']);
    }

    private function ensureActiveMember(Thread $thread, int $userId): void
    {
        $isMember = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not a member of this thread');
        }
    }

    private function broadcastMessage(Thread $thread, Message $message, Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $senderName = $user->name;
        $preview = $message->text ?? $message->type;

        $recipientIds = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            event(new ChatMessageSent(
                recipientId: (int) $recipientId,
                threadId: $thread->id,
                threadType: $thread->type,
                senderId: $userId,
                senderName: $senderName,
                messageId: $message->id,
                messageType: $message->type,
                preview: $preview,
            ));
        }
    }
}
