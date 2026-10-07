# Validation du correctif 1.2.1

État examiné le 7 octobre 2026 : patch local sur `fix/1.2.1-proposal-commission-cleanup`, basé sur `main` / `origin/main` au commit `83f1d47560b2c90cb981b8dea0b4852cc8756a4c` (1.2.0). La branche 1.3.0 reste au commit `82faae3c4e1d27d1b15f9e49ffeec76c08430e1f`. Ces résultats correspondent à la validation locale avant publication de la branche et ouverture de la PR ; aucun déploiement ni release n’a été réalisé.

## Contrats natifs et simulations exécutées

Commande reproductible : `python test/run_proposal_cleanup_matrix.py`. Le script télécharge les sources immuables dans un cache ignoré du module et enregistre leurs empreintes SHA-256. Les méthodes natives `Propal::setDraft()`, `DoliDB::begin()/commit()/rollback()` et `Form::formconfirm()` sont extraites sans modification ; le gestionnaire natif `HookManager` est chargé pour vérifier les appels successifs et la remise à zéro des résultats. SQL, utilisateurs, droits, HTTP, traductions et helpers périphériques sont simulés.

| Sources Dolibarr | Révision figée | PHP exécuté | Résultat |
|---|---|---|---|
| 20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` | 8.4.22 CLI | 63 assertions réussies |
| 21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` | 8.4.22 CLI | 63 assertions réussies |
| 22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` | 8.4.22 CLI | 63 assertions réussies |
| 23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` | 8.4.22 CLI | 63 assertions réussies |
| 24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` | 8.4.22 CLI | 63 assertions réussies |
| 25.0.0-alpha | `ef6e5818b0a5626f928cb9bc66bddb8be43ac32f` | 8.4.22 CLI | 63 assertions réussies ; préversion uniquement |

Sources : [dépôt officiel Dolibarr](https://github.com/Dolibarr/dolibarr), chemins `htdocs/comm/propal/class/propal.class.php`, `htdocs/core/db/DoliDB.class.php`, `htdocs/core/class/html.form.class.php` et `htdocs/core/class/hookmanager.class.php`. Les révisions ci-dessus figent les preuves ; elles ne constituent pas des essais de six installations complètes. PHP 8.0 et une version finale v25 n’ont pas été exécutés.

Les deux défauts sont reproduits avec le trigger de `main` : retour en brouillon laissant les commissions, suppression annulant les commissions sans supprimer leurs échéances. Après correction, la suite vérifie notamment :

- suppression des échéances avant les lignes, protection de l’autre entité et des sources périodiques, répétition sans nouvelle suppression ;
- retour en brouillon avec statut mémoire ancien et date de signature conservée, puis déverrouillage des répartitions en brouillon et après revalidation ;
- historique payé refusé sans confirmation, confirmation acceptée ou refusée, aperçu non approuvé, rejeu, expiration, nonce falsifié, changement des données, de l’acteur, de l’entité, de l’action ou des droits ;
- erreurs de début, suppression, commit et lecture des règles ; restauration simulée, transaction native imbriquée et erreur de rollback signalée ;
- nettoyage de sources absentes ou en brouillon, exclusion des devis signés et des relations incohérentes entre entités, contrôle administrateur et revalidation après changement de statut ;
- calcul réel du moteur de paliers sur SQL simulé : retrait du CA du devis et diminution de la prime calculée ; absence réelle de règle signalée ;
- confirmation native en POST avec token et challenge, cycle natif des hooks sans contamination du rendu suivant.

Le service de nettoyage appelle le service existant de recalcul des primes périodiques. La persistance complète de ces primes et la reconstruction de leurs échéances payées ne sont pas validées par cette doublure SQL. Le contrat existant conserve les échéances payées et signale les dépassements ou différences de calendrier ; le nettoyage transmet ces avertissements à l’utilisateur.

Régression complémentaire : `php test/rule_resolver_test.php` — 7 assertions sur SQL simulé et catalogues français/anglais : reproduction du conflit `margin_policy`, exclusion des politiques du résolveur de commissions, maintien des vrais conflits sur marge et paliers avec message traduit. Cette reproduction ne confirme pas la version ou les données effectivement déployées sur l’instance de la capture.

Contrôles complémentaires : `php test/tier_calculator_test.php`, lint des fichiers PHP modifiés ou ajoutés (15 fichiers dans le patch complet) et `git diff --check`. PHPStan n’a pas été exécuté : aucun exécutable ni configuration PHPStan utilisable n’est présent dans le module. Aucune dépendance n’a été installée.

## Recette sur une instance de test

Aucune instance MySQL/MariaDB ni navigateur servant ce patch n’a été utilisée. Les parcours suivants restent à exécuter sur chaque version majeure ciblée, avec un PHP accepté par son core et avec la version Multicompany réellement installée :

1. Signer un devis avec commissions, échéances et attribution du CA ; le supprimer et constater le commit final, l’absence de lignes/échéances et le retrait des répartitions.
2. Repasser un devis signé en brouillon ; vérifier la suppression, la conservation des répartitions, la possibilité de les modifier, puis revalider et signer à nouveau sans doublon.
3. Payer une échéance ; tester les confirmations native puis supplémentaire, leurs refus, les droits natifs et le droit de paiement, les tokens CSRF absents/invalides et un paiement concurrent entre aperçu et écriture.
4. Vérifier le refus serveur par API et action de masse pour un historique payé, puis le succès des opérations sans historique payé. Les tests automatisés simulent l’absence de confirmation à l’entrée métier ; ils ne lancent pas de requêtes REST ni de massactions natives.
5. Injecter une erreur SQL pendant la suppression et pendant la persistance du recalcul ; vérifier la restauration du devis, de ses commissions et de ses échéances au terme de la transaction native. Refaire avec le décorateur DebugBar installé : la doublure testée ne vaut pas un essai de ce décorateur réel.
6. Tester les périodes mensuelle, trimestrielle et annuelle, plusieurs commerciaux et des primes périodiques partiellement ou totalement payées ; vérifier les totaux, la conservation de l’historique payé et les avertissements de régularisation. Aucun remboursement automatique n’est prévu.
7. Depuis Maintenance, contrôler les aperçus des sources absentes et en brouillon, les deux confirmations pour les paiements, l’évolution des données entre étapes, puis réexécuter le diagnostic sans doublon.
8. Refaire les scénarios dans deux entités et sur un devis partagé ; contrôler propriété, accès natif, absence de fuite et configuration conservée après réactivation. Vérifier l’onglet À propos alimenté par le descripteur 1.2.1.
9. Vérifier les modifications ordinaires et le refus d’un devis : leur comportement d’annulation existant doit rester inchangé.

Les appels utilisant volontairement `notrigger=1`, le module désactivé ou des écritures SQL directes contournent les triggers natifs ; ils ne bénéficient pas de cette protection. Le traitement historique est manuel, avec aperçu et confirmation, jamais une purge automatique lors de l’activation.

Interface de répartition : les deux actions d’ajout utilisent `dolGetButtonTitle()` avec `fa fa-plus-circle`, dans le titre natif du tableau correspondant. Contrats lus sur les mêmes révisions v20 à v25 alpha (fonctions déplacées dans `html.lib.php` en v25). Lint réussi ; rendu distant non validé faute de déploiement de cette modification. Les formulaires et contrôles serveur restent inchangés.

Alignement visuel des répartitions : fermeture native de la fiche après le bandeau, puis titres et tableaux `tagtable liste`, en-têtes `th.liste_titre` et conteneurs `div-table-responsive-no-min`. Les lignes `oddeven`, totaux et états vides sont conservés. Lecture des listes de devis des six révisions ci-dessus : classes de liste présentes. Le [parcours Contacts v20](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/comm/propal/contact.php) ferme également la fiche avant ses sous-listes. Le [thème v25 alpha](https://github.com/Dolibarr/dolibarr/blob/ef6e5818b0a5626f928cb9bc66bddb8be43ac32f/htdocs/theme/eldy/global.inc.php) applique le rendu des titres de liste aux titres directement dans `div.fiche` ; le conteneur de fiche ouvert autour des répartitions empêchait ce rendu. Vérification de sources et lint uniquement : rendu navigateur des tableaux vides, remplis et sur téléphone à contrôler après déploiement. Aucun CSS de thème ajouté.
