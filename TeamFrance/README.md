# Team France — portail et outils d’administration

Le portail `/TeamFrance/` présente les informations du club, les administrateurs, les effectifs, les inscriptions ouvertes et les outils de gestion. Il exige une authentification OAuth Chess.com et le statut d’administrateur actuel de Team France.

Les trois outils associés sont :

- `/TeamFrance/Widget/admin/` — gestion de la présentation des matchs et du widget public (`/TeamFrance/Widget/`).
- `/TeamFrance/MatchAnalyzer/` — analyse des inscriptions, des classements et de la composition des équipes.
- `/TeamFrance/MatchRecruitment/` — recherche de joueurs éligibles, vérification actuelle des appartenances et export CSV.

Les pages Analyseur et Recrutement présentent, sous le champ de référence, les matchs en différé dont les inscriptions sont ouvertes. Un bouton radio préremplit le champ ; la saisie manuelle demeure possible.

Les nouvelles pages utilisent le transport OAuth et la passerelle Chess.com déjà établis pour P2K, avec contrôle des droits Team France. L’identité graphique repose sur le bleu, le blanc et le rouge. Les textes du portail et des outils modifiés sont en français. Les titres des matchs issus de Chess.com ne sont pas traduits afin de préserver leur contenu original.

## Installation incrémentale

Depuis un dossier séparé de l’installation en production :

```bash
bash TeamFrance/install-admin-hub.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
```

Le script conserve les données persistantes du Widget, les journaux de l’Analyseur, les listes de membres du Recrutement et tous les fichiers des applications TGP. Il sauvegarde les fichiers sources remplacés sous `storage/team-france-admin-hub-backups/`.
