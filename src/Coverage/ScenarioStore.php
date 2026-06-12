<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Coverage;

use RuntimeException;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

/**
 * Accumule les enregistrements de scénario pendant l'exécution de la suite PHPUnit,
 * puis les persiste sur disque en JSON pour être lus par le générateur de rapport.
 *
 * Cycle de vie :
 *   1. beginScenario()  — appelé avant le test, démarre la capture (Xdebug ou pcov)
 *   2. endScenario()    — appelé après le test, stocke le ScenarioRecord en mémoire
 *   3. flush()            — appelé en fin d'exécution, écrit le fichier JSON
 */
final class ScenarioStore
{
    /** @var ScenarioRecord[] */
    private array $records = [];

    /** Xdebug/pcov disponible ? */
    private readonly bool $coverageAvailable;

    /**
     * Moteur de collecte détecté : 'xdebug', 'pcov' ou null.
     * Détermine quelles fonctions sont appelées pour démarrer/lire la couverture.
     */
    private readonly ?string $driver;

    /**
     * Préfixe de filtrage normalisé (srcRoot + séparateur), ou '' si aucun srcRoot.
     * Le séparateur final évite les collisions de préfixe : un srcRoot 'src' ne doit
     * pas accepter par erreur des chemins comme 'srctest/Foo.php'.
     */
    private readonly string $srcPrefix;

    /**
     * Statut du test courant. 'passed' par défaut ; écrasé par les subscribers
     * Failed/Errored/Skipped quand l'event correspondant survient avant la fin du test.
     */
    private string $currentStatus = 'passed';

    /**
     * Map "id court → FQCN" pour désambiguïser les collisions de noms courts
     * entre namespaces différents (sinon écrasement silencieux dans le rapport).
     *
     * @var array<string, string>
     */
    private array $usedIds = [];

    /**
     * Map "FQCN de classe de test → index dans $records". Une classe #[Scenario]
     * est UN scénario : ses méthodes de test multiples (parcours nominal, accès
     * refusé, variantes…) sont agrégées dans un seul enregistrement.
     *
     * @var array<string, int>
     */
    private array $indexByClass = [];

    public function __construct(
        /** Chemin du fichier JSON de sortie (ex: var/scenario-coverage.json) */
        private readonly string $outputFile,
        /** Seuls les fichiers sous ce chemin sont inclus dans le coverage (ex: src/) */
        private readonly string $srcRoot,
        /**
         * Noms de dossiers exclus du coverage (récursivement) : les fichiers de
         * test ne sont pas du code de production et ne doivent pas peser sur les
         * statistiques. Symétrique de l'exclusion appliquée par SourceScanner.
         *
         * @var string[]
         */
        private readonly array $excludeDirs = ['Tests', 'tests'],
    ) {
        // Détection du moteur de coverage. pcov expose ses fonctions dans le
        // namespace \pcov\* (et NON pcov_*) : il faut donc tester '\pcov\start'.
        if (function_exists('xdebug_start_code_coverage')) {
            $this->driver = 'xdebug';
        } elseif (function_exists('pcov\\start')) {
            $this->driver = 'pcov';
        } else {
            $this->driver = null;
        }
        $this->coverageAvailable = $this->driver !== null;

        // Préfixe de filtrage avec séparateur final (anti-collision de préfixe).
        $this->srcPrefix = $srcRoot !== ''
            ? rtrim($srcRoot, '/\\') . DIRECTORY_SEPARATOR
            : '';
    }

    /**
     * Démarre la collecte de coverage pour un test.
     * À appeler juste avant l'exécution du test.
     */
    public function beginScenario(): void
    {
        // Réinitialiser le statut : 'passed' par défaut, écrasé si un event
        // Failed/Errored/Skipped survient pour ce test avant son Finished.
        $this->currentStatus = 'passed';

        if ($this->driver === 'xdebug') {
            xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
        } elseif ($this->driver === 'pcov') {
            // pcov collecte en continu : on remet le buffer à zéro puis on (ré)active
            // la collecte, afin de n'attribuer au test que les lignes qu'il exécute.
            \pcov\clear();
            \pcov\start();
        }
    }

    /**
     * Marque le statut du test courant. Appelé par les subscribers
     * Failed/Errored/Skipped, qui se déclenchent avant l'event Finished.
     */
    public function markStatus(string $status): void
    {
        $this->currentStatus = $status;
    }

