#!/usr/bin/env python3
"""Local development only; no global services or packages are changed."""
from pathlib import Path
import getpass, json, os, shutil, signal, socket, subprocess, sys, time
ROOT = Path(__file__).resolve().parents[1]
os.chdir(ROOT)
os.umask(0o077)
RUN = ROOT / 'storage/run'
RUN.mkdir(parents=True, exist_ok=True)
STATE = RUN / 'services.json'
PHP = '/opt/homebrew/opt/php@8.3/bin/php'
if not Path(PHP).exists(): PHP = shutil.which('php')

def port_ready(port):
    with socket.socket() as s:
        s.settimeout(.2)
        return s.connect_ex(('127.0.0.1', port)) == 0

def alive(pid):
    try: os.kill(pid, 0); return True
    except ProcessLookupError: return False

if len(sys.argv) != 2 or sys.argv[1] not in ('start', 'stop', 'status'):
    raise SystemExit('Usage: python3 bin/dev-services.py start|stop|status')
state = json.loads(STATE.read_text()) if STATE.exists() else {}
if sys.argv[1] == 'status':
    print(json.dumps({name: {'running': alive(info['pid']), 'port': info.get('port')} for name, info in state.items()}))
    raise SystemExit()
if sys.argv[1] == 'stop':
    for name, info in reversed(list(state.items())):
        if alive(info['pid']): os.kill(info['pid'], signal.SIGTERM)
    for _ in range(100):
        if all((not port_ready(info['port'])) if info.get('port') else (not alive(info['pid'])) for info in state.values()): break
        time.sleep(.1)
    STATE.unlink(missing_ok=True)
    print('Local services stopped.')
    raise SystemExit()
if not (ROOT / 'storage/mysql/mysql').exists():
    subprocess.run(['mariadb-install-db','--no-defaults','--datadir='+str(ROOT/'storage/mysql'),'--auth-root-authentication-method=socket','--auth-root-socket-user='+getpass.getuser(),'--skip-name-resolve','--skip-test-db'], check=True, stdout=subprocess.DEVNULL)

def start(name, port, command, env=None):
    if name in state and alive(state[name]['pid']): return
    if port and port_ready(port): raise SystemExit(f'Port {port} already occupied; no existing service was changed.')
    logfile = open(ROOT / f'storage/logs/{name}.log', 'ab')
    process = subprocess.Popen(command, cwd=ROOT, env=env, stdin=subprocess.DEVNULL, stdout=logfile, stderr=logfile, start_new_session=True)
    state[name] = {'pid': process.pid, 'port': port}
    STATE.write_text(json.dumps(state))
    for _ in range(100):
        if process.poll() is not None: raise SystemExit(f'{name} failed; inspect private service status.')
        if port is None:
            status_file=RUN/'cron-status.json'
            if status_file.exists():
                try:
                    cron_status=json.loads(status_file.read_text())
                    if cron_status.get('pid')==process.pid and 'error' not in cron_status: return
                except (json.JSONDecodeError,OSError): pass
        elif port_ready(port): return
        time.sleep(.1)
    raise SystemExit(f'{name} start timed out.')

start('mysql', 33079, ['mariadbd','--no-defaults','--datadir='+str(ROOT/'storage/mysql'),'--socket='+str(RUN/'db.sock'),'--pid-file='+str(RUN/'mysql.pid'),'--bind-address=127.0.0.1','--port=33079','--skip-name-resolve','--local-infile=0'])
subprocess.run([PHP,'bin/dev-db-setup.php'], check=True)
start('smtp-sink', 10279, [sys.executable, 'bin/smtp-sink.py'])
start('api', 8787, [PHP,'-d','display_errors=0','-S','127.0.0.1:8787','-t','public_html'])
test_env=dict(os.environ, ACC_CONFIG=str(RUN/'test-config.php'))
start('test-api', 8788, [PHP,'-d','display_errors=0','-S','127.0.0.1:8788','-t','public_html'], test_env)
sub_root=ROOT/'work/subfolder-web'
sub_root.mkdir(parents=True, exist_ok=True)
portal=sub_root/'portal'
if not portal.exists(): portal.symlink_to(ROOT/'public_html', target_is_directory=True)
sub_env=dict(os.environ, ACC_CONFIG=str(RUN/'subfolder-config.php'))
start('subfolder-api',8789,[PHP,'-d','display_errors=0','-S','127.0.0.1:8789','-t',str(sub_root)],sub_env)
start('local-cron',None,[sys.executable,'bin/dev-cron.py',PHP])
print('Local API: http://127.0.0.1:8787/api/index.php?route=/health')
