<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            $table->string('ticket_id', 20)->unique(); // "TKT-YYYYMMDD-XXX"
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->text('qr_code'); // base64 encoded QR
            $table->enum('status', ['active', 'used', 'cancelled'])->default('active');

            $table->dateTime('checked_in_at')->nullable();
            $table->string('checked_in_by', 100)->nullable(); // staff name/id

            $table->timestamps();

            $table->index('booking_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
