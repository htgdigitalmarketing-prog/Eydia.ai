<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the friendly label onto WhatsApp messages that arrived as Meta's
 * "unsupported" type BEFORE the label change — they were stored with a null body
 * (rendered as a raw "[unsupported]"). Also fix the matching conversation
 * previews. One-time data fix; the webhook now writes the label for new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $label = '⚠️ Unsupported message (view-once, poll, or an unsupported format)';

        DB::table('messages')
            ->where('type', 'unsupported')
            ->where(function ($q) {
                $q->whereNull('body')->orWhere('body', '[unsupported]');
            })
            ->update(['body' => $label]);

        DB::table('conversations')
            ->where('last_message_preview', '[unsupported]')
            ->update(['last_message_preview' => $label]);
    }

    public function down(): void
    {
        // Non-reversible content fix — nothing to roll back.
    }
};
