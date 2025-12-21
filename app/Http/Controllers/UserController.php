<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Booking;

class UserController extends Controller
{
    public function me(Request $request){
        $user = $request->user(); 

        return response()->json([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username ?? $user->name,
                'role' => $user->role,
                'phone' => $user->phone,
                'profile_picture' => $user->profile_picture,
                'created_at' => optional($user->created_at)->toIso8601ZuluString(),
                'updated_at' => optional($user->updated_at)->toIso8601ZuluString(),
            ],
        ], 200);
    }

    public function stats(Request $request){
        $user = $request->user();

        $baseBookings = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'confirmed'); 

        $total = (clone $baseBookings)->distinct('event_id')->count('event_id');

        $upcoming = (clone $baseBookings)
            ->whereHas('event', function ($q) {
                $q->where('start_date', '>', now());
            })
            ->distinct('event_id')
            ->count('event_id');

        $completed = (clone $baseBookings)
            ->whereHas('event', function ($q) {
                $q->where('end_date', '<', now());
            })
            ->distinct('event_id')
            ->count('event_id');

        return response()->json([
            'data' => [
                'total' => $total,
                'upcoming' => $upcoming,
                'completed' => $completed,
            ],
        ], 200);
    }

    public function myEvents(Request $request){
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|max:255',
            'category_id' => 'sometimes|nullable|integer|exists:categories,id',
            'booking_status' => 'sometimes|nullable|in:pending,confirmed,cancelled',
            'payment_status' => 'sometimes|nullable|in:pending,paid,failed,expired',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'sort' => 'sometimes|nullable|in:event_start_asc,event_start_desc',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $search = $request->query('search');
        $categoryId = $request->query('category_id');
        $bookingStatus = $request->query('booking_status');
        $paymentStatus = $request->query('payment_status');

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);

        $sort = $request->query('sort', 'event_start_desc');
        $sortDir = $sort === 'event_start_asc' ? 'asc' : 'desc';

        $query = Booking::query()
            ->with([
                'event.category',
                'event.organizer',
                'latestTransaction',
            ])
            
            ->where('user_id', $user->id);

        if ($bookingStatus) {
            $query->where('status', $bookingStatus);
        }

        if ($search || $categoryId) {
            $query->whereHas('event', function ($q) use ($search, $categoryId) {
                if ($search) {
                    $q->where(function ($qq) use ($search) {
                        $qq->where('title', 'like', '%' . $search . '%')
                           ->orWhere('location', 'like', '%' . $search . '%')
                           ->orWhere('address', 'like', '%' . $search . '%');
                    });
                }

                if ($categoryId) {
                    $q->where('category_id', $categoryId);
                }
            });
        }

        if ($paymentStatus) {
            $query->whereHas('latestTransaction', function ($q) use ($paymentStatus) {
                $q->where('payment_status', $paymentStatus);
            });
        }

        $query->orderBy(
            Event::select('start_date')
                ->whereColumn('events.id', 'bookings.event_id'),
            $sortDir
        );

        $bookings = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($bookings->items())->map(function (Booking $booking) {
            $event = $booking->event;
            $latest = $booking->latestTransaction;

            $uiStatus = match ($booking->status) {
                'confirmed' => 'Registered',
                'pending' => 'Pending',
                'cancelled' => 'Cancelled',
                default => (string) $booking->status,
            };

            $isPast = $event?->end_date ? now()->gt($event->end_date) : false;
            $isUpcoming = $event?->start_date ? now()->lt($event->start_date) : false;

            return [
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'status' => $booking->status,
                    'quantity' => (int) $booking->quantity,
                    'total_amount' => (string) $booking->total_amount,
                    'customer_name' => $booking->customer_name,
                    'customer_email' => $booking->customer_email,
                    'customer_phone' => $booking->customer_phone,
                    'gender' => $booking->gender,
                    'created_at' => optional($booking->created_at)->toIso8601ZuluString(),
                ],
                'event' => $event ? [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'category' => $event->category ? [
                        'id' => $event->category->id,
                        'name' => $event->category->name,
                    ] : null,
                    'start_date' => optional($event->start_date)->toIso8601ZuluString(),
                    'end_date' => optional($event->end_date)->toIso8601ZuluString(),
                    'location' => $event->location,
                    'address' => $event->address,
                    'price' => (string) $event->price,
                    'image_url' => $event->image_url,
                    'status' => $event->status,
                    'organizer' => $event->organizer ? [
                        'id' => $event->organizer->id,
                        'username' => $event->organizer->username,
                    ] : null,
                ] : null,
                'payment' => [
                    'latest' => $latest ? [
                        'id' => $latest->id,
                        'order_id' => $latest->order_id,
                        'payment_status' => $latest->payment_status,
                        'payment_type' => $latest->payment_type,
                        'payment_url' => $latest->payment_url,
                        'paid_at' => optional($latest->paid_at)->toIso8601ZuluString(),
                        'created_at' => optional($latest->created_at)->toIso8601ZuluString(),
                    ] : null,
                ],
                'ui' => [
                    'ui_status' => $uiStatus,
                    'is_past' => (bool) $isPast,
                    'is_upcoming' => (bool) $isUpcoming,
                ],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'total_pages' => $bookings->lastPage(),
            ],
        ], 200);
    }
}
