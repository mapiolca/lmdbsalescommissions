# Recette du circuit de dérogation — 29 octobre 2026

## Décision de déploiement

**Validation globale non acquise.** Le circuit principal fonctionne sur develop après correction de quatre défauts d’intégration. La CI est verte, mais la réception des notifications, les parcours API réels et plusieurs essais avec des profils distincts restent à confirmer. Ce rapport ne constitue pas un feu vert pour activer le blocage en production.

PR : [#10](https://github.com/mapiolca/lmdbsalescommissions/pull/10), version **1.3.0**, base `ecb535452d04e44fce4982717272196d118a6fa5`. Code de la dernière recette réelle : **`cd37f5decf8087408465b799b420a7e0134f6af0`**. Les ajouts documentaires postérieurs ne modifient pas ce code métier.

## Environnements et nature des preuves

| Environnement | Version / révision | Preuve |
|---|---|---|
| Local Windows | PHP 8.4.22, sources officielles figées v20–v25 alpha | Tests isolés, calculs et contrats natifs ; persistance, comptes et transport simulés |
| CI native-contracts | v20 / PHP 8.0 et 8.4 ; v21–v23 / PHP 8.2 ; v24–v25 alpha / PHP 8.4 | Sept jobs réussis par exécution ; aucune instance ERP complète installée par ces jobs |
| CI schema | MariaDB 11.4, DDL natif v20 et tables de test | SQL réel : migrations rejouées, limites natives des colonnes, verrous concurrents, unicité et rollback ; parent devis simplifié |
| develop | Dolibarr 25.0.0-alpha, PHP 8.3.36, module 1.3.0, TEST 1, compte Codex id 7 | Navigateur interne, activation native, demandes et validations réelles de devis fictifs |
| develop TEST 2 | Module initialement désactivé, laissé désactivé | Accès au dossier TEST 1 refusé ; pas de recette de partage avec deux modules actifs |

Révisions officielles : v20 `697bf01970740a3339cd99cf055b4428fc5e051c`, v21 `fd970b582a4d8c5779a2958a4e9f4fce225cf085`, v22 `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710`, v23 `57a1f05d490a7a80944a8232e9c613e6556d2704`, v24 `769c7db907099643558e77d7002c109cfda919e5`, v25 alpha `ef6e5818b0a5626f928cb9bc66bddb8be43ac32f`. La dernière révision ne prouve rien pour une future v25 finale.

## Résultats automatisés

- État `2a6daa3` : **83 exécutions locales réussies**, 106 fichiers PHP sans erreur de syntaxe, test JavaScript de 10 assertions avec DOM/Ajax simulés. Les compteurs d’assertions des suites imbriquées ne sont pas additionnés comme des scénarios uniques.
- Après le correctif de liste `cd37f5d` : les six suites `margin_workflow_test.php` passent de nouveau, chacune avec 74 assertions ; syntaxe des deux fichiers modifiés et `git diff --check` conformes.
- CI au SHA `cd37f5d` : **8 jobs réussis sur la PR et 8 sur la branche**, dont MariaDB. [PR : 40841714175](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40841714175), [branche : 40841707321](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40841707321). Aucun de ces résultats n’est présenté comme une recette ERP réelle sur les six versions.
- PHPStan **2.1.0, niveau 5, cible PHP 8.0**, exécuté sur les 20 fichiers PHP de production concernés avec les sources natives v25 alpha et `--memory-limit=1G` : **81 diagnostics de fichiers, aucune erreur globale**. Le résultat n’est pas vert. Les diagnostics comprennent du code antérieur et des limites de contexte du checkout isolé ; aucun baseline, baisse de niveau ou masquage n’a été ajouté. Le premier essai limité à 128 Mo avait échoué par manque de mémoire et a été relancé.
- Le paquet candidat vérifie le nom numérique `module_lmdbsalescommissions-1.3.0.zip`, la racine unique, l’absence du dépôt/caches/tests et **63 chemins d’inclusion sur 21 points d’entrée** : installation sous `htdocs`, sous `custom`, environnement absent. Le bootstrap est simulé ; le ZIP n’a pas été installé par l’installateur natif et n’est pas une release Dolistore.

## Défauts reproduits et corrigés dans la PR

1. Le hook natif des boutons de devis ignore `resPrint` : le bouton de demande n’arrivait pas sur la fiche. Le module imprime maintenant la contribution selon le contrat natif, en conservant le nonce et les actions natives.
2. Les constantes de modèle de notification avaient un type texte : la page native affichait un champ brut. Leur type devient `emailtemplate:lscmargincase`, sans remplacer le modèle sélectionné.
3. L’ancien identifiant dépassait les limites natives de type de modèle et de trace. L’identité courte `lscmargincase`, le hook natif de résolution et les migrations conservatrices préservent modèles et liaisons historiques. Le SQL strict et les rejouements sont testés en CI.
4. La liste récente rend le statut avec `$object`, les anciennes avec `$objectstatic`. Le drapeau de dossier est transmis aux deux contrats, sans requête par ligne. « Brouillon » a été reproduit avant correction ; **« En attente de dérogation » a été observé ensuite sur la même liste et le même devis**.

## Recette réelle sur develop

Les essais utilisent exclusivement le tiers fictif « RECETTE MARGES — Client fictif 2026-09-30 ». Aucun devis réel ni paiement n’a été modifié volontairement.

| Scénario | Résultat observé |
|---|---|
| Devis 36, vente 1 250 HT, coût 1 000 HT, marge 25 %, minimum 30 % | Bouton « Demander la validation » visible ; bouton natif « Valider » masqué |
| Devis 37, marge 40 % et minimum de vente satisfait | Bouton natif « Valider » conservé, sans demande imposée ; commission non payable selon ses propres contrôles |
| Annulation de la confirmation | Aucun dossier créé |
| Demande sans motif | Refus serveur explicite, aucun dossier créé |
| Demande confirmée sans destinataire configuré | Dossier conservé, devis techniquement Brouillon, état métier « En attente de dérogation » |
| Attente : ouverture native de l’édition d’une ligne | Refus serveur « Un approbateur doit le valider ou réautoriser sa modification » |
| Attente : répartition commissions / CA | Éditeurs verrouillés, actions de décision présentes |
| Retour motivé | Dossier clos « Modification réautorisée » ; nouvelle demande possible |
| Approbation globale par Codex | Validation native réelle ; devis 36 devenu `PR2610-0009`, statut Validée, sans signature ni email client demandé par le module |
| Commission de l’auteur, base théorique 37,50 EUR | Approbations successives à 50 %, 0 %, 100 % ; synthèses respectives 18,75 EUR, 0 EUR, 37,50 EUR ; décisions historisées |
| Retour natif en brouillon après approbation | Accord retiré du calcul courant ; nouveau circuit obligatoire |
| Affichage en liste après correctif | État « En attente de dérogation » pour le devis 36, sans changement du statut numérique natif |
| URL de soumission sans jeton | Accès refusé ; aucune nouvelle demande. Les formulaires avec jeton ont été acceptés |
| Accès depuis TEST 2 | Refus du dossier de TEST 1 ; module TEST 2 laissé inactif |

Le devis 47 est un clone fictif créé pendant la recette, devenu `PR2610-0010`. Sa première approbation à 0 % avait une base théorique nulle : elle n’est pas utilisée comme preuve du calcul à zéro sur une base non nulle. Ce dernier est couvert par le devis 36.

L’avertissement de lmdbzoning `GeocoderHttpError` apparaît lors de la validation, mais le devis est effectivement validé. Il est distinct du blocage SMTP décrit ci-dessous.

## Notifications et API : blocages restants

### SMTP

Les deux événements et leurs sélecteurs de modèles sont visibles dans l’administration native. Aucun abonnement aux événements du circuit n’a été trouvé sur les cinq comptes accessibles ni sur le tiers fictif. Les destinataires fixes initiaux des deux événements étaient absents.

Après autorisation explicite, les deux destinataires fixes ont été configurés temporairement à `developpeur@lesmetiersdubatiment.fr`, seuil 0. La soumission a exécuté la notification native puis échoué avec :

> Error bad hostname IP (IP is a local IP). Must be an external URL.

Le journal de cette opération indique `InterfaceNotification` en erreur et `ROLLBACK Transaction` ; le dossier n’est pas conservé et le devis reste Brouillon. Ce comportement est une preuve réelle de rollback après refus natif, pas une preuve de réception email. Aucun envoi réussi ni réception n’a été confirmé. Aucun contrôle de sécurité SMTP n’a été désactivé. Les deux destinataires fixes ont ensuite été retirés, conformément à l’état initial.

Il reste à faire corriger le paramétrage SMTP de l’instance, puis vérifier la réception d’une demande et des deux décisions, le contenu des substitutions, les modèles personnalisés et l’absence de doublon au rejeu. L’erreur SMTP déjà relevée antérieurement sur la signature native d’un devis fictif reste aussi à lever ; aucune nouvelle signature n’est revendiquée dans cette recette.

### API

L’utilisation du jeton existant d’Utilisateur Test id 3 a été explicitement autorisée, sans création ni réinitialisation de jeton. Aucun secret n’est conservé dans ce rapport ou les fichiers de preuve.

L’explorateur recevait d’abord des octets gzip illisibles. Le switch natif **Désactiver la compression des réponses API** est maintenant activé, **à conserver sur demande explicite de l’utilisateur** (`API_DISABLE_COMPRESSION=1`). L’explorateur s’ouvre ensuite mais ne découvre que trois ressources, sans l’API native des devis ; les redirections ignorent aussi le ciblage de sa description.

**Aucune mutation API réelle de devis n’a donc été exécutée.** Les gardes API `notrigger`, changement de statut et lignes sont couverts par les tests isolés/CI ; cela ne remplace pas un essai authentifié complet de l’API des devis. Aucun réglage de login API, de droits ou de sécurité n’a été élargi pour contourner ce blocage.

## Couverture restant à compléter avant feu vert

| Exigence | Preuve disponible | Recette réelle encore nécessaire |
|---|---|---|
| Demande sans droit natif de validation | Tests isolés | Compte commercial distinct ; essais réels effectués avec Codex administrateur |
| Hiérarchie directe/indirecte, refus hors hiérarchie et autoapprobation limitée | `getAllChildIds()` natif avec comptes simulés | Comptes responsables/commerciaux distincts, droits limités, accès métier révoqué |
| Droit de commission distinct absent, autres bénéficiaires et répartitions manuelles | Tests isolés des calculs et permissions | Refus réel sans ce droit ; fixe/proportionnel, plusieurs bénéficiaires, exports et échéances après signature |
| Règles inconnues, données ou règles modifiées pendant l’attente | Tests isolés | Accord périmé, données techniques, catégories et règles modifiées sur instance |
| Rejeux et concurrence | Tests isolés et SQL MariaDB réel | Deux requêtes HTTP simultanées et rollback des effets des modules réellement installés |
| Liste et actions de masse | Rendu réel corrigé, gardes isolés | Validation/signature/génération de masse refusées sans effets secondaires |
| API native / signature publique | Gardes isolés ; blocages d’instance documentés | Parcours authentifiés complets et fixation de la commission à la signature |
| Suppression pendant l’attente | Contrôles analysés et tests isolés | Le clic réel a été refusé par le contrôle automatique d’approbation en raison du risque de suppression ; aucune suppression exécutée |
| Deux entités actives et partage | Tests isolés et refus réel depuis TEST 2 inactive | Installation/paramétrage dans deux entités actives, accès autorisés/refusés et conservation des réglages |
| Historique signé, versements et paliers | Tests de non-régression/CI | Comparaison avant/après sur données de recette signées et payées, sans recalcul historique automatique |
| Installation ZIP, désactivation/réactivation et configurations personnalisées | Réactivation réelle TEST 1 ; SQL idempotent CI et bootstrap simulé | ZIP par installateur natif et cycle complet dans deux entités avec modèles/notifications/cron personnalisés |

Aucun essai réel ERP v20–v24 ni v25 finale n’est annoncé. Les domaines documents PDF/ODT personnalisés et nouvelle numérotation métier ne sont pas ajoutés par cette PR ; les effets documentaires de `Propal::valid()` restent toutefois à inclure dans les scénarios d’échec.

## Remise en état

- Branche serveur initiale **`feature/margin-excess-bonus`**, SHA **`8230ff3ec705fe8f2e9aeb9a865327a25de072c3`**, rétablie et confirmée directement avec Git dans le terminal cPanel. La page cPanel affichait encore l’ancienne tête après une erreur de contact distant : cette erreur n’avait pas empêché le checkout local. La branche distante d’origine n’existe plus ; elle n’a pas été recréée.
- Un répertoire serveur `bin/` non suivi a été observé et laissé intact ; aucune restauration forcée ni nettoyage n’a été effectué.
- TEST 1 restauré et module actif vérifié ; TEST 2 reste désactivé. Aucun dossier créé pendant ces essais ne reste en attente : devis 36 validé, devis 47 retourné en modification. Les historiques et devis fictifs sont conservés.
- Destinataires fixes du circuit remis à leur état initial. Les migrations additives, tables et modèles créés lors de la réactivation sont conservés ; ils ne sont pas supprimés au retour de branche.
- Compression API laissée désactivée selon la dernière instruction. Aucun compte, mot de passe ou jeton n’a été créé ou réinitialisé, aucun droit n’a été attribué manuellement.
- Aucune fusion, release, publication Dolistore ni intervention en production.

Les captures de la recette restent des preuves locales hors du dépôt source. Les [règles officielles de packaging](https://wiki.dolibarr.org/index.php/Modules_-_Packaging_rules_and_Dolistore_validation_rules), révision wiki 65313, ont été relues le 29 octobre 2026 ; les contrôles de paquet ci-dessus ne valent ni installation native ni acceptation Dolistore.
