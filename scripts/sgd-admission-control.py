#!/usr/bin/env python3
"""Operator control for a local, production-only fence. No Laravel, DB or network.

Keep drain.lock's inode forever; state is replaced atomically. CLOSED is written
before drain, so a timeout leaves admission closed and NEVER certifies a drain.
"""
import argparse
import fcntl
import os
from pathlib import Path
import stat
import sys
import tempfile
import time


def atomic_state(root, value):
    fd, name = tempfile.mkstemp(prefix='.state-', dir=root)
    try:
        os.fchmod(fd, 0o640)
        os.write(fd, value)
        os.fsync(fd)
        os.close(fd)
        fd = None
        os.replace(name, root / 'state')
        directory = os.open(root, os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if fd is not None:
            os.close(fd)
        if os.path.exists(name):
            os.unlink(name)


def operate(root, action, timeout, acknowledge=False):
    if not root.is_absolute() or root.is_symlink() or root.resolve() != root:
        raise ValueError('Invalid control directory')
    if action == 'init':
        root.mkdir(mode=0o750)  # Refuse any existing directory or symlink.
        for name in ('drain.lock', 'control.lock'):
            fd = os.open(root / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
            os.close(fd)
        atomic_state(root, b'CLOSED v1\n')
        return 'CLOSED; initialization grants no admission'
    if not root.is_dir() or (root / 'state').is_symlink():
        raise ValueError('Invalid control files')
    fd = os.open(root / 'drain.lock', os.O_RDONLY | os.O_NOFOLLOW)
    control = None
    try:
        opened, current = os.fstat(fd), os.lstat(root / 'drain.lock')
        if not stat.S_ISREG(opened.st_mode) or (opened.st_dev, opened.st_ino) != (current.st_dev, current.st_ino):
            raise ValueError('Drain lock identity changed')
        if action == 'status':
            return 'OPEN' if (root / 'state').read_bytes() == b'OPEN v1\n' else 'CLOSED; drain not certified'
        if action == 'open' and not acknowledge:
            raise ValueError('Opening requires the recorded activation decision')
        # Serialize operators too: an overlapping open must never overwrite a close.
        control = os.open(root / 'control.lock', os.O_RDONLY | os.O_NOFOLLOW)
        fcntl.flock(control, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if action == 'close':
            atomic_state(root, b'CLOSED v1\n')
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise TimeoutError('Admission remains closed; drain incomplete')
                time.sleep(0.02)
        if action == 'open':
            atomic_state(root, b'OPEN v1\n')
            return 'OPEN; financial policy is independently enforced'
        return 'CLOSED AND DRAINED for cooperating fence-aware processes only'
    finally:
        if control is not None:
            os.close(control)
        os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('init', 'status', 'close', 'open'))
    parser.add_argument('--directory', required=True, type=Path)
    parser.add_argument('--timeout', type=float, default=30)
    parser.add_argument('--acknowledge-activation', action='store_true')
    args = parser.parse_args()
    if not 0 <= args.timeout <= 60:
        parser.error('timeout must be between zero and 60 seconds')
    try:
        print(operate(args.directory, args.action, args.timeout, args.acknowledge_activation))
    except (OSError, ValueError, TimeoutError):
        print('FAIL; no drain or admission authorization certified', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
