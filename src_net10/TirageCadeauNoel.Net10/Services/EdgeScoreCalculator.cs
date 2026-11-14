using TirageCadeauNoel.Net10.Models;

namespace TirageCadeauNoel.Net10.Services;

internal sealed class EdgeScoreCalculator
{
    private const long NeverSeenBonus = 1_000_000_000L;
    private const long AgeMultiplier = 1_000_000L;
    private const int TieBreakMax = 999;

    public long ComputeScore(int targetYear, string giver, string recipient, HistoryFile history, int? seed)
    {
        var years = history.Years
            .Where(y => y.Key < targetYear && y.Value.TryGetValue(recipient, out var assignedGiver) && assignedGiver == giver)
            .Select(y => y.Key)
            .OrderBy(y => y)
            .ToList();

        var neverSeen = years.Count == 0;
        var age = neverSeen ? targetYear : targetYear - years.Max();
        var tieBreak = BuildDeterministicTieBreak(seed ?? 0, giver, recipient);

        return (neverSeen ? NeverSeenBonus : 0L) + (age * AgeMultiplier) + tieBreak;
    }

    private static int BuildDeterministicTieBreak(int seed, string giver, string recipient)
    {
        var hash = HashCode.Combine(seed, giver, recipient);
        var value = Math.Abs(hash % (TieBreakMax + 1));
        return value;
    }
}
