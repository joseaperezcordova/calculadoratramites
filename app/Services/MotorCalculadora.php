<?php

namespace App\Services;

use App\Models\VariableSistema;
use Carbon\Carbon;

class MotorCalculadora
{
    public static function ejecutar(array $config, array $inputs): array
    {
        $vars = $inputs;

        // 1. Constantes → $vars
        foreach ($config['constants'] ?? [] as $c) {
            $vars[$c['name']] = $c['value'];
        }

        // 2. Variables del sistema (BD + fecha/hora en tiempo real)
        foreach ($config['defined'] ?? [] as $d) {
            $name = $d['name'] ?? $d['catalog_key'];
            $key  = $d['catalog_key'] ?? $d['name'];
            $vars[$name] = self::resolveSystemVar($key);
        }

        // Resuelve un operando: literal numérico, nombre de variable o string
        $val = function (mixed $item) use (&$vars): mixed {
            if ($item === null || $item === '') return 0;
            if (is_numeric($item))              return (float) $item;
            return $vars[$item] ?? $item;
        };

        // 3. Variables calculadas
        foreach ($config['variables'] ?? [] as $v) {
            $op   = $v['operation'] ?? [];
            $name = $v['name'];
            $type = $op['type'] ?? '';

            if ($type === 'math') {
                $a = (float) ($val($op['operands'][0] ?? 0) ?? 0);
                $b = (float) ($val($op['operands'][1] ?? 0) ?? 0);
                $vars[$name] = match ($op['operator'] ?? '+') {
                    '+'     => $a + $b,
                    '-'     => $a - $b,
                    '*'     => $a * $b,
                    '/'     => $b != 0 ? $a / $b : 0,
                    default => 0,
                };
            } elseif ($type === 'custom' && ($op['fn'] ?? '') === 'diffYears') {
                $arg0 = $op['args'][0] ?? '';
                $arg1 = $op['args'][1] ?? 'hoy';

                // Arg0 puede ser un nombre de variable o literal de fecha
                $d1str = $vars[$arg0] ?? $arg0 ?: '2000-01-01';
                $d2 = in_array($arg1, ['hoy', 'today'])
                    ? Carbon::now()
                    : Carbon::parse($vars[$arg1] ?? $arg1);

                $d1 = Carbon::parse($d1str);

                // Replica exacta de la lógica JS: años cumplidos
                $diff = $d2->year - $d1->year;
                if ($d2->month < $d1->month || ($d2->month === $d1->month && $d2->day < $d1->day)) {
                    $diff--;
                }
                $vars[$name] = max(0, $diff);
            } elseif ($type === 'lookup') {
                $k     = $vars[$op['key'] ?? ''] ?? ($op['key'] ?? '');
                $table = $op['table'] ?? [];
                $raw   = $table[(string) $k] ?? 0;
                $vars[$name] = is_numeric($raw) ? (float) $raw : $raw;
            }
        }

        // 4. Reglas condicionales
        foreach ($config['rules'] ?? [] as $r) {
            $L = $val($r['if_left']  ?? '');
            $R = $val($r['if_right'] ?? '');

            // Comparación numérica cuando ambos son números
            if (is_numeric($L) && is_numeric($R)) {
                $L = (float) $L;
                $R = (float) $R;
            }

            $match = match ($r['operator'] ?? '==') {
                '=='    => $L == $R,
                '!='    => $L != $R,
                '>'     => $L >  $R,
                '<'     => $L <  $R,
                '>='    => $L >= $R,
                '<='    => $L <= $R,
                default => false,
            };

            $res = $match ? ($r['then'] ?? null) : ($r['else'] ?? null);
            // Si el resultado es un nombre de variable, lo resolvemos
            $res = $val($res);
            if (is_numeric($res)) $res = (float) $res;

            $vars[$r['name']] = $res;
        }

        // 5. Outputs: solo lo que el config declara
        $outputs = [];
        foreach ($config['outputs'] ?? [] as $o) {
            $outputs[$o['name']] = $vars[$o['map']] ?? null;
        }

        return [
            'outputs' => $outputs,
            '_vars'   => $vars,   // para logging/debug
        ];
    }

    private static function resolveSystemVar(string $key): mixed
    {
        $now = Carbon::now();

        $dateVars = [
            'dia_mes'     => $now->day,
            'mes_actual'  => $now->month,
            'anio_actual' => $now->year,
            'dias_anio'   => (int) $now->dayOfYear,
        ];

        if (isset($dateVars[$key])) {
            return $dateVars[$key];
        }

        $row = VariableSistema::where('clave', $key)->first();

        if (!$row) return 0;

        return is_numeric($row->valor) ? (float) $row->valor : $row->valor;
    }
}
