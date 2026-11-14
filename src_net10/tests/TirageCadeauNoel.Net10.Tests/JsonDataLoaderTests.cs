using TirageCadeauNoel.Net10.Models;
using TirageCadeauNoel.Net10.Services;

namespace TirageCadeauNoel.Net10.Tests;

public class JsonDataLoaderTests
{
    [Fact]
    public void SaveHistory_PersistsAssignments()
    {
        var tempFile = Path.Combine(Path.GetTempPath(), $"history-{Guid.NewGuid():N}.json");
        try
        {
            var loader = new JsonDataLoader();
            var history = new HistoryFile
            {
                Years = new Dictionary<int, Dictionary<string, string>>
                {
                    [2027] = new Dictionary<string, string>
                    {
                        ["B"] = "A",
                        ["A"] = "B"
                    }
                }
            };

            loader.SaveHistory(tempFile, history);
            var reloaded = loader.LoadHistory(tempFile);

            Assert.True(reloaded.Years.ContainsKey(2027));
            Assert.Equal("A", reloaded.Years[2027]["B"]);
        }
        finally
        {
            if (File.Exists(tempFile))
            {
                File.Delete(tempFile);
            }
        }
    }
}
