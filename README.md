# TYPO3 Extension ``mfa_email``

This extension adds the E-Mail MFA provider to TYPO3.

Maintained fork: [SirFunset/mfa_email](https://github.com/SirFunset/mfa_email),
published as `funset/mfa-email`. Originally developed by Ralf Freit in
[MrSilaz/mfa_email](https://github.com/MrSilaz/mfa_email).

## Compatibility

| Extension version | Branch     | TYPO3    | PHP  |
|-------------------|------------|----------|------|
| 14.x              | main       | 14.3 LTS | 8.2+ |
| 13.x              | main-v13   | 13.4 LTS | 8.2+ |
| 12.x              | main-v12   | 12.4 LTS | 8.1+ |

Major versions follow TYPO3's major version. TYPO3 11 is not supported by this fork.

## Installation

You can install the extension via composer 

`composer require funset/mfa-email:^12.0`

This fork must first be registered and released on Packagist. The upstream TER
listing does not distribute this fork. To migrate from upstream:

```sh
composer remove ralffreit/mfa-email --no-update
composer require funset/mfa-email:^12.0 --with-all-dependencies
```

The extension key (`mfa_email`), provider identifier (`email`), PHP namespace,
templates, and existing MFA settings remain unchanged. Both packages must not be
installed together; Composer explicitly prevents this. Existing codes without a
creation timestamp expire on upgrade and are regenerated on the next login.

See [release setup and maintenance](Documentation/Releasing.md) for publishing,
automatic patch releases, minor releases, and backporting.

## About Mail MFA

With the mail-based authentication code, you can increase the security of your accounts by requesting a six-digit code every time you log in. Our system sends an email with a secret code to your email address.

Each authentication code is valid only once and expires after a configurable validity period (15 minutes by default).

Setting up:
1. Enter your email address.
2. Please check your email address twice.
3. Submit the form to activate the MFA email provider.

## Available languages

- German
- English
