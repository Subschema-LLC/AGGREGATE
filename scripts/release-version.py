#!/usr/bin/env python3
"""Check or propose release tags in the vYYYY.MM.NN[.H] calendar format.

SPDX-License-Identifier: AGPL-3.0-only
NN is the release's number within that month, starting at 01; it is not the day.
H numbers hotfixes to the latest release, starting at 1.

  python3 scripts/release-version.py next            # next tag for the current UTC month
  python3 scripts/release-version.py next --month 2026.10
  python3 scripts/release-version.py next --hotfix   # next hotfix of the latest release
  python3 scripts/release-version.py check v2026.09.01
"""

import argparse
import datetime as dt
import importlib.util
from pathlib import Path
import re
import subprocess
import sys

_SPEC = importlib.util.spec_from_file_location("build_release", Path(__file__).resolve().parent / "build-release.py")
_BUILDER = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_BUILDER)
CALENDAR_VERSION = _BUILDER.CALENDAR_VERSION
validate_version = _BUILDER.validate_version


def calendar_versions(tags):
    """(year, month, index, hotfix) for each calendar tag; hotfix is 0 for a release."""
    found = []
    for tag in tags:
        if match := re.fullmatch("v" + CALENDAR_VERSION, tag.strip()):
            found.append(tuple(int(part or 0) for part in match.groups()))
    return found


def next_version(tags, month):
    """Next YYYY.MM.NN for month "YYYY.MM" given existing tag names."""
    if not re.fullmatch(r"20[0-9]{2}\.(0[1-9]|1[0-2])", month):
        raise ValueError("Month must be YYYY.MM, for example 2026.09.")
    year, number = (int(part) for part in month.split("."))
    used = [index for (y, m, index, _) in calendar_versions(tags) if (y, m) == (year, number)]
    index = max(used, default=0) + 1
    if index > 99:
        raise ValueError(f"{month} already has 99 releases.")
    return f"{month}.{index:02d}"


def next_hotfix(tags):
    """Next YYYY.MM.NN.H: a hotfix of the newest release, whatever its month."""
    versions = calendar_versions(tags)
    if not versions:
        raise ValueError("There is no release to hotfix yet.")
    year, month, index, _ = max(versions)
    hotfix = max(h for (y, m, i, h) in versions if (y, m, i) == (year, month, index)) + 1
    if hotfix > 99:
        raise ValueError(f"{year}.{month:02d}.{index:02d} already has 99 hotfixes.")
    return f"{year}.{month:02d}.{index:02d}.{hotfix}"


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    proposal = commands.add_parser("next", help="print the next unused tag for a month (default: the current UTC month)")
    choice = proposal.add_mutually_exclusive_group()
    choice.add_argument("--month", help="YYYY.MM")
    choice.add_argument("--hotfix", action="store_true", help="the next hotfix of the latest release instead")
    check = commands.add_parser("check", help="exit 1 unless the tag uses the calendar format")
    check.add_argument("tag")
    args = parser.parse_args()
    try:
        if args.command == "check":
            if not args.tag.startswith("v"):
                raise ValueError("Release tags start with v, for example v2026.09.01.")
            validate_version(args.tag)
            print(args.tag)
            return
        tags = subprocess.run(["git", "tag", "--list", "v*"], check=True, capture_output=True, text=True).stdout.splitlines()
        if args.hotfix:
            print("v" + next_hotfix(tags))
            return
        month = args.month or dt.datetime.now(dt.timezone.utc).strftime("%Y.%m")
        print("v" + next_version(tags, month))
    except (ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    sys.exit(main())
