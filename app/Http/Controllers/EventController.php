<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Categories;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage; 
use Exception;

class EventController extends Controller{

    // GET ALL
    public function index(Request $request): JsonResponse{
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please login first.',
                'data' => null,
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|nullable|in:draft,published,cancelled,completed',
            'category_id' => 'sometimes|nullable|integer|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $page = (int) ($request->query('page', 1));
        $perPage = (int) ($request->query('per_page', 10));
        $search = $request->query('search');
        $status = $request->query('status');
        $categoryId = $request->query('category_id');

        $query = Event::query()
            ->with(['category', 'organizer'])
            ->withSum([
                'bookings as current_participants' => function ($q) {
                    $q->whereIn('status', ['pending', 'confirmed']);
                }
            ], 'quantity')
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $events = $query->paginate($perPage, ['*'], 'page', $page);

        $mapped = collect($events->items())->map(function (Event $event) {
            $currentParticipants = (int) ($event->current_participants ?? 0);
            $maxParticipants = (int) ($event->max_participants ?? 0);
            $availableTickets = max(0, $maxParticipants - $currentParticipants);

            return [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'what_you_will_get' => $event->what_you_will_get,
                'category' => $event->category ? [
                    'id' => $event->category->id,
                    'name' => $event->category->name,
                    'icon' => $event->category->icon,
                ] : null,
                'start_date' => $this->toIsoLocal($event->start_date),
                'end_date' => $this->toIsoLocal($event->end_date),
                'location' => $event->location,
                'address' => $event->address,
                'price' => (float) $event->price,
                'max_participants' => $maxParticipants,
                'available_tickets' => $availableTickets,
                'current_participants' => $currentParticipants,
                'image_url' => $event->image_url,
                'organizer' => $event->organizer ? [
                    'id' => $event->organizer->id,
                    'username' => $event->organizer->username ?? $event->organizer->name ?? null,
                    'email' => $event->organizer->email,
                ] : null,
                'is_featured' => (bool) $event->is_featured,
                'status' => $event->status,
                'display_status' => $this->displayStatusForEvent($event),
                'created_at' => $event->created_at?->toISOString(),
                'updated_at' => $event->updated_at?->toISOString(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Events retrieved successfully',
            'data' => [
                'events' => $mapped,
                'pagination' => [
                    'current_page' => $events->currentPage(),
                    'per_page' => $events->perPage(),
                    'total_items' => $events->total(),
                    'total_pages' => $events->lastPage(),
                    'has_next' => $events->hasMorePages(),
                    'has_prev' => $events->currentPage() > 1,
                ],
            ],
        ], 200);
    }

    private function displayStatusForEvent(Event $event): string
    {
        $status = $event->status;

        if ($status === 'published') {
            $start = $event->start_date ? Carbon::parse($event->start_date) : null;
            if ($start && now()->lt($start)) return 'Upcoming';
            return 'Active';
        }

        return match ($status) {
            'draft' => 'Draft',
            'cancelled' => 'Cancelled',
            'completed' => 'Completed',
            default => 'Unknown',
        };
    }


    // GET
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                    'data' => null,
                ], 401);
            }

            $event = Event::query()
                ->with(['category', 'organizer'])
                ->findOrFail($id);

            // qty total peserta (pending + confirmed)
            $currentParticipants = (int) $event->bookings()
                ->whereIn('status', ['pending', 'confirmed'])
                ->sum('quantity');

            $maxParticipants = (int) ($event->max_participants ?? 0);
            $availableTickets = max(0, $maxParticipants - $currentParticipants);

            return response()->json([
                'success' => true,
                'message' => 'Event retrieved successfully',
                'data' => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'what_you_will_get' => $event->what_you_will_get, 
                    'category' => $event->category ? [
                        'id' => $event->category->id,
                        'name' => $event->category->name,
                        'icon' => $event->category->icon,
                    ] : null,
                    'start_date' => $this->toIsoLocal($event->start_date),
                    'end_date' => $this->toIsoLocal($event->end_date),
                    'location' => $event->location,
                    'address' => $event->address,
                    'price' => (float) $event->price,
                    'max_participants' => $maxParticipants,
                    'available_tickets' => $availableTickets,
                    'current_participants' => $currentParticipants,
                    'image_url' => $event->image_url,
                    'organizer' => $event->organizer ? [
                        'id' => $event->organizer->id,
                        'username' => $event->organizer->username ?? $event->organizer->name ?? null,
                        'email' => $event->organizer->email,
                        'phone' => $event->organizer->phone ?? null,
                        'profile_picture' => $event->organizer->profile_picture ?? null,
                    ] : null,
                    'is_featured' => (bool) $event->is_featured,
                    'status' => $event->status,
                    'display_status' => $this->displayStatusForEvent($event),
                    'created_at' => $event->created_at?->toISOString(),
                    'updated_at' => $event->updated_at?->toISOString(),
                ],
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
                'data' => null,
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to retrieve event detail: ' . $e->getMessage(), [
                'event_id' => $id,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve event',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    // CREATE
    public function store(Request $request): JsonResponse
    {
        try {
            // 1. Validasi Input
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'what_you_will_get' => 'sometimes|nullable|string',
                'category_id' => 'required|integer|exists:categories,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'location' => 'required|string|max:255',
                'address' => 'nullable|string',
                'price' => 'nullable|numeric|min:0',
                'max_participants' => 'required|integer|min:1',
                'status' => 'sometimes|nullable|in:draft,published,cancelled,completed',
                'is_featured' => 'nullable|boolean',
                
                // Logic Validasi Hybrid:
                // image: wajib file gambar (jpeg,png,dll) max 2MB
                'image' => 'sometimes|file|image|mimes:jpeg,png,jpg,gif|max:2048',
                // image_url: opsional string url biasa
                'image_url' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 2. Logic Hybrid Image (Link vs Upload)
            // Default: Ambil dari input link (image_url)
            $imageUrl = $request->image_url; 

            // Prioritas: Jika ada file yang diupload, kita timpa $imageUrl
            if ($request->hasFile('image')) {
                // Simpan ke storage/app/public/events
                $path = $request->file('image')->store('events', 'public');
                
                // Generate URL publik (misal: /storage/events/namafile.jpg)
                $imageUrl = Storage::url($path); 
            }

            // 3. Simpan ke Database
            $event = Event::create([
                'title' => $request->title,
                'description' => $request->description,
                'what_you_will_get' => $request->what_you_will_get,
                'category_id' => $request->category_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'location' => $request->location,
                'address' => $request->address,
                'price' => $request->price ?? 0,
                'max_participants' => $request->max_participants,
                'available_tickets' => $request->max_participants, // Biasanya sama dengan max saat dibuat
                
                // PENTING: Gunakan variabel $imageUrl hasil logic di atas
                'image_url' => $imageUrl, 
                
                // Gunakan auth()->id() untuk mengambil ID user yang sedang login
                // Fallback ke 1 jika null (hanya untuk dev, sebaiknya dihapus saat production)
                'organizer_id' => auth()->id ?? 1, 
                'is_featured' => $request->is_featured ?? false,
                'status' => $request->status ?? 'draft',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Event created successfully',
                'data' => $event->load('category', 'organizer')
            ], 201);

        } catch (Exception $e) {
            // Log error untuk debugging developer
            Log::error('Failed to create event: ' . $e->getMessage(), [
                'request' => $request->except(['image']), // Jangan log file binary agar log tidak penuh
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create event',
                // Tampilkan error asli hanya jika mode debug nyala (aman untuk production)
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


    // DELETE
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user(); 

            $event = Event::query()->findOrFail($id);

            $isAdmin = ($user->role ?? null) === 'admin';
            $isOwner = (int) $event->organizer_id === (int) $user->id;

            if (!($isAdmin || $isOwner)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to delete this event',
                    'data' => null,
                ], 403);
            }

            $totalBookings = $event->bookings()->count();

            if ($totalBookings > 0) {
                $confirmed = $event->bookings()->where('status', 'confirmed')->count();
                $pending   = $event->bookings()->where('status', 'pending')->count();

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete event with existing bookings. Please cancel all bookings first or change event status to cancelled.',
                    'data' => [
                        'total_bookings' => $totalBookings,
                        'confirmed_bookings' => $confirmed,
                        'pending_bookings' => $pending,
                    ],
                ], 400);
            }

           
            $event->delete();
            $event->refresh(); 

            return response()->json([
                'success' => true,
                'message' => 'Event deleted successfully',
                'data' => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'deleted_at' => $event->deleted_at ? $event->deleted_at->toISOString() : now()->toISOString(),
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
                'data' => null,
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to delete event: ' . $e->getMessage(), [
                'event_id' => $id,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete event',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    // UPDATE
    public function update(Request $request, int $id): JsonResponse{
        try {
            $user = $request->user();

            $event = Event::query()->findOrFail($id);

            $isAdmin = ($user->role ?? null) === 'admin';
            $isOwner = (int) $event->organizer_id === (int) $user->id;

            if (!($isAdmin || $isOwner)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to update this event',
                    'data' => null,
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|nullable|string',
                'category_id' => 'sometimes|required|integer|exists:categories,id',
                'start_date' => 'sometimes|required|date',
                'end_date' => 'sometimes|required|date|after_or_equal:start_date',
                'location' => 'sometimes|required|string|max:255',
                'address' => 'sometimes|nullable|string',
                'price' => 'sometimes|nullable|numeric|min:0',
                'max_participants' => 'sometimes|required|integer|min:1',
                'status' => 'sometimes|nullable|in:draft,published,cancelled,completed',
                'is_featured' => 'sometimes|boolean',

                'image' => 'sometimes|file|image|max:2048', 
                'image_url' => 'sometimes|nullable|string', 
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // pake agregate function sum yang di SQL, jadi yg dihitung tuh qty nya bukan rownya
            $currentParticipants = (int) $event->bookings()
                ->whereIn('status', ['pending', 'confirmed'])
                ->sum('quantity');

            
            $maxParticipants = array_key_exists('max_participants', $data)
                ? (int) $data['max_participants']
                : (int) $event->max_participants;

            // biar gk negatif tiketnya    
            $data['available_tickets'] = max(0, $maxParticipants - $currentParticipants);

            $event->fill($data);
            $event->save();
            $event->load(['category', 'organizer']);

            return response()->json([
                'success' => true,
                'message' => 'Event updated successfully',
                'data' => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'category' => $event->category ? [
                        'id' => $event->category->id,
                        'name' => $event->category->name,
                        'icon' => $event->category->icon,
                    ] : null,
                    'start_date' => $this->toIsoLocal($event->start_date),
                    'end_date' => $this->toIsoLocal($event->end_date),
                    'location' => $event->location,
                    'address' => $event->address,
                    'price' => (float) $event->price,
                    'max_participants' => (int) $event->max_participants,
                    'available_tickets' => (int) $event->available_tickets,
                    'current_participants' => $currentParticipants,
                    'image_url' => $event->image_url,
                    'organizer' => $event->organizer ? [
                        'id' => $event->organizer->id,
                        'username' => $event->organizer->username ?? $event->organizer->name ?? null,
                        'email' => $event->organizer->email,
                    ] : null,
                    'is_featured' => (bool) $event->is_featured,
                    'status' => $event->status,
                    'display_status' => $this->displayStatus($event->status),
                    'created_at' => $event->created_at?->toISOString(),
                    'updated_at' => $event->updated_at?->toISOString(),
                ],
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
                'data' => null,
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to update event: ' . $e->getMessage(), [
                'event_id' => $id,
                'user_id' => $request->user()?->id,
                'request' => $request->except(['image']),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update event',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function stats(Request $request): JsonResponse{
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                    'data' => null,
                ], 401);
            }

            $now = now();

            // GLOBAL (all organizers)
            $totalEvents = (int) DB::table('events')->count();

            $activeEvents = (int) DB::table('events')
                ->where('status', 'published')
                ->where('start_date', '<=', $now)
                ->where('end_date', '>=', $now)
                ->count();

            // Upcoming events: published & belum dimulai
            $upcomingEvents = (int) DB::table('events')
                ->where('status', 'published')
                ->where('start_date', '>', $now)
                ->count();

            $totalParticipants = (int) DB::table('bookings')
                ->where('status', 'confirmed')
                ->sum('quantity');

            $totalRevenue = (float) DB::table('transactions')
                ->where('payment_status', 'paid')
                ->sum('amount');

            $byStatus = DB::table('events')
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            $eventsByStatus = [
                $this->displayStatus('draft')     => (int) ($byStatus['draft'] ?? 0),
                $this->displayStatus('published') => (int) ($byStatus['published'] ?? 0),
                $this->displayStatus('completed') => (int) ($byStatus['completed'] ?? 0),
                $this->displayStatus('cancelled') => (int) ($byStatus['cancelled'] ?? 0),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Dashboard statistics retrieved successfully',
                'data' => [
                    'total_events' => $totalEvents,
                    'active_events' => $activeEvents,
                    'upcoming_events' => $upcomingEvents,
                    'total_participants' => $totalParticipants,
                    'total_revenue' => $totalRevenue,
                    'events_by_status' => $eventsByStatus, 
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve dashboard stats: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard statistics',
                'data' => null,
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function newest(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = (int) ($request->query('limit', 3));

        $events = Event::query()
            ->with(['category', 'organizer'])
            ->withSum([
                'bookings as current_participants' => function ($q) {
                    $q->whereIn('status', ['pending', 'confirmed']);
                }
            ], 'quantity')
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $mapped = $events->map(function (Event $event) {
            $currentParticipants = (int) ($event->current_participants ?? 0);
            $maxParticipants = (int) ($event->max_participants ?? 0);
            $availableTickets = max(0, $maxParticipants - $currentParticipants);

            return [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'what_you_will_get' => $event->what_you_will_get,
                'category' => $event->category ? [
                    'id' => $event->category->id,
                    'name' => $event->category->name,
                    'icon' => $event->category->icon,
                ] : null,
                'start_date' => $this->toIsoLocal($event->start_date),
                'end_date' => $this->toIsoLocal($event->end_date),
                'location' => $event->location,
                'address' => $event->address,
                'price' => (float) $event->price,
                'max_participants' => $maxParticipants,
                'available_tickets' => $availableTickets,
                'current_participants' => $currentParticipants,
                'image_url' => $event->image_url,
                'organizer' => $event->organizer ? [
                    'id' => $event->organizer->id,
                    'username' => $event->organizer->username ?? $event->organizer->name ?? null,
                    'email' => $event->organizer->email,
                ] : null,
                'is_featured' => (bool) $event->is_featured,
                'status' => $event->status,
                'display_status' => $this->displayStatusForEvent($event),
                'created_at' => $event->created_at?->toISOString(),
                'updated_at' => $event->updated_at?->toISOString(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Newest events retrieved successfully',
            'data' => [
                'events' => $mapped,
            ],
        ], 200);
    }


    // HELPER
    private function displayStatus(?string $status): string{
        return match ($status) {
            'published' => 'Active',
            'draft' => 'Draft',
            'cancelled' => 'Cancelled',
            'completed' => 'Completed',
            default => 'Unknown',
        };
    }

    private function toIsoLocal($value): ?string
    {
        if (!$value) return null;
        return $value->format('Y-m-d\TH:i:s');
    }

}