    /** Statut résolu du test courant ('passed' si aucun échec/skip signalé). */
    public function currentStatus(): string
    {
        return $this->currentStatus;
    }

    /**
     * Arrête la collecte et stocke l'enregistrement en mémoire.
     *
     * @param string   $testClass FQCN de la classe de test
     * @param string   $testFile  Chemin absolu du fichier de test
     * @param string   $status    passed|failed|skipped|error
     * @param float    $duration  Durée en secondes
     * @param string   $title     Titre de la scénario (#[Scenario])
     * @param string   $desc      Description (#[Scenario])
     * @param bool     $mandatory Obligatoire (#[Scenario])
     * @param string[] $tags      Tags (#[Scenario])
     */
    public function endScenario(
        string $testClass,
        string $testFile,
        string $status,
        float  $duration,
        string $title,
        string $desc,
        bool   $mandatory,
        array  $tags,
    ): void {
        $rawCoverage = [];

        if ($this->driver === 'xdebug') {
            $rawCoverage = xdebug_get_code_coverage();
            xdebug_stop_code_coverage(false);
        } elseif ($this->driver === 'pcov') {
            \pcov\stop();
            // pcov\collect() renvoie [fichier => [ligne => nb d'exécutions]]. On
            // normalise au format Xdebug attendu par la suite du pipeline et par le
            // rapport : exécutée (>0) → 1 ; exécutable non exécutée → -1.
            foreach (\pcov\collect() as $file => $lines) {
                foreach ($lines as $lineNo => $count) {
                    $rawCoverage[$file][$lineNo] = $count > 0 ? 1 : -1;
                }
            }
            \pcov\clear();
        }

        // Filtrer sur srcRoot, normaliser les chemins, et n'inclure que les fichiers
        // où AU MOINS une ligne a réellement été exécutée (valeur == 1).
        // Avec CC_UNUSED, Xdebug remonte aussi les fichiers seulement autoloadés
        // (toutes lignes à -1/-2) : les garder gonflerait la liste des fichiers
        // "couverts" par la scénario et alourdirait massivement le JSON.
        // On conserve en revanche les valeurs brutes (1/-1/-2) des fichiers retenus,
        // pour que le rapport puisse colorer les lignes couvertes vs non couvertes.
        $filtered = [];
        foreach ($rawCoverage as $file => $lines) {
            $realFile = realpath($file) ?: $file;
            if ($this->srcPrefix !== '' && !str_starts_with($realFile, $this->srcPrefix)) {
                continue;
            }
            if ($this->isExcluded($realFile)) {
                continue;
            }
            if (in_array(1, $lines, true)) {
                $filtered[$realFile] = $lines;
            }
        }

        // Une classe #[Scenario] = UN scénario. Si elle a déjà produit un
        // enregistrement (autre méthode de test de la même classe), on fusionne :
        // union du coverage, durée cumulée, pire statut. On ne crée pas de doublon.
        if (isset($this->indexByClass[$testClass])) {
            $i    = $this->indexByClass[$testClass];
            $prev = $this->records[$i];

            $this->records[$i] = new ScenarioRecord(
                id:          $prev->id,
                title:       $prev->title,
                description: $prev->description,
                mandatory:   $prev->mandatory,
                tags:        $prev->tags,
                testClass:   $prev->testClass,
                testFile:    $prev->testFile,
                status:      $this->worstStatus($prev->status, $status),
                duration:    $prev->duration + $duration,
                coverage:    $this->mergeCoverage($prev->coverage, $filtered),
            );

            return;
        }

        // ID = nom court de la classe de test. strrpos retourne false en l'absence de
        // namespace : on garde alors le FQCN complet (évite de tronquer le 1er caractère).
        $pos     = strrpos($testClass, '\\');
        $shortId = $pos === false ? $testClass : substr($testClass, $pos + 1);

        // Désambiguïser les collisions de nom court entre namespaces distincts,
        // sinon deux scénarios différentes s'écraseraient silencieusement.
        $id     = $shortId;
        $suffix = 2;
        while (isset($this->usedIds[$id]) && $this->usedIds[$id] !== $testClass) {
            $id = $shortId . '#' . $suffix++;
        }
        $this->usedIds[$id] = $testClass;

        $this->indexByClass[$testClass] = count($this->records);
        $this->records[] = new ScenarioRecord(
            id:          $id,
            title:       $title,
            description: $desc,
            mandatory:   $mandatory,
            tags:        $tags,
            testClass:   $testClass,
            testFile:    $testFile,
            status:      $status,
            duration:    $duration,
            coverage:    $filtered,
        );
    }

