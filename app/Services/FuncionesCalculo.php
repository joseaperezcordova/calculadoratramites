<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FuncionesCalculo
{
    // Retrocede meses hasta encontrar INPC en BD
    // Lógica real:
    // 1. Restar 1 mes a fecha_actual
    // 2. Si no hay INPC ese mes en BD, restar otro mes
    // 3. Si fecha_actual < hoy Y hoy <= día 10, simular sin INPC del mes anterior
    public static function calcularFechaINPC1(string $fecha_actual): string
    {
        $hoy = Carbon::now();
        return Carbon::parse($fecha_actual)->subMonth()->format('Y-m-01');
    }

    // Retrocede meses para fecha_vencimiento respetando fecha_limite
    // Lógica real:
    // 1. Restar 1 mes a fecha_vencimiento
    // 2. Si no hay INPC ese mes en BD, restar otro mes
    // 3. Si fecha_vencimiento < hoy Y hoy <= día 10, simular sin INPC del mes anterior
    public static function calcularFechaINPC2(string $fecha_vencimiento, string $fecha_limite): string
    {
        $hoy = Carbon::now();
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

    // +15 días hábiles desde fecha escritura (stub: suma 21 días corridos)
    public static function calcularFechaVencimiento(string $fecha_escritura): string
    {
        // STUB — reemplazar con lógica real de días hábiles + festivos
        return Carbon::parse($fecha_escritura)->addDays(21)->format('Y-m-d');
    }

    // Cuenta meses entre dos fechas respetando fecha límite
    public static function calcularMesesRecargos(string $fecha_inicio, string $fecha_fin, string $fecha_limite): int
    {
        // STUB
        $inicio = Carbon::parse(max($fecha_inicio, $fecha_limite));
        $fin    = Carbon::parse($fecha_fin);
        return max(0, (int) $inicio->diffInMonths($fin));
    }

    // Suma porcentajes de recargo por mes desde BD
    public static function calcularPorcentajeRecargos(string $fecha_inicio, string $fecha_fin): float
    {
        // STUB — reemplazar con: SELECT SUM(porcentaje) FROM recargos WHERE periodo BETWEEN ...
        return 27.99;
    }

    // Catálogo de todas las funciones disponibles para el motor
    public static function catalogo(): array
    {
        return [
            'calcularFechaINPC1' => [
                'params'      => ['fecha_actual'],
                'tipos'       => ['date'],
                'descripcion' => 'Fecha INPC 1 (mes anterior con validación de día 10)',
            ],
            'calcularFechaINPC2' => [
                'params'      => ['fecha_vencimiento', 'fecha_limite'],
                'tipos'       => ['date', 'date'],
                'descripcion' => 'Fecha INPC 2 (mes anterior con validación de día 10)',
            ],
            'getINPC' => [
                'params'      => ['fecha'],
                'tipos'       => ['date'],
                'descripcion' => 'Índice INPC para una fecha YYYY-MM-01',
            ],
            'restarAnios' => [
                'params'      => ['fecha', 'anios'],
                'tipos'       => ['date', 'number'],
                'descripcion' => 'Restar N años a una fecha',
            ],
            'calcularFechaVencimiento' => [
                'params'      => ['fecha_escritura'],
                'tipos'       => ['date'],
                'descripcion' => 'Fecha vencimiento (+15 días hábiles)',
            ],
            'calcularMesesRecargos' => [
                'params'      => ['fecha_inicio', 'fecha_fin', 'fecha_limite'],
                'tipos'       => ['date', 'date', 'date'],
                'descripcion' => 'Cantidad de meses del período de recargos',
            ],
            'calcularPorcentajeRecargos' => [
                'params'      => ['fecha_inicio', 'fecha_fin'],
                'tipos'       => ['date', 'date'],
                'descripcion' => 'Porcentaje total de recargos del período',
            ],
        ];
    }
}
