<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Tickets;
use App\Models\Transaction;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransCallbackController extends Controller{

    public function handle(Request $request): JsonResponse{
        $payload = $request->all();

        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $paymentType = $payload['payment_type'] ?? null;
        $midtransTransactionId = $payload['transaction_id'] ?? null;
        $signatureKey = (string) ($payload['signature_key'] ?? '');

        $serverKey = (string) config('services.midtrans.server_key', '');
        if ($serverKey === '') {
            Log::error('Midtrans server key not configured (services.midtrans.server_key).');
            return response()->json(['message' => 'OK'], 200);
        }

        $computed = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        if (!$signatureKey || !hash_equals($computed, $signatureKey)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        try {
            DB::transaction(function () use (
                $payload,
                $orderId,
                $grossAmount,
                $transactionStatus,
                $paymentType,
                $midtransTransactionId,
                $signatureKey
            ) {
                // Lock transaksi agar idempotent & aman dari callback ulang bersamaan
                $transaction = Transaction::query()
                    ->with('booking')
                    ->where('order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (!$transaction) {
                    Log::warning('Midtrans callback received but transaction not found.', [
                        'order_id' => $orderId,
                        'payload' => $payload,
                    ]);
                    return; // tetap OK
                }

                $booking = $transaction->booking;

                if (!$booking) {
                    Log::warning('Midtrans callback received but booking not found for transaction.', [
                        'order_id' => $orderId,
                        'transaction_id' => $transaction->id,
                        'payload' => $payload,
                    ]);
                    return; // tetap OK
                }

                // Validasi gross_amount (catatan: kamu pakai service fee +2000 per transaksi)
                $expectedGross = ((float) $booking->total_amount) + 2000.0;
                $expectedGrossStr = number_format($expectedGross, 2, '.', '');

                if ($grossAmount !== $expectedGrossStr) {
                    Log::error('Midtrans gross_amount mismatch.', [
                        'order_id' => $orderId,
                        'expected' => $expectedGrossStr,
                        'got' => $grossAmount,
                        'booking_id' => $booking->id,
                    ]);

                    // Jangan confirm booking / generate ticket kalau mismatch
                    $transaction->update([
                        'payment_status' => 'failed',
                        'signature_key' => $signatureKey,
                        'payment_type' => $paymentType,
                        'midtrans_transaction_id' => $midtransTransactionId,
                        'callback_response' => $payload,
                    ]);
                    return;
                }

                // Map status Midtrans -> status internal
                $newPaymentStatus = match ($transactionStatus) {
                    'settlement', 'capture' => 'paid',
                    'pending' => 'pending',
                    'expire', 'expired' => 'expired',
                    'deny', 'cancel', 'failure' => 'failed',
                    default => 'pending',
                };

                // Idempotency: kalau sudah paid, jangan ubah paid_at mundur/duplikat tiket
                $alreadyPaid = ($transaction->payment_status === 'paid') && !empty($transaction->paid_at);

                $update = [
                    'payment_status' => $newPaymentStatus,
                    'payment_type' => $paymentType,
                    'midtrans_transaction_id' => $midtransTransactionId,
                    'signature_key' => $signatureKey,
                    'callback_response' => $payload,
                    // Simpan amount sebagai total transaksi (include fee) biar konsisten laporan revenue
                    'amount' => (float) $expectedGross,
                ];

                if ($newPaymentStatus === 'paid' && !$alreadyPaid) {
                    $update['paid_at'] = now();
                }

                $transaction->update($update);

                // Kalau sukses: confirm booking + generate ticket (idempotent)
                if ($newPaymentStatus === 'paid') {
                    if ($booking->status !== 'confirmed') {
                        $booking->update(['status' => 'confirmed']);
                    }

                    // Generate tickets idempotent: buat hanya yang kurang
                    $qty = (int) $booking->quantity;
                    $existing = Tickets::query()
                        ->where('booking_id', $booking->id)
                        ->lockForUpdate()
                        ->count();

                    $missing = max(0, $qty - $existing);
                    if ($missing <= 0) return;

                    $date = now()->format('Ymd');
                    $bookingSeq = substr((string) $booking->booking_id, -3); // "001" dari "BKG-YYYYMMDD-001"

                    for ($i = 1; $i <= $missing; $i++) {
                        // ticket_id max 20 char, contoh: TKT-20251221-00101
                        $ticketId = 'TKT-' . $date . '-' . $bookingSeq . str_pad((string) ($existing + $i), 2, '0', STR_PAD_LEFT);

                        Tickets::create([
                            'ticket_id' => $ticketId,
                            'booking_id' => $booking->id,
                            // TODO: kalau mau QR image beneran, pakai library QR lalu base64 image-nya
                            'qr_code' => base64_encode($ticketId),
                            'status' => Tickets::STATUS_ACTIVE,
                        ]);
                    }
                }
            });

            return response()->json(['message' => 'OK'], 200);

        } catch (Exception $e) {
            Log::error('Midtrans callback processing failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload,
            ]);

            // Tetap 200 supaya Midtrans tidak retry terus (opsional).
            return response()->json(['message' => 'OK'], 200);
        }
    }
}