    /**
     * Fusionne deux cartes de coverage brut Xdebug ([fichier][ligne] => 1|-1|-2).
     * Une ligne couverte (1) prime sur exécutable-non-couverte (-1), qui prime
     * sur le code mort (-2) : on conserve donc la valeur maximale par ligne.
     *
     * @param array<string, array<int, int>> $base
     * @param array<string, array<int, int>> $add
     * @return array<string, array<int, int>>
     */
    private function mergeCoverage(array $base, array $add): array
    {
        foreach ($add as $file => $lines) {
            foreach ($lines as $lineNo => $value) {
                $base[$file][$lineNo] = max($base[$file][$lineNo] ?? $value, $value);
            }
        }

        return $base;
    }

    /**
     * Statut le plus grave entre deux exécutions d'une même classe de scénario
     * (error > failed > skipped > passed).
     */
    private function worstStatus(string $a, string $b): string
    {
        $rank = ['passed' => 0, 'skipped' => 1, 'failed' => 2, 'error' => 3];

        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    /**
     * Arrête proprement la capture démarrée par beginScenario pour un test qui
     * n'est PAS une scénario (classe non annotée #[Scenario]), afin de ne pas
     * laisser une session de coverage ouverte polluer le test suivant.
     */
    public function recordNonScenario(): void
    {
        if ($this->driver === 'xdebug') {
            xdebug_stop_code_coverage(false);
        } elseif ($this->driver === 'pcov') {
            \pcov\stop();
            \pcov\clear();
        }
    }

    /**
     * Persiste tous les enregistrements dans le fichier JSON de sortie.
     */
    public function flush(): void
    {
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Impossible de créer le dossier "%s"', $dir));
        }

        // Univers complet : fichiers source du bundle JAMAIS exécutés par un test.
        // On les remonte à 0 % pour que les KPI et l'explorateur reflètent
        // l'avancement réel (et pas seulement le code déjà couvert).
        $covered = [];
        foreach ($this->records as $r) {
            foreach (array_keys($r->coverage) as $file) {
                $covered[$file] = true;
            }
        }
        $sourceFiles = (new SourceScanner($this->srcRoot, $this->excludeDirs))->scanUncovered($covered);

        // Lignes explicitement exclues via @codeCoverageIgnore / #[CodeCoverageIgnore].
        // Calculées pour TOUT l'univers (fichiers couverts + jamais touchés), puis
        // soustraites en aval par CoverageData. On conserve la carte à part sans mutiler
        // les données brutes, pour que l'exclusion reste inspectable et auditable.
        $ignoredLines = $this->computeIgnoredLines(array_keys($covered), array_keys($sourceFiles));

        $data = [
            'version'   => '2',
            'srcRoot'   => $this->srcRoot,
            'generatedAt' => date('Y-m-d H:i:s'),
            'records'   => array_map(
                fn(ScenarioRecord $r): array => $r->toArray(),
                $this->records
            ),
            'sourceFiles'  => $sourceFiles,
            'ignoredLines' => $ignoredLines,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Échec de la sérialisation JSON du store.');
        }

        if (file_put_contents($this->outputFile, $json) === false) {
            throw new RuntimeException(
                sprintf('Échec d\'écriture du fichier de données "%s".', $this->outputFile)
            );
        }
    }

