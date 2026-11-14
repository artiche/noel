namespace TirageCadeauNoel.Net10.Cli;

internal enum AppAction
{
    Draw,
    Possible,
    Print,
    Help
}

internal sealed record AppOptions(
    int Year,
    AppAction Action,
    string HistoryFilePath,
    string ConstraintsFilePath,
    int? Seed,
    bool Save,
    bool ShowHelp);
