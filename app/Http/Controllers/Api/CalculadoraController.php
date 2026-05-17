<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalculoLog;
use App\Models\Tramite;
use App\Models\TramiteConfig;
use App\Models\TramiteToken;
use App\Services\MotorCalculadora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'tramite_id'   => $tramite->id,
            'token_usado'  => $tokenStr,
            'inputs_json'  => $inputs,
            'outputs_json' => $result['outputs'],
            'tiempo_ms'    => $duracionMs,
            'ip'           => $request->ip(),
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
     * POST /api/config/guardar
     * Si el token ya existe → actualiza la config activa del trámite.
     * Si no existe → crea trámite, config y token nuevos.
     */
    public function guardarConfig(Request $request): JsonResponse
    {
        $tramiteName  = $request->input('tramite_name');
        $tramiteToken = $request->input('tramite_token');
        $config       = $request->input('config');

        if (!$tramiteName || !$tramiteToken || !$config) {
            return response()->json(['ok' => false, 'error' => 'Faltan campos: tramite_name, tramite_token, config'], 422);
        }

        DB::beginTransaction();

        try {
            $tokenRecord = TramiteToken::where('token', $tramiteToken)->first();

            if ($tokenRecord) {
                // Token existente → actualizar config activa del trámite
                $tramite = $tokenRecord->tramite;
                $tramite->update(['nombre' => $tramiteName]);

                // Desactivar configs anteriores y crear una nueva activa
                $tramite->configs()->where('activo', true)->update(['activo' => false]);

                TramiteConfig::create([
                    'tramite_id' => $tramite->id,
                    'config'     => $config,
                    'version'    => '1.1',
                    'activo'     => true,
                ]);
            } else {
                // Token nuevo → crear trámite, config y token
                $tramite = Tramite::create([
                    'nombre' => $tramiteName,
                    'activo' => true,
                ]);

                TramiteConfig::create([
                    'tramite_id' => $tramite->id,
                    'config'     => $config,
                    'version'    => '1.1',
                    'activo'     => true,
                ]);

                TramiteToken::create([
                    'tramite_id'  => $tramite->id,
                    'token'       => $tramiteToken,
                    'activo'      => true,
                    'descripcion' => 'Token principal',
                ]);
            }

            DB::commit();

            return response()->json([
                'ok'         => true,
                'tramite_id' => $tramite->id,
                'mensaje'    => 'Configuración guardada',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/tramites
     * Lista todos los trámites activos con su token principal.
     */
    public function listar(): JsonResponse
    {
        $tramites = Tramite::where('activo', true)
            ->with(['tokens' => function ($q) {
                $q->where('activo', true)->limit(1);
            }])
            ->get()
            ->map(function ($t) {
                return [
                    'id'     => $t->id,
                    'nombre' => $t->nombre,
                    'token'  => optional($t->tokens->first())->token,
                ];
            });

        return response()->json(['ok' => true, 'tramites' => $tramites]);
    }

    /**
     * GET /api/ping
     */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'version' => '1.1']);
    }
}
