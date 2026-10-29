# Changelog

## [Unreleased]

### Fixed

- `notify()` refuses a webhook signed more than `webhook_tolerance` seconds (new option, 300 by
  default) from now, and takes only the signature Powens documents, base64 of the HMAC-SHA256 of
  `POST.<webhook path>.<BI-Signature-Date>.<body>`, compared exactly: the date was signed but never
  compared with the time, the HMAC of the body alone (base64 or hex, covering no date) was taken
  too, and a base64 signature was compared in lower case as well - a captured webhook could be sent
  again. `webhook_url` is now needed for `notify()`; `Api::signatures()` returns that one signature
  (none without a date or a URL). Unverified live: written from Powens' documentation.
