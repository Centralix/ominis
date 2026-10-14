"""Integration test on an isolated local PHP server. Never sends a real email."""
import html, http.cookiejar, json, os, re, shutil, subprocess, tempfile, time, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'tmp/php/runtime/php.exe'
if not PHP.exists():
    found=shutil.which('php')
    if not found: raise SystemExit('PHP est requis.')
    PHP=Path(found)
temp=Path(tempfile.mkdtemp(prefix='ominis-http-',dir=ROOT/'tmp'))
env=os.environ.copy(); env['OMINIS_DATA_DIR']=str(temp/'profiles')
(temp/'sessions').mkdir()
log=open(temp/'server.log','w',encoding='utf-8')
port=8081
proc=subprocess.Popen([str(PHP),'-d',f'session.save_path={temp / "sessions"}','-d','display_errors=0','-S',f'127.0.0.1:{port}','router.php'],cwd=ROOT,env=env,stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
checks=0
def check(condition,message):
    global checks
    checks+=1
    if not condition: raise AssertionError(message)
cookie=http.cookiejar.CookieJar(); opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookie))
base=f'http://127.0.0.1:{port}/'
def get(path='index.php'):
    with opener.open(base+path,timeout=10) as response: return response.geturl(),response.read().decode('utf-8'),response.headers
def field(doc,name):
    match=re.search(r'name="'+re.escape(name)+r'" value="([^"]*)"',doc)
    if not match: raise AssertionError('Champ absent : '+name)
    return html.unescape(match[1])
def post(url,doc,action,extra=None,run=False):
    fields={'csrf':field(doc,'csrf'),'action':action}
    if run: fields.update(run=field(doc,'run'),revision=field(doc,'revision'))
    fields.update(extra or {})
    req=urllib.request.Request(url,urllib.parse.urlencode(fields,doseq=True).encode())
    with opener.open(req,timeout=10) as response: return response.geturl(),response.read().decode('utf-8'),response.headers
