"""Exact PHP 8.3.31 containers; owned synthetic release/config/storage fixtures only."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
IMAGE = 'nium-customer-retry-php831-r8r15:local'


class ReleaseRuntimeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='sgd-isolated-runtime-')
        cls.base = Path(cls.temp.name)
        cls.release = cls.base/'release'
        cls.shared = cls.base/'shared'
        cls.shared.mkdir()
        cls.release.mkdir()
        for name in ('app', 'bootstrap', 'config', 'routes', 'vendor', 'scripts'):
            shutil.copytree(ROOT/name, cls.release/name, symlinks=True)
        for name in ('artisan', 'composer.json', 'composer.lock'):
            shutil.copyfile(ROOT/name, cls.release/name)
        shutil.rmtree(cls.release/'bootstrap/cache')
        (cls.release/'bootstrap/cache').mkdir(mode=0o770)
        cls.storage = cls.shared/'storage'
        for name in ('logs', 'framework/views', 'framework/cache/data', 'framework/sessions'):
            (cls.storage/name).mkdir(parents=True, exist_ok=True)
        (cls.release/'storage').symlink_to(cls.storage)
        cls.environment = cls.shared/'synthetic.env'
        cls.environment.write_text('''APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr
BANK_RATE_SYNC_ENABLED=false
NIUM_SGD_LOCAL_ZERO_FEE_ENABLED=true
NIUM_SGD_LOCAL_ZERO_FEE_VERSION=sgd-local-zero-fee-v1
NIUM_SGD_LOCAL_ZERO_FEE_CONFIRMATION_REFERENCE=SYNTHETIC-NOT-PRODUCTION
NIUM_SGD_LOCAL_ZERO_FEE_CONFIRMED_BY=SYNTHETIC
NIUM_SGD_LOCAL_ZERO_FEE_CONFIRMED_AT=2026-11-01T00:00:00Z
NIUM_SGD_LOCAL_ZERO_FEE_EFFECTIVE_FROM=2026-11-01T00:00:00Z
NIUM_SGD_LOCAL_ZERO_FEE_EXPIRES_AT=2026-11-09T01:25:57Z
NIUM_SGD_LOCAL_ZERO_FEE_BASE_URL=https://synthetic.invalid
NIUM_SGD_LOCAL_ZERO_FEE_CLIENT_ID=synthetic-client
NIUM_SGD_LOCAL_ZERO_FEE_CUSTOMER_ID=synthetic-customer
NIUM_SGD_LOCAL_ZERO_FEE_WALLET_ID=synthetic-wallet
''')
        cls.environment.chmod(0o640)
        (cls.release/'.env').symlink_to(cls.environment)
        cls.fence = cls.shared/'admission'
        cls.fence.mkdir()
        (cls.fence/'state').write_text('CLOSED v1\n')
        (cls.fence/'drain.lock').write_text('')
        result = cls.php('artisan', 'config:cache', '--no-ansi')
        if result.returncode:
            raise AssertionError('Synthetic config:cache failed: '+result.stdout+result.stderr)
        cls.cached = cls.release/'bootstrap/cache/config.php'
        cls.cached.chmod(0o640)
        cls.original_config = cls.cached.read_bytes()
        code = r'''$c=require $argv[1]; $f=function($v)use(&$f){if(is_array($v)){ksort($v);foreach($v as &$x)$x=$f($x);}return $v;}; echo hash('sha256',json_encode($f($c['services']['nium']['sgd_local_zero_fee']),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));'''
        result = cls.php('-r', code, str(cls.cached))
        if result.returncode:
            raise AssertionError('Synthetic policy fingerprint failed')
        cls.policy = result.stdout

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    @classmethod
    def php(cls, *args):
        return subprocess.run([
            'docker', '--host', 'unix:///var/run/docker.sock', 'run', '--pull', 'never', '--rm',
            '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--user', f'{os.getuid()}:{os.getgid()}', '--workdir', str(cls.release),
            '--mount', f'type=bind,src={cls.base},dst={cls.base}',
            '--mount', f'type=bind,src={ROOT},dst={ROOT},readonly', '--tmpfs', '/tmp:rw,mode=1777',
            '--entrypoint', '/usr/bin/env', IMAGE, '-i',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            f'APP_CONFIG_CACHE={cls.release}/bootstrap/cache/config.php',
            f'NIUM_SGD_ADMISSION_DIRECTORY={cls.fence}', 'php', *args,
        ], capture_output=True, text=True, timeout=45)

    def setUp(self):
        self.cached.write_bytes(self.original_config)
        self.cached.chmod(0o640)
        (self.release/'bootstrap/cache').chmod(0o770)
        (self.fence/'state').write_text('CLOSED v1\n')

    def verify(self):
        result = self.php('scripts/verify-sgd-release-runtime.php',
            '--environment-target='+str(self.environment), '--storage-target='+str(self.storage),
            '--policy-sha256='+self.policy, '--fence-directory='+str(self.fence))
        self.assertNotIn('SYNTHETIC', result.stdout+result.stderr)
        return result

    def test_real_config_cache_env_storage_and_release_local_autoload_pass(self):
        result = self.verify()
        self.assertEqual(0, result.returncode, result.stdout+result.stderr)
        self.assertEqual(9, result.stdout.count('PASS '))

    def test_changed_policy_is_rejected_without_exposing_the_configuration(self):
        self.cached.write_bytes(self.original_config.replace(b'SYNTHETIC-NOT-PRODUCTION', b'CHANGED-NOT-PRODUCTION'))
        result = self.verify()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('FAIL policy-identical-and-unexpired', result.stdout)

    def test_world_readable_cache_is_rejected(self):
        self.cached.chmod(0o644)
        self.assertIn('FAIL private-production-config', self.verify().stdout)

    def test_open_fence_cannot_be_certified_as_closed_for_activation(self):
        (self.fence/'state').write_text('OPEN v1\n')
        self.assertIn('FAIL fence-closed-and-configured', self.verify().stdout)

    def test_shared_vendor_autoload_cannot_silently_load_the_other_release(self):
        vendor = self.release/'vendor'
        held = self.release/'vendor-held'
        vendor.rename(held)
        vendor.symlink_to(ROOT/'vendor')
        try:
            result = self.verify()
            self.assertNotEqual(0, result.returncode)
            self.assertIn('FAIL release-local-autoload', result.stdout)
        finally:
            vendor.unlink()
            held.rename(vendor)


if __name__ == '__main__':
    unittest.main()
