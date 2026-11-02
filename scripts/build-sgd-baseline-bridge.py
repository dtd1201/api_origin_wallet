#!/usr/bin/env python3
"""Build a LOCAL fence-closed baseline bridge; never runs deployment or provider I/O."""
import argparse
import io
from pathlib import Path
import shutil
import subprocess
import tarfile

BASELINE = '7a4c95a3400608cde513aa096f5203b59b3e0708'
ROOT = Path(__file__).resolve().parents[1]
COPY = [
    'app/Services/Nium/NiumSgdAdmissionFence.php',
    'app/Services/Nium/NiumSgdAdmissionLease.php',
    'app/Services/Nium/NiumPayoutPreDispatchException.php',
    'config/sgd_admission.php', 'scripts/sgd-admission-control.py',
    'scripts/verify-sgd-release-runtime.php', 'docs/sgd-activation/financial-policy.sha256',
    'app/Services/Integrations/ProviderHttpClient.php',
    'app/Services/Nium/NiumSgdLocalPayoutWorkflow.php',
    'app/Services/Nium/NiumStandardTransferAdapter.php',
    'app/Services/Nium/NiumQuoteService.php',
    'app/Services/Nium/NiumProvisionalPayoutPricing.php',
    'app/Services/Wallet/TransferApprovalService.php',
    'app/Http/Controllers/Api/Admin/TransferController.php',
    'app/Console/Commands/AuthorizeNiumPayoutPilot.php',
    'tests/Feature/NiumSgdAdmissionBridgeTest.php',
]


def build(repository, output):
    output.mkdir()  # Refuse an existing or live directory.
    blob = subprocess.check_output(['git', '-C', str(repository), 'archive', BASELINE])
    with tarfile.open(fileobj=io.BytesIO(blob)) as archive:
        archive.extractall(output, filter='data')
    for name in COPY:
        target = output/name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT/name, target)
    name = 'app/Services/Nium/NiumTransferService.php'
    p = output/name
    text = p.read_text().replace(
        '    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer\n    {',
        "    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer\n    {\n"
        "        $admissionLease = app(NiumSgdAdmissionFence::class)->acquireFor($transfer->fresh(['provider']));", 1)
    p.write_text(text)
    name = 'app/Services/Nium/NiumPayoutFxLockService.php'
    p = output/name
    text = p.read_text().replace('    ): FxQuote {\n',
        "    ): FxQuote {\n        $admissionLease = strtoupper(trim($targetCurrency)) === 'SGD' ? app(NiumSgdAdmissionFence::class)->acquire() : null;\n", 1)
    text = text.replace('    public function createSgdPreparationLock(Transfer $transfer, string $requestId): FxQuote\n    {',
        '    public function createSgdPreparationLock(Transfer $transfer, string $requestId): FxQuote\n    {\n'
        '        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();', 1)
    p.write_text(text)
    name = 'tests/Concerns/CreatesSgdLocalExecutionFixtures.php'
    fixture = (ROOT/name).read_text()
    helpers = fixture[fixture.index('    protected string $sgdFenceDirectory;'):fixture.index('    protected function fakeSgdProvider')]
    p = output/name
    p.write_text(p.read_text().replace('    protected function fakeSgdProvider', helpers+'    protected function fakeSgdProvider', 1))
    # These remain byte-identical financial authorization, pricing, ledger and config.
    for line in (ROOT/'docs/sgd-activation/financial-policy.sha256').read_text().splitlines():
        _, name = line.split('  ', 1)
        expected = subprocess.check_output(['git', '-C', str(repository), 'show', BASELINE+':'+name])
        assert (output/name).read_bytes() == expected, name
    print('LOCAL baseline bridge built. Keep CLOSED; never use it for modern SGD dispatch/recovery.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repository', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    build(args.repository, args.output)
