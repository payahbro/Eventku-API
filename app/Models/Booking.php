<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model{
    use HasFactory;

    protected $table = 'bookings';

    protected $fillable = [
        'booking_id',
        'event_id',
        'user_id',
        'quantity',
        'total_amount',
        'customer_name',
        'customer_email',
        'customer_phone',
        'gender',
        'status',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // UBAH: dari hasOne() ke hasMany()
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'booking_id');
    }

    // OPSIONAL: akses cepat transaksi paling baru (berguna untuk status pembayaran terakhir)
    public function latestTransaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'booking_id')->latestOfMany();
    }

    /**
     * Generate unique booking ID
     */
    public static function generateBookingId(): string
    {
        $date = now()->format('Ymd');
        $lastBooking = self::whereDate('created_at', today())->latest()->first();
        $sequence = $lastBooking ? intval(substr($lastBooking->booking_id, -3)) + 1 : 1;
        return 'BKG-' . $date . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}