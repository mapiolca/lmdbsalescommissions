# Contrôle des marges — développement en cours

Cette évolution prolonge la version publiée 1.2.0. Elle ne constitue pas une nouvelle release ni une activation sur le parc. Les lots chantier, déplacements et bonus de 25 % sur le dépassement sont exclus.

## Fonctionnement livré dans la branche

Les règles `margin_policy` se configurent dans **Réglages > Contrôles de marge**, puis s'affectent dans **Affectations**. Elles sont séparées des anciennes règles de calcul `margin`/`tier`, qui continuent à déterminer le montant habituel et les modalités de versement.

Une règle générale porte sur la marge globale du devis, sans dépendre de Centrale PV, d'une catégorie ou d'un extrafield. Elle peut contrôler la vente, la commission ou les deux. Les grilles PV, stockage et mixtes contrôlent uniquement la commission. Le minimum de vente et la cible de commission différents nécessitent deux règles générales.

Le calcul utilise `FormMargin::getMarginInfosArray()` sur une copie des lignes : coûts fournisseur, coût absent, remise exceptionnelle et réglages natifs conservent leur signification. Le taux est `(vente HT − coût HT) / coût HT × 100`. Le coût nul ou une donnée de coût nécessaire indisponible ne vaut pas conformité. L'égalité au seuil est acceptée, avec uniquement une tolérance de précision machine sur le taux ; les kWc/kWh ne sont jamais arrondis avant comparaison.

| Contexte | Source technique | Application |
|---|---|---|
| Général | Aucune | Tous les devis |
| PV seul | `options_powerplantpv_peak_power` | kWc > 0 et kWh = 0 |
| Stockage seul | `options_powerplantpv_storage_capacity` | kWh utiles > 0 et kWc = 0 |
| Mixte | Les deux valeurs | kWc > 0 et kWh > 0 |

Ces valeurs sont lues sur le devis ; Centrale PV reste responsable de leur calcul. Un zéro explicite signifie absence de cet équipement. Une valeur absente, négative, non numérique ou une erreur de lecture reste inconnue. Si aucune règle technique n'est affectée, les extrafields ne sont pas lus. Si une règle technique est affectée mais que le contexte ne peut pas être déterminé, sa décision est indéterminée. Aucun repli d'une grille mixte vers une grille PV n'est effectué.

Les bornes supérieures sont incluses, les bornes inférieures peuvent être ouvertes ou fermées ; une borne vide est illimitée. Les chevauchements, y compris en deux dimensions, sont refusés. Le parent est verrouillé pendant l'édition d'une grille. Les seuils supérieurs à 100 % sont admis.

Pour chaque contexte et effet : utilisateur > groupe > défaut. Plusieurs règles distinctes de même rang constituent un conflit ; les priorités numériques des anciennes commissions ne départagent pas les politiques de marge. Une même règle atteinte par plusieurs affectations n'est appliquée qu'une fois. Général et contexte technique applicable se cumulent.

La vente exige l'accord de tous les profils bénéficiaires de la répartition des commissions. Sans répartition, le commercial auteur du devis est utilisé. La personne qui clique ne devient jamais le bénéficiaire du contrôle. Chaque bénéficiaire garde sa propre décision de commission.

Une commission refusée ou indéterminée vaut zéro dans les calculs directs, y compris les répartitions fixes et proportionnelles. Aucune échéance positive n'en découle. L'attribution du CA, les objectifs et les paliers périodiques ne sont pas réduits. Une cible de commission seule ne bloque pas la vente.

## Dérogations et historique

Deux droits supplémentaires, sans attribution administrateur implicite :

- `marginpolicy.approvesale`, identifiant 45002414 ;
- `marginpolicy.approvecommission`, identifiant 45002415.

L'identifiant de module existant 450024 est conservé ; aucun nouvel ID de module n'est attribué. Les droits existants ne sont pas renumérotés. La page exige également l'accès natif au devis. Un approbateur dispose de la visibilité nécessaire aux décisions, sans recevoir le droit de modifier les répartitions.

