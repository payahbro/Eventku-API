<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Event;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    // 1. ambil user id
    // 2. validasi input
    // 3. cek event ada & published
    // 4. cek ketersediaan tiket
    // 5. hitung total biaya
    // 6. generate booking_id unik
    // 7. simpan ke tabel bookings
    // 8. kurangi stok tiket di tabel events

    // transaction DB: langkah 3-8

    public function store(Request $request): JsonResponse{

        // 1 ambil user id
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (($user->role ?? null) !== 'user') {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        // 2 validasi input
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email:rfc,dns|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'gender' => 'nullable|in:Laki-Laki,Perempuan',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $eventId = (int) $request->input('event_id');
        $qty = (int) $request->input('quantity');

        try {
            $result = DB::transaction(function () use ($eventId, $qty, $request, $user) {
                // 3 cek event ada & published
                $event = Event::query()
                    ->whereKey($eventId)
                    ->lockForUpdate()
                    ->first();

                if (!$event) {
                    return ['not_found' => true];
                }

                if (($event->status ?? null) !== 'published') {
                    return [
                        'validation' => [
                            'quantity' => ['Event is not available for booking.'],
                        ],
                    ];
                }

                // 4 cek ketersediaan tiket
                $available = (int) ($event->available_tickets ?? 0);

                // available_tickets gk boleh > max_participants
                $maxParticipants = (int) ($event->max_participants ?? 0);
                if ($maxParticipants > 0 && $available > $maxParticipants) {
                    $available = $maxParticipants;
                }

                if ($qty > $available) {
                    return [
                        'validation' => [
                            'quantity' => ["Not enough tickets available. Available: {$available}."],
                        ],
                    ];
                }

                // 5 hitung total biaya
                $unitPrice = (float) ($event->price ?? 0);
                $subtotal = $unitPrice * $qty;

                $serviceFee = 2000.00;
                $grandTotal = $subtotal + $serviceFee;

                // 6 generate booking_id unik
                $bookingId = null;
                for ($i = 0; $i < 5; $i++) {
                    $candidate = Booking::generateBookingId();
                    $exists = Booking::query()->where('booking_id', $candidate)->exists();
                    if (!$exists) {
                        $bookingId = $candidate;
                        break;
                    }
                }
                if (!$bookingId) {
                    throw new Exception('Failed to generate unique booking_id.');
                }

                // 7 simpan ke tabel bookings
                $booking = Booking::create([
                    'booking_id' => $bookingId,
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'quantity' => $qty,
                    'total_amount' => $subtotal,
                    'customer_name' => $request->input('customer_name'),
                    'customer_email' => $request->input('customer_email'),
                    'customer_phone' => $request->input('customer_phone'),
                    'gender' => $request->input('gender'),
                    'status' => 'pending',
                ]);

                // 8 kurangi stok tiket di tabel events
                $event->available_tickets = $available - $qty;
                $event->save();

                return [
                    'booking' => $booking,
                    'event' => $event->fresh(),
                    'pricing' => [
                        'unit_price' => $this->money($unitPrice),
                        'subtotal' => $this->money($subtotal),
                        'service_fee' => $this->money($serviceFee),
                        'grand_total' => $this->money($grandTotal),
                    ],
                ];
            });

            if (!empty($result['not_found'])) {
                return response()->json(['message' => 'Event not found.'], 404);
            }

            if (!empty($result['validation'])) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $result['validation'],
                ], 422);
            }

            
            $booking = $result['booking'];
       
            $event = $result['event'];

            return response()->json([
                'data' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'status' => $booking->status,
                    'quantity' => (int) $booking->quantity,
                    'total_amount' => $this->money((float) $booking->total_amount),
                    'customer_name' => $booking->customer_name,
                    'customer_email' => $booking->customer_email,
                    'customer_phone' => $booking->customer_phone,
                    'gender' => $booking->gender,
                    'event' => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'start_date' => $this->toIsoZulu($event->start_date ?? null),
                        'end_date' => $this->toIsoZulu($event->end_date ?? null),
                        'location' => $event->location,
                        'address' => $event->address,
                        'price' => $this->money((float) ($event->price ?? 0)),
                    ],
                    'pricing' => $result['pricing'],
                ],
                'links' => [
                    'pay' => '/api/bookings/' . $booking->id . '/pay',
                ],
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to create booking: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create booking',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    public function myBookings(Request $request): JsonResponse{
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (($user->role ?? null) !== 'user') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $bookings = Booking::query()
            ->where('user_id', $user->id)
            ->with([
                'event',
                'latestTransaction',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'message' => 'My bookings retrieved successfully',
            'data' => $bookings,
        ]);
    }

    public function getBookingById(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (($user->role ?? null) !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $booking = Booking::query()
            ->whereKey($id)
            ->with([
                'event',
                'latestTransaction',
                'user', 
            ])
            ->first();

        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Booking retrieved successfully',
            'data' => $booking,
        ]);
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