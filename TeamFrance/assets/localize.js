/* Traduction de présentation isolée aux pages Team France; ne change pas les données Chess.com. */
(()=>{'use strict';
const exact=new Map(Object.entries({
 'Match analysis':'Analyse du match','Match registration':'Inscriptions au match','Match reference':'Référence du match',
 'Selected team':'Équipe sélectionnée','Other team':'Équipe adverse',
 'Match statistics':'Statistiques du match','Team registration and strength comparison':'Inscriptions des équipes et comparaison des forces',
 'Rating statistics':'Statistiques des classements','Rating statistics information':'Informations sur les classements',
 'Rating bracket chart information':'Répartition par tranches de classement',
 'Cumulative chart information':'Informations sur le classement cumulé',
 'Std. deviation':'Écart type','Number of boards':'Nombre d’échiquiers',
 'Start date unavailable':'Date de début indisponible','No limit':'Sans limite',
 'Recruitment target':'Objectif de recrutement','Rating board advantage':'Avantage de classement par échiquier',
 'Start-time board ratings':'Classements au début du match',
 'Projected lineup':'Composition projetée','Final match score':'Score final du match',
 'In-progress match projection':'Projection du match en cours',
 'In-progress projection methodology':'Méthode de projection du match en cours',
 'Board-by-board projection inputs':'Données de projection par échiquier',
 'Predicted match result':'Résultat prévisionnel du match','Prediction methodology':'Méthode de prévision',
 'No eligible paired lineup is currently available.':'Aucune composition de joueurs admissible pour le moment.',
 'The recommendation is informational only; registrations can no longer be added.':'Cette recommandation est indicative : les inscriptions sont closes.',
 'No recruitment recommendation is available yet.':'Aucune recommandation de recrutement disponible.',
 'No recruitment needed: the target lineup balance is already reached.':'Aucun recrutement nécessaire : l’équilibre souhaité est atteint.',
 'Enter a match number or URL.':'Saisissez un numéro ou une URL de match.',
 'No numeric Chess.com match ID was found in that value.':'Aucun identifiant numérique de match Chess.com trouvé.',
 'This analyzer only accepts matches involving Team France.':'Cet analyseur accepte uniquement les matchs impliquant Team France.',
 'The Chess.com API response does not contain two analyzable teams.':'La réponse Chess.com ne contient pas deux équipes analysables.',
 'This match does not expose exactly two teams in the Chess.com API response.':'Ce match ne contient pas exactement deux équipes dans les données Chess.com.',
 'The operation was cancelled.':'L’opération a été annulée.',
 'Analyze match':'Analyser le match','Close':'Fermer','Retry':'Réessayer',
 'Log in with Chess.com':'Se connecter avec Chess.com','Login required':'Connexion nécessaire',
 'Unsaved changes':'Modifications non enregistrées','Configuration saved.':'Configuration enregistrée.',
 'No Daily matches are currently in registration.':'Aucun match en différé ouvert aux inscriptions.',
 'Loading registration matches…':'Chargement des matchs ouverts aux inscriptions…',
 'Refreshing registration matches…':'Actualisation de la liste des matchs…',
 'Loading…':'Chargement…','Loading':'Chargement','Unavailable':'Indisponible',
 'Opponent':'Adversaire','League':'Ligue','Friendly':'Amical','Open':'Libre',
 'Players':'Joueurs','Boards':'Échiquiers','Rating':'Classement','Average rating':'Classement moyen',
 'Registered':'Inscrits','Registration':'Inscriptions','In progress':'En cours',
 'Start':'Début','End':'Fin','Status':'État','Date':'Date',
 'Wins':'Victoires','Draws':'Nulles','Losses':'Défaites',
 'Required players':'Joueurs requis','Current players':'Joueurs inscrits',
 'Copy':'Copier','Copied':'Copié','Download':'Télécharger',
 'Matches currently in registration':'Matchs ouverts aux inscriptions',
 'Match Showcase':'Présentation des matchs','Select team':'Choisir une équipe',
 'Recalculate':'Recalculer','Details':'Détails','Show details':'Afficher les détails',
 'Hide details':'Masquer les détails','Refresh':'Actualiser',
 'Team':'Équipe','Total':'Total','Win probability':'Probabilité de victoire',
 'Boards advantage':'Avantage aux échiquiers','Analysis':'Analyse',
}));
const phrases=[
 [/^Processing match (\d+) of (\d+)$/,'Traitement du match $1 sur $2'],
 [/^Unable to analyze matches:/,'Impossible d’analyser les matchs :'],
 [/^Analyzing match /,'Analyse du match '],
 [/^Registration opens /,'Ouverture des inscriptions '],
 [/^Registered players/,'Joueurs inscrits'],
 [/^Recommended recruitment/,'Recrutement recommandé'],
 [/^No match found/,'Aucun match trouvé'],
 [/^No results available/,'Aucun résultat disponible'],
 [/^An error occurred/,'Une erreur est survenue'],
 [/^Loading match /,'Chargement du match '],
 [/^Registration closed/,'Inscriptions closes'],
 [/^Match starts/,'Début du match'],
 [/^Retrying failed matches/,'Nouvelle tentative pour les matchs en échec'],
 ];
const root=document.querySelector('#p2kUpcomingAnalyzer')||document.querySelector('.admin-shell');if(!root)return;
function translateNode(t){const orig=t.nodeValue;if(!orig||!orig.trim()||orig.length>450)return;const trimmed=orig.trim();let result=exact.get(trimmed);if(!result){for(const [re,fr] of phrases){if(re.test(trimmed)){result=trimmed.replace(re,fr);break}}}
if(result&&result!==trimmed)t.nodeValue=orig.replace(trimmed,result);
}
const pending=new Set();let scheduled=false;
function collect(element){if(!element)return;if(element.nodeType===3){pending.add(element);return}if(element.nodeType!==1)return;if(['SCRIPT','STYLE','INPUT','TEXTAREA','CODE','PRE'].includes(element.tagName))return;
const walker=document.createTreeWalker(element,NodeFilter.SHOW_TEXT);let n=0;while(walker.nextNode()&&n++<3500)pending.add(walker.currentNode);
}
function drain(){scheduled=false;const nodes=Array.from(pending).slice(0,700);for(const node of nodes){pending.delete(node);if(node.isConnected)translateNode(node)}if(pending.size)schedule()}
function schedule(){if(scheduled)return;scheduled=true;setTimeout(drain,180)}
collect(root);schedule();
new MutationObserver(changes=>{for(const change of changes){if(change.type==='characterData')pending.add(change.target);else for(const child of change.addedNodes)collect(child)}schedule()}).observe(root,{subtree:true,childList:true,characterData:true});
})();