    /**
     * Calcule les lignes exclues via l'annotation native `@codeCoverageIgnore`
     * (docblock sur classe/méthode/fonction, ou bornes Start/End) pour tout l'univers
     * (fichiers déjà couverts + jamais touchés). Renvoie uniquement les fichiers ayant
     * au moins une ligne EXÉCUTABLE exclue, indexés par realpath.
     *
     * On s'appuie sur l'analyseur statique de php-code-coverage (même biblio que PHPUnit),
     * déjà utilisé par SourceScanner. Absent (hors env de test) : on renonce silencieusement.
     *
     * Subtilité : `ignoredLinesFor()` renvoie aussi systématiquement la ligne de
     * déclaration de chaque classe (comportement interne de php-code-coverage). On
     * intersecte donc avec `executableLinesIn()` pour ne retenir que les lignes réellement
     * exécutables effectivement annotées — sinon on retirerait des lignes parasites.
     *
     * @param list<string> $coveredFiles
     * @param list<string> $sourceFiles
     * @return array<string, list<int>>
     */
    private function computeIgnoredLines(array $coveredFiles, array $sourceFiles): array
    {
        if (!class_exists(ParsingFileAnalyser::class)) {
            return [];
        }

        // ignoreDeprecatedCode = FALSE : on ne veut exclure QUE le code explicitement
        // annoté @codeCoverageIgnore, pas tout le code @deprecated (souvent encore testé).
        $analyser = new ParsingFileAnalyser(true, false);
        // Dédup : un realpath peut figurer dans les deux listes selon les cas.
        $files    = array_values(array_unique([...$coveredFiles, ...$sourceFiles]));
        $result   = [];

        foreach ($files as $file) {
            // Garde : ne traiter que les fichiers portant réellement le marqueur
            // d'exclusion — sous forme d'ATTRIBUT PHP 8 #[CodeCoverageIgnore] (résolu en
            // FQCN par l'analyseur) ou, à défaut, l'annotation docblock @codeCoverageIgnore.
            // Test insensible à la casse : couvre les deux écritures (« CodeCoverageIgnore »
            // pour l'attribut, « codeCoverageIgnore » pour l'annotation).
            // Sans cette garde, ignoredLinesFor() renverrait la ligne de déclaration de
            // CHAQUE classe (comportement interne de php-code-coverage), décalant le
            // dénominateur de tous les fichiers. On reste donc strictement sur l'intention.
            $source = @file_get_contents($file);
            if ($source === false || stripos($source, 'codecoverageignore') === false) {
                continue;
            }

            try {
                $ignored    = $analyser->ignoredLinesFor($file);
                $executable = array_keys($analyser->executableLinesIn($file));
            } catch (\Throwable) {
                // Fichier non analysable : on n'exclut rien plutôt que d'échouer.
                continue;
            }

            $ignoredExecutable = array_values(array_intersect(
                array_map('intval', $ignored),
                array_map('intval', $executable),
            ));

            if ($ignoredExecutable !== []) {
                $result[$file] = $ignoredExecutable;
            }
        }

        return $result;
    }

    /**
     * Charge un store depuis un fichier JSON existant.
     *
     * @return array{srcRoot: string, generatedAt: string, records: ScenarioRecord[], sourceFiles: array<string, list<int>>, ignoredLines: array<string, list<int>>}
     */
    public static function loadFromFile(string $jsonFile): array
    {
        if (!file_exists($jsonFile)) {
            throw new RuntimeException(sprintf('Fichier de données introuvable : %s', $jsonFile));
        }

        $raw  = file_get_contents($jsonFile);
        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Fichier JSON invalide : %s', $jsonFile));
        }

        return [
            'srcRoot'     => (string) ($data['srcRoot'] ?? ''),
            'generatedAt' => (string) ($data['generatedAt'] ?? ''),
            'records'     => array_map(
                fn(array $r): ScenarioRecord => ScenarioRecord::fromArray($r),
                (array) ($data['records'] ?? [])
            ),
            'sourceFiles' => array_map(
                static fn(array $lines): array => array_map('intval', $lines),
                (array) ($data['sourceFiles'] ?? [])
            ),
            // Rétrocompatible : un JSON v1 sans 'ignoredLines' donne une carte vide.
            'ignoredLines' => array_map(
                static fn(array $lines): array => array_map('intval', $lines),
                (array) ($data['ignoredLines'] ?? [])
            ),
        ];
    }

    public function isCoverageAvailable(): bool
    {
        return $this->coverageAvailable;
    }

    /** Chemin du fichier JSON de sortie (pour les messages de fin d'exécution). */
    public function outputFile(): string
    {
        return $this->outputFile;
    }

    /**
     * Un fichier sous srcRoot tombe-t-il dans un dossier exclu (ex. Tests) ?
     * Compare segment par segment le chemin relatif à srcRoot.
     */
    private function isExcluded(string $realFile): bool
    {
        if ($this->excludeDirs === [] || $this->srcPrefix === '') {
            return false;
        }

        $relative = substr($realFile, strlen($this->srcPrefix));
        $segments = explode(DIRECTORY_SEPARATOR, $relative);

        return array_intersect($segments, $this->excludeDirs) !== [];
    }

    /** @return ScenarioRecord[] */
    public function getRecords(): array
    {
        return $this->records;
    }
}
