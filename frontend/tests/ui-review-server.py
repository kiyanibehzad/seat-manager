"""Loopback-only UI review, including an installer that never creates records."""
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse
import argparse
import json

ROOT = Path(__file__).resolve().parent.parent
STAGING = ROOT / 'build' / 'staging'
CASES = {'available', 'installed', 'disabled', 'error', 'field-error'}


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def log_message(self, *args):
        pass

    def end_headers(self):
        self.send_header('Cache-Control', 'no-store')
        super().end_headers()

    def fixture(self):
        parts = urlparse(self.path).path.strip('/').split('/')
        return (parts[1], '/'.join(parts[2:])) if len(parts) >= 3 and parts[0] == 'install-review' and parts[1] in CASES else None

    def respond_json(self, data, status=200):
        value = {'ok': status == 200, 'data': data if status == 200 else None,
                 'error': None if status == 200 else data}
        body = json.dumps(value, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        route = self.fixture()
        if not route:
            return super().do_GET()
        case, resource = route
        if resource == 'api/install.php':
            if case == 'error':
                return self.respond_json({'message': 'خطای آزمایشی دریافت وضعیت نصب'}, 503)
            return self.respond_json({'installed': case == 'installed', 'available': case not in {'installed', 'disabled'}, 'csrf_token': 'ui-only-no-real-install-token'})
        if resource == 'install.html':
            body = (STAGING / 'install.html').read_text().replace('<body>', '<body><aside class="preview-tools">آزمون مستقل نصب؛ هیچ حساب، پایگاه داده یا رمز واقعی ساخته نمی‌شود.</aside>').encode()
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if resource.startswith('assets/'):
            candidate = (STAGING / resource).resolve()
            if candidate.is_relative_to(STAGING.resolve()) and candidate.is_file():
                self.path = '/build/staging/' + resource
                return super().do_GET()
        self.send_error(404)

    def do_POST(self):
        route = self.fixture()
        if route and route[1] == 'api/install.php':
            size = min(int(self.headers.get('Content-Length', 0)), 8192)
            self.rfile.read(size)  # Discard synthetic fields; never save or log them.
            if route[0] == 'available':
                return self.respond_json({'installed': True})
            if route[0] == 'field-error':
                return self.respond_json({'message': 'اطلاعات آزمایشی نیازمند اصلاح است.', 'fields': {'email': 'ایمیل آزمایشی قابل استفاده نیست.'}}, 422)
        self.send_error(405)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8794)
    options = parser.parse_args()
    ThreadingHTTPServer(('127.0.0.1', options.port), Handler).serve_forever()
