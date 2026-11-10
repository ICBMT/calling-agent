<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::table('audit_logs', fn (Blueprint $table) => $table->foreignId('organization_id')->nullable()->change());

        Schema::create('provider_scopes', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::create('provider_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20)->default('organization');
            $table->string('provider', 30);
            $table->string('capability', 20);
            $table->string('name', 100);
            $table->text('api_key')->nullable(); // Laravel authenticated encryption; never plaintext.
            $table->string('project_id', 100)->nullable();
            $table->string('provider_organization', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('credential_version')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->string('selected_model', 200)->nullable();
            $table->string('selected_voice', 200)->nullable();
            // Unique across scope + tenant + capability + role; NULL permits unassigned configs.
            $table->string('routing_key', 160)->nullable()->unique();
            $table->string('routing_role', 20)->default('none');
            $table->string('connection_status', 30)->default('not_tested');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('tested_version')->nullable();
            $table->uuid('test_token')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->string('last_message', 500)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->json('catalog')->nullable();
            $table->json('usage_snapshot')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();
            $table->index(['scope', 'organization_id', 'capability']);
        });

        Schema::create('provider_connection_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('credential_version');
            $table->string('outcome', 30);
            $table->string('code', 50);
            $table->string('message', 500);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('model_count')->default(0);
            $table->boolean('applied')->default(true);
            $table->timestamp('created_at');
            $table->index(['provider_configuration_id', 'created_at'], 'provider_test_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connection_tests');
        Schema::dropIfExists('provider_configurations');
        Schema::dropIfExists('provider_scopes');
        DB::table('audit_logs')->whereNull('organization_id')->delete();
        Schema::table('audit_logs', fn (Blueprint $table) => $table->foreignId('organization_id')->nullable(false)->change());
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
    }
};