Depuis **Répartition commissions / CA**, chaque accord vise un bénéficiaire, une règle et un effet. Motif obligatoire, auteur de l'accord et horodatage serveur sont conservés. Une décision indéterminée exige de corriger les données ou le conflit ; elle n'est pas dérogeable. Une dérogation de vente ne rétablit pas la commission.

Sur la fiche devis et dans **Répartition commissions / CA**, la colonne **Règles appliquées** affiche une loupe et **Consulter**. La modale native Dolibarr présente, pour le bénéficiaire concerné, la source, la règle, le contexte, l'effet, le seuil et le résultat. Les accords existants et les formulaires de dérogation autorisés restent dans ce détail. Si JavaScript est désactivé dans Dolibarr, le tableau reste accessible dans un bloc dépliable.

L'empreinte comprend les données économiques, les coûts résolus, les réglages de marge, les données techniques utiles, les bénéficiaires, les politiques et leurs révisions. Les mutations natives de devis/lignes et les mutations des règles/affectations/répartitions invalident les anciens accords. Un formulaire ouvert avant une modification ne peut pas approuver le nouvel état.

Les décisions sont figées dans la transaction de signature, par devis et bénéficiaire, avec règles, valeurs et accords. Les commissions acquises existantes et les versements ne sont pas recalculés. Un devis signé avant activation reste historique ; un devis validé mais non signé est recontrôlé à sa signature. Une signature postérieure à l'activation sans décision conservée est signalée, sans fabrication rétroactive d'un instantané.

La liste de suivi expose les deux décisions figées et un filtre de droit à commission. L'export des lignes contient `sale_control`, `commission_control` et `margin_decision` (instantané JSON explicite, comprenant le motif, l'approbateur et la date des dérogations). Les lignes historiques sans instantané et les paliers gardent ces colonnes vides. Le détail courant reste accessible sur le devis.

## Installation et activation

1. Installer toute la branche sur une instance de recette, dans la seule racine du module.
2. Actualiser le module par son mécanisme natif d'activation, pour installer les tables, les deux colonnes de règle, les droits et les contextes de hooks. Les migrations ajoutent les colonnes absentes et ne purgent aucune donnée.
3. Attribuer explicitement le droit de configuration et, séparément, les droits d'approbation nécessaires.
4. Créer les règles désactivées, compléter les grilles, effectuer leurs affectations puis activer les règles.
5. Exécuter la recette des canaux utilisés et vérifier **Compatibilité**. L'activation refuse une version hors v20–v25, des fichiers core ne contenant plus les points d'extension attendus ou des contextes de hooks non actualisés.
6. Activer volontairement le contrôle dans chaque entité depuis l'onglet dédié. L'horodatage est celui du serveur.

Une désactivation/réactivation du module conserve les réglages. Désactiver puis réactiver volontairement **le contrôle** ouvre une nouvelle période d'activation ; les instantanés déjà conservés restent prioritaires. Aucun seuil issu des anciennes pièces jointes n'est préchargé automatiquement.

Tables internes ajoutées : `margin_band`, `margin_approval`, `margin_snapshot`, `margin_revision`, préfixées par `lmdbsalescommissions_` et le préfixe SQL natif. Toutes sont rattachées à l'entité ; les liaisons et instantanés sont explicites, sans cascade métier. Ces paramètres ne sont pas partageables indépendamment du devis. La lecture des paramètres d'une autre entité est explicite et distingue une erreur SQL d'une configuration désactivée.

Les contrôles de devis partagés nécessitant un calcul vivant sont actuellement exécutés **dans l'entité propriétaire** : `FormMargin` lit les réglages du contexte actif. Depuis une autre entité, un calcul nécessaire est refusé plutôt que d'appliquer ses réglages de coûts. Les décisions déjà figées restent consultables selon les droits. Aucune élévation ni bascule globale d'entité n'est effectuée.

