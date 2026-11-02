"""Local scheduler subprocess and offline Nginx cutover/rollback; no services/VPS."""
import fcntl
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SchedulerHandoffTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sgd-scheduler-handoff-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.releases = self.base/'releases'
        for name in ('old', 'new'):
            release = self.releases/name
            (release/'bootstrap/cache').mkdir(parents=True)
            (release/'bootstrap/cache/config.php').touch()
            (release/'artisan').touch()
        self.pointer = self.base/'current'
        self.switch('old')
        self.lock = self.base/'scheduler.lock'
        self.lock.touch()
        self.output = self.base/'executed'
        self.gate = self.base/'gate'
        self.php = self.base/'fake-php'
        self.php.write_text('#!/bin/bash\nset -eu\necho "$1" >> "'+str(self.output)+'"\n'
                            'if [[ -p "'+str(self.gate)+'" ]]; then read -r _ < "'+str(self.gate)+'"; fi\n')
        self.php.chmod(0o700)
        text = (ROOT/'deploy/sgd-activation/scheduler-launcher.sh.template').read_text()
        for key, value in {'SCHEDULER_LOCK': self.lock, 'SCHEDULER_POINTER': self.pointer,
                           'RELEASES_ROOT': self.releases, 'PHP_BINARY': self.php,
                           'FENCE': self.base/'closed-fence'}.items():
            text = text.replace('@'+key+'@', str(value))
        self.wrapper = self.base/'launcher'
        self.wrapper.write_text(text)
        self.children = []
        self.addCleanup(self.cleanup_children)

    def cleanup_children(self):
        for child in self.children:
            if child.poll() is None:
                child.kill()
            child.communicate(timeout=3)

    def switch(self, name):
        temporary = self.base/'next'
        temporary.symlink_to(self.releases/name)
        os.replace(temporary, self.pointer)

    def launch(self):
        child = subprocess.Popen(['bash', str(self.wrapper)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.children.append(child)
        return child

    def wait_started(self):
        deadline = time.monotonic()+3
        while not self.output.exists() and time.monotonic() < deadline:
            time.sleep(.01)
        self.assertTrue(self.output.exists(), 'synthetic schedule did not start')

    def test_waiting_launcher_resolves_release_only_after_exclusive_cutover(self):
        with self.lock.open('rb') as operator:
            fcntl.flock(operator, fcntl.LOCK_EX)
            child = self.launch()
            time.sleep(.05)
            self.assertIsNone(child.poll())
            self.assertFalse(self.output.exists())
            self.switch('new')
            fcntl.flock(operator, fcntl.LOCK_UN)
            self.assertEqual(0, child.wait(timeout=3))
        self.assertEqual(str(self.releases/'new/artisan')+'\n', self.output.read_text())

    def test_active_schedule_blocks_switch_then_new_and_rollback_use_intended_runtime(self):
        os.mkfifo(self.gate)
        child = self.launch()
        self.wait_started()
        with self.lock.open('rb') as operator:
            with self.assertRaises(BlockingIOError):
                fcntl.flock(operator, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.gate.open('w') as gate:
                gate.write('finish\n')
            self.assertEqual(0, child.wait(timeout=3))
            self.gate.unlink()
            fcntl.flock(operator, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.switch('new')
            fcntl.flock(operator, fcntl.LOCK_UN)
        self.assertEqual(0, self.launch().wait(timeout=3))
        with self.lock.open('rb') as operator:
            fcntl.flock(operator, fcntl.LOCK_EX)
            self.switch('old')
        self.assertEqual(0, self.launch().wait(timeout=3))
        self.assertEqual([str(self.releases/name/'artisan') for name in ('old', 'new', 'old')],
                         self.output.read_text().splitlines())

    def test_pointer_outside_release_root_is_rejected_without_execution(self):
        self.pointer.unlink()
        self.pointer.symlink_to(self.base)
        self.assertNotEqual(0, self.launch().wait(timeout=3))
        self.assertFalse(self.output.exists())


class NginxRoutingTest(unittest.TestCase):
    def test_rendered_nginx_cutover_and_rollback_and_rejected_invalid_config(self):
        with tempfile.TemporaryDirectory(prefix='sgd-nginx-handoff-') as temporary:
            base = Path(temporary)
            template = (ROOT/'deploy/sgd-activation/nginx-locations.conf.template').read_text()
            for name in ('old', 'new'):
                public = base/'releases'/name/'public'
                public.mkdir(parents=True)
                (public/'identity.txt').write_text(name+'\n')
                rendered = template.replace('@RELEASE@', '/runtime/releases/'+name).replace('@ID@', name)
                self.assertNotIn('@', rendered)
                (base/(name+'.conf')).write_text(rendered)
            (base/'nginx.conf').write_text('''pid /tmp/nginx.pid;
error_log /tmp/error.log;
events {}
http {
  access_log off;
  client_body_temp_path /tmp/client;
  proxy_temp_path /tmp/proxy;
  fastcgi_temp_path /tmp/fastcgi;
  uwsgi_temp_path /tmp/uwsgi;
  scgi_temp_path /tmp/scgi;
  server { listen 8080; include /runtime/live.conf; }
}
''')
            script = r'''set -eu
cp /etc/nginx/fastcgi_params /runtime/fastcgi_params
cp /runtime/old.conf /runtime/live.conf
nginx -t -c /runtime/nginx.conf
nginx -c /runtime/nginx.conf
trap 'nginx -s quit -c /runtime/nginx.conf' EXIT
expect_identity() {
  for attempt in $(seq 1 100); do
    actual="$(wget -q -O - http://127.0.0.1:8080/identity.txt || true)"
    if [ "$actual" = "$1" ]; then return 0; fi
    sleep .02
  done
  return 1
}
expect_identity old
cp /runtime/new.conf /runtime/live.next
mv /runtime/live.next /runtime/live.conf
nginx -t -c /runtime/nginx.conf
nginx -s reload -c /runtime/nginx.conf
expect_identity new
cp /runtime/old.conf /runtime/live.next
mv /runtime/live.next /runtime/live.conf
nginx -t -c /runtime/nginx.conf
nginx -s reload -c /runtime/nginx.conf
expect_identity old
cp /runtime/live.conf /runtime/saved.conf
echo 'invalid_directive;' > /runtime/live.next
mv /runtime/live.next /runtime/live.conf
if nginx -t -c /runtime/nginx.conf; then exit 1; fi
cp /runtime/saved.conf /runtime/live.next
mv /runtime/live.next /runtime/live.conf
nginx -t -c /runtime/nginx.conf
expect_identity old
echo 'PASS offline Nginx routing, graceful cutover, rollback, invalid-config restoration'
'''
            result = subprocess.run([
                'docker', '--host', 'unix:///var/run/docker.sock', 'run', '--pull', 'never', '--rm',
                '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                '--user', f'{os.getuid()}:{os.getgid()}', '--mount', f'type=bind,src={base},dst=/runtime',
                '--tmpfs', '/tmp:rw,mode=1777', '--entrypoint', '/bin/sh', 'nginx:stable-alpine', '-c', script,
            ], text=True, capture_output=True, timeout=30)
            self.assertEqual(0, result.returncode, result.stdout+result.stderr)
            self.assertIn('PASS offline Nginx routing', result.stdout)


if __name__ == '__main__':
    unittest.main()
