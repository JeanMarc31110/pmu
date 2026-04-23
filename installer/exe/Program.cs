using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Text;

namespace PMUInstaller
{
    internal static class Program
    {
        private static int Main(string[] args)
        {
            string scriptPath = null;

            try
            {
                scriptPath = ExtractEmbeddedScript();
                return RunPowerShell(scriptPath, args);
            }
            catch (Exception ex)
            {
                Console.Error.WriteLine(ex.Message);
                return 1;
            }
            finally
            {
                TryDelete(scriptPath);
            }
        }

        private static string ExtractEmbeddedScript()
        {
            Assembly assembly = Assembly.GetExecutingAssembly();
            string resourceName = assembly
                .GetManifestResourceNames()
                .FirstOrDefault(name => name.EndsWith("install-pmu.ps1", StringComparison.OrdinalIgnoreCase));

            if (resourceName == null)
            {
                throw new FileNotFoundException("Le script d'installation embarqué est introuvable.");
            }

            string tempFile = Path.Combine(Path.GetTempPath(), "pmu-install-" + Guid.NewGuid().ToString("N") + ".ps1");

            using (Stream resourceStream = assembly.GetManifestResourceStream(resourceName))
            {
                if (resourceStream == null)
                {
                    throw new FileNotFoundException("Le flux du script embarqué est introuvable.");
                }

                using (FileStream fileStream = File.Create(tempFile))
                {
                    resourceStream.CopyTo(fileStream);
                }
            }

            return tempFile;
        }

        private static int RunPowerShell(string scriptPath, string[] args)
        {
            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = "powershell.exe";
            psi.UseShellExecute = false;

            string arguments = "-NoProfile -ExecutionPolicy Bypass -File " + QuoteArgument(scriptPath);
            if (args != null && args.Length > 0)
            {
                arguments += " " + string.Join(" ", args.Select(QuoteArgument).ToArray());
            }
            psi.Arguments = arguments;

            using (Process process = Process.Start(psi))
            {
                if (process == null)
                {
                    throw new InvalidOperationException("Impossible de démarrer PowerShell.");
                }

                process.WaitForExit();
                return process.ExitCode;
            }
        }

        private static string QuoteArgument(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return "\"\"";
            }

            bool needsQuotes = value.IndexOfAny(new char[] { ' ', '\t', '\n', '\v', '"' }) >= 0;
            if (!needsQuotes)
            {
                return value;
            }

            StringBuilder builder = new StringBuilder();
            builder.Append('"');

            int backslashes = 0;
            for (int i = 0; i < value.Length; i++)
            {
                char c = value[i];
                if (c == '\\')
                {
                    backslashes++;
                    continue;
                }

                if (c == '"')
                {
                    builder.Append(new string('\\', backslashes * 2 + 1));
                    builder.Append('"');
                    backslashes = 0;
                    continue;
                }

                if (backslashes > 0)
                {
                    builder.Append(new string('\\', backslashes));
                    backslashes = 0;
                }

                builder.Append(c);
            }

            if (backslashes > 0)
            {
                builder.Append(new string('\\', backslashes * 2));
            }

            builder.Append('"');
            return builder.ToString();
        }

        private static void TryDelete(string path)
        {
            try
            {
                if (!string.IsNullOrEmpty(path) && File.Exists(path))
                {
                    File.Delete(path);
                }
            }
            catch
            {
                // ignore cleanup failures
            }
        }
    }
}
