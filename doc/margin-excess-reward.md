# Prime de dépassement de marge — 1.3.0

## Comportement

La prime s’ajoute à la commission habituelle d’un devis. Elle concerne les bénéficiaires de la répartition des commissions ou, sans répartition, l’auteur du devis. Un bénéficiaire sans CA attribué ne reçoit aucune prime. Une répartition de CA absente conserve l’attribution native du module de 100 % à l’auteur ; une répartition explicite incomplète empêche la signature.

Le taux de marge reste celui de Dolibarr : `(vente HT − coût) / coût × 100`. Le seuil retenu est le plus élevé des objectifs **de commission** applicables (général, PV, stockage ou mixte). Le minimum autorisant la vente n’entre pas dans ce calcul. Une dérogation autorise la commission mais n’abaisse jamais l’objectif utilisé pour la prime.

- Marge cible = coût natif × objectif / 100.
- Surplus = marge native réalisée − marge cible, uniquement s’il est strictement positif.
- Forfait : montant configuré × quote-part de CA du bénéficiaire.
- Pourcentage : surplus × taux de prime / 100 × quote-part de CA.

Exemple : coût 10 000, vente HT 15 000, objectif 40 % sur coût, soit un surplus de 1 000. Avec 60 % du CA attribué, un forfait de 200 donne une prime de 120 ; un taux de 25 % du surplus donne 150. Les montants utilisent la devise et la précision natives de l’instance (`price2num(..., 'MT')`, `price()`).

Aucune prime à l’égalité, sous la cible, sans objectif applicable, avec une donnée technique manquante, une marge indisponible, un coût nul/négatif ou une commission refusée. Un objectif explicite à zéro reste valide. Les objectifs sur coût peuvent dépasser 100 % ; le taux de prime est compris entre 0 exclu et 100 % inclus. Les erreurs de lecture restent des erreurs et ne deviennent pas une absence de règle ou un paiement immédiat.

## Configuration

1. Réactiver le module après mise à jour pour exécuter les migrations additives ; aucune prime n’est créée par la migration.
2. Configurer et activer les contrôles de marge dans l’entité propriétaire du devis, avec au moins une cible de commission.
3. Dans **Règles**, créer une règle **Prime de dépassement de marge**, choisir **Montant forfaitaire** ou **Pourcentage du surplus de marge**, puis saisir une valeur positive.
4. Affecter cette règle depuis les affectations habituelles : utilisateur, groupe ou défaut. L’utilisateur prime sur le groupe, puis sur le défaut ; la priorité d’affectation départage un même niveau. Deux règles distinctes de même niveau/priorité sont signalées comme conflit. Une même règle affectée plusieurs fois ne double pas la prime.
5. Les modalités de versement proviennent de la répartition du bénéficiaire ou de ses règles habituelles. La règle de prime n’a pas de calendrier de versement propre ; les champs période/cumul/modalité de l’affectation ne modifient pas ce contrat.

La prime est estimée avant signature. Le tableau de synthèse de la fiche affiche commission de base + prime ; **Consulter** présente le seuil, le surplus, la quote-part, la formule et le motif. Le détail est aussi accessible depuis la répartition. Les listes, échéances, indicateurs utilisateur et exports distinguent le mode `margin_excess`. Le CA et les paliers ne comptabilisent pas le surplus comme du CA supplémentaire.

## Historique et intégration

À la signature native, la décision de marge fige aussi la prime ou son absence explicite. La ligne conserve un instantané de calcul et `fk_reward_rule`, tandis que sa clé d’acquisition reste stable par entité/devis/bénéficiaire. Un rejeu, un rattrapage ou un changement ultérieur de règle n’accorde pas une seconde prime et ne modifie pas une prime acquise, même annulée. Les échéances et versements utilisent le service existant ; les versements déjà effectués restent conservés.

Les anciennes décisions sans information de prime et les devis antérieurs à l’activation ne reçoivent aucune prime rétroactive. La réouverture d’un devis déjà acquis ne relance pas son calcul de prime. Aucun nouvel endpoint API, événement Agenda/Notification, modèle documentaire, catégorie, numérotation ou cron n’est ajouté. Les chemins natifs de signature déjà couverts par les contrôles de marge appellent le même gel et le même service d’acquisition.

Les règles et lignes restent rattachées à leur entité propriétaire. Les lectures utilisent les droits directs `hasRight()` existants, les périmètres propres/groupe/global et les contrôles des propositions ; un droit de dérogation seul n’ouvre pas le détail de la prime. L’identifiant du module **450024** et sa famille **Les Métiers du Bâtiment** sont conservés, sans nouvelle attribution d’ID.

## Vérifications et limites

Validation locale du 30 septembre 2026, PHP **8.4.22**, code de cette branche :

| Contrôle | Résultat local |
|---|---|
| Lint PHP, diff sans erreurs d’espacement | Réussite |
| `reward_test.php` : calcul, arrondi natif, affectations, CA, conflits, gel et absence historique | 43 assertions sur chaque révision native v20 à v25 alpha |
| `reward_lifecycle_test.php` : estimation/acquisition, échéances, rejeu, versements conservés, erreurs et transaction imbriquée | 19 assertions ; persistance et transactions simulées |
| `margin_summary_test.php` : DOM avec `Form` natif, totaux, détail, droits, absence de JavaScript | 40 assertions par révision ; données et sessions simulées |
| Contrats natifs, contrôles de marge et demandes de dérogation | Régressions réussies sur les six révisions |
| Calculateur des paliers | Réussite |
| MariaDB locale | Non exécuté : serveur/client indisponibles ; test SQL étendu dans la CI |
| PHPStan | Non exécuté : outil/configuration absents ; aucune dépendance ajoutée |
| Navigateur/instance déployée et Multicompany réel | Non exécutés : aucun déploiement de cette branche |

Les révisions immuables sont celles de `test/fetch_native_contracts.py` : 20.0.0, 21.0.0, 22.0.0, 23.0.0, 24.0.0 et **25.0.0-alpha** (`ef6e5818…`). Les tests chargent des sources natives et des doubles ciblés, pas six ERP installés. La compatibilité d’une future v25 finale n’est pas démontrée. La CI prévoit PHP 8.0/8.4 pour v20, PHP 8.2 pour v21–v23 et PHP 8.4 pour v24/v25 alpha ; son résultat doit être rattaché au SHA publié.

`margin_schema_test.php` utilise une base MariaDB jetable : préfixe long, migrations réelles rejouées deux fois via le descripteur, conservation d’une ligne existante, unicité de la prime malgré un changement de règle, isolation de deux entités, rollback conjoint ligne/échéance et validité jusqu’à la fin du jour. Ces essais ne remplacent pas une activation complète de Dolibarr.

Recette restante sur une instance de test : activation/désactivation/réactivation et conservation de tous les réglages, saisie des règles avec/sans JavaScript et CSRF, signature depuis fiche/liste/API/lien public, affichage réel des totaux, partage Multicompany et droits utilisateurs, paiement partiel/final, échec transactionnel avec le connecteur natif et son éventuel décorateur DebugBar. Ne pas assimiler les doubles de transaction à cette dernière preuve.

Les contrôles documentaires, Agenda/Notifications, catégories, numérotation et fichiers joints n’ont pas de changement propre à la prime. La migration, les données métier, les droits, les arrondis, l’interface, les exports et la publication constituent la matrice d’impact applicable.
