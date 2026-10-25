#!/usr/bin/env python3
"""Loopback-only SMTP capture. No relay, no email contents printed to logs."""
from pathlib import Path
import base64, json, os, socketserver, subprocess, time, uuid
ROOT=Path(__file__).resolve().parents[1]
os.chdir(ROOT)
os.umask(0o077)
class Handler(socketserver.StreamRequestHandler):
    def reply(self, text):
        self.wfile.write((text+'\r\n').encode()); self.wfile.flush()
    def handle(self):
        self.connection.settimeout(30)
        mode_path=ROOT/'storage/run/smtp-mode'
        mode=mode_path.read_text().strip() if mode_path.exists() else ''
        if mode=='pre_fail': self.reply('421 Test pre-delivery failure'); return
        self.reply('220 local-sink ESMTP')
        sender=''; recipients=[]
        while True:
            raw=self.rfile.readline(65536)
            if not raw: return
            line=raw.decode('utf-8', 'replace').strip()
            command=line.split(' ',1)[0].upper()
            if command in ('EHLO','HELO'): self.reply('250 local-sink')
            elif command=='MAIL': sender=line[5:]; self.reply('250 OK')
            elif command=='RCPT': recipients.append(line[5:]); self.reply('250 OK')
            elif command=='DATA':
                self.reply('354 End with dot')
                parts=[]; size=0
                while True:
                    part=self.rfile.readline(65536)
                    if part in (b'.\r\n',b'.\n'): break
                    if not part: return
                    size+=len(part)
                    if size>1024*1024: return
                    parts.append(part[1:] if part.startswith(b'..') else part)
                # Store a private encrypted mailbox; stdout/stderr never contain message data.
                record=json.dumps({'sender':sender,'recipients':recipients,'message_b64':base64.b64encode(b''.join(parts)).decode()}).encode()
                php='/opt/homebrew/opt/php@8.3/bin/php'
                result=subprocess.run([php,'bin/sink-encrypt.php'],input=record,capture_output=True)
                if result.returncode: self.reply('451 Capture unavailable'); continue
                target=ROOT/'storage/mail-sink'/(str(time.time_ns())+'-'+str(uuid.uuid4())+'.enc')
                target.write_bytes(result.stdout)
                if mode=='drop_ack': return
                if mode=='delay_ack': time.sleep(1)
                self.reply('250 Captured locally')
                sender=''; recipients=[]
            elif command=='RSET': sender=''; recipients=[]; self.reply('250 OK')
            elif command=='NOOP': self.reply('250 OK')
            elif command=='QUIT': self.reply('221 Bye'); return
            else: self.reply('502 Unsupported')
class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address=True
    daemon_threads=True
with Server(('127.0.0.1',10279),Handler) as server: server.serve_forever()
