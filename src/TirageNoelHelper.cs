using System;
using System.Collections.Generic;
using System.Linq;
using System.Runtime.Serialization.Formatters;
using System.Text;
using System.Threading.Tasks;

namespace TirageCadeauNoel
{
    internal class TirageNoelHelper
    {

        private List<string> Users = new List<string>(new string[] { "Maxime", "Valentine", "Natacha", "Beatrice", "Bernard", "Quentin", "Sylvain" });

        private Dictionary<string, List<string>> Lien = new System.Collections.Generic.Dictionary<string, List<string>>
        {
            {"Maxime", new List<string>() },
            { "Valentine", new List<string> { "Quentin" } },
            { "Natacha",  new List<string> { "Sylvain" } },
            { "Beatrice",  new List<string> { "Bernard" } },
            { "Bernard",   new List<string> { "Beatrice" } },
            { "Quentin",  new List<string> { "Valentine" } },
            { "Sylvain",  new List<string> { "Natacha" } }
        };


        private Dictionary<int, Dictionary<string, string>> TiragesPrecedents =
            new Dictionary<int, Dictionary<string, string>>
            {
                {
                    2021,
                    new Dictionary<string, string> {
                        {"Maxime","Beatrice"},
                        {"Valentine","Sylvain"},
                        {"Natacha","Maxime"},
                        {"Beatrice","Valentine"},
                        {"Bernard","Natacha"},
                        {"Quentin","Bernard"},
                        {"Sylvain","Quentin"},
                    }
                },
                {
                    2022,
                    new Dictionary<string, string> {
                        {"Maxime","Sylvain"},
                        {"Valentine","Maxime"},
                        {"Natacha","Beatrice"},
                        {"Beatrice","Quentin"},
                        {"Bernard","Valentine"},
                        {"Quentin","Natacha"},
                        {"Sylvain","Bernard"}
                    }
                },
                {
                    2023,
                    new Dictionary<string, string>
                    {
                        {"Maxime","Valentine"},
                        {"Valentine","Beatrice"},
                        {"Natacha","Bernard"},
                        {"Beatrice","Natacha"},
                        {"Bernard","Quentin"},
                        {"Quentin","Sylvain"},
                        {"Sylvain","Maxime"}
                    }
                },
                {
                    2024,
                    new Dictionary<string, string>
                    {
                        {"Maxime","Natacha"},
                        {"Valentine","Bernard"},
                        {"Natacha","Quentin"},
                        {"Beatrice","Sylvain"},
                        {"Bernard","Maxime"},
                        {"Quentin","Beatrice"},
                        {"Sylvain","Valentine"},
                    }
                },
                {
                    2025,
                    new Dictionary<string, string>
                    {
                        {"Maxime","Bernard"},
                        {"Valentine","Natacha"},
                        {"Natacha","Valentine"},
                        {"Beatrice","Quentin"},
                        {"Bernard","Sylvain"},
                        {"Quentin","Maxime"},
                        {"Sylvain","Beatrice"},
                    }
                }
            };

        /// <summary>
        /// retourne les choix possible pour chacun
        /// </summary>
        /// <param name="annee">année tirage</param>
        /// <param name="n"> nombre d'année passées à considérer</param>
        /// <returns></returns>
        public Dictionary<string, List<string>> GetPossibles(int annee, int n)
        {
            Dictionary<string, List<string>> result = new Dictionary<string, List<string>>();

            var checkDoublons = new Dictionary<string, List<string>>();


            foreach (var user in Users)
            {
                var p = new List<string>(Users.ToArray());
                p.Remove(user);
                p.RemoveAll(p => Lien[user].Contains(p));
                result.Add(user, p);

                for (int i = 1; i <= n; i++)
                {
                    if (TiragesPrecedents.ContainsKey(annee - i))
                    {
                        p.RemoveAll(a => TiragesPrecedents[annee - i][user].Contains(a));
                        if(p.Count==1)
                        {
                            if(!checkDoublons.ContainsKey(p[0]))
                            {
                                checkDoublons.Add(p[0], new List<string>());
                            }
                            checkDoublons[p[0]].Add(user);
                        }
                        else if(p.Count==0)
                        {
                            p.AddRange(Users.ToArray());
                            p.Remove(user);
                            p.RemoveAll(p => Lien[user].Contains(p));
                        }
                    }
                }
            }

            var doublons = checkDoublons.Where((a) => a.Value.Count > 1).Select(a => a).ToList();
            
            if (doublons.Count>0)
            {
                foreach(var d in doublons)
                {
                    foreach(var elt in d.Value)
                    {
                        var eltToAdd = new List<string>(result[d.Key]);

                        eltToAdd.Remove(elt);
                        eltToAdd.RemoveAll(p => Lien[elt].Contains(p));
                        eltToAdd.RemoveAll(p => result[elt].Contains(p));
                        result[elt].AddRange(eltToAdd);
                    }
                }
            }

            return result;
        }


        public Dictionary<string, string>? EssaiTirage(int annee, int n, int nbRetryOnFail)
        {
            Dictionary<string, string> result = new Dictionary<string, string>();

            try
            {

                Random rand = new Random(DateTime.Now.Millisecond);

                var ordreTirageUser = new List<string>();
                var possibilites = GetPossibles(annee, n);

                for (int i = 0; i < Users.Count; i++)
                {
                    int u = rand.Next(Users.Count);

                    while (ordreTirageUser.Contains(Users[u]))
                    {
                        u = rand.Next(Users.Count);
                    }
                    ordreTirageUser.Add(Users[u]);
                }

                foreach (var u in ordreTirageUser)
                {
                    if (possibilites[u].Count == 0)
                    {
                        throw new Exception("failed to find a solution");
                        //result[u] = "";
                        //continue;
                    }

                    var i = rand.Next(possibilites[u].Count);

                    result[u] = possibilites[u][i];

                    foreach (var k in possibilites.Keys)
                    {
                        if (k == u) continue;

                        possibilites[k].Remove(possibilites[u][i]);
                    }
                }

                return result;
            } catch(Exception e)
            {
                if (nbRetryOnFail > 0) { return EssaiTirage(annee, n, nbRetryOnFail - 1); }
                else
                {
                    return null;
                }
            }
        }
    }
}
