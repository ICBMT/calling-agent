<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->enum('purpose', ['receptionist', 'support', 'appointments', 'sales', 'custom']);
            $table->string('description', 500)->nullable();
            $table->enum('personality', ['friendly', 'professional', 'empathetic', 'concise'])->default('friendly');
            $table->string('language', 8)->default('en');
            $table->text('instructions');
            $table->string('greeting', 500)->nullable();
            // Publication is deliberately unavailable until voice compatibility checks exist.
            $table->enum('status', ['draft'])->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['incoming', 'outgoing', 'browser']);
            $table->enum('status', ['queued', 'ringing', 'in_progress', 'completed', 'failed', 'missed', 'canceled']);
            $table->string('caller_number', 32)->nullable();
            $table->string('destination_number', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
        Schema::dropIfExists('agents');
    }
};
