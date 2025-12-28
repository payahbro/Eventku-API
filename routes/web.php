<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// Sekedar debug
Route::get('/setup-database-darurat', function () {
    try {
        Artisan::call('migrate:fresh', ['--force' => true]);
        return '<h1>SUKSES! Database berhasil di-reset & di-migrate.</h1><br>' . nl2br(Artisan::output());
    } catch (\Exception $e) {
        return '<h1>GAGAL!</h1><br>Error: ' . $e->getMessage();
    }
});
