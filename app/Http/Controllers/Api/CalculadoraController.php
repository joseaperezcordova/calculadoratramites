<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalculoLog;
use App\Models\Tramite;
use App\Models\TramiteConfig;
use App\Services\FuncionesCalculo;
use App\Services\MotorCalculadora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalculadoraController extends Controller
{
    /**
     * POST /api/calcular
     */
    public function calcular(Request $request): JsonResponse
    {
        $inputs   = $request->all();
        $tokenStr = $inputs['idtramite'] ?? null;

        if (!$tokenStr) {
            return response()->json(['ok' => false, 'error' => 'Token requerido (campo idtramite)'], 401);
        }

        $configRecord = TramiteConfig::where('token', $tokenStr)
            ->where('activo', 1)
            ->first();

        if (!$configRecord) {
            return response()->json(['ok' => false, 'error' => 'Token inválido'], 401);
        }

        $tramite = Tramite::find($configRecord->tramite_id);

        if (!$tramite || !$tramite->activo) {
            return response()->json(['ok' => false, 'error' => 'Trámite inactivo'], 403);
        }

        $t0 = microtime(true);

        $result = MotorCalculadora::ejecutar(json_decode($configRecord->config, true), $inputs);

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
     */
    public function schema(int $id): JsonResponse
    {
        $configRecord = TramiteConfig::where('tramite_id', $id)
            ->where('activo', 1)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$configRecord) {
            return response()->json(['ok' => false, 'error' => 'Sin configuración activa'], 404);
        }

        $tramite    = Tramite::find($id);
        $properties = [];
        $required   = [];
        $typeMap    = ['number' => 'number', 'boolean' => 'boolean'];

        $configData = json_decode($configRecord->config, true) ?? [];
        foreach ($configData['inputs'] ?? [] as $input) {
            if ($input['token'] ?? false) continue;

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
                'title'      => $tramite->nombre ?? '',
                'type'       => 'object',
                'properties' => $properties,
                'required'   => $required,
            ],
        ]);
    }

    /**
     * POST /api/config/guardar
     * Con tramite_id → actualiza. Sin tramite_id → crea nuevo.
     */
    public function guardar(Request $request)
    {
        $tramiteId    = $request->input('tramite_id');
        $tramiteName  = $request->input('tramite_name');
        $tramiteToken = $request->input('tramite_token');
        $config       = $request->input('config');

        if ($tramiteId) {
            // ── ACTUALIZAR trámite existente ──────────────────
            $tramite = Tramite::find($tramiteId);
            if (!$tramite) {
                return response()->json(['ok' => false, 'mensaje' => 'Trámite no encontrado'], 404);
            }

            $tramite->nombre = $tramiteName;
            $tramite->save();

            TramiteConfig::where('tramite_id', $tramite->id)
                ->where('activo', 1)
                ->update(['activo' => 0]);

            TramiteConfig::create([
                'tramite_id' => $tramite->id,
                'token'      => $tramiteToken,
                'version'    => $config['version'] ?? '1.1',
                'config'     => json_encode($config),
                'activo'     => 1,
            ]);

            return response()->json([
                'ok'         => true,
                'tramite_id' => $tramite->id,
                'mensaje'    => 'Configuración actualizada',
            ]);
        }

        // ── CREAR trámite nuevo (sin tramite_id) ──────────────
        $tramite = Tramite::create([
            'nombre' => $tramiteName,
            'activo' => 1,
        ]);

        TramiteConfig::create([
            'tramite_id' => $tramite->id,
            'token'      => $tramiteToken,
            'version'    => $config['version'] ?? '1.1',
            'config'     => json_encode($config),
            'activo'     => 1,
        ]);

        return response()->json([
            'ok'         => true,
            'tramite_id' => $tramite->id,
            'mensaje'    => 'Trámite creado',
        ]);
    }

    /**
     * GET /api/tramites
     */
    public function listar()
    {
        $tramites = Tramite::where('activo', 1)
            ->get()
            ->map(function ($t) {
                $config = TramiteConfig::where('tramite_id', $t->id)
                    ->where('activo', 1)
                    ->orderBy('created_at', 'desc')
                    ->first();

                return [
                    'id'           => $t->id,
                    'nombre'       => $t->nombre,
                    'token'        => $config?->token,
                    'tiene_config' => $config ? true : false,
                    'version'      => $config?->version,
                    'updated_at'   => $config?->updated_at,
                ];
            });

        return response()->json(['ok' => true, 'tramites' => $tramites]);
    }

    /**
     * GET /api/tramites/{id}/config
     */
    public function tramiteConfig(int $id): JsonResponse
    {
        $configRecord = TramiteConfig::where('tramite_id', $id)
            ->where('activo', 1)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$configRecord) {
            return response()->json(['ok' => false, 'error' => 'Sin configuración activa'], 404);
        }

        return response()->json(['ok' => true, 'config' => json_decode($configRecord->config, true)]);
    }

    /**
     * DELETE /api/tramites/{id}
     */
    public function eliminar(int $id): JsonResponse
    {
        $tramite = Tramite::find($id);

        if (!$tramite) {
            return response()->json(['ok' => false, 'error' => 'Trámite no encontrado'], 404);
        }

        $tramite->update(['activo' => false]);

        return response()->json(['ok' => true, 'mensaje' => 'Trámite eliminado']);
    }

    /**
     * GET /api/funciones
     */
    public function funciones(): JsonResponse
    {
        return response()->json(['ok' => true, 'funciones' => FuncionesCalculo::catalogo()]);
    }

    /**
     * GET /api/ping
     */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'version' => '1.1']);
    }
}
