<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Attribute;

use Attribute;

/**
 * Décore une classe de test PHPUnit pour la déclarer comme trajectoire utilisateur.
 *
 * Une trajectoire = un scénario utilisateur nommé et décrit, exécuté de bout en bout.
 * L'extension TrajectoryExtension collecte les lignes de code couvertes par chaque
 * trajectoire et les associe à ce titre/description dans le rapport HTML.
 *
 * Usage :
 *   #[Trajectory(
 *       title: "Créer un devis",
 *       description: "L'utilisateur crée un devis depuis la liste, ajoute une ligne produit et vérifie les totaux.",
 *       mandatory: true,
 *   )]
 *   class S01_CreateQuoteTest extends TrajectoryTestCase { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Trajectory
{
    public function __construct(
        /**
         * Titre court du scénario (s'affiche dans l'arborescence du rapport).
         * Ex : "Créer un devis", "Paiement partiel (50%)", "Flux complet Devis → Facture"
         */
        public readonly string $title,

        /**
         * Description détaillée du scénario utilisateur (visible dans le panel trajectoire).
         */
        public readonly string $description,

        /**
         * Si true, la trajectoire est marquée comme obligatoire dans le rapport.
         * Un échec ou une absence de couverture est signalé en rouge.
         */
        public readonly bool $mandatory = false,

        /**
         * Tags libres pour regrouper les trajectoires (ex: ["vente", "comptabilité"]).
         * @var string[]
         */
        public readonly array $tags = [],
    ) {}
}
