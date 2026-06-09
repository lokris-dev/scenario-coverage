# scenario-coverage

[![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Mappe les **scénarios** utilisateur (tests PHPUnit de bout en bout) sur le code
source, et produit un **rapport HTML interactif autonome** : quels scénarios
couvrent quel code.

> Un *scénario* = un parcours utilisateur nommé, joué de bout en bout — pas un
> test unitaire. Là où le coverage classique répond « quelle ligne est testée »,
> `scenario-coverage` répond « **quel parcours** couvre quelle ligne ».

## Installation

```bash
composer require --dev lokris/scenario-coverage
```

Prérequis pour la collecte de couverture : `ext-xdebug` **ou** `ext-pcov`
(le rapport fonctionne sans, mais ne contiendra alors que les métadonnées).

## Usage

### 1. Annoter une classe de test comme scénario

```php
use Lokris\ScenarioCoverage\Attribute\Scenario;
use PHPUnit\Framework\TestCase;

#[Scenario(
    title: "Créer un devis",
    description: "L'utilisateur crée un devis, ajoute une ligne produit et vérifie les totaux.",
    mandatory: true,
    tags: ["vente"],
)]
final class S01_CreateQuoteTest extends TestCase
{
    public function testCreateQuote(): void { /* ... */ }
}
```

### 2. Activer l'extension dans `phpunit.xml`

```xml
<extensions>
    <bootstrap class="Lokris\ScenarioCoverage\Extension\ScenarioExtension">
        <parameter name="outputFile" value="var/scenario-coverage.json"/>
        <parameter name="srcRoot"    value="src"/>
    </bootstrap>
</extensions>
```

(Voir [`phpunit.extension.xml.dist`](phpunit.extension.xml.dist) pour un exemple complet.)

### 3. Lancer les scénarios, puis générer le rapport

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit
vendor/bin/scenario-report --name="mon-projet" --open
```

## Le rapport

Un **unique fichier HTML autonome** (zéro dépendance, ouvrable hors-ligne) :

- **Dashboard** global (couverture, scénarios passés/échoués).
- **Arborescence** des fichiers avec taux de couverture.
- **Vue source** annotée : lignes couvertes + badges des scénarios qui les couvrent.
- **Vue par scénario** : couverture inversée (un scénario → les fichiers qu'il touche).

## Options du CLI `scenario-report`

| Option     | Défaut                          | Description                                   |
|------------|---------------------------------|-----------------------------------------------|
| `--input`  | `var/scenario-coverage.json`    | JSON produit par l'extension PHPUnit          |
| `--output` | `var/scenario-coverage.html`    | Fichier HTML à générer                        |
| `--src`    | `src`                           | Racine source (chemins relatifs du rapport)   |
| `--name`   | dossier courant                 | Nom du projet affiché                         |
| `--open`   | —                               | Ouvre le rapport dans le navigateur           |

## Licence

MIT — voir [`LICENSE`](LICENSE).
