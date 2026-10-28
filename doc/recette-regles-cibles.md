# Recette des règles par produits et catégories

## Périmètre et code

Branche `fix/product-category-margin-rules`, créée depuis la tête de la PR 4
`d290131eee5d96d1b6a0de29f78b86d1905c9c77`. La recette navigateur des 16 et
28 octobre 2026 porte sur `51ca737a5f1f897c8d6828e84db611767af5abd7`.
La reprise ajoute ensuite la conservation des cibles devenues inaccessibles, les contrôles de lecture correspondants, la CI et les PHPDoc. Leur contrôle navigateur est consigné ci-dessous.

Instance : develop.lesmetiersdubatiment.fr, Dolibarr **25.0.0-alpha**, module
**1.3.0**, Multicompany **24.0.2**. PHP web **8.3.36** relevé le 28 octobre.
Ce sont des essais réels dans le navigateur intégré ; ils ne valident pas toutes
les versions du parc. Aucune release ni fusion n'est effectuée par cette recette.

## Fonctionnement vérifié le 16 octobre

| Cas | Résultat observé |
|---|---|
| Création et réouverture avec deux catégories | Les deux catégories natives sont conservées |
| Création et réouverture avec plusieurs produits | Les produits choisis sont conservés |
| Sélection vide pour une règle catégorie | Refus serveur et valeurs du formulaire conservées |
| Changement offre / catégorie / produit | Seul le sélecteur du périmètre choisi est affiché |
| Produit et service du catalogue dans la même règle | Deux lignes regroupées, marge sur coût de 40 % |
| Produit / catégorie / offre sur un même devis | Respect de la priorité par ligne et de l'offre sur les seules lignes restantes |
| Deux catégories sélectionnées | Les lignes des deux catégories sont regroupées dans le contrôle |
| Seuil exactement atteint | Vente autorisée ; validation native exécutée |
| Ligne ciblée sous le seuil malgré une marge globale supérieure | Vente bloquée avant validation |
| Deux règles catégorie de même rang se chevauchant | Conflit explicite et résultat indéterminé |
| Retrait d'une catégorie d'un produit | Recalcul des lignes ciblées et reprise par la règle offre |
| Dérogation de vente | Accord distinct de la commission ; accord caduc après changement de catégorie |
| Coût nul | Résultat indéterminé, sans assimiler le coût à une absence de règle |
| Classification interne signée | Décisions figées ; désactivation des règles et retrait des catégories sans modification de l'instantané |
| Réouverture et retour en brouillon | Parcours natifs exécutés ; données fictives conservées |

Les catégories sont celles directement liées aux produits/services. La recette ne
prouve pas une inclusion récursive des sous-catégories. Les kits ont été abordés
comme produits identifiables sur les lignes ; une ventilation particulière de
composants de kit nécessite sa propre recette.

Deux défauts de rendu ont été corrigés puis observés sur l'instance :

- URL du script de saisie versionnée pour renouveler le cache navigateur ;
- affichage des taux et seuils avec le formateur natif `price()`.

## Contrôles complémentaires du 28 octobre

| Cas | Résultat observé |
|---|---|
| TEST 2 avec le module activé temporairement | Aucune règle de TEST 1 dans sa liste |
| Accès depuis TEST 2 à la règle 9 et au devis 45 de TEST 1 | Accès refusé |
| Création d'une règle inactive de TEST 2 sur deux produits du catalogue partagé | Cibles conservées après réouverture |
| Retour dans TEST 1 puis accès à la règle 12 de TEST 2 | Absence de la liste et accès direct refusé |
| Catalogue partagé | Les sélecteurs suivent l'accès également observé sur la fiche produit native de TEST 1 depuis TEST 2 |
| Compte interne non administrateur sans droits du module | Administration des règles et répartition du devis refusées ; aucun détail ni bouton d'approbation exposé sur la fiche native |
| Même compte, devis fictif 36 à 25 % | La validation native reste bloquée malgré l'absence de droits de lecture du module |
| Action GET de règle sans jeton | Accès refusé et état de la règle inchangé |
| Action native avec jeton | Activation/désactivation et sauvegarde fonctionnelles |

Ces essais couvrent le refus d'accès et la séparation des règles. Ils ne couvrent
pas toutes les combinaisons de partage individuel, d'utilisateurs externes et de
profils de droits partiels.

### Signature publique : blocage de la voie autorisée

Sur le devis fictif 45, la tentative avec un seuil produit de 41 % pour une marge
ciblée de 40 % échoue sans passage au statut signé et sans fichier supplémentaire
observé. Le même parcours au seuil exact de 40 % dépasse le précontrôle, écrit un
fichier, puis échoue avant la persistance du statut signé.

