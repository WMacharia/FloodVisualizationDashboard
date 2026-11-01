<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On-screen alerts. audience: public (everyone), staff (responders + admins), admin, user (one person)
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                 // system | weather | advisory | place
            $table->string('level', 20);                // info | warning | severe
            $table->string('audience', 20);             // public | staff | admin | user
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('alerts')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->json('areas')->nullable();          // sub-county names the alert applies to
            $table->json('action')->nullable();         // e.g. {"type":"scenario","intensity":20,"duration":2}
            $table->string('dedupe_key')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['audience', 'resolved_at', 'expires_at']);
        });

        Schema::create('alert_reads', function (Blueprint $table) {
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['alert_id', 'user_id']);
        });

        Schema::create('saved_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->string('subcounty')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('summary');
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('saved_places');
        Schema::dropIfExists('alert_reads');
        Schema::dropIfExists('alerts');
    }
};
