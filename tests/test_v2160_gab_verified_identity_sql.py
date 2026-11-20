#!/usr/bin/env python3
"""SQLite regression for P2K GAB stable-player-ID alias projection."""
import re
import sqlite3
import unittest
from pathlib import Path

root = Path(__file__).resolve().parents[1]
compat = (root/"server/team-points-green/src/GreenCompatibility.php").read_text()
gab = (root/"server/team-points-green/src/GreenAnalyticsBootstrap.php").read_text()
pairs = [
 ("erdarro-rei","jenebro",186517619),
 ("gugusheep_original","simurghson",59307376),
 ("rajanrajpa","aditya_16-01-10",361380897),
 ("richtea2000","richard-108",219116777),
 ("cretaceouscheckmate","amchess1000",140346355),
 ("al-hackym-danial","al-hackym-allah",229467987),
 ("elvergax","no_soy_un_avion",136544522),
]

class GabIdentityTest(unittest.TestCase):
 def setUp(self):
  self.db=sqlite3.connect(":memory:")
  self.db.executescript("""
   CREATE TABLE p2k_g_identity_map(username_key TEXT,canonical_username_key TEXT,chess_player_id INT,trusted INT);
   CREATE TABLE p2k_g_player_aliases(username_key TEXT,chess_player_id INT);
   CREATE TABLE p2k_miac_canonical_map(club_slug TEXT,username_key TEXT,canonical_username_key TEXT,conflict INT);
   CREATE TABLE p2k_miac_edges(club_slug TEXT,old_username_key TEXT,new_username_key TEXT,status TEXT);
   CREATE TABLE observed(username_key TEXT);
  """)
  for old,new,pid in pairs:
   self.db.execute("INSERT INTO p2k_g_identity_map VALUES (?,?,?,1)",(old,new,pid))
   self.db.execute("INSERT INTO p2k_g_identity_map VALUES (?,?,?,1)",(new,new,pid))
   self.db.execute("INSERT INTO p2k_g_player_aliases VALUES (?,?)",(old,pid))
   self.db.execute("INSERT INTO p2k_miac_canonical_map VALUES (?,?,?,0)",("promote-to-king",old,old))
  m=re.search(r'private function verifiedCanonicalSql\(.*?\): string\s*\{\s*return "(.*?)";\s*\}',compat,re.S)
  assert m, "missing production SQL identity helper"
  self.expr=(m.group(1).replace("{$usernameSql}","obs.username_key")
             .replace("{$greenAlias}","im").replace("{$miacAlias}","mi"))
 def resolve(self,old):
  self.db.execute("DELETE FROM observed")
  self.db.execute("INSERT INTO observed VALUES (?)",(old,))
  return self.db.execute(f"""SELECT {self.expr} FROM observed obs
     LEFT JOIN p2k_g_identity_map im ON im.username_key=obs.username_key
     LEFT JOIN p2k_miac_canonical_map mi ON mi.username_key=obs.username_key
       AND mi.club_slug='promote-to-king' AND mi.conflict=0""").fetchone()[0]
 def test_verified_pairs(self):
  for old,new,pid in pairs:
   with self.subTest(old=old): self.assertEqual(self.resolve(old),new)
 def test_explicit_rejection(self):
  old,new,_=pairs[0]
  self.db.execute("INSERT INTO p2k_miac_edges VALUES (?,?,?,'rejected')",("promote-to-king",old,new))
  self.assertEqual(self.resolve(old),old)
 def test_explicit_conflict_reverse(self):
  old,new,_=pairs[1]
  self.db.execute("INSERT INTO p2k_miac_edges VALUES (?,?,?,'conflict')",("promote-to-king",new,old))
  self.assertEqual(self.resolve(old),old)
 def test_other_miac_canonical(self):
  old,_,_=pairs[2]
  self.db.execute("UPDATE p2k_miac_canonical_map SET canonical_username_key='other' WHERE username_key=?",(old,))
  self.assertEqual(self.resolve(old),"other")
 def test_untrusted(self):
  old,_,_=pairs[3]
  self.db.execute("UPDATE p2k_g_identity_map SET trusted=0 WHERE username_key=?",(old,))
  self.assertEqual(self.resolve(old),old)
 def test_missing_alias_id(self):
  old,_,_=pairs[4]
  self.db.execute("DELETE FROM p2k_g_player_aliases WHERE username_key=?",(old,))
  self.assertEqual(self.resolve(old),old)
 def test_different_player_id(self):
  old,new,pid=pairs[5]
  self.db.execute("UPDATE p2k_g_identity_map SET chess_player_id=? WHERE username_key=?",(pid+1,new))
  self.assertEqual(self.resolve(old),old)
 def test_noble_is_not_fabricated(self):
  self.assertEqual(self.resolve("noble_sse"),"noble_sse")
 def test_source_guards(self):
  for marker in ("{$boardIdentity} canonical_username_key","{$eventIdentity} event_canonical_username_key",
    "AND {$eventIdentity}=?","{$conflictIdentity}<>im.canonical_username_key",
    "function repairTrustedIdentityBoard()","LIMIT 1","incomplete_verified_game_projection",
    "existing_game_conflict","->beginTransaction()","->rollBack()"):
   self.assertIn(marker,compat)
  self.assertIn("repairTrustedIdentityBoard()",gab)

if __name__=="__main__": unittest.main()