Le journal de la requête du 28 octobre à **09:52:35** (heure affichée par le
journal ; écritures SQL datées 10:52:35) indique un échec de
`InterfaceNotification` sur `PROPAL_CLOSE_SIGNED`, suivi d'un **ROLLBACK** final.
Une seconde tentative sur la base PR 4 d290131, à **10:20:08–10:20:09** dans le journal, reproduit le même échec InterfaceNotification suivi du **ROLLBACK** final. Cet échec est donc reproduit avant l'ajout des périmètres. L'appel HTTP natif de clôture expose ensuite le détail : Error bad hostname IP (IP is a local IP). Must be an external URL.
L'avertissement lmdbzoning de géocodage sur le client fictif est concomitant et ne
prouve pas la cause du rollback. La conservation du fichier après échec est un
effet du parcours natif avant la transaction ; aucun fichier n'a été supprimé.

La réussite complète de la signature publique reste donc non validée. Aucun
changement du core, désactivation des Notifications ou assouplissement de contrôle
n'est appliqué pour contourner cet échec. Aucun email n'a été envoyé manuellement.

### API HTTP

L'utilisateur a créé le jeton du compte de recette et l'a fourni pour l'explorateur
natif. Aucun jeton ne figure dans le dépôt ou les preuves partagées.
Les droits existants hérités du groupe suffisent pour lire, créer et valider des
propositions ; aucun droit n'a été ajouté.

| Appel dans l'explorateur avec le compte standard | Résultat réel |
|---|---|
| GET devis fictif 36 | HTTP 200, brouillon, entité 1 |
| POST validation devis 36 à 25 %, notrigger: 0 | HTTP 409 LscSaleBlocked |
| Même validation avec notrigger: 1 | HTTP 409 LscApiLifecycle |
| PUT devis 36 avec status: 1 | HTTP 409 LscApiLifecycle |
| POST validation devis 45, règle produit à 41 % pour une marge ciblée de 40 % | HTTP 409 LscSaleBlocked, brouillon conservé malgré la marge totale de 42,5 % |
| Même validation au seuil exact de 40 % | HTTP 200, statut validé persisté et relu sur la fiche native |
| POST clôture acceptée du devis fictif 45 | HTTP 500, erreur d'URL de notification ; statut validé conservé |
| PUT devis 36 avec entity: 2, après confirmation explicite du test | HTTP 409 LscApiLifecycle |
| GET devis 36 après ce dernier refus | HTTP 200, toujours brouillon dans l'entité 1 |

La compression a été temporairement désactivée depuis les réglages natifs API,
avec accord de l'utilisateur, pour rendre les réponses lisibles dans
l'explorateur. Son état initial a ensuite été rétabli. Le mot de passe de session
n'est pas utilisé comme jeton et l'API de login n'a pas été activée.

Multicompany utilise l'en-tête natif DOLAPIENTITY pour demander le contexte
d'entité ; ce mécanisme ne déplace pas l'objet. Sa présence et le contrôle
d'authentification par entité ont été lus dans le core figé v25 alpha.
La modification du champ entity par PUT a été réellement testée et refusée.
Les appels HTTP avec deux valeurs distinctes de l'en-tête DOLAPIENTITY restent
non exécutés dans cette recette ; les essais réels entre TEST 1 et TEST 2 décrits
plus haut sont ceux de l'interface.

## Vérifications locales

Sous **PHP 8.4.22**, les cinq suites suivantes passent sur chacune des six
révisions natives figées documentées dans [les contrôles de marge](margin-controls.md) :

| Suite | Assertions affichées |
|---|---:|
| `margin_scope_test.php` | 170 cumulées |
| `margin_admin_test.php` | 97 |
| `margin_summary_test.php` | 74 |
| `margin_guard_test.php` | 174 cumulées |
| `margin_validation_test.php` | 208 cumulées |

Les composants natifs sont exécutés ; la base, les acteurs et HTTP sont simulés
dans ces suites. Les compteurs cumulés comprennent les suites incluses et ne
doivent pas être additionnés comme autant de scénarios indépendants. Le test des
périmètres est ajouté au workflow `Margin controls` afin d'être exécuté par la
matrice PHP 8.0/8.2/8.4 et Dolibarr v20 à v25 alpha. Le schéma et les requêtes de visibilité du catalogue sont testés
par le job MariaDB 11.4 de cette CI, pas par une base locale. L'identité de la
catégorie et les listes d'entités restent simulées dans ce test SQL.

### Cibles devenues inaccessibles

