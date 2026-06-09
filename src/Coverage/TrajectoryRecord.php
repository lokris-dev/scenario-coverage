<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Coverage;

/**
 * Enregistrement immuable d'une trajectoire exécutée.
 *
 * Stocke les métadonnées de la trajectoire + les lignes de code couvertes
 * pendant son exécution (collectées via Xdebug ou pcov).
 */
final class TrajectoryRecord
{
    /**
     * @param string   $id          Identifiant unique dérivé du FQCN du test (ex: "S01_CreateQuoteTest")
     * @param string   $title       Titre humain (#[Trajectory] title)
     * @param string   $description Description (#[Trajectory] description)
     * @param bool     $mandatory   Trajectoire obligatoire
     * @param string[] $tags        Tags libres
     * @param string   $testClass   FQCN de la classe de test
     * @param string   $testFile    Chemin absolu du fichier de test
     * @param string   $status      "passed" | "failed" | "skipped" | "error"
     * @param float    $duration    Durée d'exécution en secondes
     * @param array<string, array<int, int>> $coverage
     *   Carte de couverture : chemin absolu du fichier → tableau de lignes couvertes
     *   (valeur : 1 = couverte, -1 = non couverte, -2 = non exécutable)
     *   Format identique à celui de xdebug_get_code_coverage().
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $mandatory,
        public readonly array $tags,
        public readonly string $testClass,
        public readonly string $testFile,
        public readonly string $status,
        public readonly float $duration,
        public readonly array $coverage,
    ) {}

    /**
     * Sérialise l'enregistrement en tableau pour stockage JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'mandatory'   => $this->mandatory,
            'tags'        => $this->tags,
            'testClass'   => $this->testClass,
            'testFile'    => $this->testFile,
            'status'      => $this->status,
            'duration'    => $this->duration,
            'coverage'    => $this->coverage,
        ];
    }

    /**
     * Reconstruit un enregistrement depuis un tableau JSON.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Null-coalescing sur chaque clé : un JSON partiel ou issu d'une version
        // antérieure (champ absent) ne doit pas émettre de warning — et donc pas
        // crasher sous un set_error_handler qui convertit les warnings en exceptions.
        return new self(
            id:          (string) ($data['id']          ?? ''),
            title:       (string) ($data['title']       ?? ''),
            description: (string) ($data['description'] ?? ''),
            mandatory:   (bool)   ($data['mandatory']   ?? false),
            tags:        (array)  ($data['tags']        ?? []),
            testClass:   (string) ($data['testClass']   ?? ''),
            testFile:    (string) ($data['testFile']    ?? ''),
            status:      (string) ($data['status']      ?? 'passed'),
            duration:    (float)  ($data['duration']    ?? 0.0),
            coverage:    (array)  ($data['coverage']    ?? []),
        );
    }
}
