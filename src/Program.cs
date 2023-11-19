namespace TirageCadeauNoel
{
    internal class Program
    {
        static void Main(string[] args)
        {
            var annee = DateTime.Now.Year;
            var n = 0;
            var action = "tirage";
            var printHelp = false;

            if (args.Length > 0)
            {
                if(!int.TryParse(args[0], out annee))
                {
                    action = "help";
                }

                if (args.Length > 1)
                {
                   if(! int.TryParse(args[1], out n))
                    {
                        action = "help";
                    }
                }
            }

            if (args.Length > 2)
            {
                action = args[2].ToLower();
            }

            TirageNoelHelper helper = new TirageNoelHelper();

            switch (action)
            {
                case "tirage":
                    {
                        Console.WriteLine($"Tirage {annee} ({n}):");

                        var t = helper.EssaiTirage(annee, n, 5);

                        if (t == null)
                        {
                            Console.WriteLine($"failed to find a solution after 5 retry");
                        }
                        else
                        {

                            foreach (var k in t.Keys)
                            {

                                Console.WriteLine(k + ":" + t[k]);
                            }
                        }
                    }
                    break;
                case "possible":
                    {
                        Console.WriteLine($"Possibilités {annee} ({n}):");

                        var p = helper.GetPossibles(annee, n);

                        foreach (var k in p.Keys)
                        {
                            var lst = "";
                            if (p[k].Count > 0)
                            {
                                lst = p[k].Aggregate((a, b) => a + "," + b);
                            }
                            Console.WriteLine(k + ":" + lst);
                        };
                    }
                    break;
                case "help":
                    {
                        Console.WriteLine("TirageCadeauNoel [annee [n [action]]]");
                        Console.WriteLine("annee : yyyy, annee de référence");
                        Console.WriteLine("n : nombre d'annees à vérifier dans le passé");
                        Console.WriteLine("action : {tirage , possible , help}");
                    }
                    break;
            }


        }
    }
}