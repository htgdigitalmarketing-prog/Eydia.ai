<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * SocialConnection.page_access_token gained an `encrypted` cast; rows saved
 * before that hold plaintext and would throw on read. Encrypt them in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('social_connections')
            ->whereNotNull('page_access_token')
            ->orderBy('id')
            ->each(function ($row) {
                try {
                    Crypt::decryptString($row->page_access_token);
                } catch (DecryptException) {
                    DB::table('social_connections')
                        ->where('id', $row->id)
                        ->update(['page_access_token' => Crypt::encryptString($row->page_access_token)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible on purpose: never write tokens back as plaintext.
    }
};
