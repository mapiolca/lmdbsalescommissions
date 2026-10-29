# Circuit de validation sous le minimum de marge — 1.3.0

Une marge obligatoire insuffisante remplace **Valider** par **Demander la validation**. La confirmation native présente les contrôles bloquants et exige un motif. Une simple cible de commission insuffisante ne déclenche pas ce circuit. Une donnée obligatoire indéterminée doit être corrigée.

Le dossier est **En attente de dérogation** ; le devis reste techniquement en Brouillon. Les modifications de fiche, lignes, remises, qualification technique et répartitions, la validation, la signature et la suppression sont refusées pendant cette attente. Les listes affichent le badge métier sans ajouter de statut numérique au core. Un lien donne accès à l’historique paginé, y compris après la décision.

## Droits et mise à niveau

1. Déployer le module puis le réactiver dans chaque entité concernée pour installer la table additive `lmdbsalescommissions_margin_case`, le nouveau droit et les constantes absentes. Les réglages de marge, modèles sélectionnés et abonnements existants sont conservés. La version reste 1.3.0 ; **À propos** lit le descripteur.
2. Conserver l’activation explicite des contrôles de marge dans les réglages existants. Aucune ancienne demande par règle n’est transformée en dossier verrouillant. Les devis déjà signés et leurs paiements ne sont pas recalculés.
3. Attribuer les droits selon le tableau ci-dessous, y compris aux administrateurs. Configurer la hiérarchie native des utilisateurs pour les approbateurs limités aux subordonnés.
4. Faire la recette des canaux utilisés sur develop avant mise en production du blocage.

| Permission | ID stable | Portée |
|---|---:|---|
| `marginpolicy.approvesale` | 45002414 | Approbation de tous les auteurs accessibles, y compris soi-même ; ancien droit de dérogation de vente conservé |
| `marginpolicy.approvecommission` | 45002415 | Maintien d’une commission normalement refusée ; indépendant de la vente |
| `marginpolicy.approvesubordinates` | 45002416 | Approbation des collaborateurs directs et indirects ; aucune autoapprobation |

Le module conserve son ID 450024 ; aucun autre droit n’est renuméroté. Le demandeur doit lire et créer/modifier le devis, et accéder à celui-ci, sans devoir posséder la validation native. Le responsable doit accéder au devis et disposer du droit natif de validation pour **Approuver et valider**. Il peut **Réautoriser la modification** avec son droit d’approbation seul, en motivant le retour.

Les mutations se font dans l’entité propriétaire, qui doit être l’entité authentifiée. Consulter un objet partagé n’autorise pas à changer silencieusement l’entité pour le traiter.

## Décision et commission

Les deux actions sont disponibles sur la fiche et dans **Répartition commissions / CA**. L’approbation recharge le devis, vérifie l’empreinte et les droits, enregistre les dérogations nécessaires puis appelle `Propal::valid()` avec l’approbateur. Le devis est réellement Validé ; aucune signature ni envoi client n’est demandé par le module. Les traitements et notifications éventuellement configurés sur la validation native restent exécutés par Dolibarr.

Le responsable choisit 0 à 100 % de la commission directe théorique de l’auteur. La valeur initiale est 0 si la commission est refusée, 100 si elle est autorisée. Accorder une commission refusée exige le droit distinct correspondant ; sans ce droit, le champ reste à zéro. Le montant résultant est calculé et formaté côté serveur. Répartitions fixes et proportionnelles, estimations, échéances et exports utilisent le même pourcentage. Il apparaît dans le détail **Consulter** et l’historique. Les autres bénéficiaires, primes de dépassement, CA et paliers ne sont pas réduits.

Un changement des données ou règles invalide les accords et le pourcentage. Une demande périmée doit être retournée en modification. À la signature, le pourcentage rejoint l’instantané des décisions de marge. Les anciens instantanés sans ce champ conservent leur calcul habituel.

Les transitions verrouillent le parent natif avec `SELECT … FOR UPDATE`. La clé nullable `(entity, active_proposal)` garantit un seul dossier actif ; les décisions closes libèrent cette clé. Un rejeu identique ne répète ni validation ni événement. Une autre décision concurrente ou un rejeu divergent est refusé. Dossier, accords et statut SQL sont écrits dans la même transaction ; les triggers de mutation utilisent le même verrou et une lecture courante du dossier.

## Notifications natives

Configurer les destinataires fixes, abonnements et modèles depuis **Configuration → Modules → Notifications**, sans écran de destinataires propre au module :

| Événement affiché | Trigger CRUD |
|---|---|
| Nouvelle demande de validation de devis | `LMDBSALESCOMMISSIONS_MARGIN_CASE_CREATE` |
| Décision sur une demande de validation de devis | `LMDBSALESCOMMISSIONS_MARGIN_CASE_UPDATE` |

L’état et `context['trigger_reason']` distinguent l’accord du retour en modification. Les événements sont déclarés dans `c_action_trigger` et par `notifsupported` dans le contexte `notification`. La réception d’un email n’accorde aucun droit. Aucune page n’appelle un second envoi.

