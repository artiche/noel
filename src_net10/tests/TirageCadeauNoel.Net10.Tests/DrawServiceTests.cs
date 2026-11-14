using TirageCadeauNoel.Net10.Models;
using TirageCadeauNoel.Net10.Services;

namespace TirageCadeauNoel.Net10.Tests;

public class DrawServiceTests
{
    [Fact]
    public void ComputeBestDraw_ReturnsPerfectMatching_WhenConstraintsAllow()
    {
        var constraints = new ConstraintsFile
        {
            Users = new List<string> { "A", "B", "C" },
            ForbiddenRecipientsByGiver = new Dictionary<string, List<string>>
            {
                ["A"] = new List<string>(),
                ["B"] = new List<string>(),
                ["C"] = new List<string>()
            }
        };

        var history = new HistoryFile
        {
            Years = new Dictionary<int, Dictionary<string, string>>
            {
                [2025] = new Dictionary<string, string>
                {
                    ["B"] = "A",
                    ["C"] = "B",
                    ["A"] = "C"
                }
            }
        };

        var service = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());
        var result = service.ComputeBestDraw(2026, constraints, history, seed: 123);

        Assert.NotNull(result);
        Assert.Equal(3, result!.Count);
        Assert.Equal(3, result.Values.Distinct().Count());
        Assert.DoesNotContain(result, kv => kv.Key == kv.Value);
    }

    [Fact]
    public void EdgeScoreCalculator_PrefersNeverSeenPair_OverSeenPair()
    {
        var history = new HistoryFile
        {
            Years = new Dictionary<int, Dictionary<string, string>>
            {
                [2025] = new Dictionary<string, string>
                {
                    ["B"] = "A"
                }
            }
        };

        var scorer = new EdgeScoreCalculator();
        var seenScore = scorer.ComputeScore(2026, "A", "B", history, seed: 7);
        var neverSeenScore = scorer.ComputeScore(2026, "A", "C", history, seed: 7);

        Assert.True(neverSeenScore > seenScore);
    }

    [Fact]
    public void WeightedBipartiteMatcher_ReturnsNull_WhenPerfectMatchingImpossible()
    {
        var matcher = new WeightedBipartiteMatcher();
        var weights = new long[,]
        {
            { 10, 0 },
            { 10, 0 }
        };
        var allowed = new bool[,]
        {
            { true, false },
            { true, false }
        };

        var result = matcher.SolveMaximumPerfectMatching(weights, allowed);

        Assert.Null(result);
    }
}
