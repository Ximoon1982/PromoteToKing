# Team France — Assistant de recrutement

Application autonome et privée sous `/TeamFrance/MatchRecruitment/`.

- Réutilise l’authentification OAuth et vérifie les droits d’administration de Team France.
- Les appels Chess.com passent par le transport OAuth côté serveur ; aucun jeton n’est exposé.
- Requêtes de statistiques, appartenance à Team France et au club adverse vérifiées avant export.
- Liste des membres sauvegardée sous `data/` et conservée lors des mises à jour.
- Tableau paginé (50 lignes), recherche, journal repliable et export CSV local.
- Sélecteur radio des matchs ouverts aux inscriptions, partagé avec l’Analyseur.
