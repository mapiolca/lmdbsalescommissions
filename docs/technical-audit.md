# Audit technique - Module lmdbsalescommissions

## Contexte

Audit réalisé avant développement du module Dolibarr **Commissions commerciales**, nom technique `lmdbsalescommissions`.

Le dépôt inspecté est un dépôt de module autonome correspondant directement à la racine du module `lmdbsalescommissions`. Il ne contient pas de projet Dolibarr complet et ne doit pas contenir d'arborescence `htdocs/custom/`, car ce chemin est géré par Dolibarr lors de l'installation.

## Arborescence constatée

Fichiers présents au démarrage :

- `LICENSE` : licence AGPL-3.0.

Répertoires ou fichiers absents au moment de l'audit initial :

- `core/modules/modLmdbSalesCommissions.class.php`
- `class/`
- `admin/`
- `sql/`
- `langs/`
- `README.md`
- `ChangeLog.md`
- `modulebuilder.txt`

## État du module

Aucun module Dolibarr existant n'a été trouvé. Le socle complet devait donc être créé directement à la racine du dépôt, qui représente le futur contenu de :

```text
htdocs/custom/lmdbsalescommissions/
```

Le nom technique `lmdbsalescommissions` doit être utilisé partout : tables SQL, constantes, droits, hooks, menus, langues, pages de configuration, exports, crons et notifications.

## Standards Dolibarr appliqués

- Descripteur `modLmdbSalesCommissions.class.php`.
- `config_page_url` limité à `setup.php@lmdbsalescommissions`.
- Aucun menu haut dédié.
- Menu gauche `Commissions` rattaché au menu haut natif Facturation/Paiement.
- Pages métier séparées des pages de configuration.
- Pages de configuration réservées aux administrateurs et organisées en onglets.
- Permissions numérotées avec `$this->numero * 100 + $r`.
- Droits contrôlés avec `$user->hasRight()`.
- Activation contrôlée avec `isModEnabled('lmdbsalescommissions')`.
- Tables métier avec colonne `entity`.
- Requêtes métier filtrées par entité.
- Traductions `fr_FR` et `en_US`.
- Compatibilité Dolibarr v20+ et PHP 8.0+.
- Aucun helper local inutile autour des helpers natifs Dolibarr.

## Points d'attention

- Le dépôt ne contient pas le core Dolibarr : les tests d'activation, d'installation SQL, d'affichage des menus, des hooks, des crons et des notifications devront être vérifiés dans une instance Dolibarr cible.
- Le périmètre V1 est large : commissions, paliers, objectifs, archives, widgets, exports, crons et notifications.
- Les hooks de devis et les données de marge doivent être validés sur Dolibarr v20+.
- Les événements de paiement acompte/facture finale restent conservateurs tant que le lien exact facture/devis n'est pas validé dans l'instance cible.
- Les documents PDF/ODT ne font pas partie de cette V1.

## Ordre de développement suivi

Les prompts PR 0 à PR 22 ont été exécutés dans l'ordre : audit, socle, SQL, permissions, règles, modalités, paliers, affectations, résolution, objectifs, archivage, estimation devis, acquisition, primes par paliers, échéances, pages métier, onglet utilisateur, widgets, maintenance, crons, notifications, exports et stabilisation.

## Conclusion

Le dépôt est prêt pour une installation comme module externe Dolibarr sous `htdocs/custom/lmdbsalescommissions/`. Les validations runtime doivent être réalisées dans une instance Dolibarr v20+ avec base MySQL/MariaDB.
