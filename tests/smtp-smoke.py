"""Verify SMTP AUTH, DATA, rejection and certificate validation against a local TLS server."""
from pathlib import Path
import base64, datetime, ipaddress, os, shutil, socket, ssl, subprocess, tempfile, threading
from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'tmp/php/runtime/php.exe'
if not PHP.exists(): PHP=Path(shutil.which('php') or '')
tmp=Path(tempfile.mkdtemp(prefix='ominis-smtp-',dir=ROOT/'tmp'))
key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'127.0.0.1')])
now=datetime.datetime.now(datetime.timezone.utc)
cert=(x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=1)).not_valid_after(now+datetime.timedelta(days=1)).add_extension(x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),critical=False).add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True).sign(key,hashes.SHA256()))
(tmp/'key.pem').write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
(tmp/'cert.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM))
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(tmp/'cert.pem',tmp/'key.pem')
def run_case(reject=False,trusted=True):
    listener=socket.socket(); listener.bind(('127.0.0.1',0)); listener.listen(1); listener.settimeout(10)
    port=listener.getsockname()[1]; received=[]; failures=[]
    def serve():
        try:
            raw,_=listener.accept()
            with ctx.wrap_socket(raw,server_side=True) as conn:
                conn.settimeout(10); f=conn.makefile('rwb')
                def send(msg): f.write(msg.encode()+b'\r\n'); f.flush()
                def expect(prefix):
                    line=f.readline().decode().strip()
                    if not line.startswith(prefix): raise AssertionError('SMTP command mismatch')
                    return line
                send('220 test ESMTP')
                expect('EHLO'); send('250-test'); send('250 AUTH LOGIN')
                expect('AUTH LOGIN'); send('334 VXNlcm5hbWU6')
                assert base64.b64decode(expect('')).decode()=='sender@example.invalid'; send('334 UGFzc3dvcmQ6')
                assert base64.b64decode(expect('')).decode()=='test-only-password!'; send('235 Authenticated')
                expect('MAIL FROM:<sender@example.invalid>'); send('250 Sender OK')
                expect('RCPT TO:<recipient@example.invalid>')
                if reject: send('550 Recipient rejected'); return
                send('250 Recipient OK'); expect('DATA'); send('354 End with dot')
                chunks=[]
                while True:
                    line=f.readline()
                    if line==b'.\r\n': break
                    if not line: raise AssertionError('Truncated DATA')
                    chunks.append(line)
                received.append(b''.join(chunks)); send('250 Message accepted'); expect('QUIT')
        except ssl.SSLError:
            if trusted: failures.append('TLS failed unexpectedly')
        except Exception as ex: failures.append(str(ex))
        finally: listener.close()
    thread=threading.Thread(target=serve,daemon=True); thread.start()
    code=f'''require "app/smtp.php"; try {{ smtp_send(['host'=>'127.0.0.1','port'=>{port},'username'=>'sender@example.invalid','password'=>'test-only-password!','recipient'=>'recipient@example.invalid'], 'Ominis · test local', "Justification française\\nUne proposition à revoir."); echo 'ACCEPTED'; }} catch (Throwable $e) {{ echo 'REJECTED'; }}'''
    cmd=[str(PHP)]
    if (ROOT/'tmp/php/runtime/ext').exists(): cmd+=['-d',f'extension_dir={ROOT / "tmp/php/runtime/ext"}','-d','extension=openssl']
    if trusted: cmd+=['-d',f'openssl.cafile={tmp / "cert.pem"}']
    cmd+=['-r',code]
    result=subprocess.run(cmd,cwd=ROOT,capture_output=True,timeout=20)
    thread.join(timeout=12)
    assert not failures,failures
    assert result.returncode==0,result.stderr
    assert result.stdout==(b'REJECTED' if reject or not trusted else b'ACCEPTED'),result.stdout
    if received:
        headers,body=received[0].split(b'\r\n\r\n',1)
        assert b'Content-Transfer-Encoding: base64' in headers
        assert 'Justification française' in base64.b64decode(body).decode('utf-8')
    return True
try:
    assert run_case()
    assert run_case(reject=True)
    assert run_case(trusted=False)
    print('OK : SMTP TLS local, authentification, encodage UTF-8, refus serveur et certificat non fiable. Aucun email réel envoyé.')
finally: shutil.rmtree(tmp)
