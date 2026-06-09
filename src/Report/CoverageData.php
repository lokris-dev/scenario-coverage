<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Report;

use Lokris\ScenarioCoverage\Coverage\ScenarioRecord;
use Lokris\ScenarioCoverage\Coverage\ScenarioStore;

/**
 * Agrège les enregistrements de scénarios en statistiques exploitables.
 *
 * Cœur de calcul partagé : utilisé par {@see HtmlReporter} pour le rapport autonome,
 * mais aussi consommable par n'importe quel hôte (ex. une page de dashboard Symfony)
 * qui veut afficher la couverture par scénario / par fichier / par module — sans
 * réimplémenter l'agrégation.
 *
 * Toutes les méthodes de calcul sont mémoïsées (l'instance est immuable).
 */
final class CoverageData
{
    /** @var array<string, array<int, list<string>>>|null  [file][line][] = scenarioId */
    private ?array $fileMap = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $scenarioStats = null;

    /** @var array<string, array{covered:int,total:int,percent:int,scenarios:list<string>}>|null */
    private ?array $fileStats = null;

    /**
     * @param ScenarioRecord[]            $records
     * @param array<string, list<int>>    $sourceFiles  Fichiers source de l'univers
     *        JAMAIS touchés par un test, avec leurs lignes exécutables (analyse
     *        statique). Ils apparaissent à 0 % pour refléter l'avancement réel.
     */
    private function __construct(
        public readonly array  $records,
        public readonly string $srcRoot,
        public readonly string $generatedAt,
        public readonly array  $sourceFiles = [],
    ) {}

    /** Charge depuis le JSON produit par l'extension PHPUnit. */
    public static function fromFile(string $jsonFile): self
    {
        $data = ScenarioStore::loadFromFile($jsonFile);

        return new self($data['records'], $data['srcRoot'], $data['generatedAt'], $data['sourceFiles']);
    }

    /**
     * Construit directement depuis des enregistrements en mémoire.
     *
     * @param ScenarioRecord[]         $records
     * @param array<string, list<int>> $sourceFiles  cf. constructeur
     */
    public static function fromRecords(array $records, string $srcRoot = '', string $generatedAt = '', array $sourceFiles = []): self
    {
        return new self($records, $srcRoot, $generatedAt, $sourceFiles);
    }

    /**
     * Carte de couverture : [fichier][ligne][] = id de scénario.
     * Une ligne exécutable mais jamais couverte est présente avec une liste vide,
     * pour que le rapport puisse distinguer « non couverte » de « non exécutable ».
     *
     * Valeurs brutes Xdebug : 1 = exécutée, -1 = exécutable non exécutée,
     * -2 = code mort (XDEBUG_CC_DEAD_CODE — ex. accolade fermante après un
     * return). Le code mort n'est PAS exécutable : on l'écarte du total, comme
     * le fait php-code-coverage. Sans cela, ces lignes gonflent le dénominateur
     * et empêchent un fichier d'atteindre 100 % (divergence connue avec pcov,
     * qui ne les remonte pas).
     *
     * @return array<string, array<int, list<string>>>
     */
    public function fileMap(): array
    {
        if ($this->fileMap !== null) {
            return $this->fileMap;
        }

        $map = [];
        foreach ($this->records as $record) {
            foreach ($record->coverage as $file => $lines) {
                foreach ($lines as $lineNo => $covered) {
                    if ($covered === 1) {
                        $map[$file][$lineNo][] = $record->id;
                    } elseif ($covered === -1 && !isset($map[$file][$lineNo])) {
                        $map[$file][$lineNo] = [];
                    }
                    // $covered === -2 : code mort, ligne non exécutable → ignorée.
                }
            }
        }

        // Fichiers de l'univers jamais touchés par un test : toutes leurs lignes
        // exécutables (analyse statique) sont marquées non couvertes (liste vide).
        // On n'écrase JAMAIS un fichier déjà présent via les records : un fichier
        // testé garde ses données runtime (cohérentes, capables d'atteindre 100 %),
        // alors que la liste statique inclut des lignes — signatures notamment —
        // que Xdebug n'exécute jamais.
        foreach ($this->sourceFiles as $file => $lines) {
            if (isset($map[$file])) {
                continue;
            }
            foreach ($lines as $lineNo) {
                $map[$file][$lineNo] = [];
            }
        }

        return $this->fileMap = $map;
    }

