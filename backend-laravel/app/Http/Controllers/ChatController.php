<?php

namespace App\Http\Controllers;

use App\Models\PrivateMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function getMessages(Request $request, $userId, $friendId)
    {
        $messages = PrivateMessage::where(function ($q) use ($userId, $friendId) {
            $q->where('sender_id', $userId)->where('receiver_id', $friendId);
        })->orWhere(function ($q) use ($userId, $friendId) {
            $q->where('sender_id', $friendId)->where('receiver_id', $userId);
        })
        ->with(['sender', 'receiver'])
        ->orderBy('created_at', 'asc')
        ->get();

        return response()->json($messages);
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            'sender_id' => 'required|exists:users,id',
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string',
        ]);

        $message = PrivateMessage::create([
            'id' => (string) Str::uuid(),
            'sender_id' => $request->sender_id,
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
        ]);

        return response()->json($message->load(['sender', 'receiver']), 201);
    }
}
