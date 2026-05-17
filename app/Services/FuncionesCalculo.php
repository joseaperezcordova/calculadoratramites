<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FuncionesCalculo
{
    // Retrocede meses hasta encontrar INPC en BD
    // Stub: retorna el mes anterior a la fecha dada
    public static function calcularFechaINPC1($fecha_actual, $fecha_hoy = null): string
    {
        // STUB — reemplazar con lógica real
        return Carbon::parse($fecha_actual)->subMonth()->format('Y-m-01');
    }

    // Retrocede meses para fecha_vencimiento respetando fecha_limite
    // Stub: retorna el mes anterior al vencimiento
    public static function calcularFechaINPC2($fecha_vencimiento, $fecha_limite): string
    {
        // STUB — reemplazar con lógica real
        return Carbon::parse($fecha_vencimiento)->subMonth()->format('Y-m-01');
    }

    // Busca el valor INPC en BD para una fecha dada (YYYY-MM-01)
    // Stub: retorna 132.45
    public static function getINPC($fecha): float
    {
        // STUB — reemplazar con: SELECT valor FROM variables_sistema WHERE clave='inpc_YYYY_MM'
        return 132.45;
    }

    // Resta N años a una fecha y retorna YYYY-MM-01
    public static function restarAnios($fecha, $anios): string
    {
        return Carbon::parse($fecha)->subYears((int) $anios)->format('Y-m-01');
    }

    // Catálogo de todas las funciones disponibles para el motor
    public static function catalogo(): array
    {
        return [
            'calcularFechaINPC1' => [
                'params'      => ['fecha_actual', 'fecha_hoy'],
                'descripcion' => 'Fecha INPC 1 (mes anterior con validación)',
            ],
            'calcularFechaINPC2' => [
                'params'      => ['fecha_vencimiento', 'fecha_limite'],
                'descripcion' => 'Fecha INPC 2 (mes anterior a vencimiento)',
            ],
            'getINPC' => [
                'params'      => ['fecha'],
                'descripcion' => 'Índice INPC para una fecha YYYY-MM-01',
            ],
            'restarAnios' => [
                'params'      => ['fecha', 'anios'],
                'descripcion' => 'Restar N años a una fecha',
            ],
        ];
    }
}
