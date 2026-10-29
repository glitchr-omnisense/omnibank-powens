# Changelog

## [Unreleased]

### Fixed

- An `UnavailableException` no longer carries the client secret and the user's token. The message
  quoted the HTTP client's (the whole URL), the previous was the client's own exception, and every
  trace of the chain kept the arguments of the calls under it (the request's options, the headers
  with the credentials, the token given to `call()`) when `zend.exception_ignore_args` is off: a log
  of the whole chain wrote them. The message masks them as `***`, the previous is a copy of the
  client's exception (the same class and code), and no exception `Api` throws keeps its trace's
  arguments (`Omnibank\Http\Redaction`).
- `notify()` refuses a webhook signed more than `webhook_tolerance` seconds (new option, 300 by
  default) from now, and takes only the signature Powens documents, base64 of the HMAC-SHA256 of
  `POST.<webhook path>.<BI-Signature-Date>.<body>`, compared exactly: the date was signed but never
  compared with the time, the HMAC of the body alone (base64 or hex, covering no date) was taken
  too, and a base64 signature was compared in lower case as well - a captured webhook could be sent
  again. `webhook_url` is now needed for `notify()`; `Api::signatures()` returns that one signature
  (none without a date or a URL). Unverified live: written from Powens' documentation.
