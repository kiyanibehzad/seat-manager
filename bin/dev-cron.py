#!/usr/bin/env python3
"""Project-owned local scheduler; never edits system cron/launchd."""
from pathlib import Path
import json, os, signal, subprocess, sys, threading, time
ROOT=Path(__file__).resolve().parents[1]
os.chdir(ROOT)
os.umask(0o077)
stop=threading.Event()
child=None
def stop_runner(*_):
    stop.set()
    if child is not None and child.poll() is None:
        child.terminate()
signal.signal(signal.SIGTERM,stop_runner)
signal.signal(signal.SIGINT,stop_runner)
php=sys.argv[1]
run=ROOT/'storage/run'
# Only the development config; no alternate API/test config inherited by the runner.
env=dict(os.environ)
env.pop('ACC_CONFIG',None)
while not stop.is_set():
    started=time.monotonic()
    try:
        child=subprocess.Popen([php,'-d','display_errors=0','-d','zend.exception_ignore_args=1','bin/cron.php'],cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        stdout,_=child.communicate(timeout=55)
        # Keep only the worker's numeric/boolean counts; never forward PHP/SMTP message text.
        data=json.loads(stdout) if child.returncode==0 else ({'stopped':True} if stop.is_set() else {'error':'local_cron_failed'})
        if not isinstance(data,dict): data={'error':'invalid_cron_status'}
    except (subprocess.TimeoutExpired,json.JSONDecodeError):
        if child is not None and child.poll() is None:
            child.kill()
            child.communicate()
        data={'error':'local_cron_timeout_or_invalid_status'}
    finally:
        child=None
    data['pid']=os.getpid()
    data['checked_at_utc']=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
    target=run/'cron-status.json'
    temp=run/'cron-status.tmp'
    temp.write_text(json.dumps(data))
    temp.replace(target)
    stop.wait(max(1,60-(time.monotonic()-started)))
