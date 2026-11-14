using TirageCadeauNoel.Net10.Cli;

namespace TirageCadeauNoel.Net10.Tests;

public class AppOptionsParserTests
{
    [Fact]
    public void Parse_WithSaveAndDraw_SetsSaveToTrue()
    {
        var options = AppOptionsParser.Parse(new[] { "--year", "2027", "--action", "tirage", "--save" });

        Assert.Equal(AppAction.Draw, options.Action);
        Assert.True(options.Save);
    }

    [Fact]
    public void Parse_WithSaveAndPossible_ThrowsArgumentException()
    {
        var ex = Assert.Throws<ArgumentException>(() => AppOptionsParser.Parse(new[] { "--year", "2027", "--action", "possible", "--save" }));
        Assert.Contains("--save", ex.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void Parse_WithPrintAction_ReadsPrintCorrectly()
    {
        var options = AppOptionsParser.Parse(new[] { "--year", "2027", "--action", "print" });

        Assert.Equal(AppAction.Print, options.Action);
        Assert.False(options.Save);
    }
}
