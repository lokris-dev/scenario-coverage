<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Coverage;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;
use SplFileInfo;

/**
 * Découvre l'univers complet des fichiers source d'un répertoire et fournit, pour
 * ceux qu'aucun test n'a exécutés, la liste de leurs lignes exécutables.
 *
 * Pourquoi : Xdebug ne remonte que les fichiers chargés pendant l'exécution. Les
 * fichiers jamais sollicités par un scénario sont donc absents des données de
 * couverture — ce qui fausse les KPI (dénominateur tronqué) et masque les
 * fichiers restant à tester. On rétablit la vérité en scannant le disque et en
 * comptant les lignes exécutables via l'analyseur statique de php-code-coverage
 * (même bibliothèque que PHPUnit ; présente dans l'environnement de test).
 *
 * Les lignes exécutables statiques diffèrent légèrement de ce que Xdebug compte
 * (signatures de fonctions, etc.) : c'est sans conséquence ici car ces fichiers
 * sont à 0 % (0 / N), et l'on ne mélange jamais les deux mesures sur un même
 * fichier (cf. {@see CoverageData::fileMap()}).
 */
final class SourceScanner
{
    /**
     * @param string   $srcRoot     Racine à scanner (absolue).
     * @param string[] $excludeDirs Noms de dossiers à ignorer (récursivement).
     */
    public function __construct(
        private readonly string $srcRoot,
        private readonly array  $excludeDirs = ['Tests', 'tests'],
    ) {}

    /**
     * Liste les .php sous srcRoot (hors excludeDirs) absents de $coveredFiles, et
     * renvoie pour chacun ses lignes exécutables. Les fichiers sans aucune ligne
     * exécutable (interfaces, configuration, enums purs) sont omis : ils n'ont
     * pas de sens dans une métrique de couverture (0 / 0).
     *
     * @param array<string, bool> $coveredFiles realpath => true des fichiers déjà couverts
     * @return array<string, list<int>> realpath => lignes exécutables
     */
    public function scanUncovered(array $coveredFiles): array
    {
        if ($this->srcRoot === '' || !is_dir($this->srcRoot)) {
            return [];
        }

        // L'analyse statique repose sur php-code-coverage. Absent (ex. hors env de
        // test) : on renonce silencieusement à l'univers plutôt que d'échouer.
        if (!class_exists(ParsingFileAnalyser::class)) {
            return [];
        }
        $analyser = new ParsingFileAnalyser(true, true);

        $excludeDirs = $this->excludeDirs;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->srcRoot, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current) use ($excludeDirs): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $excludeDirs, true);
                    }

                    return strtolower($current->getExtension()) === 'php';
                }
            )
        );

        $result = [];
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $path = $file->getRealPath();
            if ($path === false || isset($coveredFiles[$path])) {
                continue;
            }

            // Un fichier non analysable (syntaxe exotique, script de déploiement,
            // template .php) ne doit jamais interrompre la collecte : on l'ignore.
            try {
                $lines = array_map('intval', array_keys($analyser->executableLinesIn($path)));
            } catch (\Throwable) {
                continue;
            }
            if ($lines === []) {
                continue;
            }

            sort($lines);
            $result[$path] = $lines;
        }

        return $result;
    }
}
