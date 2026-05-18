<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FuncionesCalculo
{
    // Fecha INPC para fecha_actual: retrocede 1 mes (o 2 si no existe en BD o día < 10)
    public static function calcularFechaINPC1(string $fecha_actual): string
    {
        return self::resolverFechaInpc($fecha_actual)['fecha_ym'];
    }

    // Fecha INPC para fecha_vencimiento respetando fecha_limite como mínimo
    public static function calcularFechaINPC2(string $fecha_vencimiento, string $fecha_limite): string
    {
        return self::resolverFechaInpc($fecha_vencimiento, $fecha_limite)['fecha_ym'];
    }

    // Devuelve el índice INPC de la BD para una fecha dada (cualquier formato parseable)
    public static function getINPC($fecha): float
    {
        $ts  = strtotime($fecha);
        $row = self::buscarInpc($ts);
        return (float) max(0, $row['indice']);
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

    // Calcula la fecha límite de pago ISN (día 17 del mes siguiente, ajustando días hábiles)
    public static function calcularFechaLimiteISN(int $mes_isn, int $anio_isn): string
    {
        if($mes_isn == 12){ $mesLim = 1; $anioLim = $anio_isn + 1; }
        else { $mesLim = $mes_isn + 1; $anioLim = $anio_isn; }

        $fechaLim = sprintf('%02d-%02d-%04d', 17, $mesLim, $anioLim);
        $inhabiles = self::getInhabiles($anioLim);

        while(in_array(date("w", strtotime($fechaLim)), [0,6]) || in_array($fechaLim, $inhabiles)){
            $fechaLim = date("d-m-Y", strtotime($fechaLim.' +1 days'));
        }
        return $fechaLim; // formato dd-mm-YYYY
    }

    // Devuelve 1 si fecha_actual > fecha_limite (extemporáneo), 0 si es oportuno
    public static function esExtemporaneo(string $fecha_actual, string $fecha_limite): int
    {
        return strtotime($fecha_actual." 00:00:00") > strtotime($fecha_limite." 23:59:59") ? 1 : 0;
    }

    // Factor de actualización ISN: INPC reciente / INPC del mes anterior a fecha_limite
    public static function getFactorActualizacionISN(string $fecha_limite): float
    {
        $fechaLimCarbon = Carbon::createFromFormat('d-m-Y', $fecha_limite);
        $mesAntiguo  = $fechaLimCarbon->copy()->subMonth();

        $inpcReciente = DB::connection('operacion')
            ->table('oper_inpc')
            ->orderBy('ano','desc')->orderBy('mes','desc')
            ->value('indice') ?? 145.831;

        $inpcAntiguo = DB::connection('operacion')
            ->table('oper_inpc')
            ->where('ano', $mesAntiguo->year)
            ->where('mes', $mesAntiguo->month)
            ->value('indice') ?? 140.012;

        $factor = round($inpcReciente / $inpcAntiguo, 4);
        return $factor < 1 ? 1 : $factor;
    }

    // Porcentaje de recargos entre fecha_limite+1día y fecha_actual
    public static function getPorcentajeRecargosISN(string $fecha_limite, string $fecha_actual): float
    {
        $unDiaMas = date("d-m-Y", strtotime($fecha_limite." +1 day"));
        $anioMesI = date("Ym", strtotime($unDiaMas));
        $anioMesF = date("Ym", strtotime($fecha_actual));

        try {
            $result = DB::select("SELECT SUM(federal_vencido) porcentaje
                         FROM (SELECT CONCAT(anio,LPAD(mes,2,0)) aniomes, federal_vencido
                               FROM porcentajes
                               WHERE CONCAT(anio,LPAD(mes,2,0)) >= ?
                                 AND CONCAT(anio,LPAD(mes,2,0)) <= ?
                               ORDER BY 1 DESC LIMIT 60) t",
                         [$anioMesI, $anioMesF]);
            return (float)($result[0]->porcentaje ?? 20.04);
        } catch(\Exception $e){
            return 20.04;
        }
    }

    // Helper privado — días inhábiles del año
    private static function getInhabiles(int $anio): array
    {
        $dias = DB::table('diasferiados')
            ->where('Ano', $anio)->orWhere('Ano', $anio+1)
            ->select('Ano','Mes','Dia')->get();
        return $dias->map(fn($d) =>
            str_pad($d->Dia,2,'0',STR_PAD_LEFT).'-'.
            str_pad($d->Mes,2,'0',STR_PAD_LEFT).'-'.$d->Ano
        )->toArray();
    }

    // Resuelve el mes INPC correcto retrocediendo desde $fechaIN.
    // $fechaMinima (string Y-m-d o similar) limita el retroceso mínimo.
    private static function resolverFechaInpc(string $fechaIN, $fechaMinima = 0): array
    {
        $ts       = strtotime($fechaIN);
        $diaActual = (int) date('d', $ts);
        $base      = strtotime(date('Y-m-01', $ts));   // primer día del mes

        $inpcTs   = strtotime('-1 month', $base);
        $inpcTmp  = self::buscarInpc($inpcTs);

        if ($inpcTmp['indice'] == -1 || ($fechaMinima == 0 && $diaActual < 10)) {
            $inpcTs  = strtotime('-2 months', $base);
            $inpcTmp = self::buscarInpc($inpcTs);
        }

        if ($fechaMinima != 0 && $inpcTs < strtotime($fechaMinima . ' 00:00:00')) {
            $inpcTs  = strtotime(date('Y-m-01', strtotime($fechaMinima)));
            $inpcTmp = self::buscarInpc($inpcTs);
        }

        return [
            'fecha_ym' => date('Y-m-01', $inpcTs),
            'indice'   => $inpcTmp['indice'],
        ];
    }

    // Consulta oper_inpc en la conexión por defecto para un timestamp dado.
    private static function buscarInpc(int $fecha): array
    {
        try {
            $indice = DB::table('oper_inpc')
                ->where('ano', date('Y', $fecha))
                ->where('mes', (int) date('n', $fecha))
                ->value('indice');
            return [
                'fecha'  => date('d-m-Y', $fecha),
                'indice' => $indice ?? -1,
            ];
        } catch (\Exception $e) {
            return ['fecha' => date('d-m-Y', $fecha), 'indice' => -2];
        }
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
            'calcularFechaLimiteISN' => [
                'params'      => ['mes_isn', 'anio_isn'],
                'tipos'       => ['number', 'number'],
                'descripcion' => 'Fecha límite de pago ISN (día 17 hábil del mes siguiente)',
            ],
            'esExtemporaneo' => [
                'params'      => ['fecha_actual', 'fecha_limite'],
                'tipos'       => ['date', 'date'],
                'descripcion' => 'Devuelve 1 si es extemporáneo, 0 si es oportuno',
            ],
            'getFactorActualizacionISN' => [
                'params'      => ['fecha_limite'],
                'tipos'       => ['date'],
                'descripcion' => 'Factor de actualización INPC (mínimo 1.0)',
            ],
            'getPorcentajeRecargosISN' => [
                'params'      => ['fecha_limite', 'fecha_actual'],
                'tipos'       => ['date', 'date'],
                'descripcion' => 'Porcentaje total de recargos del período ISN',
            ],
        ];
    }
}
