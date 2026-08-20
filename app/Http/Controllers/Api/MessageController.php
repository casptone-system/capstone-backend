<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\AccreditationCycle;
use App\Models\RoleStorageFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function contacts(Request $request): JsonResponse
    {
        $payload = app(\App\Services\AccreditationMessagingService::class)->contacts($request->user());

        return response()->json(['success' => true, 'data' => $payload]);
    }

    /**
     * Get all conversations for the current user
     */
    public function listConversations(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $conversations = $user->conversations()
            ->wherePivot('is_archived', false)
            ->with(['accreditationCycle.program.college', 'creator', 'participants'])
            ->withCount(['messages'])
            ->orderByDesc('updated_at')
            ->paginate(20);

        $conversations->transform(function ($conversation) use ($user) {
            $latestMessage = $conversation->getLatestMessage();
            
            return [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
                'type' => $conversation->type,
                'accreditation_cycle' => $conversation->accreditationCycle ? [
                    'id' => $conversation->accreditationCycle->id,
                    'program_name' => $conversation->accreditationCycle->program->name,
                    'level' => $conversation->accreditationCycle->level,
                    'phase' => $conversation->accreditationCycle->phase,
                    'college_name' => $conversation->accreditationCycle->program->college->name,
                ] : null,
                'creator' => [
                    'id' => $conversation->creator->id,
                    'name' => $conversation->creator->name,
                    'email' => $conversation->creator->email,
                ],
                'unread_count' => $conversation->getUnreadCountForUser($user->id),
                'latest_message' => $latestMessage ? [
                    'body' => substr($latestMessage->body, 0, 80) . (strlen($latestMessage->body) > 80 ? '...' : ''),
                    'sender_name' => $latestMessage->sender->name,
                    'created_at' => $latestMessage->created_at,
                ] : null,
                'message_count' => $conversation->messages_count,
                'updated_at' => $conversation->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $conversations,
        ]);
    }

    /**
     * Get a specific conversation with all messages
     */
    public function getConversation(Conversation $conversation, Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user is participant
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this conversation',
            ], 403);
        }

        // Mark as read
        $conversation->markAsReadForUser($user->id);

        $messages = $conversation->messages()
            ->with(['sender', 'attachments.storageFile'])
            ->paginate(50);

        $messages->transform(function ($message) {
            return [
                'id' => $message->id,
                'sender' => [
                    'id' => $message->sender->id,
                    'name' => $message->sender->name,
                    'email' => $message->sender->email,
                ],
                'body' => $message->body,
                'attachments' => $message->attachments->map(function ($att) {
                    return [
                        'id' => $att->id,
                        'file_name' => $att->file_name,
                        'file_mime' => $att->file_mime,
                        'file_size' => $att->file_size,
                        'formatted_size' => $att->getFormattedSize(),
                        'file_icon' => $att->getFileIcon(),
                        'file_path' => $att->file_path,
                        'storage_file_id' => $att->role_storage_file_id,
                    ];
                }),
                'created_at' => $message->created_at,
                'is_edited' => $message->isEdited(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'subject' => $conversation->subject,
                    'type' => $conversation->type,
                    'accreditation_cycle' => $conversation->accreditationCycle ? [
                        'id' => $conversation->accreditationCycle->id,
                        'program_name' => $conversation->accreditationCycle->program->name,
                        'level' => $conversation->accreditationCycle->level,
                    ] : null,
                    'participants' => $conversation->participants->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'email' => $p->email,
                    ]),
                ],
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * Send a message in a conversation
     */
    public function sendMessage(Conversation $conversation, Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user is participant
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to send message in this conversation',
            ], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer|exists:role_storage_files,id',
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'body' => $validated['body'],
        ]);

        // Attach storage files if provided
        if (!empty($validated['attachment_ids'])) {
            foreach ($validated['attachment_ids'] as $storageFileId) {
                $storageFile = RoleStorageFile::find($storageFileId);
                
                if ($storageFile && $storageFile->user_id === $user->id) {
                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'role_storage_file_id' => $storageFile->id,
                        'file_name' => $storageFile->original_name ?: $storageFile->name,
                        'file_path' => $storageFile->file_path,
                        'file_mime' => $storageFile->mime_type,
                        'file_size' => $storageFile->file_size,
                    ]);
                }
            }
        }

        // Send notification to other participants
        $this->notifyParticipants($conversation, $message, $user);

        // Update conversation updated_at
        $conversation->touch();

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => [
                'id' => $message->id,
                'sender' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'body' => $message->body,
                'attachments' => $message->attachments->map(function ($att) {
                    return [
                        'id' => $att->id,
                        'file_name' => $att->file_name,
                    ];
                }),
                'created_at' => $message->created_at,
            ],
        ]);
    }

    /**
     * Create a new conversation
     */
    public function createConversation(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'accreditation_cycle_id' => ['nullable', 'exists:accreditation_cycles,id'],
            'area_id' => ['nullable', 'exists:accreditation_areas,id'],
            'parameter_id' => ['nullable', 'exists:accreditation_parameters,id'],
            'workspace_id' => ['nullable', 'exists:accreditation_workspaces,id'],
            'subject' => 'required|string|max:255',
            'type' => ['required', Rule::in(\App\Services\AccreditationMessagingService::TYPES)],
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'integer|exists:users,id',
        ]);

        app(\App\Services\AccreditationMessagingService::class)
            ->assertCanCreate($user, $validated['type'], $validated['participant_ids']);

        $conversation = Conversation::create([
            'accreditation_cycle_id' => $validated['accreditation_cycle_id'] ?? null,
            'area_id' => $validated['area_id'] ?? null,
            'parameter_id' => $validated['parameter_id'] ?? null,
            'workspace_id' => $validated['workspace_id'] ?? null,
            'subject' => $validated['subject'],
            'type' => $validated['type'],
            'created_by' => $user->id,
        ]);

        // Add participants
        $participantIds = array_unique(array_merge([$user->id], $validated['participant_ids']));
        $conversation->participants()->sync($participantIds);

        return response()->json([
            'success' => true,
            'message' => 'Conversation created successfully',
            'data' => [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
                'type' => $conversation->type,
            ],
        ], 201);
    }

    /**
     * Mark conversation as read
     */
    public function markAsRead(Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $conversation->markAsReadForUser($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Conversation marked as read',
        ]);
    }

    /**
     * Archive conversation
     */
    public function archiveConversation(Conversation $conversation): JsonResponse
    {
        $user = Auth::user();

        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $conversation->participants()
            ->where('user_id', $user->id)
            ->update(['is_archived' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation archived',
        ]);
    }

    /**
     * Get unread conversation count
     */
    public function getUnreadCount(): JsonResponse
    {
        $user = Auth::user();

        $unreadCount = $user->conversations()
            ->wherePivot('is_archived', false)
            ->get()
            ->reduce(function ($carry, $conversation) use ($user) {
                return $carry + $conversation->getUnreadCountForUser($user->id);
            }, 0);

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Check authorization for conversation creation
     */
    private function authorizeConversationCreation($user, $data): void
    {
        $type = $data['type'];
        $participantCount = count($data['participant_ids']);

        // Different conversation types have different authorization rules
        match ($type) {
            'direct' => $this->checkDirectAuth($user, $data),
            'vpaa_dean' => $this->checkVPAADeanAuth($user),
            'dean_chair' => $this->checkDeanChairAuth($user),
            'chair_faculty' => $this->checkChairFacultyAuth($user),
            'dean_vpaa' => $this->checkDeanVPAAAuth($user),
            'qa_vpaa' => $this->checkQAVPAAAuth($user),
            'vpaa_qa' => $this->checkVPAAQAAuth($user),
            'qa_dean' => $this->checkQADeanAuth($user),
            'dean_qa' => $this->checkDeanQAAuth($user),
            'qa_chair' => $this->checkQAChairAuth($user),
            'chair_qa' => $this->checkChairQAAuth($user),
            default => abort(403, 'Invalid conversation type'),
        };
    }

    private function checkDirectAuth($user, array $data): void
    {
        abort_unless($user, 403, 'You must be signed in to start a conversation.');
        abort_if(in_array($user->id, $data['participant_ids'] ?? [], true), 422, 'You cannot message yourself.');
    }

    private function checkVPAADeanAuth($user): void
    {
        abort_unless($user->isVPAA(), 403, 'Only VPAA can create VPAA-Dean conversations');
    }

    private function checkDeanChairAuth($user): void
    {
        abort_unless($user->isDean() || $user->isProgramChair(), 403, 'Only Deans can create Dean-Chair conversations');
    }

    private function checkChairFacultyAuth($user): void
    {
        abort_unless($user->isProgramChair(), 403, 'Only Program Chairs can create Chair-Faculty conversations');
    }

    private function checkDeanVPAAAuth($user): void
    {
        abort_unless($user->isDean(), 403, 'Only Deans can create Dean-VPAA conversations');
    }

    private function checkQAVPAAAuth($user): void
    {
        abort_unless($user->hasRole('qa'), 403, 'Only QA staff can create QA-VPAA conversations');
    }

    private function checkVPAAQAAuth($user): void
    {
        abort_unless($user->isVPAA(), 403, 'Only VPAA can create VPAA-QA conversations');
    }

    private function checkQADeanAuth($user): void
    {
        abort_unless($user->hasRole('qa'), 403, 'Only QA staff can create QA-Dean conversations');
    }

    private function checkDeanQAAuth($user): void
    {
        abort_unless($user->isDean(), 403, 'Only Deans can create Dean-QA conversations');
    }

    private function checkQAChairAuth($user): void
    {
        abort_unless($user->hasRole('qa'), 403, 'Only QA staff can create QA-Program Chair conversations');
    }

    private function checkChairQAAuth($user): void
    {
        abort_unless($user->isProgramChair(), 403, 'Only Program Chairs can create Program Chair-QA conversations');
    }

    /**
     * Notify participants of new message
     */
    private function notifyParticipants(Conversation $conversation, Message $message, $sender): void
    {
        // Get all participants except sender
        $participants = $conversation->participants()
            ->where('user_id', '!=', $sender->id)
            ->get();

        foreach ($participants as $participant) {
            // Send notification (implement based on your notification system)
            $participant->notify(new \App\Notifications\NewMessageNotification($message, $conversation));
        }
    }
}
