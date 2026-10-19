<?php

namespace App\Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Modules\WhatsApp\Resources\ConversationResource;
use App\Modules\WhatsApp\Resources\MessageResource;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InboxController extends Controller
{
    public function __construct(private readonly WhatsAppMessageService $messages) {}

    /**
     * Agents (no conversations.assign) only work the chats assigned to them;
     * owners/managers, who can hand chats out, see every conversation.
     */
    private function seesOnlyAssigned(): bool
    {
        $user = auth()->user();

        return $user && ! $user->is_super_admin && ! $user->can('conversations.assign');
    }

    /** Block an agent from reaching a conversation that is not theirs. */
    private function guardConversationAccess(Conversation $conversation): void
    {
        if ($this->seesOnlyAssigned() && $conversation->assigned_agent_id !== auth()->id()) {
            abort(403, 'This conversation is not assigned to you.');
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('conversations.view');

        $query = Conversation::with(['contact', 'phoneNumber', 'assignedAgent'])
            // AI-selected hot leads first (highest priority score on top), then newest chats.
            ->orderByRaw('(SELECT ' . Contact::hotPrioritySql('hc') . ' FROM contacts hc WHERE hc.id = conversations.contact_id LIMIT 1) DESC')
            ->orderByDesc('last_message_at');

        if ($this->seesOnlyAssigned()) {
            $query->where('assigned_agent_id', auth()->id());
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $safe = '%' . $this->escapeLike($search) . '%';
            $query->whereHas('contact', fn ($q) => $q->where('name', 'like', $safe)
                ->orWhere('phone', 'like', $safe)
                ->orWhere('wa_id', 'like', $safe));
        }

        $conversations = $query->paginate($request->integer('per_page', 25));

        return $this->ok([
            'conversations' => ConversationResource::collection($conversations),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function messages(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('conversations.view');

        $conversation = Conversation::where('uuid', $conversationUuid)->firstOrFail();
        $this->guardConversationAccess($conversation);

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->orderByDesc('created_at')
            ->cursorPaginate($request->integer('per_page', 50));

        return $this->ok([
            'messages' => MessageResource::collection($messages),
            'next_cursor' => $messages->nextCursor()?->encode(),
            'has_more' => $messages->hasMorePages(),
        ]);
    }

    public function markRead(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('conversations.view');

        $conversation = Conversation::where('uuid', $conversationUuid)->firstOrFail();
        $this->guardConversationAccess($conversation);
        $conversation->update(['unread_count' => 0]);

        return $this->ok(['message' => 'Conversation marked as read.']);
    }

    public function send(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('conversations.reply');

        $conversation = Conversation::where('uuid', $conversationUuid)
            ->with(['contact', 'phoneNumber'])
            ->firstOrFail();
        $this->guardConversationAccess($conversation);

        $data = $request->validate([
            'type' => ['nullable', 'in:text,template'],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'template' => ['required_if:type,template', 'nullable', 'string'],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        $type = $data['type'] ?? 'text';
        $phone = $conversation->phoneNumber;
        $to = $conversation->contact->wa_id;

        if ($type === 'text') {
            if (! $conversation->windowIsOpen()) {
                return $this->fail('The 24-hour messaging window has expired. Send a template message to re-open the conversation.', 422);
            }
            $result = $this->messages->sendText($phone, $to, $data['body']);
        } else {
            $result = $this->messages->sendTemplate(
                $phone, $to,
                $data['template'],
                $data['language'] ?? 'en',
            );
        }

        $wamid = $this->messages->wamid($result);

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_phone_number_id' => $phone->id,
            'sender_user_id' => $request->user()->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $type === 'template' ? 'template' : 'text',
            'body' => $data['body']
                ?? (\App\Models\Template::bodyText($conversation->tenant_id, $data['template'] ?? '', $data['language'] ?? null)
                    ?: '📄 Template: ' . ($data['template'] ?? '')),
            'external_message_id' => $wamid,
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => $result,
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($message->body, 100),
        ]);

        return $this->ok(['message' => new MessageResource($message->load(['sender', 'attachments']))]);
    }

    private const MEDIA_TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'video' => ['video/mp4', 'video/3gpp'],
        'audio' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus'],
        'document' => [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv',
            'application/zip', 'application/x-zip-compressed',
        ],
    ];

    public function sendMedia(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('conversations.reply');

        $conversation = Conversation::where('uuid', $conversationUuid)
            ->with(['contact', 'phoneNumber'])
            ->firstOrFail();
        $this->guardConversationAccess($conversation);

        if (! $conversation->windowIsOpen()) {
            return $this->fail('The 24-hour messaging window has expired. Send a template message to re-open the conversation.', 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:16384'],
            'media_type' => ['required', 'in:image,video,audio,document'],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        $file = $request->file('file');
        $mediaType = $request->input('media_type');
        $caption = $request->input('caption');
        $mime = $file->getMimeType();

        $allowedMimes = self::MEDIA_TYPES[$mediaType];
        if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
            return $this->fail("Invalid file type for {$mediaType}. Got: {$mime}", 422);
        }

        $phone = $conversation->phoneNumber;
        $to = $conversation->contact->wa_id;

        $storedPath = $file->store("media/{$conversation->tenant_id}", 'local');

        $result = $this->messages->sendMedia(
            $phone,
            $to,
            $mediaType,
            Storage::disk('local')->path($storedPath),
            $mime,
            $caption,
            $file->getClientOriginalName(),
        );

        $wamid = $this->messages->wamid($result);
        $bodyPreview = $caption ?: "[" . ucfirst($mediaType) . "]";

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_phone_number_id' => $phone->id,
            'sender_user_id' => $request->user()->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $mediaType,
            'body' => $bodyPreview,
            'external_message_id' => $wamid,
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => $result,
        ]);

        MessageAttachment::create([
            'tenant_id' => $conversation->tenant_id,
            'message_id' => $message->id,
            'type' => $mediaType,
            'meta_media_id' => $result['messages'][0]['id'] ?? null,
            'mime_type' => $mime,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'storage_disk' => 'local',
            'storage_path' => $storedPath,
            'caption' => $caption,
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($bodyPreview, 100),
        ]);

        return $this->ok(['message' => new MessageResource($message->load(['sender', 'attachments']))]);
    }

    public function deleteMessage(string $conversationUuid, string $messageUuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $conversation = Conversation::where('uuid', $conversationUuid)->firstOrFail();
        $this->guardConversationAccess($conversation);
        $message = $conversation->messages()->where('uuid', $messageUuid)->firstOrFail();

        $message->attachments()->delete();
        $message->delete();

        return $this->ok(['message' => 'Message deleted.']);
    }

    public function media(string $uuid)
    {
        $attachment = MessageAttachment::where('uuid', $uuid)->firstOrFail();

        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk($attachment->storage_disk)->response(
            $attachment->storage_path,
            $attachment->file_name,
            ['Content-Type' => $attachment->mime_type]
        );
    }
}