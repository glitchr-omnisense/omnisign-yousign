# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnisign Yousign: simple, advanced and qualified electronic signatures through Yousign's public API v3 (Youtrust since its new name) - a signature request built from documents, signers and fields, activated, followed, reminded, cancelled; signing in the application's page; the signed documents and the audit trail; webhooks with their signature checked.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnisign: ^1.0`: stable versions, so `composer require omnisign/yousign` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
