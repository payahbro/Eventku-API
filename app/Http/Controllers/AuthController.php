<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validatedData = $request->validate([
            'username' => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'username' => $validatedData['username'],
            'email'    => $validatedData['email'],
            'password' => $validatedData['password'],
            'role'     => 'user',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil',
            'user'    => $user,
            'token'   => $token
        ], 201);
    }

    public function login(Request $request){
        $credentials = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'] )->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email atau password salah'
            ], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken('login_token')->plainTextToken;
        
        return response()->json([
            'message' => 'Login berhasil',
            'user'    => $user,
            'token'   => $token
        ], 200);
        
    }

    public function logout(Request $request){
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out from all devices'], 200);
    }
}
