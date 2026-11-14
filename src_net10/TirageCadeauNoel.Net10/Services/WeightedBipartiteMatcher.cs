namespace TirageCadeauNoel.Net10.Services;

internal sealed class WeightedBipartiteMatcher
{
    public int[]? SolveMaximumPerfectMatching(long[,] weights, bool[,] allowed)
    {
        var n = weights.GetLength(0);
        if (n != weights.GetLength(1) || n != allowed.GetLength(0) || n != allowed.GetLength(1))
        {
            throw new ArgumentException("Matrices invalides pour le matching biparti.");
        }

        var maxAllowedWeight = long.MinValue;
        var hasAllowedEdge = false;
        for (var i = 0; i < n; i++)
        {
            for (var j = 0; j < n; j++)
            {
                if (!allowed[i, j])
                {
                    continue;
                }

                hasAllowedEdge = true;
                maxAllowedWeight = Math.Max(maxAllowedWeight, weights[i, j]);
            }
        }

        if (!hasAllowedEdge)
        {
            return null;
        }

        var forbiddenCost = maxAllowedWeight + 10_000_000_000L;
        var cost = new long[n, n];
        for (var i = 0; i < n; i++)
        {
            for (var j = 0; j < n; j++)
            {
                cost[i, j] = allowed[i, j] ? maxAllowedWeight - weights[i, j] : forbiddenCost;
            }
        }

        var assignment = HungarianMinCost(cost);
        for (var i = 0; i < n; i++)
        {
            if (!allowed[i, assignment[i]])
            {
                return null;
            }
        }

        return assignment;
    }

    private static int[] HungarianMinCost(long[,] cost)
    {
        var n = cost.GetLength(0);
        var u = new long[n + 1];
        var v = new long[n + 1];
        var p = new int[n + 1];
        var way = new int[n + 1];

        for (var i = 1; i <= n; i++)
        {
            p[0] = i;
            var minv = new long[n + 1];
            var used = new bool[n + 1];
            Array.Fill(minv, long.MaxValue / 4);
            var j0 = 0;

            do
            {
                used[j0] = true;
                var i0 = p[j0];
                var delta = long.MaxValue / 4;
                var j1 = 0;

                for (var j = 1; j <= n; j++)
                {
                    if (used[j])
                    {
                        continue;
                    }

                    var cur = cost[i0 - 1, j - 1] - u[i0] - v[j];
                    if (cur < minv[j])
                    {
                        minv[j] = cur;
                        way[j] = j0;
                    }

                    if (minv[j] < delta)
                    {
                        delta = minv[j];
                        j1 = j;
                    }
                }

                for (var j = 0; j <= n; j++)
                {
                    if (used[j])
                    {
                        u[p[j]] += delta;
                        v[j] -= delta;
                    }
                    else
                    {
                        minv[j] -= delta;
                    }
                }

                j0 = j1;
            } while (p[j0] != 0);

            do
            {
                var j1 = way[j0];
                p[j0] = p[j1];
                j0 = j1;
            } while (j0 != 0);
        }

        var assignment = new int[n];
        for (var j = 1; j <= n; j++)
        {
            assignment[p[j] - 1] = j - 1;
        }

        return assignment;
    }
}
