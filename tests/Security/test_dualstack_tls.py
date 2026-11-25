"""Real local TLS connections using fictitious DNS names and credentials, no external service."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json,subprocess,sys,tempfile,threading,ssl,os
php=os.environ.get('PHP','php')
root=str(Path(__file__).resolve().parents[2])
counts={}
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def handle(self):
        try:super().handle()
        except (BrokenPipeError,ConnectionResetError):pass
    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length','0')));self.do_GET()
    def do_GET(self):
        counts[self.path]=counts.get(self.path,0)+1
        status=200;body=json.dumps({'host':self.headers.get('Host'),'sni':getattr(self.connection,'qa_sni',None)}).encode()
        if self.path=='/token':body=b'{"access_token":"qa-token","expires_in":3600}'
        if self.path=='/initSession':body=b'{"session_token":"qa-session"}'
        if self.path=='/redirect':status=302
        if self.path=='/large':body=b'x'*(10*1024*1024+1)
        self.send_response(status)
        if status==302:self.send_header('Location','https://attacker.invalid/should-not-reach')
        self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers()
        try:self.wfile.write(body)
        except (BrokenPipeError,ssl.SSLError,ConnectionResetError):pass
with tempfile.TemporaryDirectory() as directory:
    d=Path(directory);cert=d/'cert.pem';key=d/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),
        '-subj','/CN=fixture.invalid','-addext','subjectAltName=DNS:fixture.invalid','-days','1'],check=True,capture_output=True)
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key)
    ctx.sni_callback=lambda socket,name,context:setattr(socket,'qa_sni',name)
    server.socket=ctx.wrap_socket(server.socket,server_side=True)
    threading.Thread(target=server.serve_forever,daemon=True).start()
    try:
        for mode in ['streams','curl']:
            args=[php,'-d','openssl.cafile='+str(cert),'-d','curl.cainfo='+str(cert)]
            probe=subprocess.run([php,'-r',"echo extension_loaded('curl')?'yes':'no';"],capture_output=True,text=True,check=True)
            if mode=='curl' and probe.stdout.strip()!='yes':args+=['-d','extension=curl']
            args += [str(Path(__file__).with_name('dualstack_tls_fixture.php')),root,'https://fixture.invalid:'+str(server.server_port),mode]
            p=subprocess.run(args,text=True,capture_output=True,timeout=90)
            print(p.stdout,end='')
            if p.returncode:raise RuntimeError(p.stderr or p.stdout)
        assert counts.get('/valid')==2,counts
        assert counts.get('/should-not-reach',0)==0,counts
        assert counts.get('/token')==2 and counts.get('/initSession')==2 and counts.get('/killSession')==2,counts
        print('OK observed server counts: IPv6-first fallback reached IPv4; no request with wrong TLS name or redirected destination')
    finally:server.shutdown();server.server_close()
