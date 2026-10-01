# Contrôle des marges — version 1.3.0

Cette évolution prépare la version 1.3.0, après la version publiée 1.2.0. Le descripteur, l’onglet À propos (alimenté par le descripteur), le README et le changelog sont alignés. Cette préparation ne constitue pas une release GitHub ni une activation sur le parc. Les lots chantier restent exclus. La prime configurable de dépassement est décrite dans [le contrat de récompense](margin-excess-reward.md).

## Fonctionnement livré dans la branche

Les règles `margin_policy` se configurent dans **Réglages > Contrôles de marge**, puis s'affectent dans **Affectations**. Elles sont séparées des anciennes règles de calcul `margin`/`tier`, qui continuent à déterminer le montant habituel et les modalités de versement.

La ligne **Activer le contrôle des marges** porte l'interrupteur natif et son aide. Le bouton **+** en haut à droite du tableau ouvre la création en modale ; la colonne **Actions** propose le crayon pour modifier et la corbeille pour demander confirmation de suppression. Les références restent du texte. Les formulaires utilisent POST avec token et restent accessibles sans JavaScript ; la confirmation de suppression utilise `Form::formconfirm()` et l'éditeur le dialogue jQuery UI livré par Dolibarr. Le commutateur conserve l'action métier du module, ses contrôles de compatibilité et son horodatage d'activation, au lieu d'écrire directement une constante.

Dès la création comme en édition, les contextes PV, stockage et mixte affichent immédiatement la grille en JavaScript ; Général la masque, sans perdre les valeurs saisies pendant les changements de sélection. La ligne de saisie et le bouton **Ajouter** se trouvent directement sous l'en-tête des bornes, avec le style natif d'une ligne ordinaire (`oddeven`). Chaque tranche enregistrée se supprime avec `img_delete()` sans cadre de bouton (`bordertransp cursorpointer`) et un formulaire POST indépendant. **Enregistrer** et **Annuler** sont dans le pied natif de la modale. L'enregistrement soumet le contrôle avec l'éventuelle tranche en cours dans la même transaction, ferme la modale et revient à la liste après succès ; **Ajouter** conserve l'éditeur ouvert après rechargement pour compléter la grille. Un échec serveur réaffiche les valeurs et l'erreur. Les tranches masquées en contexte Général ne sont pas soumises ni ajoutées. Un changement du contexte d'une grille existante exige de supprimer d'abord ses tranches enregistrées, pour ne pas les réinterpréter silencieusement.

La suppression vérifie le droit de configuration, le rôle administrateur interne et l'entité propriétaire. Elle refuse les contrôles encore affectés ou référencés par une ligne, un accord ou une demande de dérogation ; leur désactivation reste possible. Pour un contrôle inutilisé, les tranches et la règle sont supprimées dans la même transaction avec un unique événement CRUD de suppression et invalidation des décisions courantes. Les instantanés historiques sont conservés. Cette évolution d'interface ne nécessite aucune migration ni réactivation supplémentaire.

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

### Majoration selon le trajet

Chaque règle de marge peut recevoir des paliers de trajet, soit en **minutes aller-retour**, soit en **kilomètres aller-retour**. Un palier s'applique si la valeur calculée est **strictement supérieure** à sa borne. Les majorations s'ajoutent en **points de pourcentage** au seuil de la règle, sans se cumuler entre paliers : seul le palier le plus élevé franchi s'applique. Une règle ne mélange pas les deux unités et les paliers supérieurs ne peuvent pas réduire la majoration. Exemple : au-delà de 105 minutes aller-retour, +10 points porte un minimum de 50 % à 60 % ; exactement 105 minutes conserve 50 %.

Les paliers de trajet se configurent dans la modale d'édition d'une règle déjà enregistrée. La première ligne du tableau, sous les en-têtes, réunit le critère, le seuil, la majoration et le bouton **Ajouter**, comme la ligne de saisie des bornes de puissance. L'ajout et la suppression disposent chacun d'un formulaire POST indépendant avec token. La suppression d'une règle inutilisée retire ses paliers techniques et ses paliers de trajet dans la même transaction.

Cette partie de la modale n'apparaît que si lmdbzoning est actif et fournit la lecture des trajets des devis. Les actions d'ajout et de suppression par URL directe sont alors refusées si cette capacité est indisponible ; une règle contenant des paliers de trajet ne peut pas être activée dans cet état. Les paliers déjà enregistrés sont conservés. Une règle déjà active dont le trajet devient indisponible reste indéterminée jusqu'au retour de lmdbzoning, sans assimiler l'absence de trajet à une majoration nulle.

