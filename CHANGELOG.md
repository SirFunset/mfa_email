# Changelog

## 12.0.0 (unreleased)

### Changed
- Publish the maintained fork as `funset/mfa-email`, with the package major
  matching TYPO3 12; retain the extension key, provider identifier, and PHP namespace.
- Update project/support links and credit Benno Nijholt alongside Ralf Freit.
- Add tested, opt-in GitHub release automation and Packagist indexing verification.

### Security
- Reject empty codes, compare codes in constant time, and invalidate successful codes.
- Expire codes after 15 minutes by default; regenerate legacy codes without timestamps.
- Fall back to six failed attempts and safe expiry defaults for missing/invalid settings.
- Clear codes on lockout or provider changes; do not send for locked/inactive providers
  or after a failed attempt to save a newly generated code.
- Reject malformed email/authentication input without array-to-string warnings.

See `Documentation/Releasing.md` for the TYPO3 12 core security/release limitation.
