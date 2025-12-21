<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tickets;

class TicketController extends Controller{

    public function myTickets(Request $request){
        $user = $request->user();

        $tickets = Tickets::query()
            ->whereHas('booking', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with('booking') 
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'message' => 'My tickets retrieved successfully',
            'data' => $tickets,
        ]);
    }
}