La source est `LmdbZoningTravelService::read('propal', <id du devis>, '', <utilisateur>)` dans **lmdbzoning 1.3.0**. Le type de trajet **Devis** doit être actif. Le service lmdbzoning choisit le point de référence autonome de l’entité, ou son profil par défaut ; un profil vide est donc valide lorsqu’un point de référence est configuré. La lecture porte sur le trajet du devis affiché sur sa fiche, avec les sites liés éventuels résolus par lmdbzoning, sans repli vers un trajet distinct enregistré sur le tiers. Le service lit les deux sens du trajet routier déjà stockés et vérifie leur fraîcheur, le profil, les adresses et le fournisseur de calcul. Le module de commissions convertit les secondes en minutes et les mètres en kilomètres sans arrondir avant comparaison. Il ne déclenche ni calcul d'itinéraire, ni appel réseau pendant la validation ou la signature.

Si le module, le point de référence ou profil, les droits de lecture de lmdbzoning, du devis ou des destinations, ou un trajet frais manquent, le contrôle qui dépend du trajet est **indéterminé** : aucune majoration de zéro n'est supposée. Le trajet doit alors être calculé depuis lmdbzoning ou par son travail planifié. Pour une signature publique, l'utilisateur ayant validé le devis sert d'acteur à la lecture, comme dans le trigger natif ; ses droits doivent permettre cette lecture. L'empreinte des accords inclut l'état, les deux métriques et la provenance du trajet : une modification des métriques ou de la date de calcul rend les accords précédents caducs. Le détail affiche le seuil de base, les points ajoutés, la valeur du trajet et le seuil effectif. Les décisions déjà figées ne sont pas recalculées.

Pour chaque contexte et effet : utilisateur > groupe > défaut. Plusieurs règles distinctes de même rang constituent un conflit ; les priorités numériques des anciennes commissions ne départagent pas les politiques de marge. Une même règle atteinte par plusieurs affectations n'est appliquée qu'une fois. Général et contexte technique applicable se cumulent.

La vente exige l'accord de tous les profils bénéficiaires de la répartition des commissions. Sans répartition, le commercial auteur du devis est utilisé. La personne qui clique ne devient jamais le bénéficiaire du contrôle. Chaque bénéficiaire garde sa propre décision de commission.

Une commission refusée ou indéterminée vaut zéro dans les calculs directs, y compris les répartitions fixes et proportionnelles. Aucune échéance positive n'en découle. L'attribution du CA, les objectifs et les paliers périodiques ne sont pas réduits. Une cible de commission seule ne bloque pas la vente.

## Dérogations et historique

Deux droits supplémentaires, sans attribution administrateur implicite :

- `marginpolicy.approvesale`, identifiant 45002414 ;
- `marginpolicy.approvecommission`, identifiant 45002415.

L'identifiant de module existant 450024 est conservé ; aucun nouvel ID de module n'est attribué. Les droits existants ne sont pas renumérotés. La page exige également l'accès natif au devis. Un approbateur dispose de la visibilité nécessaire aux décisions, sans recevoir le droit de modifier les répartitions.

Depuis **Répartition commissions / CA**, chaque accord vise un bénéficiaire, une règle et un effet. Motif obligatoire, auteur de l'accord et horodatage serveur sont conservés. Une décision indéterminée exige de corriger les données ou le conflit ; elle n'est pas dérogeable. Une dérogation de vente ne rétablit pas la commission.

Sur la fiche devis, un seul tableau réunit **Commercial**, **Com. estimée**, **État** de la commission et **Règles appliquées**, avec le total dans la colonne des montants lorsque le périmètre global est autorisé et tous les calculs sont disponibles. La loupe **Consulter** ouvre deux tableaux dans la même modale native : le calcul de commission (formule, modalités ou règle automatique, base et taux) et les contrôles de marge (vente, commission, taux réel, sources, contextes, seuils et résultats). Un tableau supplémentaire détaille la prime de dépassement lorsqu’une règle de prime s’applique. Le rapprochement se fait par identifiant de bénéficiaire, jamais par position. Une commission refusée ou indéterminée est distinguée d’une commission autorisée mais non acquise. Le calcul habituel reste visible lorsque les contrôles sont désactivés ou ne s’appliquent pas ; un montant indisponible n’est pas présenté comme zéro.

