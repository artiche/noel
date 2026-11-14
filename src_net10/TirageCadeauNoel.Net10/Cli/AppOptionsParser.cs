namespace TirageCadeauNoel.Net10.Cli;

internal static class AppOptionsParser
{
    private const string DefaultHistoryPath = "history.json";
    private const string DefaultConstraintsPath = "constraints.json";

    public static AppOptions Parse(string[] args)
    {
        if (args.Length == 0)
        {
            return new AppOptions(DateTime.UtcNow.Year, AppAction.Draw, DefaultHistoryPath, DefaultConstraintsPath, null, false, false);
        }

        if (args.Any(a => a is "--help" or "-h"))
        {
            return new AppOptions(DateTime.UtcNow.Year, AppAction.Help, DefaultHistoryPath, DefaultConstraintsPath, null, false, true);
        }

        if (args[0].StartsWith("--", StringComparison.Ordinal))
        {
            return ParseNamedArguments(args);
        }

        return ParseLegacyPositionalArguments(args);
    }

    public static void PrintHelp()
    {
        Console.WriteLine("TirageCadeauNoel.Net10 - documentation utilisateur");
        Console.WriteLine();
        Console.WriteLine("USAGE (recommande):");
        Console.WriteLine("  TirageCadeauNoel.Net10 --year <yyyy> --action <tirage|possible|print|help> [options]");
        Console.WriteLine();
        Console.WriteLine("USAGE (compatibilite historique):");
        Console.WriteLine("  TirageCadeauNoel.Net10 [year [n [action]]]");
        Console.WriteLine("  - n est accepte pour compatibilite mais n'est pas utilise par l'algorithme net10.");
        Console.WriteLine();
        Console.WriteLine("PARAMETRES:");
        Console.WriteLine("  --year <yyyy>              Annee du tirage (1900..9999). Defaut: annee courante.");
        Console.WriteLine("  --action <...>             Action a executer:");
        Console.WriteLine("                             - tirage   : calcule le meilleur matching donneur->receveur");
        Console.WriteLine("                             - possible : liste les receveurs possibles tries par score");
        Console.WriteLine("                             - print    : affiche le tirage deja present dans l'historique");
        Console.WriteLine("                             - help     : affiche cette aide");
        Console.WriteLine("  --history-file <path>      Chemin du JSON d'historique. Defaut: history.json");
        Console.WriteLine("  --constraints-file <path>  Chemin du JSON de contraintes. Defaut: constraints.json");
        Console.WriteLine("  --seed <int>               Seed optionnelle pour stabiliser le tie-break aleatoire.");
        Console.WriteLine("  --save                     Enregistre le resultat dans le fichier history (action tirage uniquement).");
        Console.WriteLine("  --help, -h                 Affiche cette documentation.");
        Console.WriteLine();
        Console.WriteLine("EXEMPLES:");
        Console.WriteLine("  TirageCadeauNoel.Net10 --year 2027 --action tirage");
        Console.WriteLine("  TirageCadeauNoel.Net10 --year 2027 --action tirage --save");
        Console.WriteLine("  TirageCadeauNoel.Net10 --year 2027 --action possible --seed 42");
        Console.WriteLine("  TirageCadeauNoel.Net10 --year 2027 --action print");
        Console.WriteLine("  TirageCadeauNoel.Net10 2027 5 possible");
    }

    private static AppOptions ParseNamedArguments(string[] args)
    {
        var values = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        var save = false;
        for (var i = 0; i < args.Length; i++)
        {
            var arg = args[i];
            if (!arg.StartsWith("--", StringComparison.Ordinal))
            {
                throw new ArgumentException($"Argument inattendu: {arg}");
            }

            if (arg.Equals("--save", StringComparison.OrdinalIgnoreCase))
            {
                save = true;
                continue;
            }

            if (i + 1 >= args.Length || args[i + 1].StartsWith("--", StringComparison.Ordinal))
            {
                throw new ArgumentException($"Valeur manquante pour l'option {arg}");
            }

            values[arg] = args[i + 1];
            i++;
        }

        var year = ReadYear(values.TryGetValue("--year", out var yearValue) ? yearValue : DateTime.UtcNow.Year.ToString());
        var action = ReadAction(values.TryGetValue("--action", out var actionValue) ? actionValue : "tirage");
        var historyPath = values.TryGetValue("--history-file", out var historyValue) ? historyValue : DefaultHistoryPath;
        var constraintsPath = values.TryGetValue("--constraints-file", out var constraintsValue) ? constraintsValue : DefaultConstraintsPath;
        var seed = values.TryGetValue("--seed", out var seedValue) ? (int?)ReadSeed(seedValue) : null;

        if (save && action != AppAction.Draw)
        {
            throw new ArgumentException("L'option --save est valide uniquement avec l'action tirage.");
        }

        return new AppOptions(year, action, historyPath, constraintsPath, seed, save, action == AppAction.Help);
    }

    private static AppOptions ParseLegacyPositionalArguments(string[] args)
    {
        var year = DateTime.UtcNow.Year;
        if (args.Length >= 1)
        {
            year = ReadYear(args[0]);
        }

        var action = AppAction.Draw;
        if (args.Length >= 2 && !int.TryParse(args[1], out _))
        {
            action = ReadAction(args[1]);
        }
        else if (args.Length >= 3)
        {
            action = ReadAction(args[2]);
        }

        return new AppOptions(year, action, DefaultHistoryPath, DefaultConstraintsPath, null, false, action == AppAction.Help);
    }

    private static int ReadYear(string input)
    {
        if (!int.TryParse(input, out var year) || year < 1900 || year > 9999)
        {
            throw new ArgumentException($"Année invalide: {input}");
        }

        return year;
    }

    private static int ReadSeed(string input)
    {
        if (!int.TryParse(input, out var seed))
        {
            throw new ArgumentException($"Seed invalide: {input}");
        }

        return seed;
    }

    private static AppAction ReadAction(string input)
    {
        return input.ToLowerInvariant() switch
        {
            "tirage" => AppAction.Draw,
            "possible" => AppAction.Possible,
            "print" => AppAction.Print,
            "help" => AppAction.Help,
            _ => throw new ArgumentException($"Action invalide: {input}")
        };
    }
}
