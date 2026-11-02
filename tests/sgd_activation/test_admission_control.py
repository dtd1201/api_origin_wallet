"""Disposable local directories and subprocesses only; no application services."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('control', ROOT/'scripts/sgd-admission-control.py')
control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(control)


class AdmissionControlTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sgd-control-unit-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)/'control'
        control.operate(self.root, 'init', 0)

    def test_initialization_is_closed_and_cannot_replace_an_existing_lock(self):
        inode = (self.root/'drain.lock').stat().st_ino
        self.assertEqual(b'CLOSED v1\n', (self.root/'state').read_bytes())
        with self.assertRaises(FileExistsError):
            control.operate(self.root, 'init', 0)
        self.assertEqual(inode, (self.root/'drain.lock').stat().st_ino)

    def test_open_requires_activation_ack_and_close_keeps_same_lock_inode(self):
        with self.assertRaises(ValueError):
            control.operate(self.root, 'open', 0)
        inode = (self.root/'drain.lock').stat().st_ino
        control.operate(self.root, 'open', 0, True)
        self.assertEqual('OPEN', control.operate(self.root, 'status', 0))
        self.assertIn('CLOSED AND DRAINED', control.operate(self.root, 'close', 0))
        self.assertEqual(inode, (self.root/'drain.lock').stat().st_ino)

    def test_timeout_leaves_closed_and_cannot_certify_or_reopen_active_work(self):
        control.operate(self.root, 'open', 0, True)
        with (self.root/'drain.lock').open('rb') as lease:
            fcntl.flock(lease, fcntl.LOCK_SH)
            with self.assertRaises(TimeoutError):
                control.operate(self.root, 'close', .03)
            self.assertEqual(b'CLOSED v1\n', (self.root/'state').read_bytes())
            with self.assertRaises(TimeoutError):
                control.operate(self.root, 'open', .03, True)
        self.assertIn('CLOSED AND DRAINED', control.operate(self.root, 'close', 0))

    def test_overlapping_operator_cannot_open_during_close(self):
        with (self.root/'control.lock').open('rb') as operator:
            fcntl.flock(operator, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError):
                control.operate(self.root, 'open', 0, True)
            self.assertEqual(b'CLOSED v1\n', (self.root/'state').read_bytes())

    def test_real_php_lease_makes_close_wait_and_new_php_process_observes_closed(self):
        control.operate(self.root, 'open', 0, True)
        php = r'''
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config()->set('sgd_admission.directory', $argv[2]);
        $lease = app(App\Services\Nium\NiumSgdAdmissionFence::class)->acquire();
        echo "READY\n"; fflush(STDOUT); fgets(STDIN);
        '''
        environment = {**os.environ, 'APP_ENV': 'testing', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:',
                       'DB_URL': '', 'CACHE_STORE': 'array', 'APP_CONFIG_CACHE': self.temp.name+'/missing-config.php'}
        child = subprocess.Popen(['php', '-r', php, str(ROOT), str(self.root)], stdin=subprocess.PIPE,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=environment)
        self.addCleanup(lambda: child.kill() if child.poll() is None else None)
        self.assertEqual('READY\n', child.stdout.readline())
        closer = subprocess.Popen(['python3', '-B', str(ROOT/'scripts/sgd-admission-control.py'), 'close',
                                   '--directory', str(self.root), '--timeout', '3'], stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, text=True)
        self.addCleanup(lambda: closer.kill() if closer.poll() is None else None)
        deadline = time.monotonic()+2
        while (self.root/'state').read_bytes() != b'CLOSED v1\n' and time.monotonic() < deadline:
            time.sleep(.01)
        self.assertIsNone(closer.poll(), 'drain must wait for the PHP operation lease')
        probe = subprocess.run(['php', '-r', php, str(ROOT), str(self.root)], input='\n', capture_output=True,
                               text=True, env=environment)
        self.assertNotEqual(0, probe.returncode)
        child.communicate('\n', timeout=3)
        output, error = closer.communicate(timeout=3)
        self.assertEqual(0, closer.returncode, error)
        self.assertIn('CLOSED AND DRAINED', output)

    def test_missing_and_symlink_controls_fail_closed(self):
        os.unlink(self.root/'drain.lock')
        os.symlink(self.root/'state', self.root/'drain.lock')
        with self.assertRaises(OSError):
            control.operate(self.root, 'close', 0)


if __name__ == '__main__':
    unittest.main()