Dans **Répartition commissions / CA**, la vue détaillée et les formulaires de dérogation autorisés restent accessibles via **Consulter**. Les périmètres de lecture s’appliquent aussi au HTML des modales, sans élévation implicite de l’administrateur ; le seul droit d’approbation ne donne pas accès aux montants de commission. Si JavaScript est désactivé dans Dolibarr, les détails restent accessibles dans un bloc dépliable.

Au clic sur **Valider**, une vente bloquée sous son minimum affiche une confirmation native proposant **Demander une dérogation** ou **Modifier ma proposition**. Le second choix revient au devis brouillon sans mutation. Le premier ouvre un formulaire avec motif obligatoire, puis enregistre par POST protégé une demande pour chaque règle de vente non atteinte et son bénéficiaire. Le droit natif de validation du devis (y compris les permissions avancées), son accès commercial et son entité propriétaire sont contrôlés. Une cible de commission seule ne déclenche pas cette modale. Un contrôle de vente indéterminé sans seuil non atteint propose uniquement de corriger le devis.

Les demandes sont des traces immuables dans `lmdbsalescommissions_margin_request`, liées au devis, à la règle et au bénéficiaire ; elles ne constituent jamais un accord. L'insertion de toutes les lignes est atomique et les doublons d'un même demandeur sur le même état sont ignorés sans écraser le premier motif. L'empreinte rend les demandes caduques après modification. Les approbateurs de vente les voient dans **Répartition commissions / CA** avec motif, demandeur, date et état (en attente, accordée ou caduque), et accordent chaque exception via **Consulter**. Aucun email automatique n'est envoyé. Le devis reste brouillon jusqu'à une nouvelle validation autorisée ; les contrôles serveur existants continuent de couvrir les appels directs.

Après déploiement de la version 1.3.0, réactiver le module par le mécanisme natif pour créer les tables additives, dont celle des demandes. Les règles, répartitions et accords existants sont conservés.

L'empreinte comprend les données économiques, les coûts résolus, les réglages de marge, les données techniques utiles, les bénéficiaires, les politiques et leurs révisions. Les mutations natives de devis/lignes et les mutations des règles/affectations/répartitions invalident les anciens accords. Un formulaire ouvert avant une modification ne peut pas approuver le nouvel état.

À chaque ajout, modification ou suppression d'une ligne de devis, le trigger natif (`LINEPROPAL_INSERT`, `LINEPROPAL_MODIFY`, `LINEPROPAL_DELETE`) invalide les accords courants. La fiche relit les lignes enregistrées après la transaction native et recalcule le coût, le taux de marge, les règles applicables et les décisions. Ce recalcul après l'écriture est nécessaire : le trigger de suppression natif intervient avant le `DELETE` SQL et les totaux du devis sont mis à jour après les triggers de ligne.

Les décisions sont figées dans la transaction de signature, par devis et bénéficiaire, avec règles, valeurs et accords. Les commissions acquises existantes et les versements ne sont pas recalculés. Un devis signé avant activation reste historique ; un devis validé mais non signé est recontrôlé à sa signature. Une signature postérieure à l'activation sans décision conservée est signalée, sans fabrication rétroactive d'un instantané.

La liste de suivi expose les deux décisions figées et un filtre de droit à commission. L'export des lignes contient `sale_control`, `commission_control` et `margin_decision` (instantané JSON explicite, comprenant le motif, l'approbateur et la date des dérogations). Les lignes historiques sans instantané et les paliers gardent ces colonnes vides. Le détail courant reste accessible sur le devis.

## Installation et activation

1. Installer toute la branche sur une instance de recette, dans la seule racine du module.
2. Actualiser le module par son mécanisme natif d'activation, pour installer les tables, les deux colonnes de règle, les droits et les contextes de hooks. Les migrations ajoutent les colonnes absentes et ne purgent aucune donnée.
3. Attribuer explicitement le droit de configuration et, séparément, les droits d'approbation nécessaires.
4. Créer les règles désactivées, compléter les grilles et les éventuels paliers de trajet, effectuer leurs affectations puis activer les règles. Pour les paliers de trajet, installer lmdbzoning 1.3.0, activer le trajet des devis, définir le point de référence du siège ou son profil par défaut et calculer les trajets avant l'activation des règles.
5. Exécuter la recette des canaux utilisés et vérifier **Compatibilité**. L'activation refuse une version hors v20–v25, des fichiers core ne contenant plus les points d'extension attendus ou des contextes de hooks non actualisés.
6. Activer volontairement le contrôle dans chaque entité depuis l'onglet dédié. L'horodatage est celui du serveur.