Les modèles FR/EN **LscWorkflowDefaultMail** sont créés uniquement en leur absence. Les constantes `<TRIGGER>_TEMPLATE` sont initialisées uniquement si elles n’existent pas dans l’entité. Un modèle sélectionné absent, inactif ou inaccessible est signalé ; sélectionner de nouveau un modèle actif. Pour d’autres langues, prévoir les variantes ou le modèle sans langue dans la configuration native.

Le dossier utilise l’élément court `lscmargincase`, résolu vers sa classe métier par le hook natif `elementproperties`. Ce nom respecte les limites natives des modèles et des traces de notifications, y compris le suffixe historique `_send`. La réactivation corrige le type des constantes de sélection et les anciens identifiants de développement, sans modifier les libellés sélectionnés, les contenus personnalisés, leurs entités ou les choix volontairement vides. Les liaisons historiques Agenda/Notifications sont conservées et migrées sans nouvel envoi.

Les versions v20–v23 de `Notify::send()` recherchent un type suffixé `_send`, alors que l’administration utilise l’élément du dossier. Des copies de transport du modèle sélectionné sont donc préparées sous verrou, dans la transaction du dossier, sans modifier les modèles sources ni leur sélection. Ces copies générées ne doivent pas être éditées. Depuis v24, l’envoi natif utilise directement le type sélectionné ; aucune copie n’est préparée.

Substitutions disponibles, avec préfixe `__LSC_WORKFLOW_` et suffixe `__` : `PROPOSAL_REF`, `AUTHOR`, `REQUESTER`, `MARGIN`, `RULES`, `REASON`, `DECISION`, `DECISION_REASON`, `APPROVER`, `COMMISSION`, `URL`. Le lien est absolu et authentifié, sans jeton public. Les textes libres sont échappés.

Les erreurs SMTP suivent le contrat natif : certains cores journalisent l’échec sans faire échouer leur trigger Notification. Le module ne relance pas l’envoi. Un email déjà transmis ne peut pas être annulé par un rollback SQL. De même, les déplacements de fichiers provisoires par la validation native ne constituent pas une transaction documentaire. La recette doit couvrir ces cas avec les modules et réglages réellement installés.

## Couverture et preuves

Le contrôle de fiche précède les actions et effets documentaires ; les sélections de liste sont contrôlées avant traitement. Le garde API refuse les mutations d’un devis en attente, y compris lignes, suppression et appels `notrigger`. Le garde de signature publique intervient avant les écritures de signature. Les triggers natifs ajoutent le contrôle transactionnel des mutations de devis, lignes et répartitions. Les appels de scripts/modules tiers supprimant les triggers et les écritures SQL directes restent hors de cette garantie ; ils doivent intégrer le service métier. Aucun endpoint REST ni fichier core n’est ajouté.

| Environnement | Révision des sources officielles | Résultat local au 2026-10-28 |
|---|---|---|
| v20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` | Tests isolés sous PHP 8.4.22 |
| v21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` | Tests isolés sous PHP 8.4.22 |
| v22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` | Tests isolés sous PHP 8.4.22 |
| v23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` | Tests isolés sous PHP 8.4.22 |
| v24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` | Tests isolés sous PHP 8.4.22 |
| v25 alpha | `ef6e5818b0a5626f928cb9bc66bddb8be43ac32f` | Tests isolés ; aucune preuve pour une future v25 finale |

`margin_workflow_test.php` exécute les méthodes natives `Propal::valid()`, `FormMargin`, `price2num`, les parcours de hiérarchie, `HookManager` et `Form::formconfirm()`. La persistance CommonObject, les utilisateurs, la base et le transport email restent simulés. `margin_workflow_notification_test.php` exécute la sélection native de modèles ; le test JavaScript simule le DOM/Ajax, les réinitialisations et réponses obsolètes. Les gardes API utilisent le cycle Restler natif avec objets/SQL simulés.

La CI complète la matrice avec PHP 8.0/8.2/8.4 et teste sur MariaDB 11.4 le DDL, les clés, la concurrence de verrouillage et le rollback du dossier avec le parent natif. Vérifier son résultat sur le SHA publié : une déclaration de job n’est pas une exécution réussie. PHPStan 2.1.0 a été exécuté au niveau 5 le 29 octobre 2026, avec PHP cible 8.0 et des sources natives v25 alpha dans un cache de test. L’analyse isolée conserve des diagnostics de contexte et de code préexistant ; elle ne constitue pas une validation PHPStan intégralement verte. Aucun baseline ni règle de masquage n’a été ajouté.

Recette réelle restante sur develop : installation/réactivation dans deux entités, comptes commerciaux et responsables distincts, confirmation/annulation, retour et nouvelle demande, droits hiérarchiques directs/indirects, commissions 0/partielle/100 %, lignes et données techniques, actions concurrentes, API et signature publique, emails configurés/désactivés/erreurs SMTP, modèles personnalisés, préservation des devis signés et paiements. Aucun déploiement ni email réel n’est effectué par les tests locaux.