## Points d'entrée et limites

| Canal | Contrôle préalable | Contrôle complémentaire |
|---|---|---|
| Fiche devis | Hook natif `doActions`, avant validation/clôture signée | `PROPAL_VALIDATE`, `PROPAL_CLOSE_SIGNED` |
| Liste/action de masse | Précontrôle de toute la sélection ; un refus annule le lot avant sa première mutation | Triggers natifs par devis |
| Signature publique | Initialisation du contexte `ajaxonlinesign`, après vérification du secret natif et avant écriture de l'image/PDF | Relecture du devis au trigger natif |
| API Proposals | Événement natif Restler `onCall`, après authentification et validation des arguments, avant la méthode | Triggers natifs |

L'API refuse `notrigger=1` sur validation/signature lorsque le contrôle est actif et refuse l'injection de champs de statut/signature/contexte par POST/PUT générique. Les clients doivent omettre ces champs et utiliser les méthodes natives de transition. Aucune authentification ni protection CSRF native n'est remplacée.

La souscription Restler est enregistrée lors de l'initialisation du contexte `api` : elle couvre aussi v20/v21, où `beforeApiCall` n'existe pas. Le contrôle public intervient dans le constructeur du hook car le premier appel métier de hook explicite intervient après l'écriture documentaire. Ce choix est limité au script, contexte, mode et action natifs vérifiés, sans hook global `all`.

Les triggers seuls ne garantissent pas l'absence d'effets externes : Dolibarr peut avoir généré un PDF ou appelé un autre trigger avant le refus. Les précontrôles couvrent les canaux natifs ci-dessus. Les imports personnalisés, autres modules, scripts PHP appelant directement une méthode avec triggers désactivés et écritures SQL directes **ne sont pas interceptables universellement dans ce seul module**. Ils doivent appeler le service de contrôle avant leur opération et conserver les triggers. Ne pas activer une politique bloquante sur un canal non audité.

Une écriture directe suivie d'un retour aux anciennes valeurs sans événement natif n'est pas une mutation traçable par la révision du module. La suppression/réédition d'un devis déjà signé, les courses entre une modification concurrente et une signature, l'ordre des triggers tiers et les effets PDF/email doivent faire partie de la recette réelle. Le verrou de grille ne prétend pas verrouiller toute la transaction de signature native.

Les notifications, l'Agenda, les documents, les crons et les catégories ne reçoivent aucun nouveau mécanisme parallèle. Le module écoute les événements natifs existants et les anciennes mutations `MODIFY` émises par `CommonObject` pour l'invalidation ; aucun nouveau code de transition custom n'est créé.

## Vérifications reproductibles

Le 30 septembre 2026, exécution locale sous **PHP 8.4.22**, sans base ERP ni serveur HTTP. Les composants natifs `FormMargin`, `HookManager` et Restler sont exécutés ; SQL, utilisateur authentifié et objets du devis sont des doublures. Les assertions portent sur les décisions et sur l'absence d'appel de méthode après refus, pas sur une authentification HTTP réelle.

