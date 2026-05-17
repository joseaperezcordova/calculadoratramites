<?php

use App\Http\Controllers\Api\CalculadoraController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes – Motor Calculadora de Trámites
|--------------------------------------------------------------------------
|
| La autenticación es mediante token propio (campo idtramite en el body).
| No se usa auth:sanctum para estas rutas.
|
*/

// Preflight OPTIONS para CORS
Route::options('/{any}', function () {
    return response()->json('ok', 200)
        ->header('Access-Control-Allow-Origin',  '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
})->where('any', '.*');

Route::get('/ping', [CalculadoraController::class, 'ping']);

Route::post('/calcular', [CalculadoraController::class, 'calcular']);

Route::get('/tramite/{id}/schema', [CalculadoraController::class, 'schema']);

Route::post('/config/guardar', [CalculadoraController::class, 'guardarConfig']);

Route::get('/tramites', [CalculadoraController::class, 'listar']);
Route::get('/tramites/{id}/config', [CalculadoraController::class, 'tramiteConfig']);
Route::delete('/tramites/{id}', [CalculadoraController::class, 'eliminar']);
