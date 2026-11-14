using TirageCadeauNoel.Net10.Cli;

namespace TirageCadeauNoel.Net10.Tests;

public class CliHelpTests
{
    [Fact]
    public void PrintHelp_IncludesUserDocumentationAndParameters()
    {
        var originalOut = Console.Out;
        var writer = new StringWriter();
        Console.SetOut(writer);
        try
        {
            AppOptionsParser.PrintHelp();
        }
        finally
        {
            Console.SetOut(originalOut);
        }

        var text = writer.ToString();
        Assert.Contains("documentation utilisateur", text, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("--year <yyyy>", text, StringComparison.Ordinal);
        Assert.Contains("--action <tirage|possible|print|help>", text, StringComparison.Ordinal);
        Assert.Contains("--history-file <path>", text, StringComparison.Ordinal);
        Assert.Contains("--constraints-file <path>", text, StringComparison.Ordinal);
        Assert.Contains("--seed <int>", text, StringComparison.Ordinal);
        Assert.Contains("--save", text, StringComparison.Ordinal);
        Assert.Contains("--help, -h", text, StringComparison.Ordinal);
    }
}
