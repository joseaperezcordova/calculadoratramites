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

        $trace  = [];
        $sorted = self::topoSort($config['variables'] ?? [], $config['rules'] ?? []);

        foreach ($sorted as $node) {
            if ($node['type'] === 'var')  self::processVariable($node['data'], $vars, $val, $trace);
            if ($node['type'] === 'rule') self::procesarRegla($node['data'], $vars, $val);
        }

        $outputs = [];
        foreach ($config['outputs'] ?? [] as $o) {
            $raw = $vars[$o['map']] ?? null;
            if (is_float($raw) || is_int($raw)) {
                $raw = self::aplicarRedondeo($raw, $o['decimals'] ?? 2, $o['round_mode'] ?? 'half_up');
            }
            $outputs[$o['name']] = $raw;
        }

        return [
            'outputs' => $outputs,
            '_trace'  => self::limpiarFloats($trace),
            '_vars'   => self::limpiarFloats($vars),
        ];
    }

    private static function topoSort(array $variables, array $rules): array
    {
        $allNodes = array_merge(
            array_map(fn($v) => ['name' => $v['name'], 'type' => 'var',  'data' => $v], $variables),
            array_map(fn($r) => ['name' => $r['name'], 'type' => 'rule', 'data' => $r], $rules)
        );

        $nameToNode = collect($allNodes)->keyBy('name');
        $inDegree   = collect($allNodes)->mapWithKeys(fn($n) => [$n['name'] => 0])->toArray();
        $adj        = collect($allNodes)->mapWithKeys(fn($n) => [$n['name'] => []])->toArray();

        foreach ($variables as $v) {
            $op   = $v['operation'] ?? [];
            $deps = array_merge(
                $op['operands'] ?? [],
                $op['args']     ?? [],
                [$op['key']     ?? ''],
                [$op['src']     ?? '']
            );
            foreach ($deps as $dep) {
                if ($dep && $nameToNode->has($dep) && !is_numeric($dep)) {
                    $adj[$dep][]          = $v['name'];
                    $inDegree[$v['name']]++;
                }
            }
        }

        foreach ($rules as $r) {
            $deps = [
                $r['if_left']  ?? '',
                $r['if_right'] ?? '',
                $r['then']     ?? '',
                $r['else']     ?? '',
            ];
            foreach ($deps as $dep) {
                if ($dep && $nameToNode->has($dep) && !is_numeric($dep)) {
                    $adj[$dep][]         = $r['name'];
                    $inDegree[$r['name']]++;
                }
            }
        }

        $queue  = array_keys(array_filter($inDegree, fn($d) => $d === 0));
        $sorted = [];

        while (!empty($queue)) {
            $n        = array_shift($queue);
            $sorted[] = $n;
            foreach ($adj[$n] ?? [] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) $queue[] = $neighbor;
            }
        }

        // Nodos restantes (ciclos o sin dependencias registradas)
        foreach ($allNodes as $node) {
            if (!in_array($node['name'], $sorted)) $sorted[] = $node['name'];
        }

        return array_map(fn($name) => $nameToNode[$name], $sorted);
    }

    private static function procesarRegla(array $r, array &$vars, callable $val): void
    {
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

        $res = $match ? ($r['then'] ?? null) : ($r['else'] ?? null);

        // Si el resultado es el nombre de una variable existente, usar su valor
        if (is_string($res) && isset($vars[$res])) {
            $res = $vars[$res];
        }

        // Si es un string numérico, convertir a float
        if (is_string($res) && is_numeric($res)) {
            $res = (float) $res;
        }

        $vars[$r['name']] = $res;
    }

    private static function processVariable(array $v, array &$vars, callable $val, array &$trace = []): void
    {
        $op   = $v['operation'] ?? [];
        $name = $v['name'];
        $type = $op['type'] ?? '';

        switch ($type) {
            case 'math':
                $steps = $op['steps'] ?? [
                    ['val' => $op['operands'][0] ?? 0, 'op' => $op['operator'] ?? '+'],
                    ['val' => $op['operands'][1] ?? 0],
                ];
                $result = (float) ($val($steps[0]['val']) ?? 0);
                for ($i = 0; $i < count($steps) - 1; $i++) {
                    $b      = (float) ($val($steps[$i + 1]['val']) ?? 0);
                    $op_str = $steps[$i]['op'] ?? '+';
                    $result = match ($op_str) {
                        '+'     => $result + $b,
                        '-'     => $result - $b,
                        '*'     => $result * $b,
                        '/'     => $b != 0 ? $result / $b : 0,
                        default => 0,
                    };
                }
                $vars[$name] = self::aplicarRedondeo($result, $v['decimals'] ?? 2, $v['round_mode'] ?? 'half_up');
                break;

            case 'lookup':
                $k           = $vars[$op['key'] ?? ''] ?? ($op['key'] ?? '');
                $table       = $op['table'] ?? [];
                $raw         = $table[(string) $k] ?? 0;
                $vars[$name] = is_numeric($raw) ? (float) $raw : $raw;
                break;

            case 'fn':
            case 'php_function':
            case 'custom':
                $fn           = $op['fn'] ?? '';
                $argKeys      = $op['args'] ?? [];
                $resolvedArgs = array_map(
                    fn($a) => isset($vars[$a]) ? $vars[$a] : $a,
                    $argKeys
                );
                // Cast args según tipos declarados en el catálogo
                $fnDef = FuncionesCalculo::catalogo()[$fn] ?? [];
                foreach ($resolvedArgs as $i => &$argVal) {
                    $tipo = $fnDef['tipos'][$i] ?? null;
                    if ($tipo === 'number') $argVal = is_numeric($argVal) ? (float) $argVal : $argVal;
                    if ($tipo === 'date')   $argVal = (string) $argVal;
                }
                unset($argVal);
                // Nombres seguros para el trace: reemplaza nulls/''/numéricos por param_N
                $argNames = array_map(
                    fn($k, $a) => (is_string($a) && $a !== '') ? $a : 'param_' . $k,
                    array_keys($argKeys),
                    $argKeys
                );
                try {
                    $resultado   = FuncionesCalculo::$fn(...$resolvedArgs);
                    $resultado   = self::aplicarRedondeo($resultado, $v['decimals'] ?? 2, $v['round_mode'] ?? 'half_up');
                    $trace[]     = [
                        'variable'  => $name,
                        'funcion'   => $fn,
                        'args_in'   => array_combine($argNames, $resolvedArgs),
                        'resultado' => $resultado,
                        'tipo_res'  => gettype($resultado),
                    ];
                    $vars[$name] = $resultado;
                } catch (\Throwable $e) {
                    $trace[]     = [
                        'variable' => $name,
                        'funcion'  => $fn,
                        'args_in'  => array_combine($argNames, $resolvedArgs),
                        'error'    => $e->getMessage(),
                    ];
                }
                break;
        }
    }

    private static function limpiarFloats(array $data): array
    {
        return array_map(function ($v) {
            if (is_float($v))  return round($v, 10);
            if (is_array($v))  return self::limpiarFloats($v);
            return $v;
        }, $data);
    }

    private static function aplicarRedondeo(mixed $valor, int $decimals, string $mode = 'half_up'): mixed
    {
        if (!is_numeric($valor)) return $valor;
        $valor = (float) $valor;
        return match ($mode) {
            'half_down' => round($valor, $decimals, PHP_ROUND_HALF_DOWN),
            'floor'     => $decimals === 0
                ? (int) floor($valor)
                : floor($valor * pow(10, $decimals)) / pow(10, $decimals),
            'ceil'      => $decimals === 0
                ? (int) ceil($valor)
                : ceil($valor * pow(10, $decimals)) / pow(10, $decimals),
            'none'      => $valor,
            default     => round($valor, $decimals),
        };
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