try:
    for _ in range(40):
        if proc.poll() is not None: raise RuntimeError('Le serveur de test ne démarre pas.')
        try: url,doc,headers=get(); break
        except OSError: time.sleep(.1)
    else: raise RuntimeError('Serveur inaccessible.')
    check('checked><span>10</span>' in doc,'10 questions par défaut')
    check('no-store' in headers['Cache-Control'],'Réponses privées non mises en cache')
    check('frame-ancestors' in headers['Content-Security-Policy'],'CSP')
    for banned in ['config/mail.php','app/core.php','storage/.htaccess','qcm/154-infections-broncho-pulmonaires.txt','tmp/php/runtime/php.exe','tests/run.php','../config/mail.php']:
        try: get(banned); raise AssertionError('Fichier privé accessible : '+banned)
        except urllib.error.HTTPError as ex: check(ex.code in (403,404),'Fichier privé refusé')
    raw=subprocess.check_output([str(PHP),'-r','require "app/core.php"; echo json_encode(bank()[1]);'],cwd=ROOT)
    bank=json.loads(raw)
    # A forged or expired CSRF token must not create a run.
    forged=doc.replace(field(doc,'csrf'),'0'*64)
    url,doc,_=post(url,forged,'start',{'mode':'exam','count':'10','items[]':'154'})
    check('La session a expiré' in doc and 'question-prompt' not in doc,'CSRF refusé')
    url,doc,_=post(url,doc,'start',{'mode':'exam','count':'10','items[]':'154'})
    check('page=quiz' in url,'Examen démarré')
    check('Comprendre la réponse' not in doc,'Corrigé masqué en examen')
    old_doc=doc; old_url=url
    attempted=[]; intentional=[]; expected_total=0
    for i in range(10):
        qid=field(doc,'question'); q=bank[qid]; attempted.append(q)
        answers={k:v['correct'] for k,v in q['choices'].items()}
        error_count=i%4
        if q['type']=='QRU':
            true_letter=next(k for k,v in answers.items() if v)
            selected=true_letter if i%2==0 else next(k for k in answers if k!=true_letter)
            extra={'pick':selected}; expected_total+=1 if selected==true_letter else 0
            if selected!=true_letter: intentional.append(qid)
        else:
            for k in list(answers)[:error_count]: answers[k]=not answers[k]
            extra={f'answers[{k}]':'V' if v else 'F' for k,v in answers.items()}
            expected_total+=[1,.7,.2,0][error_count]
            if error_count: intentional.append(qid)
        extra.update(question=qid,reasoning='Raisonnement de test <script>alert(1)</script>')
        url,doc,_=post(url,doc,'answer',extra,run=True)
        check('Comprendre la réponse' not in doc,'Corrigé absent avant fin')
    check(sum(q['rank']=='A' for q in attempted)==7,'Série 7 A / 3 B')
    check('Terminer et voir mon bilan' in doc,'Résumé de fin')
    # Stale revision submitted from another tab.
    stale_q=field(old_doc,'question'); stale=bank[stale_q]
    extras={'question':stale_q}
    if stale['type']=='QRU': extras['pick']='A'
    else: extras.update({f'answers[{k}]':'V' for k in 'ABCDE'})
    _,stale_doc,_=post(old_url,old_doc,'answer',extras,run=True)
    check('autre onglet' in stale_doc,'Révision périmée refusée')
    url,doc,_=get('index.php?page=quiz&i=10')
    url,doc,_=post(url,doc,'finish',run=True)
    check('page=results' in url and 'Comprendre la réponse' in doc,'Corrigé final')
    expected_score=f'{20*expected_total/10:.1f}'.replace('.',',')
    check(expected_score in doc,'Total de la série')
    check('&lt;script&gt;alert(1)&lt;/script&gt;' in doc,'Raisonnement échappé')
    check('<script>alert(1)</script>' not in doc,'Aucune injection HTML')
    url,doc,_=get('index.php?page=errors')
    check(f'{len(intentional)} À REVOIR' in doc,'Erreurs persistantes')
    # Reopening with the persistent profile cookie but a fresh PHP session retains all progress.
    for c in list(cookie):
        if c.name=='ominis_session': cookie.clear(c.domain,c.path,c.name)
    url,doc,_=get('index.php?page=errors')
    check(f'{len(intentional)} À REVOIR' in doc,'Retrouvé après nouvelle session')
    url,doc,_=post(url,doc,'start',{'retry':'1','mode':'practice','count':'all'})
    for i in range(len(intentional)):
        qid=field(doc,'question'); q=bank[qid]
        check(qid in intentional,'Reprise uniquement des erreurs')
        extra={'question':qid}
        if q['type']=='QRU': extra['pick']=next(k for k,c in q['choices'].items() if c['correct'])
        else: extra.update({f'answers[{k}]':'V' if c['correct'] else 'F' for k,c in q['choices'].items()})
        url,doc,_=post(url,doc,'answer',extra,run=True)
        check('1,0 / 1' in doc and 'Comprendre la réponse' in doc,'Correction immédiate')
        url,doc,_=post(url,doc,'finish' if i==len(intentional)-1 else 'next',run=True)
    url,doc,_=get('index.php?page=errors')
    check('0 À REVOIR' in doc,'Erreurs retirées après réussite')
    url,doc,_=get('index.php?page=analysis')
    check('Nature des erreurs' in doc and 'Vos résultats par item' in doc,'Analyse complète')
    check('Entraînement' in doc and 'Reprise des erreurs' in doc,'Historique accessible')
    # Invalid report must not trigger SMTP, nor leak the configured destination.
    url,doc,_=post(url,doc,'report',{'report_question':'154-001','message':'court'})
    check('entre 15 et 4 000 caractères' in doc,'Signalement incomplet refusé')
    config=(ROOT/'config/mail.php').read_text(encoding='utf-8')
    secrets=re.findall(r"\?: '([^']+)'",config)
    check(not any(secret in doc for secret in secrets if '@' in secret or '!' in secret),'Aucune adresse ni identifiant exposé')
    log.flush()
    server_log=(temp/'server.log').read_text(encoding='utf-8')
    check(not any(word in server_log for word in ['Fatal error','Warning:','Deprecated:']),'Aucune erreur serveur')
    print(f'OK : {checks} contrôles HTTP (examen, correction, stockage, reprise, CSRF, isolement et confidentialité).')
finally:
    proc.terminate(); proc.wait(timeout=10); log.close(); shutil.rmtree(temp)
