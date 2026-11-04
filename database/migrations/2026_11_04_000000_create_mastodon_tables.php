<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('mastodon.tables');

        Schema::create($tables['apps'], function (Blueprint $table) {
            $table->id();
            $table->string('instance_url');
            $table->text('client_id');
            $table->text('client_secret');
            $table->json('scopes');
            $table->string('redirect_uri', 512);
            $table->timestamp('client_secret_expires_at')->nullable();
            $table->string('instance_version')->nullable();
            $table->timestamps();

            $table->unique(['instance_url', 'redirect_uri']);
        });

        Schema::create($tables['accounts'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('mastodon_app_id')->constrained($tables['apps'])->cascadeOnDelete();
            $table->nullableMorphs('owner');
            $table->string('remote_id');
            $table->string('acct');
            $table->string('username');
            $table->string('display_name')->nullable();
            $table->string('url', 512)->nullable();
            $table->text('access_token');
            $table->json('scopes');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['mastodon_app_id', 'remote_id']);
        });

        Schema::create($tables['posts'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('mastodon_account_id')->constrained($tables['accounts'])->cascadeOnDelete();
            $table->nullableMorphs('owner');
            $table->string('idempotency_key')->unique();
            $table->string('state', 20)->default('pending')->index();
            $table->string('remote_status_id')->nullable();
            $table->string('remote_scheduled_id')->nullable();
            $table->string('url', 512)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tables = config('mastodon.tables');

        Schema::dropIfExists($tables['posts']);
        Schema::dropIfExists($tables['accounts']);
        Schema::dropIfExists($tables['apps']);
    }
};
