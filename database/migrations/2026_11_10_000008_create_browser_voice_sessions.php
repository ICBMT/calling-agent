<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_configuration_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('credential_version');
            $table->unsignedInteger('configuration_revision');
            $table->string('architecture', 30)->default('native_realtime');
            $table->string('state', 30)->default('provisioning');
            // One active slot per actor per tenant; terminal sessions release the unique slot.
            $table->string('active_user_key', 100)->nullable()->unique();
            $table->string('room_name', 70)->unique();
            $table->string('participant_identity', 80);
            $table->string('worker_identity', 80);
            $table->string('dispatch_id', 100)->nullable();
            $table->string('worker_job_id', 100)->nullable();
            $table->text('configuration_snapshot')->nullable(); // Encrypted; no permanent key copied here.
            $table->char('claim_hash', 64)->nullable();
            $table->char('lease_hash', 64)->nullable();
            $table->timestamp('claim_expires_at');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->boolean('cleanup_pending')->default(true);
            $table->timestamp('room_deleted_at')->nullable();
            $table->unsignedInteger('event_sequence')->default(0);
            $table->json('metrics')->nullable(); // Bounded, numeric allowlist; never full transcripts/audio.
            $table->timestamps();
            $table->index(['organization_id', 'state', 'expires_at']);
            $table->index(['state', 'heartbeat_at']);
        });

        Schema::create('voice_worker_nonces', function (Blueprint $table): void {
            $table->char('hash', 64)->primary();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_worker_nonces');
        Schema::dropIfExists('voice_sessions');
    }
};
