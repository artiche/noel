using System.Text.Json;
using TirageCadeauNoel.Net10.Models;

namespace TirageCadeauNoel.Net10.Services;

internal sealed class JsonDataLoader
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true
    };

    public ConstraintsFile LoadConstraints(string path)
    {
        var fullPath = ResolvePath(path);
        var constraints = ReadJson<ConstraintsFile>(fullPath);
        ValidateConstraints(constraints, fullPath);
        return constraints;
    }

    public HistoryFile LoadHistory(string path)
    {
        var fullPath = ResolvePath(path);
        var history = ReadJson<HistoryFile>(fullPath);
        ValidateHistory(history, fullPath);
        return history;
    }

    public void SaveHistory(string path, HistoryFile history)
    {
        var fullPath = ResolvePath(path);
        var directory = Path.GetDirectoryName(fullPath);
        if (!string.IsNullOrWhiteSpace(directory))
        {
            Directory.CreateDirectory(directory);
        }

        ValidateHistory(history, fullPath);
        var json = JsonSerializer.Serialize(history, JsonOptions);
        File.WriteAllText(fullPath, json);
    }

    private static T ReadJson<T>(string path)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException($"Fichier introuvable: {path}");
        }

        var json = File.ReadAllText(path);
        var model = JsonSerializer.Deserialize<T>(json, JsonOptions);
        if (model is null)
        {
            throw new InvalidOperationException($"Impossible de lire le JSON: {path}");
        }

        return model;
    }

    private static void ValidateConstraints(ConstraintsFile constraints, string path)
    {
        if (constraints.Users.Count == 0)
        {
            throw new InvalidOperationException($"Aucun utilisateur dans {path}");
        }

        var duplicateUser = constraints.Users
            .GroupBy(u => u, StringComparer.Ordinal)
            .FirstOrDefault(g => g.Count() > 1);
        if (duplicateUser is not null)
        {
            throw new InvalidOperationException($"Utilisateur dupliqué '{duplicateUser.Key}' dans {path}");
        }

        foreach (var user in constraints.Users)
        {
            if (!constraints.ForbiddenRecipientsByGiver.ContainsKey(user))
            {
                constraints.ForbiddenRecipientsByGiver[user] = new List<string>();
            }
        }

        foreach (var (giver, forbiddenRecipients) in constraints.ForbiddenRecipientsByGiver)
        {
            if (!constraints.Users.Contains(giver, StringComparer.Ordinal))
            {
                throw new InvalidOperationException($"Donneur inconnu '{giver}' dans {path}");
            }

            foreach (var recipient in forbiddenRecipients)
            {
                if (!constraints.Users.Contains(recipient, StringComparer.Ordinal))
                {
                    throw new InvalidOperationException($"Receveur interdit inconnu '{recipient}' pour '{giver}' dans {path}");
                }
            }
        }
    }

    private static void ValidateHistory(HistoryFile history, string path)
    {
        foreach (var (year, assignments) in history.Years)
        {
            var duplicateGiver = assignments
                .GroupBy(kv => kv.Value, StringComparer.Ordinal)
                .FirstOrDefault(g => g.Count() > 1);
            if (duplicateGiver is not null)
            {
                throw new InvalidOperationException($"Historique invalide {year}: donneur '{duplicateGiver.Key}' attribue plusieurs receveurs dans {path}");
            }
        }
    }

    private static string ResolvePath(string path)
    {
        if (Path.IsPathRooted(path))
        {
            return path;
        }

        return Path.GetFullPath(Path.Combine(Directory.GetCurrentDirectory(), path));
    }
}