Une désactivation/réactivation du module conserve les réglages. Désactiver puis réactiver volontairement **le contrôle** ouvre une nouvelle période d'activation ; les instantanés déjà conservés restent prioritaires. Aucun seuil issu des anciennes pièces jointes n'est préchargé automatiquement.

Tables internes ajoutées : `margin_band`, `margin_travel_band`, `margin_approval`, `margin_snapshot`, `margin_revision`, préfixées par `lmdbsalescommissions_` et le préfixe SQL natif. Toutes sont rattachées à l'entité ; les liaisons et instantanés sont explicites, sans cascade métier. Seuls les paliers sont stockés par le module : la durée et la distance restent dans lmdbzoning. Ces paramètres ne sont pas partageables indépendamment du devis. La lecture des paramètres d'une autre entité est explicite et distingue une erreur SQL d'une configuration désactivée.

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
php test/margin_validation_test.php test/.core-cache/20.0.0/htdocs 20.0.0
php test/margin_summary_test.php test/.core-cache/20.0.0/htdocs
php test/margin_admin_test.php test/.core-cache/20.0.0/htdocs
php test/tier_calculator_test.php
```

La suite comprend les seuils 25/40/65 %, l'égalité décimale, les bornes techniques, les conflits, l'indépendance des accords, leur péremption, le rejeu de signature, le gel historique, la désactivation, l'erreur SQL, le rollback simulé, deux entités, les refus API, les appels successifs du gestionnaire de hooks et les commissions fixes/proportionnelles nulles avec CA conservé. Le workflow `Margin controls` ajoute la matrice PHP/Dolibarr et un test DDL MariaDB sur base jetable, avec préfixe long, unicité par entité et résolution SQL réelle des affectations (utilisateur/groupe/défaut et validité jusqu’à la fin du jour). Son statut réel doit être lu sur le SHA de la PR ; sa déclaration ne vaut pas exécution.

PHPStan n'est pas installé/configuré dans cet environnement : analyse non exécutée. Lint PHP et tests ciblés ne la remplacent pas.

Le 1er octobre 2026, correction de la source des trajets : lecture du devis au lieu du tiers et résolution du point de référence par lmdbzoning sans imposer de profil. Le test reproduit avant correction un trajet de devis disponible mais ignoré en l’absence de trajet sur le tiers. Après correction : service 101 assertions, gardes 125, validation 159 et administration 64 sur les six révisions natives ci-dessus sous PHP 8.4.22. Les réponses lmdbzoning, acteurs et SQL sont simulés ; point de référence sans profil, priorité sur un ancien profil, trajet périmé et droits absents sont couverts. Le contrat a été lu dans les sources locales lmdbzoning (`LmdbZoningTravelSource::resolve`, `LmdbZoningTravelService::read`, `ActionsLmdbZoning::formObjectOptions`). Aucun calcul routier ni changement de configuration n’est effectué. Le rendu et le trajet réel restent à vérifier après déploiement.

Pour les réglages compacts, `margin_admin_test.php` exécute **51 assertions sur chacune des six révisions** sous PHP 8.4.22 : tableau sans formulaire permanent, création/édition POST, token, échappement, initialisation Select2, tranches disponibles dès la création, ligne de saisie sous l'en-tête, valeurs conservées, corbeille native et formulaire de suppression indépendant, confirmation native avec et sans JavaScript, contrôle référencé, droits et entité, échecs de début/écriture/trigger/commit et rollback simulé. `Form` et ses composants sont natifs ; SQL, acteur et effets de `deleteCommon()` sont simulés, sans transaction ERP réelle. Les helpers de boutons et pictogrammes sont des doublures pour le test de structure ; leur présence a été vérifiée dans les sources figées v20–v25 alpha. Cette suite ne soumet pas le contrôleur HTTP d'enregistrement des règles et tranches. Elle figure dans la matrice CI existante.

Dans Chrome, l'aperçu HTTP local utilise le JavaScript natif v25 alpha et le template du module. Les changements Général → PV → Général → stockage → mixte ont été exécutés : affichage/masquage des bornes, conservation des valeurs et effet commission imposé aux grilles techniques observés. Le DOM confirme les boutons dans le pied de modale et la ligne d'ajout directement sous l'en-tête. Le thème et les pictogrammes de l'aperçu sont simplifiés, les données simulées. Le pilotage du clic Enregistrer a échoué avant exécution : les soumissions, la fermeture après succès, la réouverture, le rendu complet Dolibarr/Select2 et le mobile restent à vérifier. Ces réglages modifiés n'ont pas été déployés sur develop pendant cette vérification ; la recette réelle doit couvrir création avec première tranche, édition, sauvegarde sans tranche en cours, erreurs/rollback, ajout successif et suppression.

Le 1er octobre 2026, correction de présentation limitée aux classes natives du tableau et de la corbeille. Lecture des styles `theme/eldy/global.inc.php` sur les six commits figés ci-dessus : `bordertransp` retire la bordure et le fond, `cursorpointer` est disponible. Les 51 assertions d’administration ont été rejouées avec succès sur les six révisions sous PHP 8.4.22 ; aucune logique de soumission, de droit ou de suppression modifiée. Le nouveau rendu reste à vérifier dans le thème de develop après redéploiement ; cette lecture de sources ne constitue pas une recette navigateur.

Pour la saisie des majorations de trajet dans la première ligne du tableau, la suite d’administration passe à **64 assertions sur chacune des six révisions** sous PHP 8.4. Elle vérifie l’ordre des lignes, l’association des champs au formulaire POST indépendant par l’attribut HTML `form`, le token, l’absence de saisie dupliquée sous le tableau, la conservation des valeurs après erreur et l'absence du bloc lorsque la capacité de trajet est indisponible. Le rendu dans l’instance Dolibarr reste à vérifier après déploiement de cette modification. Le test des événements natifs de ligne couvre les trois mutations et le refus d'une erreur de révision ; la suite de calcul vérifie le changement de décision et d'empreinte après ajout, édition et suppression. Ces essais utilisent SQL et acteurs simulés ; ils ne remplacent pas une recette HTTP sur instance.

Recette complémentaire du 30 septembre 2026 : après déploiement de la branche par l'utilisateur, six devis brouillons ont été préparés dans l'entité TEST de develop (Dolibarr 25 alpha, Multicompany 24.0.2). Les décisions affichées ont été vérifiées pour les marges générales de 25/40/65 %, PV 9 kWc, stockage utile 10 kWh et mixte 9 kWc + 10 kWh à 65 %. Aucun de ces devis n'a été validé ou signé pendant cette recette ; les canaux de mutation ci-dessous restent à vérifier.

Pour le lien **Consulter**, le rendu PHP de `Form::textwithpicto()` a été exécuté avec les six révisions natives ci-dessus sous PHP 8.4.22, avec données simulées et identifiants distincts pour deux bénéficiaires dans deux blocs. Le tableau compact a été observé dans Chrome sur un aperçu HTTP local utilisant le JavaScript natif v25 alpha. Le pilotage des clics a échoué avant leur exécution : l'ouverture, la fermeture et la réouverture de la modale restent à vérifier après redéploiement sur develop. Ce dernier changement d'interface n'a pas été déployé pendant cette vérification.

Pour la synthèse unique, `margin_summary_test.php` ajoute 35 assertions réussies sur les six révisions natives sous PHP 8.4.22 : rapprochement de bénéficiaires présentés dans un ordre différent, deux tableaux par modale sans modale imbriquée, échappement HTML, total, droits propre/groupe/global, administrateur sans droit, utilisateur externe, approbateur sans droit aux montants, absence de règle, erreur de lecture, règle automatique, état indéterminé et repli sans JavaScript. SQL, utilisateurs et estimations sont simulés ; `Form::textwithpicto()` et le rendu du module sont exécutés sans modification. Les quatre colonnes et les montants ont été observés dans le DOM de l’aperçu local Chrome avec deux bénéficiaires et deux blocs indépendants. Le clic et la capture n’ont pas abouti dans l’outil navigateur ; ouverture/fermeture, rendu visuel sur téléphone et recette develop restent à exécuter après déploiement.

L’ordre d’affichage utilise désormais la propriété native `ActionsLmdbSalesCommissions::$priority = PHP_INT_MAX` : `HookManager` trie les contributions et `FormMargin` les insère après ses totaux. La synthèse termine ainsi le bloc après la ligne de prix au Wc de Centrale PV, sans déplacement JavaScript. Cette priorité s’applique à toute la classe de hooks ; les précontrôles restent exécutés avant les opérations natives. Un module tiers utilisant lui aussi une priorité terminale ou remplaçant le résultat complet exige une vérification de coexistence.

Le test reproduisait l’échec avant correction. La suite de synthèse passe désormais **53 assertions sur chacune des six révisions** sous PHP 8.4.22, avec initialisation, tri, concaténation et remise à zéro du `HookManager` natif : deux ordres de chargement, une contribution de priorité 1000, Centrale PV absent et deux appels successifs sans doublon. Les autres contributeurs sont simulés ; leurs calculs ne sont pas testés ici. Les 121 assertions de validation et de gardes ont également été rejouées avec succès. Le nouvel ordre reste à vérifier visuellement sur develop après redéploiement ; ce seul changement de priorité ne nécessite pas de réactivation du module.

Pour le parcours **Valider → demande de dérogation**, `margin_validation_test.php` exécute le hook avec `HookManager`, `Form::formconfirm()` et `FormMargin` natifs : 121 assertions cumulées réussies sur chacune des six révisions sous PHP 8.4.22. Sont notamment couverts le seuil exact, la cible de commission seule, l'accord déjà obtenu, le coût inconnu, deux bénéficiaires, les droits avancés, le périmètre commercial, l'entité, l'empreinte périmée, l'erreur d'écriture et le formulaire natif sans JavaScript avec token. La CI MariaDB ajoute l'insertion réelle, le rollback et la protection contre les doublons des demandes ; son résultat doit être vérifié sur le commit publié. SQL et autorisations restent simulés dans la suite PHP locale.

Dans Chrome, la modale avec ses deux boutons et le focus initial sur **Modifier ma proposition** a été observée après ouverture directe de l'URL de validation de l'aperçu local (JavaScript natif v25 alpha, données simulées). Le pilotage des clics n'a pas abouti ; le parcours HTTP réel (CSRF, enregistrement, retour au brouillon et traitement par l'approbateur) et le rendu des demandes sur develop restent à recetter après déploiement et réactivation du module.

## Recette d'instance avant activation

- Activation/réactivation native, droits persistés, colonnes existantes, maintien des réglages à 0 et des constantes ; installation MariaDB/MySQL avec deux entités réelles.
- Formulaires avec et sans token, utilisateur interne sans droit, approbateur seul, administrateur sans droits fonctionnels, utilisateur limité à ses tiers ; aucun accès indirect aux accords d'un autre devis.
- Vente à 25/40/65 %, règles générales avec Centrale PV désactivé et aucune définition technique, grilles PV/stockage/mixte, bornes et données manquantes.
- Modification après accord, avant validation puis avant signature ; contrôle de tous les bénéficiaires, maintien du CA et des paliers, absence d'échéance payable pour une décision indéterminée.
- Fiche, liste, signature publique et API authentifiée, y compris PUT de statut, `notrigger`, lot mixte autorisé/refusé, modules tiers actifs ; vérifier les fichiers, emails et la transaction externe après refus.
- Conservation des commissions/versements historiques, signature répétée, annulation et éventuelle réouverture d'un devis signé, erreurs de commit/rollback avec le pilote natif et DebugBar.
- Rendu des grilles, accords, suivi, filtres, pagination et exports ; confronter les résultats aux valeurs affichées par la marge native.

Ces essais réels restent à exécuter par environnement, notamment avec la version Multicompany du parc. Une réussite des tests isolés ne les remplace pas.

### Infobulle utilisateur à l’ouverture du détail

Le dialogue natif jQuery UI donne le focus au premier lien de son contenu. Le lien utilisateur avec infobulle déclenchait donc automatiquement son chargement (avec le libellé provisoire `tocomplete`). Le détail utilise désormais `User::getNomUrl(1, '', 0, 1)` ; le lien du tableau principal conserve son comportement. Aucun gestionnaire global de focus ou de tooltip n’est modifié. Le quatrième paramètre natif est présent dans les sources Dolibarr v20 à v24 et dans le checkout local v25 alpha. La suite de rendu passe 59 assertions sur les six révisions natives, avec utilisateurs et SQL simulés ; les liens du dialogue restent présents et ne portent plus d’infobulle. L’ouverture et la réouverture sur instance restent à vérifier après déploiement.
