<?php

use App\Enums\SyncStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('calendar_id');
            $table->string('external_event_id')->unique();
            $table->string('sync_status', 20)->default(SyncStatus::Pending->value);
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index(['calendar_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
