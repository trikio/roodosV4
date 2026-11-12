#!/usr/bin/env python3
import os, random, re, unicodedata
import requests, mysql.connector
from bs4 import BeautifulSoup
from dotenv import load_dotenv

FEED_URL='https://www.doomos.cl/feeds/trovit.xml'

def fix_text(s:str)->str:
    if not s: return ''
    s=s.strip()
    try:
        t=s.encode('latin1').decode('utf-8')
        if t.count('�')<=s.count('�'): s=t
    except Exception:
        pass
    return s

def norm(s:str)->str:
    s=fix_text(s).lower().strip()
    s=unicodedata.normalize('NFKD',s)
    s=''.join(ch for ch in s if not unicodedata.combining(ch))
    s=re.sub(r'[^a-z0-9]+',' ',s)
    return ' '.join(s.split())

def tag_text(ad,name):
    t=ad.find(name)
    return fix_text(t.text) if t and t.text else ''

def webshare_proxies(token):
    if not token: return []
    r=requests.get('https://proxy.webshare.io/api/proxy/list/',headers={'Authorization':f'Token {token}'},params={'page_size':60},timeout=20)
    r.raise_for_status(); out=[]
    for p in r.json().get('results') or []:
        host=p.get('proxy_address') or p.get('address') or p.get('ip')
        port=(p.get('ports') or {}).get('http') or p.get('port')
        if host and port:
            out.append(f"http://{p.get('username')}:{p.get('password')}@{host}:{port}")
    random.shuffle(out)
    return out

def fetch_xml(token):
    h={'User-Agent':'Mozilla/5.0','Accept':'application/xml,text/xml;q=0.9,*/*;q=0.8'}
    try:
        r=requests.get(FEED_URL,headers=h,timeout=40)
        if r.status_code==200 and ('<?xml' in r.text[:200] or '<trovit' in r.text[:500].lower()):
            return r.text
    except Exception:
        pass
    for p in webshare_proxies(token):
        try:
            r=requests.get(FEED_URL,headers=h,proxies={'http':p,'https':p},timeout=40)
            if r.status_code==200 and ('<?xml' in r.text[:200] or '<trovit' in r.text[:500].lower()):
                return r.text
        except Exception:
            continue
    raise RuntimeError('feed blocked')

def op_phrase(raw):
    n=norm(raw)
    if 'for sale' in n or 'sale' in n or 'venta' in n: return 'venta'
    if 'for rent' in n or 'rent' in n or 'arriendo' in n or 'alquiler' in n: return 'arriendo'
    return n

def first_id(cur, table, q):
    if not q: return None
    try:
        cur.execute(f"SELECT id FROM {table} WHERE MATCH(%s) ORDER BY WEIGHT() DESC LIMIT 1", (q,))
        r=cur.fetchone(); return int(r['id']) if r and r.get('id') is not None else None
    except Exception:
        return None

def location_ids(cur, city, region):
    q1=norm(f"{city} {region}")
    q2=norm(city)
    for q in [q1,q2]:
        if not q: continue
        try:
            cur.execute("SELECT id,id_state FROM location WHERE country='cl' AND MATCH(%s) ORDER BY puntaje DESC, WEIGHT() DESC LIMIT 1", (q,))
            r=cur.fetchone()
            if r: return (int(r['id']) if r.get('id') is not None else None, int(r['id_state']) if r.get('id_state') is not None else None)
        except Exception:
            pass
    return (None,None)

def main():
    load_dotenv('/home/trikio/roodos_v4/nexo/.env')
    xml=fetch_xml(os.getenv('WEBSHARE_API_TOKEN',''))
    soup=BeautifulSoup(xml,'xml')
    ads=soup.find_all('ad')

    db=mysql.connector.connect(host=os.getenv('MYSQL_HOST','localhost'),port=int(os.getenv('MYSQL_PORT','3306')),user=os.getenv('MYSQL_USER','trikio'),password=os.getenv('MYSQL_PASSWORD',''),database=os.getenv('MYSQL_DATABASE','roodos'))
    cur=db.cursor(dictionary=True)
    mc=mysql.connector.connect(host='127.0.0.1',port=9306)
    mcur=mc.cursor(dictionary=True)

    done=0
    for i,ad in enumerate(ads,1):
        url=tag_text(ad,'url')[:250]
        if not url: continue
        op=first_id(mcur,'house_operation',op_phrase(tag_text(ad,'type')))
        typ=first_id(mcur,'house_type',norm(tag_text(ad,'property_type')))
        cid,sid=location_ids(mcur,tag_text(ad,'city'),tag_text(ad,'region'))
        cur.execute("UPDATE houses SET id_house_operation=COALESCE(%s,id_house_operation), id_house_type=COALESCE(%s,id_house_type), id_city=COALESCE(%s,id_city), id_state=COALESCE(%s,id_state), updated=NOW() WHERE id_country='CL' AND url=%s", (op,typ,cid,sid,url))
        done += cur.rowcount
        if i%2000==0:
            db.commit(); print('progress',i,'rows_touched',done)
    db.commit(); print({'ads':len(ads),'rows_touched':done})
    mcur.close(); mc.close(); cur.close(); db.close()

if __name__=='__main__':
    main()
