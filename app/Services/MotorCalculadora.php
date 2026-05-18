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

        $sorted = self::topoSort($config['variables'] ?? [], $config['rules'] ?? []);

        foreach ($sorted as $node) {
            if ($node['type'] === 'var')  self::processVariable($node['data'], $vars, $val);
            if ($node['type'] === 'rule') self::procesarRegla($node['data'], $vars, $val);
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
            foreach ([$r['if_left'] ?? '', $r['if_right'] ?? ''] as $dep) {
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

        $res = $val($match ? ($r['then'] ?? null) : ($r['else'] ?? null));
        if (is_numeric($res)) $res = (float) $res;
        $vars[$r['name']] = $res;
    }

    private static function processVariable(array $v, array &$vars, callable $val): void
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
                $vars[$name] = round($result, $v['decimals'] ?? 2);
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
                $fn   = $op['fn'] ?? '';
                $args = array_map(
                    fn($a) => is_string($a) ? ($vars[$a] ?? $a) : ($a ?? ''),
                    $op['args'] ?? []
                );
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
