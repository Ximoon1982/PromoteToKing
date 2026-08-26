from pathlib import Path
from io import BytesIO
import hashlib, json
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
ORDER=['seniority-1m','seniority-3m','seniority-6m','seniority-1y','seniority-2y','seniority-3y','seniority-5y']
EXPECTED={
'seniority-1m':'28383f7835c1dfbaf4bbf4511597b2ef75cd47dbab49f70b72ac33053a641079',
'seniority-3m':'72694a7f6e6841ddf2504cceee5ac8460b0df6832ee629196a195f02c90e38b6',
'seniority-6m':'b56e47199823fe26f6c72051b09ce3d93fce0cb79b8bbf831a564dd8556f74ce',
'seniority-1y':'10e83fdaadbeeb3bc535ce04b44f5aacc65a4fcf41c750fe4272104c084de7ea',
'seniority-2y':'29df8bd9198cb909e9eca8763283c3ce2056431c0ec481ca15a2a6dc1be77977',
'seniority-3y':'5c139c9a12cf8e4384a8ccfe296af350cda98cbfe42cf30339180ecb2884502c',
'seniority-5y':'5f3df0691d33ed2f4b53d6b6c4dcda308b02f2416eb6aa3f144f7a62d2e16a01',
}
def sha(p): return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_v29223_identity_and_schema_unchanged():
    assert text('VERSION').strip()=='2.9.22.3'
    repo=text('server/team-points/src/Repository.php')
    assert 'CORE_SCHEMA_VERSION = 15;' in repo
    assert 'ANALYTICS_SCHEMA_VERSION = 7;' in repo
    assert text('server/team-points/src/AchievementCatalog.php').count('self::item(')==162

def test_approved_seniority_masters_and_derivatives_are_exact():
    rec=json.loads(text('POST_v2.9.22.2_SENIORITY_ARTWORK_INTEGRATION.json'))
    assert rec['approved_count']==7
    assert [x['key'] for x in rec['records']]==ORDER
    for key in ORDER:
        master=ROOT/f'assets/images/achievements/{key}.png'
        thumb=ROOT/f'assets/images/achievements/thumbs/128/{key}.webp'
        mini64=ROOT/f'assets/images/achievements/mini/64/{key}.webp'
        mini=ROOT/f'assets/images/achievements/mini/{key}.webp'
        assert sha(master)==EXPECTED[key]
        im=Image.open(master).convert('RGBA'); assert im.size==(640,640)
        for size,path in [(128,thumb),(64,mini64),(64,mini)]:
            target=im.resize((size,size),Image.Resampling.LANCZOS)
            b=BytesIO(); target.save(b,'WEBP',quality=92,method=6)
            assert hashlib.sha256(b.getvalue()).hexdigest()==sha(path)

def test_seniority_catalogue_uses_approved_raster_artwork_only():
    catalogue=text('server/team-points/src/AchievementCatalog.php')
    criteria={
      'seniority-1m':'Maintain continuous membership for one month.',
      'seniority-3m':'Maintain continuous membership for three months.',
      'seniority-6m':'Maintain continuous membership for six months.',
      'seniority-1y':'Maintain continuous membership for one year.',
      'seniority-2y':'Maintain continuous membership for two years.',
      'seniority-3y':'Maintain continuous membership for three years.',
      'seniority-5y':'Maintain continuous membership for five years.',
    }
    for key in ORDER:
        line=next(x for x in catalogue.splitlines() if f"self::item('{key}'" in x)
        assert criteria[key] in line
        assert f"'assets/images/achievements/{key}.png','assets/images/achievements/thumbs/128/{key}.webp'" in line
        assert 'placeholders/' not in line
