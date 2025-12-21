<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration{
    public function up(): void{
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('message');

            $table->enum('type', ['booking', 'payment', 'event_reminder', 'general']);

            $table->boolean('is_read')->default(false);

            // ERD: text link_url (aku bikin nullable biar notifikasi bisa tanpa link)
            $table->text('link_url')->nullable();

            // Best practice: created_at + updated_at
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};