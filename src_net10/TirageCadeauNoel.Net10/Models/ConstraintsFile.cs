namespace TirageCadeauNoel.Net10.Models;

internal sealed class ConstraintsFile
{
    public required List<string> Users { get; init; }

    public required Dictionary<string, List<string>> ForbiddenRecipientsByGiver { get; init; }
}
