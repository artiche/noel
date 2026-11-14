namespace TirageCadeauNoel.Net10.Models;

internal sealed class HistoryFile
{
    public required Dictionary<int, Dictionary<string, string>> Years { get; init; }
}
