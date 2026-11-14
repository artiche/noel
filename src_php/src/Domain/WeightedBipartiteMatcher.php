<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;

final class WeightedBipartiteMatcher
{
    /**
     * @param array<int, array<int, int>> $weights
     * @param array<int, array<int, bool>> $allowed
     * @return array<int, int>|null
     */
    public function solveMaximumPerfectMatching(array $weights, array $allowed): ?array
    {
        $n = count($weights);
        if ($n === 0 || count($allowed) !== $n) {
            throw new ApiException(422, 'invalid_matrix', 'Invalid matching matrix dimensions');
        }

        $maxAllowedWeight = PHP_INT_MIN;
        $hasAllowedEdge = false;
        for ($i = 0; $i < $n; $i++) {
            if (count($weights[$i]) !== $n || count($allowed[$i]) !== $n) {
                throw new ApiException(422, 'invalid_matrix', 'Invalid matching matrix dimensions');
            }
            for ($j = 0; $j < $n; $j++) {
                if (!$allowed[$i][$j]) {
                    continue;
                }
                $hasAllowedEdge = true;
                if ($weights[$i][$j] > $maxAllowedWeight) {
                    $maxAllowedWeight = $weights[$i][$j];
                }
            }
        }

        if (!$hasAllowedEdge) {
            return null;
        }

        $forbiddenCost = $maxAllowedWeight + 10_000_000_000;
        $cost = [];
        for ($i = 0; $i < $n; $i++) {
            $cost[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                $cost[$i][$j] = $allowed[$i][$j] ? ($maxAllowedWeight - $weights[$i][$j]) : $forbiddenCost;
            }
        }

        $assignment = $this->hungarianMinCost($cost);
        for ($i = 0; $i < $n; $i++) {
            if (!$allowed[$i][$assignment[$i]]) {
                return null;
            }
        }

        return $assignment;
    }

    /**
     * @param array<int, array<int, int|float>> $cost
     * @return array<int, int>
     */
    private function hungarianMinCost(array $cost): array
    {
        $n = count($cost);
        $u = array_fill(0, $n + 1, 0.0);
        $v = array_fill(0, $n + 1, 0.0);
        $p = array_fill(0, $n + 1, 0);
        $way = array_fill(0, $n + 1, 0);

        for ($i = 1; $i <= $n; $i++) {
            $p[0] = $i;
            $minv = array_fill(0, $n + 1, INF);
            $used = array_fill(0, $n + 1, false);
            $j0 = 0;

            do {
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = INF;
                $j1 = 0;

                for ($j = 1; $j <= $n; $j++) {
                    if ($used[$j]) {
                        continue;
                    }

                    $cur = $cost[$i0 - 1][$j - 1] - $u[$i0] - $v[$j];
                    if ($cur < $minv[$j]) {
                        $minv[$j] = $cur;
                        $way[$j] = $j0;
                    }
                    if ($minv[$j] < $delta) {
                        $delta = $minv[$j];
                        $j1 = $j;
                    }
                }

                for ($j = 0; $j <= $n; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } else {
                        $minv[$j] -= $delta;
                    }
                }

                $j0 = $j1;
            } while ($p[$j0] !== 0);

            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }

        $assignment = array_fill(0, $n, 0);
        for ($j = 1; $j <= $n; $j++) {
            $assignment[$p[$j] - 1] = $j - 1;
        }

        return $assignment;
    }
}
