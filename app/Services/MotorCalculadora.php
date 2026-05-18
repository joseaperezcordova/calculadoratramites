<?php

namespace App\Services;

use App\Models\VariableSistema;
use Carbon\Carbon;

class MotorCalculadora
{
    public static function ejecutar(array $config, array $inputs): array
    {
        $vars = $inputs;

        foreach ($config['constants'] ?? [] as $c) {
            $vars[$c['name']] = $c['value'];
        }

        foreach ($config['defined'] ?? [] as $d) {
            $name = $d['name'] ?? $d['catalog_key'];
            $key  = $d['catalog_key'] ?? $d['name'];
            $vars[$name] = self::resolveSystemVar($key);
        }

        $val = function (mixed $item) use (&$vars): mixed {
            if ($item === null || $item === '') return 0;
            if (is_numeric($item))              return (float) $item;
            return $vars[$item] ?? $item;
        };

        // Paso 1: variables independientes (no extractores)
        foreach ($config['variables'] ?? [] as $v) {
            if (($v['operation']['type'] ?? '') !== 'extractor') {
                self::processVariable($v, $vars, $val);
            }
        }

        // Paso 2: reglas condicionales
        foreach ($config['rules'] ?? [] as $r) {
            $L = $val($r['if_left']  ?? '');
            $R = $val($r['if_right'] ?? '');

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

            $res = $val($match ? ($r['then'] ?? null) : ($r['else'] ?? null));
            if (is_numeric($res)) $res = (float) $res;
            $vars[$r['name']] = $res;
        }

        // Paso 3: variables que dependen de outputs de reglas
        $ruleNames = array_column($config['rules'] ?? [], 'name');
        foreach ($config['variables'] ?? [] as $v) {
            $type = $v['operation']['type'] ?? '';
            if ($type === 'extractor') continue;
            $op   = $v['operation'] ?? [];
            $deps = array_merge($op['operands'] ?? [], $op['args'] ?? []);
            if (array_intersect($deps, $ruleNames)) {
                self::processVariable($v, $vars, $val);
            }
        }

        // Paso 4: extractores (dependen de php_function que ya están en $vars)
        foreach ($config['variables'] ?? [] as $v) {
            if (($v['operation']['type'] ?? '') === 'extractor') {
                self::processVariable($v, $vars, $val);
            }
        }

        $outputs = [];
        foreach ($config['outputs'] ?? [] as $o) {
            $outputs[$o['name']] = $vars[$o['map']] ?? null;
        }

        return [
            'outputs' => $outputs,
            '_vars'   => $vars,
        ];
    }

    private static function processVariable(array $v, array &$vars, callable $val): void
    {
        $op   = $v['operation'] ?? [];
        $name = $v['name'];
        $type = $op['type'] ?? '';

        switch ($type) {
            case 'math':
                $a = (float) ($val($op['operands'][0] ?? 0) ?? 0);
                $b = (float) ($val($op['operands'][1] ?? 0) ?? 0);
                $vars[$name] = match ($op['operator'] ?? '+') {
                    '+'     => $a + $b,
                    '-'     => $a - $b,
                    '*'     => $a * $b,
                    '/'     => $b != 0 ? $a / $b : 0,
                    default => 0,
                };
                break;

            case 'custom':
                if (($op['fn'] ?? '') === 'diffYears') {
                    $arg0  = $op['args'][0] ?? '';
                    $arg1  = $op['args'][1] ?? 'hoy';
                    $d1str = $vars[$arg0] ?? $arg0 ?: '2000-01-01';
                    $d2    = in_array($arg1, ['hoy', 'today'])
                        ? Carbon::now()
                        : Carbon::parse($vars[$arg1] ?? $arg1);
                    $d1    = Carbon::parse($d1str);
                    $diff  = $d2->year - $d1->year;
                    if ($d2->month < $d1->month || ($d2->month === $d1->month && $d2->day < $d1->day)) {
                        $diff--;
                    }
                    $vars[$name] = max(0, $diff);
                }
                break;

            case 'lookup':
                $k           = $vars[$op['key'] ?? ''] ?? ($op['key'] ?? '');
                $table       = $op['table'] ?? [];
                $raw         = $table[(string) $k] ?? 0;
                $vars[$name] = is_numeric($raw) ? (float) $raw : $raw;
                break;

            case 'php_function':
                $fn          = $op['fn'] ?? '';
                $args        = array_map(fn($a) => $vars[$a] ?? $a, $op['args'] ?? []);
                $vars[$name] = FuncionesCalculo::$fn(...$args);
                break;

            case 'extractor':
                $src         = $vars[$op['src'] ?? ''] ?? null;
                $field       = $op['field'] ?? '';
                $vars[$name] = is_array($src) ? ($src[$field] ?? null) : null;
                break;
        }
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