| Source Dolibarr | Commit figé | Résultat local |
|---|---|---|
| 20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` | Contrats de sources + scénarios exécutés |
| 21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` | Idem |
| 22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` | Idem |
| 23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` | Idem |
| 24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` | Idem |
| 25.0.0-alpha | `ef6e5818b0a5626f928cb9bc66bddb8be43ac32f` | Idem ; ne valide pas une future v25 finale |

Sources : dépôt officiel [Dolibarr](https://github.com/Dolibarr/dolibarr), fichiers `htdocs/core/class/html.formmargin.class.php`, `core/class/hookmanager.class.php`, `core/ajax/onlineSign.php`, `comm/propal/card.php`, `list.php`, `class/propal.class.php`, `class/api_proposals.class.php`, `api/index.php` et `includes/restler/framework/Luracast/Restler/{Restler,EventDispatcher}.php`, pour chaque commit ci-dessus.

```sh
python3 test/fetch_native_contracts.py 20.0.0
php test/native_margin_contract_test.php test/.core-cache/20.0.0/htdocs 20.0.0
php test/margin_guard_test.php test/.core-cache/20.0.0/htdocs 20.0.0
php test/tier_calculator_test.php
```

La suite comprend les seuils 25/40/65 %, l'égalité décimale, les bornes techniques, les conflits, l'indépendance des accords, leur péremption, le rejeu de signature, le gel historique, la désactivation, l'erreur SQL, le rollback simulé, deux entités, les refus API, les appels successifs du gestionnaire de hooks et les commissions fixes/proportionnelles nulles avec CA conservé. Le workflow `Margin controls` ajoute la matrice PHP/Dolibarr et un test DDL MariaDB sur base jetable, avec préfixe long, unicité par entité et résolution SQL réelle des affectations (utilisateur/groupe/défaut et validité jusqu’à la fin du jour). Son statut réel doit être lu sur le SHA de la PR ; sa déclaration ne vaut pas exécution.

PHPStan n'est pas installé/configuré dans cet environnement : analyse non exécutée. Lint PHP et tests ciblés ne la remplacent pas.

Recette complémentaire du 30 septembre 2026 : après déploiement de la branche par l'utilisateur, six devis brouillons ont été préparés dans l'entité TEST de develop (Dolibarr 25 alpha, Multicompany 24.0.2). Les décisions affichées ont été vérifiées pour les marges générales de 25/40/65 %, PV 9 kWc, stockage utile 10 kWh et mixte 9 kWc + 10 kWh à 65 %. Aucun de ces devis n'a été validé ou signé pendant cette recette ; les canaux de mutation ci-dessous restent à vérifier.

Pour le lien **Consulter**, le rendu PHP de `Form::textwithpicto()` a été exécuté avec les six révisions natives ci-dessus sous PHP 8.4.22, avec données simulées et identifiants distincts pour deux bénéficiaires dans deux blocs. Le tableau compact a été observé dans Chrome sur un aperçu HTTP local utilisant le JavaScript natif v25 alpha. Le pilotage des clics a échoué avant leur exécution : l'ouverture, la fermeture et la réouverture de la modale restent à vérifier après redéploiement sur develop. Ce dernier changement d'interface n'a pas été déployé pendant cette vérification.

## Recette d'instance avant activation

- Activation/réactivation native, droits persistés, colonnes existantes, maintien des réglages à 0 et des constantes ; installation MariaDB/MySQL avec deux entités réelles.
- Formulaires avec et sans token, utilisateur interne sans droit, approbateur seul, administrateur sans droits fonctionnels, utilisateur limité à ses tiers ; aucun accès indirect aux accords d'un autre devis.
- Vente à 25/40/65 %, règles générales avec Centrale PV désactivé et aucune définition technique, grilles PV/stockage/mixte, bornes et données manquantes.
- Modification après accord, avant validation puis avant signature ; contrôle de tous les bénéficiaires, maintien du CA et des paliers, absence d'échéance payable pour une décision indéterminée.
- Fiche, liste, signature publique et API authentifiée, y compris PUT de statut, `notrigger`, lot mixte autorisé/refusé, modules tiers actifs ; vérifier les fichiers, emails et la transaction externe après refus.
- Conservation des commissions/versements historiques, signature répétée, annulation et éventuelle réouverture d'un devis signé, erreurs de commit/rollback avec le pilote natif et DebugBar.
- Rendu des grilles, accords, suivi, filtres, pagination et exports ; confronter les résultats aux valeurs affichées par la marge native.

Ces essais réels restent à exécuter par environnement, notamment avec la version Multicompany du parc. Une réussite des tests isolés ne les remplace pas.
