using TirageCadeauNoel.Net10.Models;

namespace TirageCadeauNoel.Net10.Services;

internal sealed class DrawService
{
    private readonly EdgeScoreCalculator _scoreCalculator;
    private readonly WeightedBipartiteMatcher _matcher;

    public DrawService(EdgeScoreCalculator scoreCalculator, WeightedBipartiteMatcher matcher)
    {
        _scoreCalculator = scoreCalculator;
        _matcher = matcher;
    }

    public Dictionary<string, string>? ComputeBestDraw(int targetYear, ConstraintsFile constraints, HistoryFile history, int? seed)
    {
        ValidateConsistency(constraints, history, targetYear);

        var users = constraints.Users.OrderBy(u => u, StringComparer.Ordinal).ToList();
        var n = users.Count;
        var weights = new long[n, n];
        var allowed = new bool[n, n];

        for (var i = 0; i < n; i++)
        {
            var giver = users[i];
            for (var j = 0; j < n; j++)
            {
                var recipient = users[j];
                var isAllowed = giver != recipient && !constraints.ForbiddenRecipientsByGiver[giver].Contains(recipient, StringComparer.Ordinal);
                allowed[i, j] = isAllowed;
                weights[i, j] = isAllowed
                    ? _scoreCalculator.ComputeScore(targetYear, giver, recipient, history, seed)
                    : long.MinValue / 4;
            }
        }

        var assignment = _matcher.SolveMaximumPerfectMatching(weights, allowed);
        if (assignment is null)
        {
            return null;
        }

        var result = new Dictionary<string, string>(StringComparer.Ordinal);
        for (var i = 0; i < n; i++)
        {
            result[users[i]] = users[assignment[i]];
        }

        return result;
    }

    public Dictionary<string, List<string>> GetPossibleRecipients(int targetYear, ConstraintsFile constraints, HistoryFile history, int? seed)
    {
        ValidateConsistency(constraints, history, targetYear);

        var result = new Dictionary<string, List<string>>(StringComparer.Ordinal);

        foreach (var giver in constraints.Users)
        {
            var candidates = constraints.Users
                .Where(recipient => giver != recipient && !constraints.ForbiddenRecipientsByGiver[giver].Contains(recipient, StringComparer.Ordinal))
                .Select(recipient => new
                {
                    Recipient = recipient,
                    Score = _scoreCalculator.ComputeScore(targetYear, giver, recipient, history, seed)
                })
                .OrderByDescending(x => x.Score)
                .ThenBy(x => x.Recipient, StringComparer.Ordinal)
                .Select(x => x.Recipient)
                .ToList();

            result[giver] = candidates;
        }

        return result;
    }

    private static void ValidateConsistency(ConstraintsFile constraints, HistoryFile history, int targetYear)
    {
        var users = constraints.Users.ToHashSet(StringComparer.Ordinal);

        foreach (var (year, assignments) in history.Years)
        {
            if (year >= targetYear)
            {
                continue;
            }

            if (assignments.Count != users.Count)
            {
                throw new InvalidOperationException($"Historique invalide {year}: {assignments.Count} receveurs pour {users.Count} utilisateurs.");
            }

            var seenGivers = new HashSet<string>(StringComparer.Ordinal);
            foreach (var (recipient, giver) in assignments)
            {
                if (!users.Contains(recipient))
                {
                    throw new InvalidOperationException($"Historique invalide {year}: receveur inconnu '{recipient}'.");
                }

                if (!users.Contains(giver))
                {
                    throw new InvalidOperationException($"Historique invalide {year}: donneur inconnu '{giver}'.");
                }

                if (!seenGivers.Add(giver))
                {
                    throw new InvalidOperationException($"Historique invalide {year}: donneur '{giver}' present plusieurs fois.");
                }
            }
        }
    }
}
