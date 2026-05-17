<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalculoLog;
use App\Models\Tramite;
use App\Models\TramiteToken;
use App\Services\MotorCalculadora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalculadoraController extends Controller
{
    /**
     * POST /api/calcular
     * Autentica por token propio (campo idtramite), ejecuta el motor y registra el log.
     */
    public function calcular(Request $request): JsonResponse
    {
        $inputs   = $request->all();
        $tokenStr = $inputs['idtramite'] ?? null;

        if (!$tokenStr) {
            return response()->json(['ok' => false, 'error' => 'Token requerido (campo idtramite)'], 401);
        }

        $tokenRecord = TramiteToken::with('tramite')
            ->where('token', $tokenStr)
            ->where('activo', true)
            ->first();

        if (!$tokenRecord) {
            return response()->json(['ok' => false, 'error' => 'Token inválido'], 401);
        }

        $tramite = $tokenRecord->tramite;

        if (!$tramite || !$tramite->activo) {
            return response()->json(['ok' => false, 'error' => 'Trámite inactivo'], 403);
        }

        $configRecord = $tramite->configs()
            ->where('activo', true)
            ->latest()
            ->first();

        if (!$configRecord) {
            return response()->json(['ok' => false, 'error' => 'Sin configuración activa para este trámite'], 422);
        }

        $t0 = microtime(true);

        $result = MotorCalculadora::ejecutar($configRecord->config, $inputs);

        $duracionMs = (int) round((microtime(true) - $t0) * 1000);

        CalculoLog::create([
            'tramite_id'  => $tramite->id,
            'token_usado' => $tokenStr,
            'inputs'      => $inputs,
            'outputs'     => $result['outputs'],
            'duracion_ms' => $duracionMs,
        ]);

        return response()->json([
            'ok'      => true,
            'tramite' => $tramite->nombre,
            'outputs' => $result['outputs'],
        ]);
    }

    /**
     * GET /api/tramite/{id}/schema
     * JSON Schema de los inputs del trámite (sin el campo token).
     */
    public function schema(int $id): JsonResponse
    {
        $tramite = Tramite::find($id);

        if (!$tramite) {
            return response()->json(['ok' => false, 'error' => 'Trámite no encontrado'], 404);
        }

        $configRecord = $tramite->configs()
            ->where('activo', true)
            ->latest()
            ->first();

        if (!$configRecord) {
            return response()->json(['ok' => false, 'error' => 'Sin configuración activa'], 404);
        }

        $properties = [];
        $required   = [];

        $typeMap = ['number' => 'number', 'boolean' => 'boolean'];

        foreach ($configRecord->config['inputs'] ?? [] as $input) {
            if ($input['token'] ?? false) continue; // omitir el campo idtramite

            $jsonType = $typeMap[$input['type'] ?? ''] ?? 'string';

            $prop = [
                'type'  => $jsonType,
                'title' => $input['label'] ?? $input['name'],
            ];

            if (($input['type'] ?? '') === 'date') {
                $prop['format'] = 'date';
            }

            $properties[$input['name']] = $prop;

            if ($input['required'] ?? true) {
                $required[] = $input['name'];
            }
        }

        return response()->json([
            'ok'     => true,
            'schema' => [
                '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
                'title'      => $tramite->nombre,
                'type'       => 'object',
                'properties' => $properties,
                'required'   => $required,
            ],
        ]);
    }

    /**
     * GET /api/ping
     */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'version' => '1.1']);
    }
}
