# Smart-HTTP Git server (git http-backend) that requires Basic auth deploy:s3cret. Test fixture.
# Usage: git-http-server.py <repositories-root> <port>
import base64, os, subprocess, sys
from http.server import BaseHTTPRequestHandler, HTTPServer
ROOT = sys.argv[1]; GOOD = 'Basic ' + base64.b64encode(b'deploy:s3cret').decode()
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def handle_git(self):
        if self.headers.get('Authorization') != GOOD:
            self.send_response(401); self.send_header('WWW-Authenticate', 'Basic realm="git"'); self.end_headers(); return
        path, _, query = self.path.partition('?')
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        env = dict(os.environ, GIT_PROJECT_ROOT=ROOT, GIT_HTTP_EXPORT_ALL='1', PATH_INFO=path, QUERY_STRING=query,
                   REQUEST_METHOD=self.command, CONTENT_TYPE=self.headers.get('Content-Type', ''), CONTENT_LENGTH=str(len(body)),
                   GIT_PROTOCOL=self.headers.get('Git-Protocol', ''), REMOTE_USER='deploy')
        out = subprocess.run(['git', 'http-backend'], input=body, env=env, capture_output=True).stdout
        head, _, payload = out.partition(b'\r\n\r\n')
        status = 200
        headers = []
        for line in head.decode().split('\r\n'):
            k, _, v = line.partition(': ')
            if k.lower() == 'status': status = int(v.split()[0])
            elif k: headers.append((k, v))
        self.send_response(status)
        for k, v in headers: self.send_header(k, v)
        self.send_header('Content-Length', str(len(payload))); self.end_headers(); self.wfile.write(payload)
    do_GET = do_POST = handle_git
HTTPServer(('127.0.0.1', int(sys.argv[2])), H).serve_forever()
