using TirageCadeauNoel.Net10.Cli;
using TirageCadeauNoel.Net10.Models;
using TirageCadeauNoel.Net10.Services;

var options = AppOptionsParser.Parse(args);
if (options.ShowHelp)
{
    AppOptionsParser.PrintHelp();
    return 0;
}

try
{
    var dataLoader = new JsonDataLoader();
    var constraints = dataLoader.LoadConstraints(options.ConstraintsFilePath);
    var history = dataLoader.LoadHistory(options.HistoryFilePath);

    var drawService = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());

    switch (options.Action)
    {
        case AppAction.Possible:
        {
            Console.WriteLine($"Possibilités {options.Year}:");
            var possible = drawService.GetPossibleRecipients(options.Year, constraints, history, options.Seed);
            foreach (var giver in constraints.Users.OrderBy(u => u, StringComparer.Ordinal))
            {
                var recipients = possible[giver];
                var list = recipients.Count == 0 ? string.Empty : string.Join(",", recipients);
                Console.WriteLine($"{giver}:{list}");
            }

            return 0;
        }
        case AppAction.Draw:
        {
            Console.WriteLine($"Tirage {options.Year}:");
            var draw = drawService.ComputeBestDraw(options.Year, constraints, history, options.Seed);
            if (draw is null)
            {
                Console.Error.WriteLine("Aucune solution valide trouvée avec les contraintes hard.");
                return 2;
            }

            foreach (var giver in constraints.Users.OrderBy(u => u, StringComparer.Ordinal))
            {
                Console.WriteLine($"{giver}:{draw[giver]}");
            }

            if (options.Save)
            {
                if (history.Years.ContainsKey(options.Year))
                {
                    throw new InvalidOperationException($"L'historique contient deja un tirage pour {options.Year}. Utilisez une autre annee ou modifiez le fichier manuellement.");
                }

                var recipientToGiver = draw.ToDictionary(kv => kv.Value, kv => kv.Key, StringComparer.Ordinal);
                history.Years[options.Year] = recipientToGiver;
                dataLoader.SaveHistory(options.HistoryFilePath, history);
                Console.WriteLine($"Tirage enregistre dans {options.HistoryFilePath}");
            }

            return 0;
        }
        case AppAction.Print:
        {
            if (!history.Years.TryGetValue(options.Year, out var yearDraw))
            {
                Console.WriteLine($"Aucun tirage trouve pour l'annee {options.Year}.");
                return 0;
            }

            var giverToRecipient = yearDraw.ToDictionary(kv => kv.Value, kv => kv.Key, StringComparer.Ordinal);
            foreach (var giver in constraints.Users.OrderBy(u => u, StringComparer.Ordinal))
            {
                if (!giverToRecipient.TryGetValue(giver, out var recipient))
                {
                    throw new InvalidOperationException($"Historique invalide: le donneur '{giver}' est absent du tirage {options.Year}.");
                }

                Console.WriteLine($"{giver} offre un cadeau a {recipient}");
            }

            return 0;
        }
        default:
            AppOptionsParser.PrintHelp();
            return 1;
    }
}
catch (Exception ex)
{
    Console.Error.WriteLine(ex.Message);
    if (ex is ArgumentException)
    {
        Console.Error.WriteLine();
        AppOptionsParser.PrintHelp();
    }

    return 1;
}
