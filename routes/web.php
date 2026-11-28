<?php

use App\Http\Controllers\OrientationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));
Route::get('/login', [OrientationController::class, 'loginForm'])->name('login');
Route::post('/login', [OrientationController::class, 'login']);
Route::post('/logout', [OrientationController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/orientation/rup', [OrientationController::class, 'rup'])->name('orientation.rup');
    Route::post('/orientation/import', [OrientationController::class, 'import'])->name('orientation.import');
    Route::post('/orientation/student/choices', [OrientationController::class, 'studentChoices'])->name('orientation.student.choices');
    Route::post('/orientation/{student}/choices', [OrientationController::class, 'choices'])->name('orientation.choices');
    Route::post('/orientation/estimate', [OrientationController::class, 'estimate'])->name('orientation.estimate');
    Route::get('/orientation/student', [OrientationController::class, 'student'])->name('orientation.student');
});