La revue du 28 octobre a identifié un risque de perte silencieuse des relations
lorsqu'une sélection existante devient partiellement invisible. Le correctif
8d6f60b refuse la sauvegarde sans vue complète du catalogue. La lecture d'une
règle active vérifie également l'existence et l'accessibilité de toutes ses
cibles ; une cible supprimée ou retirée du partage ne déclenche pas un repli
silencieux vers l'offre. Les erreurs de lecture restent distinctes d'une liste vide.

Vingt assertions supplémentaires couvrent ces cas, les droits produit/service,
les changements de périmètre et l'absence de permission de configuration, avec
acteurs et SQL simulés. Le job MariaDB vérifie aussi les SELECT réels avec des
catalogues appartenant à deux entités, partagés puis non partagés.
La modification des partages réels de l'instance pour ces cas négatifs n'a pas
été exécutée.

Le commit 67730e1 (même code de production que 8d6f60b, adaptation des fixtures
MariaDB) a été chargé temporairement via cPanel. Les sauvegardes des deux cibles
produit/service et des deux catégories, puis le calcul avec le seuil produit
exact de 40 %, ont été rejoués avec succès sur ce code servi.

### PHPStan

PHPStan **2.1.0**, niveau **5**, `phpVersion: 80000`, sans baseline ni nouvelle
règle d'ignore. Analyse des neuf fichiers PHP de production modifiés, avec le
bootstrap natif et les sources Dolibarr 25 alpha figées au commit
`ef6e5818b0a5626f928cb9bc66bddb8be43ac32f`, plus les classes du module et la
dépendance locale lmdbzoning. L'outillage et les résultats restent dans
`test/.tools/`, ignoré par Git ; aucune dépendance de production n'est ajoutée.

La comparaison avec les mêmes fichiers de la base PR 4 donne **192 diagnostics
sur la base, 7 sur la branche et aucun diagnostic supplémentaire**. Les types des
variables de page et de template sont explicités. Les sept diagnostics restants
concernent des inclusions conditionnelles/contexte d'analyse et les PHPDoc hérités
`date_creation` / `tms` de la classe de règle. L'analyse globale n'est donc pas
verte. Le nouveau résolveur, le moteur, le service, la vue, l'infobulle et le template ne
produisent pas de diagnostic dans cette configuration.

Commande utilisée avec la configuration locale de test :

```sh
php test/.tools/phpstan/vendor/phpstan/phpstan/phpstan.phar analyse \
  -c test/.tools/phpstan.neon --no-progress --error-format=json --memory-limit=1G
```

## État et limites de restitution

État final observé le 28 octobre :

- serveur revenu à **feature/margin-excess-bonus**, commit
  **8230ff3ec705fe8f2e9aeb9a865327a25de072c3**, vérifié par Git dans le terminal
  cPanel malgré un message d'échec de contact avec sa branche distante supprimée ;
- session revenue dans TEST 1 ; module toujours actif dans TEST 1 et **désactivé
  dans TEST 2**, vérifié dans la liste native des modules ;
- contrôle des marges conservé actif ; cinq règles préexistantes
  actives, trois règles ciblées et leurs affectations de recette désactivées ;
- seuil produit revenu à 40 %, devis 45 revenu en brouillon ;
- devis 36 toujours en brouillon dans l'entité 1 après le PUT refusé ;
- compression API rétablie à son état initial ; droits du compte de recette
  inchangés ;
- règle inactive 12 de TEST 2 conservée ; paramètres et droits administrateurs
  créés par l'activation native conservés selon le contrat de désactivation ;
- deux PDF issus des tentatives de signature publique, les événements natifs de
  validation/retour en brouillon et les autres données fictives conservés.
  La commande brouillon (PROV67) préexistante n'a pas été supprimée.

La CI du commit **67730e1552fcb97871803a6b1e06607850ab8f90** réussit ses huit
jobs : sept couples Dolibarr/PHP (v20/PHP 8.0 et 8.4, v21–v23/PHP 8.2,
v24 et v25 alpha/PHP 8.4), plus MariaDB 11.4.
[Exécution vérifiée](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40675125379).
L'échec initial du job MariaDB sur 8d6f60b provenait de l'adaptateur de test PDO
incomplet ; les fixtures et cet adaptateur ont été corrigés sans changement du
code de production ni affaiblissement du test.

Les captures restent locales et ne sont pas commitées. La signature publique
autorisée reste bloquée par la configuration Notifications de develop. Le test
HTTP de deux contextes DOLAPIENTITY, les utilisateurs externes, les composants
de kits et l'héritage récursif des catégories ne sont pas validés par cette
recette. Les cinq suites locales utilisent PHP 8.4.22 ; la matrice CI complète
les versions PHP du socle.
