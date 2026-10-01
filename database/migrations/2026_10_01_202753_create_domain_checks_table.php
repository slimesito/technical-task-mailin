<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_check_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('input');
            $table->string('domain');
            $table->string('dkim_selector')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);

            // Blacklist tool
            $table->string('blacklist_status', 20)->nullable();
            $table->jsonb('listed_on')->nullable();

            // Provider tool
            $table->string('provider', 30)->nullable();
            $table->string('provider_status', 20)->nullable();
            $table->jsonb('evidence')->nullable();

            // Shared DNS data
            $table->jsonb('mx_records')->nullable();
            $table->jsonb('dns_records')->nullable();
            $table->jsonb('errors')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['bulk_check_id', 'status']);
            $table->index(['bulk_check_id', 'blacklist_status']);
            $table->index(['bulk_check_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_checks');
    }
};
