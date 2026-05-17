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

Route::get('/ping', [CalculadoraController::class, 'ping']);

Route::post('/calcular', [CalculadoraController::class, 'calcular']);

Route::get('/tramite/{id}/schema', [CalculadoraController::class, 'schema']);
