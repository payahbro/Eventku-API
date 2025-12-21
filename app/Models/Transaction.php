<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model{
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'order_id',
        'booking_id',
        'amount',
        'payment_status',
        'payment_type',
        'midtrans_transaction_id',
        'midtrans_token',
        'signature_key',
        'payment_url',
        'paid_at',
        'callback_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'callback_response' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * Generate unique order ID
     */
    public static function generateOrderId(): string
    {
        $date = now()->format('Ymd');
        $lastTransaction = self::whereDate('created_at', today())->latest()->first();
        $sequence = $lastTransaction ? intval(substr($lastTransaction->order_id, -3)) + 1 : 1;
        return 'ORDER-' . $date . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
