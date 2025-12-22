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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller{

    public function __construct(){
        \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
        \Midtrans\Config::$isSanitized = true;
        \Midtrans\Config::$is3ds = true;
        
        // TAMBAHKAN INI: Matikan verifikasi SSL (Biar error 77 hilang)
        \Midtrans\Config::$curlOptions = [
            CURLOPT_SSL_VERIFYPEER => false,
        ];
    }
    
    // 1. ambil user id
    // 2. cek role user
    // 3. ambil konfig dan url midtrans
    // 4. ambil booking yg pending
    // 5. ambil event yg udh published
    // 6. cek siapa tau ada transaksi pending yg ada payment_url
    // 7. hitung nominal
    // 8. Generate order_id unik
    // 9. Buat transaksi dulu (pending)
    // 10. Payload Snap dan Header
    // 11. kirim request ke midtrans snap
    // 12. ambil token dan redirect_url
    // 13. update transaksi dengan token dan url

    public function pay(Request $request, int $id): JsonResponse{
        // 1. ambil user id
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 2. cek role user
        if (($user->role ?? null) !== 'user') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // 3. ambil konfig dan url midtrans
        $serverKey = (string) env('MIDTRANS_SERVER_KEY', '');
        $isProduction = filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN);

        if ($serverKey === '') {
            Log::error('Midtrans server key is not configured (MIDTRANS_SERVER_KEY).');
            return response()->json([
                'message' => 'Payment gateway is not configured.',
            ], 500);
        }

        $snapUrl = $isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        try {
            $result = DB::transaction(function () use ($id, $user, $request, $snapUrl, $serverKey) {
                // 4. ambil booking yg pending
                $booking = Booking::query()
                    ->with('event')
                    ->lockForUpdate()
                    ->find($id);

                
                if (!$booking) {
                    return ['not_found' => true];
                }

                
                if ((int) $booking->user_id !== (int) $user->id) {
                    return ['forbidden' => true];
                }

            
                if (($booking->status ?? null) !== 'pending') {
                    return [
                        'validation' => [
                            'booking' => ['Booking status must be pending to proceed payment.'],
                        ],
                    ];
                }

                // 5. ambil event yg udh published
                $event = $booking->event;
                if (!$event || (($event->status ?? null) !== 'published')) {
                    return [
                        'validation' => [
                            'event' => ['Event is not available for payment.'],
                        ],
                    ];
                }

                // 6. cek siapa tau ada transaksi pending yg ada payment_url
                $existing = Transaction::query()
                    ->where('booking_id', $booking->id)
                    ->where('payment_status', 'pending')
                    ->whereNotNull('payment_url')
                    ->latest()
                    ->first();

                if ($existing) {
                    return [
                        'booking' => $booking,
                        'transaction' => $existing,
                        'reused' => true,
                    ];
                }

                // 7. hitung nominal
                // amount harus sama dengan booking.total_amount (atau grand_total kalau kamu simpan service fee di server)
                $amountDecimal = (float) $booking->total_amount;
                $grossAmount = (int) round($amountDecimal+2000); 


                // 8. Generate order_id unik
                $orderId = null;
                for ($i = 0; $i < 5; $i++) {
                    $candidate = Transaction::generateOrderId();
                    if (!Transaction::query()->where('order_id', $candidate)->exists()) {
                        $orderId = $candidate;
                        break;
                    }
                }
                if (!$orderId) {
                    throw new Exception('Failed to generate unique order_id.');
                }

                // 9. Buat transaksi dulu (pending)
                $transaction = Transaction::create([
                    'order_id' => $orderId,
                    'booking_id' => $booking->id,
                    'amount' => $amountDecimal,
                    'payment_status' => 'pending',
                ]);

                // 10. Payload Snap dan Header
                $payload = [
                    'transaction_details' => [
                        'order_id' => $orderId,
                        'gross_amount' => $grossAmount,
                    ],
                    'customer_details' => [
                        'first_name' => (string) $booking->customer_name,
                        'email' => (string) $booking->customer_email,
                        'phone' => (string) ($booking->customer_phone ?? ''),
                    ],
                    'item_details' => [
                        [
                            'id' => 'EVENT-' . (string) $event->id,
                            'price' => $grossAmount,
                            'quantity' => 1,
                            'name' => (string) ($event->title ?? 'Event Booking'),
                        ],
                    ],
                ];

                $authHeader = 'Basic ' . base64_encode($serverKey . ':');

                // 11. kirim request ke midtrans snap
                $resp = Http::withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => $authHeader,
                    ])
                    ->timeout(20)
                    ->post($snapUrl, $payload);

                $body = $resp->json();

                if (!$resp->successful()) {
                    $transaction->update([
                        'payment_status' => 'failed',
                        'callback_response' => $body ?: ['raw' => $resp->body()],
                    ]);

                    Log::error('Midtrans Snap request failed.', [
                        'order_id' => $orderId,
                        'status' => $resp->status(),
                        'body' => $body,
                    ]);

                    return [
                        'gateway_error' => true,
                    ];
                }

                // 12. ambil token dan redirect_url
                $token = $body['token'] ?? null;
                $redirectUrl = $body['redirect_url'] ?? null;

                if (!$token || !$redirectUrl) {
                    $transaction->update([
                        'payment_status' => 'failed',
                        'callback_response' => $body,
                    ]);

                    Log::error('Midtrans response missing token/redirect_url.', [
                        'order_id' => $orderId,
                        'body' => $body,
                    ]);

                    return [
                        'gateway_error' => true,
                    ];
                }

                // 13. update transaksi dengan token dan url
                $transaction->update([
                    'midtrans_token' => $token,
                    'payment_url' => $redirectUrl,
                    'payment_status' => 'pending',
                    'callback_response' => $body,
                ]);

                return [
                    'booking' => $booking,
                    'transaction' => $transaction->fresh(),
                ];
            });

            if (!empty($result['not_found'])) {
                return response()->json(['message' => 'Booking not found.'], 404);
            }

            if (!empty($result['forbidden'])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            if (!empty($result['validation'])) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $result['validation'],
                ], 422);
            }

            if (!empty($result['gateway_error'])) {
                return response()->json([
                    'message' => 'Failed to create payment session.',
                ], 502);
            }

           
            $booking = $result['booking'];

            $transaction = $result['transaction'];

            return response()->json([
                'data' => [
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'status' => $booking->status,
                        'total_amount' => $this->money((float) $booking->total_amount),
                    ],
                    'transaction' => [
                        'id' => $transaction->id,
                        'order_id' => $transaction->order_id,
                        'amount' => $this->money((float) $transaction->amount),
                        'payment_status' => $transaction->payment_status,
                        'payment_type' => $transaction->payment_type,
                        'midtrans_token' => $transaction->midtrans_token,
                        'payment_url' => $transaction->payment_url,
                        'created_at' => $this->toIsoZulu($transaction->created_at),
                    ],
                ],
                'links' => [
                    'redirect_to_payment' => $transaction->payment_url,
                ],
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to create Midtrans payment.', [
                'booking_id' => $id,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create payment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function toIsoZulu($value): ?string
    {
        if (!$value) return null;

        if ($value instanceof Carbon) {
            return $value->toIso8601ZuluString();
        }

        return Carbon::parse($value)->toIso8601ZuluString();
    }
    
}
