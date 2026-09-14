# Proprietary licensing release — 2026-09-06

nexus-enterprise-import-export-RELEASE.zip is the FINAL-v2 live tree with a licensing-only correction for Nexus Smart Solutions.

Changed package files:

- composer.json: license metadata is proprietary.
- LICENSE.md: Nexus Smart Solutions Proprietary Software License; Copyright © 2026 Nexus Smart Solutions; All Rights Reserved; authorized-use and written-permission restrictions; third-party license preservation.
- README.md: proprietary ownership and license section replacing the previous package license statement.

The earlier license requirements are reflected in the authorized-use, client-integration, redistribution, sublicensing, public source disclosure, standalone resale/rental/lease, relicensing, notice-preservation and competing-package restrictions. Exceptions require written authorization from Nexus Smart Solutions.

Implementation code, tests, configuration, migrations, examples, dependencies and composer.lock are unchanged from FINAL-v2. A before/after file-hash comparison confirmed that only the three package files above changed before release evidence and archive metadata were generated.

Only these validation commands were run for this licensing change:

| Command | Result |
| --- | --- |
| composer validate --strict | Passed; composer.json is valid |
| php vendor/bin/pint --test | Passed |

The command outputs are licensing-composer.log and licensing-pint.log. No PHPUnit suite, static-analysis run or benchmark was executed for this licensing-only release. Earlier FINAL-v2 verification and completed benchmark evidence remain included without claiming a new run.

Repository license searches covered current package files and dependency metadata/notices. Third-party license files were left untouched. Dependency license names and removed lines in the generated historical changes.patch are not current license grants for this package; LICENSE.md and composer.json state the current proprietary license. Git history is preserved.

The release archive regenerates the tracked diff, file inventory and archive checksums using the existing packaging tool. It does not include installed vendor dependencies or temporary test data.
