# Recette du circuit de dérogation — 29 octobre 2026

## Décision de déploiement

**Validation globale non acquise.** Les blocages SMTP, API et PHPStan ont été levés pendant la reprise. Les demandes et les deux décisions sont reçues par email ; les refus API en brouillon et pendant l’attente sont vérifiés sur develop. La signature autorisée du devis fictif 36 a ensuite confirmé la commission figée de 37,50 EUR et ses trois échéances. La demande sans validation native, les responsables directs et indirects, le refus d’autoapprobation limitée et la commission partielle sont désormais exercés avec des profils distincts. Les autres limites ci-dessous restent à lever avant activation du blocage en production.

PR : [#10](https://github.com/mapiolca/lmdbsalescommissions/pull/10), version **1.3.0**, base `ecb535452d04e44fce4982717272196d118a6fa5`. Code de la reprise réelle : **`0681c77386d4f1646cff1a5cc110a24742f2fb32`**, puis **`b2fe02732fb6a1fd79f1344d8114ae972a4ce799`** pour les accents des notifications. La tête servie a été confirmée dans Git Version Control de cPanel, sans déploiement par terminal.

## Environnements et nature des preuves

| Environnement | Version / révision | Preuve |
|---|---|---|
| Local Windows | PHP 8.4.22, sources officielles figées v20–v25 alpha | Tests isolés, calculs et contrats natifs ; persistance, comptes et transport simulés |
| CI native-contracts | v20 / PHP 8.0 et 8.4 ; v21–v23 / PHP 8.2 ; v24–v25 alpha / PHP 8.4 | Sept jobs réussis par exécution ; aucune instance ERP complète installée par ces jobs |
| CI schema | MariaDB 11.4, DDL natif v20 et tables de test | SQL réel : migrations rejouées, limites natives des colonnes, verrous concurrents, unicité et rollback ; parent devis simplifié |
| develop | Dolibarr 25.0.0-alpha, core `31627071c3d2dd890322088ebba9917515de7ac4`, PHP 8.3.36, Multicompany 24.0.2, module 1.3.0, TEST 1 | Navigateur interne avec Codex id 7 ; API native avec le jeton existant d’Utilisateur Test id 3 ; objets fictifs uniquement |
| develop TEST 2 | Module initialement désactivé, laissé désactivé | Accès au dossier TEST 1 refusé ; pas de recette de partage avec deux modules actifs |

Révisions officielles : v20 `697bf01970740a3339cd99cf055b4428fc5e051c`, v21 `fd970b582a4d8c5779a2958a4e9f4fce225cf085`, v22 `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710`, v23 `57a1f05d490a7a80944a8232e9c613e6556d2704`, v24 `769c7db907099643558e77d7002c109cfda919e5`, v25 alpha `ef6e5818b0a5626f928cb9bc66bddb8be43ac32f`. La dernière révision ne prouve rien pour une future v25 finale.

## Résultats automatisés

- État `2a6daa3` : **83 exécutions locales réussies**, 106 fichiers PHP sans erreur de syntaxe, test JavaScript de 10 assertions avec DOM/Ajax simulés. Les compteurs d’assertions des suites imbriquées ne sont pas additionnés comme des scénarios uniques.
- Après le correctif de liste `cd37f5d` : les six suites `margin_workflow_test.php` passent de nouveau, chacune avec 74 assertions ; syntaxe des deux fichiers modifiés et `git diff --check` conformes.
- CI au SHA `cd37f5d` : **8 jobs réussis sur la PR et 8 sur la branche**, dont MariaDB. [PR : 40841714175](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40841714175), [branche : 40841707321](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40841707321). Aucun de ces résultats n’est présenté comme une recette ERP réelle sur les six versions.
- Premier PHPStan : 81 diagnostics. Après correction au SHA `0681c77`, **PHPStan 2.1.0, niveau 5, cible PHP 8.0 : zéro erreur** sur les 20 fichiers de production du circuit ; même résultat après `b2fe027`. Commande : `python test/run_phpstan.py test/.tools/native-phpstan/htdocs --version 25.0.0-alpha --zoning <checkout-lmdbzoning> --propalpv <checkout-lmdbpropalpv>`. Les sources core complètes et les contrats des deux modules optionnels sont fournis sans bootstrap ERP ni dépendance obligatoire ajoutée. Aucun baseline, baisse de niveau ou masquage.
- État `0681c77` : **76 exécutions locales réussies sur les six révisions natives**, 107 lints PHP, 10 assertions JavaScript et 72 chemins de bootstrap sur 21 points d’entrée. Après `b2fe027`, les six suites de notifications passent avec 95 assertions cumulatives chacune, dont les neuf vérifications des sujets, motifs échappés et montants. Le test de sujet échouait avant le correctif et passe ensuite.
- CI `b2fe027` : [PR 40868453159](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40868453159) et [branche 40868446663](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40868446663), toutes deux réussies. Les tests de contrats et MariaDB restent distincts d’une recette ERP complète.
- Le paquet candidat vérifie le nom numérique `module_lmdbsalescommissions-1.3.0.zip`, la racine unique, l’absence du dépôt/caches/tests et **72 chemins d’inclusion sur 21 points d’entrée** : installation sous `htdocs`, sous `custom`, environnement absent. Le bootstrap est simulé ; le ZIP n’a pas été installé par l’installateur natif et n’est pas une release Dolistore.

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
| Deux confirmations HTTP de demande depuis deux onglets, même compte, `b2fe027` | Un seul dossier actif ; aucun doublon dans l’historique |
| Approbation et retour envoyés concurremment depuis ces deux onglets | Une seule décision conservée : approbation gagnante, retour refusé « La demande ou le devis a déjà changé » ; devis réellement Validé puis remis en Brouillon |
| Affichage en liste après correctif | État « En attente de dérogation » pour le devis 36, sans changement du statut numérique natif |
| URL de soumission sans jeton | Accès refusé ; aucune nouvelle demande. Les formulaires avec jeton ont été acceptés |
| Accès depuis TEST 2 | Refus du dossier de TEST 1 ; module TEST 2 laissé inactif |

Le devis 47 est un clone fictif créé pendant la recette, devenu `PR2610-0010`. Sa première approbation à 0 % avait une base théorique nulle : elle n’est pas utilisée comme preuve du calcul à zéro sur une base non nulle. Ce dernier est couvert par le devis 36.

L’avertissement de lmdbzoning `GeocoderHttpError` apparaît lors de la validation, mais le devis est effectivement validé. Il est distinct du blocage SMTP décrit ci-dessous.

## Reprise SMTP, notifications et API

### SMTP et réception des notifications

Les deux événements et leurs modèles sont visibles dans l’administration native. Les premiers essais avaient échoué avec `Error bad hostname IP (IP is a local IP). Must be an external URL.` ; la notification native renvoyait une erreur et la transaction du dossier était annulée. Cette observation initiale reste une preuve de rollback, pas d’envoi réussi.

Le core réellement servi vérifie la cible dans `CMailFile::checkSmtpTargetAllowed()` et prévoit l’option `$dolibarr_mail_allow_unrestricted_smtp_target`. Après accord explicite, **seule cette option a été ajoutée au `conf.php` de develop**. Le nom SMTP, le port 465, le TLS et les identifiants sont conservés ; aucun fichier source du core n’est modifié. Le repli `localhost` proposé puis refusé par l’utilisateur n’a pas été appliqué.

L’envoi forcé constaté pointait vers `test@…` : le réglage natif a été corrigé vers **`developpeur@lesmetiersdubatiment.fr`**, conformément à la demande. Le test natif SMTP de 11:57 est accepté. Le suivi natif cPanel confirme l’acheminement ; les deux lignes d’un même parcours correspondent au filtre de transfert puis à la livraison locale, pas à deux messages.

Deux destinataires fixes temporaires, CREATE et UPDATE au seuil 0, ont ensuite permis la recette réelle :

| Opération / code servi | Réception INBOX constatée | Résultat |
|---|---|---|
| Demande du devis 47, `0681c77`, 12:33 | UID 24792 | Une réception, substitutions renseignées ; défaut d’entité HTML dans le sujet reproduit |
| Retour motivé, `b2fe027`, 12:51 | UID 24794 | « Modification réautorisée : PR2610-0010 », accents lisibles |
| Nouvelle demande après retour, `b2fe027`, 12:51 | UID 24795 | « En attente de dérogation : PR2610-0010 », accents lisibles |
| Approbation native, `b2fe027`, 12:52 | UID 24796 | « Dérogation approuvée : PR2610-0010 », approbateur, motif, marge 25 %, règle 30 %, commission 0 % et lien authentifié renseignés |

La recherche INBOX, non tronquée, retourne exactement ces quatre messages après rechargement de la page de décision. Les corps complets de la demande initiale et de l’accord ont été lus sans marquer les messages comme lus. Les modèles restent gérés par Notifications ; aucun second envoi n’est ajouté aux pages. L’en-tête d’authentification du transport local est non évalué : aucune validation SPF/DKIM n’est revendiquée.

Le défaut de sujet a été corrigé par `Translate::transnoentities()` pour les libellés statiques de décision ; les motifs libres restent échappés. Les modèles personnalisés sont préservés par les tests de transport natif sur les six versions. Les deux destinataires fixes temporaires ont été retirés et leur absence confirmée après rechargement ; la redirection forcée et l’option SMTP expressément autorisée sont conservées.

### API native authentifiée

Le jeton existant d’Utilisateur Test id 3 a été autorisé pour l’explorateur puis pour un client HTTP local limité aux devis fictifs 36 et 47. Le client reproductible `test/live_api_recipe.py` ne modifie que le devis 47, transmet le jeton dans `DOLAPIKEY`, vérifie TLS, refuse les redirections et supprime le fichier temporaire du jeton, y compris après échec. Aucun secret n’est versionné dans les rapports.

**Compression désactivée conservée** selon instruction (`API_DISABLE_COMPRESSION=1`). Le mode production/cache a été désactivé temporairement : la découverte de l’explorateur restait limitée à trois ressources. Il a été rétabli ; aucun élargissement de droits ni activation du login API. L’API des devis répond bien aux appels HTTP directs malgré ce défaut de découverte de l’explorateur.

Résultats réels `0681c77`, avec lecture avant/après et comparaison des données :

| État / appel | Résultat |
|---|---|
| GET devis 36 et 47 | HTTP 200, références et états attendus |
| Brouillon sans demande : PUT de la note publique à sa valeur actuelle | HTTP 200 ; droit normal d’écriture confirmé |
| PUT direct de statut ou `notrigger=1` ; POST validate avec triggers désactivés | HTTP 409 `LscApiLifecycle` |
| Validation et signature sous minimum sans dérogation | HTTP 409 `LscSaleBlocked` |
| Attente : PUT note, PUT ligne 84, POST nouvelle ligne | HTTP 409 `LscWorkflowLocked` pour chaque appel |
| Attente : validate sans triggers, close au statut signé | HTTP 409 `LscWorkflowLocked` |
| GET final après chaque série | HTTP 200 ; statut, économie, lignes, extrafields et note publique inchangés |

Une série intermédiaire a reçu un HTTP 403 **Imunify360 avant Dolibarr** ; elle a été arrêtée dès la première lecture, sans mutation. La reprise après les autres essais est entièrement passée. Aucun filtrage Imunify, IP autorisée, User-Agent, proxy ou sécurité TLS n’a été modifié pour la faire passer. Les rapports locaux ignorés `live-api-unlocked.json` et `live-api-pending.json` contiennent les résultats sans jeton.

## Complément : pictogrammes et libellés des modèles

Au code `a78c342ee841eb6f9651bcb80e9008775111c14f`, servi temporairement et confirmé dans Git Version Control, une actualisation native du module a rendu visibles les deux pictogrammes de pourcentage jaunes dans l’administration Notifications. Le nom technique `LscWorkflowDefaultMail` et le type `lscmargincase` restent inchangés ; le libellé sélectionné devient « Validation et dérogation de marge des devis ». Les deux valeurs sélectionnées restent `LscWorkflowDefaultMail:lscmargincase`. Le catalogue anglais fournit « Proposal margin approval and exception » ; ses traductions sont testées, sans changement de langue utilisateur pendant la recette réelle.

Le hook charge explicitement son catalogue. Le core dérive la classe Font Awesome du type de modèle ; une feuille déclarée nativement associe uniquement cet alias au glyphe de pourcentage existant. Aucun modèle ni abonnement n’a été modifié ou envoyé pour cet essai. Les six suites de notifications passent avec 99 assertions cumulatives chacune, PHPStan niveau 5 reste sans erreur, le paquet vérifie 143 fichiers et 72 bootstraps. [CI PR](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40872787188) et [CI branche](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40872781727) réussies. La capture de preuve reste hors du dépôt. Au retour sur la branche initiale `8230ff3`, l’actualisation native ne supprimait pas la partie CSS absente de son ancien descripteur : sa déclaration technique a été neutralisée à `[]` dans l’écran natif des constantes de TEST 1. Aucun lien CSS manquant n’est ensuite émis. L’actualisation de la branche corrigée redéclarera sa feuille selon le descripteur ; aucun réglage métier ni sélection de modèle n’a été changé.

## Complément : onglet et bannière natives du devis

Le code `d9406caa66a7ea534cb0aa71e70a608efedcdc83`, confirmé dans Git Version Control et actualisé nativement dans TEST 1, a été servi pour cet essai. L’onglet **Historique des validations** apparaît après les onglets transverses et les autres onglets externes, sur la fiche du devis 47, Notes et Répartition commissions / CA. Il est actif sur la page d’historique existante ; la bannière native affiche la référence `PR2610-0010`, sa référence client, le tiers fictif, le retour liste et le statut. Aucun lien d’historique en double ne reste dans les actions.

L’annulation de **Demander la validation** laisse le devis en Brouillon. Une demande fictive motivée a ensuite affiché **En attente de dérogation** dans la bannière, avec **Réautoriser la modification** et **Approuver et valider** dans `tabsAction`. La confirmation d’approbation native s’ouvre avec les contrôles et la commission ; elle a été annulée. Le retour motivé en modification clôture la nouvelle demande et restitue le statut natif Brouillon. L’historique est conservé, sans dossier actif ni signature. Le sélecteur natif de pagination soumet bien son formulaire GET, recharge à 50 puis revient à 20. Aucun envoi SMTP n’est revendiqué pour ce complément.

Le hook `completeTabsHead`, limité à l’ajout externe et à un objet `Propal` chargé, évite les expressions utilisateur que l’évaluateur de v23 refuse. Les contextes `globalcard` et `propalnote` couvrent les pages natives ; la page d’historique conserve son contexte de statut. Les six suites exécutent le gestionnaire natif et la fonction native de complétion des onglets : ordre, absence de doublon, remise à zéro, refus utilisateur externe/sans lecture, disponibilité et objet différent. Résultat : **88 assertions de circuit**, **113 assertions cumulées avec les notifications**, sur chacune des six révisions v20–v25 alpha sous PHP 8.4.22. Utilisateurs, données et transport de ces suites restent simulés. PHPStan niveau 5, cible PHP 8.0, ne signale aucune erreur sur les 20 fichiers de production. Les huit jobs [CI PR](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40876029812) réussissent sur ce SHA, y compris PHP 8.0 et MariaDB 11.4 ; ils ne constituent pas six instances ERP complètes.

Le rendu réel concerne Dolibarr 25 alpha / PHP 8.3.36 sur develop. Le devis de cet essai n’est pas lié à un projet : le lien de projet n’a pas été vérifié visuellement. La lecture des six sources confirme la présence du contrôle natif d’accès projet ; le rendu le soumet aussi aux droits et entités autorisées. La capture reste hors du dépôt. Ce complément ne lève pas les limites de recette métier ci-dessous.

Après l’essai, le candidat a été désactivé avec son descripteur encore servi pour retirer ses déclarations techniques. Git Version Control a rétabli la branche initiale **feature/margin-excess-bonus / 8230ff3** ; après le message d’échec de contact distant, son rechargement confirme cette branche et ce SHA. Le module d’origine a ensuite été réactivé nativement : état actif confirmé, nouveau lien CSS absent, ancien ensemble d’onglets restauré et devis 47 en Brouillon. Aucun changement de branche ni déploiement n’a utilisé le terminal. Les dossiers et modèles ne sont pas supprimés et les autres réglages de recette restent inchangés.

## Reprise : compte commercial réel et signature autorisée

Après accord explicite de l’utilisateur pour signer uniquement le devis fictif 36, la branche `fix/margin-approval-workflow` au SHA `18757e3a3cae54eec2fe7df6c3f5b1ef796661db` a été servie temporairement via Git Version Control et le module actualisé nativement dans TEST 1. La branche initiale relevée reste `feature/margin-excess-bonus / 8230ff3`.

Avant confirmation, la fiche `PR2610-0009` affiche 37,50 EUR **Non acquise**. Le contrôle automatique a d’abord refusé la signature à cause du destinataire d’origine `erp@…` annoncé par la modale. La lecture de la configuration native a confirmé **Envoyer tous les emails à developpeur@lesmetiersdubatiment.fr** ; le core `CMailFile` remplace la destination et vide CC/CCI avec ce réglage. La même confirmation native a ensuite été acceptée, sans modification de configuration ni autre mode de signature.

| Contrôle réel | Résultat |
|---|---|
| Statut après confirmation | **Signée (à facturer)** ; seule la confirmation native de signature a été exécutée |
| Détail des décisions | Vente et commission autorisées par dérogation ; marge 25 %, commission de l’auteur conservée à **100 %**, **Décision figée à la signature** |
| Suivi des commissions filtré sur `PR2610-0009` | Commission sur marge **Acquise**, base 250 EUR, taux 15 %, commission **37,50 EUR** ; CA attribué **1 250 EUR** conservé, prime de dépassement nulle |
| Échéance signature, révision 1 | **10 % = 3,75 EUR, À verser**, datée du 29/10/2026 |
| Échéance acompte, révision 1 | **40 % = 15 EUR, En attente** |
| Échéance facture finale, révision 1 | **50 % = 18,75 EUR, En attente** |
| Rechargement des échéances | Toujours trois lignes, total **37,50 EUR**, aucune ligne marquée versée et aucun doublon |

Le connecteur de boîte mail a refusé la recherche de réception car son quota journalier est épuisé : aucune recherche ni lecture supplémentaire n’a été exécutée. La réception de l’email de signature n’est donc pas revendiquée, contrairement aux notifications de demande et décision vérifiées lors de la session précédente. Les captures de signature et d’échéances restent hors du dépôt.

La connexion au compte existant `utilisateur.test` (id 3) dans le navigateur interne a réussi, sans création ni modification de compte, mot de passe ou jeton. Sur le devis fictif 47, le bouton **Demander la validation** et sa confirmation native sont accessibles ; l’annulation conserve le Brouillon et ne crée aucun nouveau dossier. L’historique est accessible, les pourcentages de commission des autres auteurs restent masqués et aucun bouton d’approbation n’est proposé. L’accès direct à l’administration des modules est refusé.

Ce compte **hérite déjà du droit natif de validation** et sa fiche indique **Responsable hiérarchique : Aucun**. Aucun droit, groupe ou lien hiérarchique n’a été modifié : ces constats ne valident ni la demande sans droit natif de validation ni l’approbation par un responsable de subordonnés. Le responsable de recette et le profil adapté restent à préciser. La session du compte de test a été fermée, puis l’utilisateur a reconnecté l’administrateur dans le navigateur interne. Le devis 36 signé et ses échéances sont conservés ; le devis 47 reste en Brouillon sans nouveau dossier actif.

La restauration de cette reprise est achevée : Git Version Control confirme **feature/margin-excess-bonus / 8230ff3** après rechargement, malgré le message de contact distant. Le module d’origine est actualisé nativement, puis la constante technique `MAIN_MODULE_LMDBSALESCOMMISSIONS_CSS` de TEST 1 est remise à sa valeur initiale `[]` dans l’administration native : le module est actif et aucun lien vers la feuille du candidat n’est ensuite émis. Le devis 36 reste **Signé**, avec **37,50 EUR** et la décision figée visibles après restauration. Aucun réglage SMTP/API ni droit n’a été changé pendant ces essais ; aucun terminal n’a servi au déploiement. CI du rapport `995c7cd` : [huit jobs réussis](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40881866982) ; code de production inchangé depuis `d9406ca`.

## Reprise : profils limités et présentation de la demande

Le code `1f9d404` a corrigé le bouton natif **Valider** désactivé, dont l’URL ne contient pas d’action : il est désormais masqué sous le minimum obligatoire même sans droit de validation. Le script réellement rendu par le hook est exercé avec des doublures DOM/jQuery : les autres boutons refusés et le clonage restent préservés hors attente. Dix assertions d’aperçu et huit assertions de commandes passent. Aucun contrôle serveur n’est supprimé.

La recette utilise deux comptes existants distincts : un commercial avec lecture/création des devis sans validation native, puis son responsable hiérarchique direct avec validation et approbation des subordonnés, sans approbation globale. L’identité de chaque session a été vérifiée après connexion. Les autorisations restent limitées aux données fictives prévues ; aucun mot de passe ni jeton n’a été créé, changé ou enregistré dans les preuves.

Le devis fictif de recette porte une marge de 25 % pour un minimum de 30 %. Le commercial sans droit natif de validation peut soumettre la demande. Pendant l’attente, aucun lien d’approbation ne lui est proposé et les actions natives sont neutralisées. Le responsable limité peut retourner la demande en modification, puis approuver une nouvelle demande : le statut natif devient réellement **Validée (proposition ouverte)**. Sans droit de commission distinct, le champ est en lecture seule à 0 %, malgré une commission théorique de 37,50 EUR ; le tableau de synthèse affiche une commission **Nulle**. Le retour natif en Brouillon invalide l’accord, puis une nouvelle demande est soumise et approuvée avec les deux identités vérifiées. Ce devis reste Validé, sans signature ; aucun dossier actif n’y reste en attente.

Lors du retour natif en Brouillon de ce devis fictif cloné, un avertissement indique une règle de palier historique indisponible : les commissions du devis sont supprimées, mais la prime périodique doit être vérifiée manuellement. Aucun rattrapage ni effacement de palier n’a été lancé ; ce cas reste dans les limites de recette historique.

La demande utilise maintenant une explication du circuit, le tableau des règles bloquantes puis une justification obligatoire sur toute la largeur. Le texte décrit l’attente verrouillée, les décisions possibles et l’indépendance de la commission. La hauteur automatique native évite de couper ces explications. Le SHA `892e4308e2ffdcec1596e143f0018937dd4f0cfe` est confirmé comme servi dans Git Version Control ; le rendu réel sur develop montre cet ordre, le texte accentué et le pied de confirmation natif. L’annulation laisse l’historique inchangé. La capture reste hors du dépôt.

Les six révisions natives v20–v25 alpha exécutent **92 assertions de circuit chacune**, avec et sans JavaScript pour la confirmation ; les données, utilisateurs et le transport restent simulés dans ces suites. Les **18 assertions JavaScript**, la syntaxe PHP et PHPStan 2.1.0 niveau 5/cible PHP 8.0 passent, sans erreur sur les 20 fichiers de production. Les huit jobs de [CI PR du code 892e430](https://github.com/mapiolca/lmdbsalescommissions/actions/runs/40891860277) réussissent ; ces contrats ne sont pas six installations ERP complètes.

À la fin de ce premier complément, l’essai positif du droit distinct de commission et la restauration attendaient une session administrateur. Ils sont décrits dans la clôture ci-dessous. Les profils et la hiérarchie restent configurés selon l’instruction de l’utilisateur. Ces essais n’ajoutent aucune preuve de réception d’email, le quota du connecteur étant épuisé.

## Clôture : commission partielle, hiérarchie indirecte et autoapprobation

Les essais complémentaires ont utilisé le même code de production `892e430`, sans nouvelle modification du code PHP ou JavaScript. Les identités des sessions ont été contrôlées après connexion ; aucune signature supplémentaire et aucun paiement n’ont été exécutés.

| Parcours réel | Résultat |
|---|---|
| Responsable limité avec le droit distinct de commission | Champ éditable, valeur initiale 0 %, aperçu serveur de **18,75 EUR** pour 50 % des 37,50 EUR théoriques |
| Approbation à 50 % | Devis réellement Validé ; historique à 50 % ; synthèse et détail avec les droits de lecture adaptés : base de marge 250 EUR, taux 15 %, commission **18,75 EUR Non acquise** |
| Persistance après rechargement et restauration du code | Suivi : commission sur marge **18,75 EUR Estimée**, CA attribué **1 250 EUR**, aucun montant à verser ni versé ; aucune acquisition sans signature |
| Responsable indirect | Une chaîne hiérarchique native existante a été vérifiée avant l’essai, sans modifier d’autre compte. Le responsable limité a effectivement validé le devis de son subordonné indirect avec commission nulle. Le devis est ensuite remis en Brouillon |
| Autoapprobation limitée | Clone fictif créé par le responsable, demande soumise, aucune action d’approbation. L’URL directe d’approbation est refusée côté serveur, sans modale ni changement du statut En attente. Le dossier est ensuite retourné en modification par un approbateur global ; le clone reste en Brouillon |
| Historique signé | Le devis déjà signé conserve **37,50 EUR**, 100 % retenus et la décision figée. Aucun paiement ni nouvel envoi manuel |

Le droit distinct de commission du responsable est conservé selon l’autorisation donnée pour la recette ; l’approbation globale reste absente. Aucun droit du groupe commun n’a été modifié. Un approbateur n’ayant pas le droit de lire les commissions des autres bénéficiaires ne voit pas leur estimation dans la synthèse : la confirmation d’approbation et son historique ont été contrôlés avec ce profil, puis le montant de synthèse avec le compte disposant des droits de lecture adaptés.

L’éditeur de la règle fictive a de nouveau expiré avant toute saisie ou soumission : **aucun seuil n’a été changé**, l’accord périmé n’est donc pas validé par ce test réel. La lecture de `admin/marginpolicies.php` montre un chargement intégral des produits lors de chaque création/édition ; le lien entre ce chargement et le blocage du pilotage reste une hypothèse à confirmer. L’onglet n’a pas pu être fermé par l’API de pilotage, qui expire également ; aucune autre méthode de modification du réglage n’a été utilisée.

Le candidat a été désactivé nativement avec son descripteur encore servi, puis **Git Version Control** a rétabli la branche initiale au SHA `8230ff3ec705fe8f2e9aeb9a865327a25de072c3`. Après le message de contact distant, le rechargement de la page cPanel confirme la branche et ce SHA. Le module d’origine a ensuite été réactivé nativement : état actif et absence de la feuille CSS du candidat confirmés. Aucun terminal n’a servi au déploiement. Les données et historiques fictifs sont conservés, sans dossier actif en attente dans les devis de ces essais ; les paramètres SMTP/API n’ont pas été changés pendant cette clôture.

La fiche du devis partiel, recalculée par l’ancienne branche restaurée, affiche 0 EUR, alors que le suivi conserve les **18,75 EUR** estimés enregistrés par le candidat. Les observations du nouveau circuit se rapportent donc au code `892e430` effectivement servi avant restauration ; le rendu de l’ancienne branche ne constitue pas une recette de ce circuit. Aucun recalcul n’a été lancé pour faire coïncider les deux versions.

Cette clôture ne lève pas le refus hors hiérarchie distinct de l’autoapprobation, l’accès métier révoqué, l’accord périmé réel, la signature publique, les deux entités actives et les autres limites du tableau. Les captures restent hors du dépôt. Les résultats automatisés du code restent ceux de `892e430` ; ce complément documentaire ne modifie pas la production.

## Couverture restant à compléter avant feu vert

| Exigence | Preuve disponible | Recette réelle encore nécessaire |
|---|---|---|
| Demande sans droit natif de validation | Demande réelle avec un profil commercial limité et son identité vérifiés ; retour et nouvelle demande | Scénario positif vérifié sur develop uniquement |
| Hiérarchie directe/indirecte, refus hors hiérarchie et autoapprobation limitée | Responsables direct et indirect réels sans approbation globale ; autoapprobation limitée refusée dans l’interface et par URL directe | Hors hiérarchie distinct de l’autoapprobation et accès métier révoqué sur instance |
| Droit de commission distinct absent, autres bénéficiaires et répartitions manuelles | Sans ce droit : pourcentage verrouillé à 0 % et commission nulle. Avec ce droit : 50 % et 18,75 EUR persistés ; CA intégral et aucun montant à verser avant signature | Fixe/proportionnel, plusieurs bénéficiaires, exports et échéances après signature partielle |
| Règles inconnues, données ou règles modifiées pendant l’attente | Tests isolés | Accord périmé et règles modifiées sur instance : expiration du pilotage de la modale d’édition avant toute saisie ; aucun seuil changé |
| Rejeux et concurrence | Tests isolés, verrous SQL MariaDB réel et deux soumissions/décisions HTTP réelles depuis deux onglets du même compte | Course entre sessions de comptes différents, sans éventuelle sérialisation de session PHP ; rollback des autres effets installés |
| Liste et actions de masse | Rendu réel corrigé, gardes isolés | Validation/signature/génération de masse refusées sans effets secondaires |
| API native / signature publique | API réelle : lectures, écriture normale, refus lifecycle et verrouillage en attente ; signature native réelle, commission 37,50 EUR figée à 100 % et trois échéances | Signature publique, autres profils et entités API ; commissions partielles à la signature |
| Suppression pendant l’attente | Contrôles analysés et tests isolés | Le clic réel a été refusé par le contrôle automatique d’approbation en raison du risque de suppression ; aucune suppression exécutée |
| Deux entités actives et partage | Tests isolés et refus réel depuis TEST 2 inactive | Installation/paramétrage dans deux entités actives, accès autorisés/refusés et conservation des réglages |
| Historique signé, versements et paliers | Tests de non-régression/CI | Comparaison avant/après sur données de recette signées et payées, sans recalcul historique automatique |
| Installation ZIP, désactivation/réactivation et configurations personnalisées | Réactivation réelle TEST 1 ; SQL idempotent CI et bootstrap simulé | ZIP par installateur natif et cycle complet dans deux entités avec modèles/notifications/cron personnalisés |

Aucun essai réel ERP v20–v24 ni v25 finale n’est annoncé. Les domaines documents PDF/ODT personnalisés et nouvelle numérotation métier ne sont pas ajoutés par cette PR ; les effets documentaires de `Propal::valid()` restent toutefois à inclure dans les scénarios d’échec.

## Remise en état et interventions

- Première session, avant cette reprise : branche serveur initiale **`feature/margin-excess-bonus`**, SHA **`8230ff3ec705fe8f2e9aeb9a865327a25de072c3`**, rétablie et confirmée directement avec Git dans le terminal cPanel. La page cPanel affichait encore l’ancienne tête après une erreur de contact distant : cette erreur n’avait pas empêché le checkout local. La branche distante d’origine n’existe plus ; elle n’a pas été recréée.
- Fin de reprise : branche **`feature/margin-excess-bonus`** et SHA **`8230ff3ec705fe8f2e9aeb9a865327a25de072c3`** rétablis par **Git Version Control**. La sélection a signalé l’absence de contact distant ; après rechargement, la page native confirme effectivement la branche et le SHA initiaux. Aucun checkout ni déploiement de cette reprise n’a utilisé le terminal cPanel.
- Un répertoire serveur `bin/` non suivi a été observé et laissé intact ; aucune restauration forcée ni nettoyage n’a été effectué.
- Reprise : module TEST 1 actualisé nativement sur `0681c77`, puis code `b2fe027` servi par Git Version Control. TEST 2 reste désactivé. Le dossier ouvert pour le réglage a été retourné en modification. Le dossier du test concurrent a ensuite été approuvé par la seule décision gagnante ; le devis 47 a été remis en Brouillon par le parcours natif. Aucun dossier actif ne reste en attente. Aucun seuil de règle n’a été modifié. Les historiques et devis fictifs sont conservés. La confirmation native de signature du devis 36 a été préparée puis annulée sans mutation, en attente de l’accord spécifique de l’utilisateur ; ce devis reste Validé à 37,50 EUR estimés.
- Destinataires fixes du circuit remis à leur état initial. Les migrations additives, tables et modèles créés lors de la réactivation sont conservés ; ils ne sont pas supprimés au retour de branche.
- Cache de production API rétabli après l’essai ; compression API laissée désactivée selon la dernière instruction. SMTP forcé vers l’adresse de test et option native autorisée conservés sur develop uniquement. Aucun compte, mot de passe ou jeton n’a été créé ou réinitialisé, aucun droit n’a été attribué manuellement.
- Aucune fusion, release, publication Dolistore ni intervention en production.

Les captures de la recette restent des preuves locales hors du dépôt source. Les [règles officielles de packaging](https://wiki.dolibarr.org/index.php/Modules_-_Packaging_rules_and_Dolistore_validation_rules), révision wiki 65313, ont été relues le 29 octobre 2026 ; les contrôles de paquet ci-dessus ne valent ni installation native ni acceptation Dolistore.
