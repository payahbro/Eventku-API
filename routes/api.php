<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController; 
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\MidtransCallbackController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TicketController;
use Illuminate\Http\Request;



// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Event 
Route::get('/events/newest', [EventController::class, 'newest']);

// Category
Route::get('/categories', [CategoryController::class, 'index']);

// Midtrans Callback
Route::post('/midtrans/callback', [MidtransCallbackController::class, 'handle']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // User
    Route::get('/me', [UserController::class, 'me']);
    Route::put('/me', [UserController::class, 'updateMe']);
    Route::delete('/me', [UserController::class, 'destroyMe']);
    Route::get('/my-events/stats', [UserController::class, 'stats']);
    Route::get('/my-events', [UserController::class, 'myEvents']);
    Route::get('/my-tickets', [TicketController::class, 'myTickets']);
    Route::get('/my-bookings', [BookingController::class, 'myBookings']);

    // Booking
    Route::post('/bookings', [BookingController::class, 'store']);


    // Payment
    Route::post('/bookings/{id}/pay', [PaymentController::class, 'pay']);


    // Event
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{id}', [EventController::class, 'show']);

    Route::middleware('is_admin')->group(function () {      
        // Event Admin/EO
        Route::post('/events', [EventController::class, 'store']);
        Route::delete('/events/{id}', [EventController::class, 'destroy']);
        Route::put('/events/{id}', [EventController::class, 'update']);
        Route::get('/events/dashboard/stats', [EventController::class, 'stats']);

        // Booking Admin/EO
        Route::get('/bookings/{id}', [BookingController::class, 'getBookingById']);
    });

});