    /**
     * Métadonnées + fichiers touchés, par scénario (indexé par id).
     *
     * @return array<string, array<string, mixed>>
     */
    public function scenarioStats(): array
    {
        if ($this->scenarioStats !== null) {
            return $this->scenarioStats;
        }

        $stats = [];
        foreach ($this->records as $record) {
            $files = [];
            foreach (array_keys($record->coverage) as $file) {
                $files[$file] = true; // dédup par clé
            }

            $stats[$record->id] = [
                'id'          => $record->id,
                'title'       => $record->title,
                'description' => $record->description,
                'status'      => $record->status,
                'duration'    => round($record->duration, 3),
                'mandatory'   => $record->mandatory,
                'tags'        => $record->tags,
                'files'       => array_keys($files),
            ];
        }

        return $this->scenarioStats = $stats;
    }

    /**
     * Statistiques par fichier : lignes couvertes / total exécutable / % / scénarios.
     *
     * @return array<string, array{covered:int,total:int,percent:int,scenarios:list<string>}>
     */
    public function fileStats(): array
    {
        if ($this->fileStats !== null) {
            return $this->fileStats;
        }

        $stats = [];
        foreach ($this->fileMap() as $file => $lines) {
            $total   = count($lines);
            $coveredLines = array_filter($lines, static fn(array $ids): bool => count($ids) > 0);
            $covered = count($coveredLines);
            $ids     = $coveredLines === []
                ? []
                : array_values(array_unique(array_merge(...array_values($coveredLines))));

            $stats[$file] = [
                'covered'   => $covered,
                'total'     => $total,
                'percent'   => $total > 0 ? (int) round(($covered / $total) * 100) : 0,
                'scenarios' => $ids,
            ];
        }

        return $this->fileStats = $stats;
    }

    /**
     * Statistiques globales (compteurs de scénarios par statut + couverture agrégée).
     *
     * @return array{files:int,filesCovered:int,scenarios:int,passed:int,failed:int,skipped:int,errored:int,globalCovered:int,globalTotal:int,globalPercent:int}
     */
    public function globalStats(): array
    {
        $byStatus = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($this->records as $record) {
            $byStatus[$record->status] = ($byStatus[$record->status] ?? 0) + 1;
        }

        $fileStats    = $this->fileStats();
        $covered      = array_sum(array_column($fileStats, 'covered'));
        $total        = array_sum(array_column($fileStats, 'total'));
        // Fichiers ayant au moins une ligne couverte : indicateur d'avancement.
        $filesCovered = count(array_filter($fileStats, static fn (array $s): bool => $s['covered'] > 0));

        return [
            'files'         => count($fileStats),
            'filesCovered'  => $filesCovered,
            'scenarios'     => count($this->records),
            'passed'        => $byStatus['passed'],
            'failed'        => $byStatus['failed'],
            'skipped'       => $byStatus['skipped'],
            'errored'       => $byStatus['error'],
            'globalCovered' => $covered,
            'globalTotal'   => $total,
            'globalPercent' => $total > 0 ? (int) round(($covered / $total) * 100) : 0,
        ];
    }

    /**
     * Agrège les stats de fichiers par groupe arbitraire (ex. module / bundle),
     * via une fonction qui mappe un chemin de fichier vers une clé de groupe.
     * Un fichier dont le mappeur renvoie null est ignoré.
     *
     * @param callable(string $filePath): ?string $grouper
     * @return array<string, array{covered:int,total:int,percent:int,files:int,scenarios:int}>
     */
    public function groupBy(callable $grouper): array
    {
        /** @var array<string, array{covered:int,total:int,files:int,_ids:array<string,true>}> $groups */
        $groups = [];

        foreach ($this->fileStats() as $file => $st) {
            $key = $grouper($file);
            if ($key === null) {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = ['covered' => 0, 'total' => 0, 'files' => 0, '_ids' => []];
            }
            $groups[$key]['covered'] += $st['covered'];
            $groups[$key]['total']   += $st['total'];
            $groups[$key]['files']   += 1;
            foreach ($st['scenarios'] as $sid) {
                $groups[$key]['_ids'][$sid] = true;
            }
        }

        $result = [];
        foreach ($groups as $key => $g) {
            $result[$key] = [
                'covered'   => $g['covered'],
                'total'     => $g['total'],
                'percent'   => $g['total'] > 0 ? (int) round(($g['covered'] / $g['total']) * 100) : 0,
                'files'     => $g['files'],
                'scenarios' => count($g['_ids']),
            ];
        }

        return $result;
    }
}
