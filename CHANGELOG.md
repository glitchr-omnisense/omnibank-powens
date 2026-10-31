# Changelog

## [Unreleased]

### Added

- Powens' words mapped onto omnisense/omnibank's (docs.powens.com): its account types onto
  `AccountKind` (`Account::$kind`), its connection states onto `ConnectionState` (an account's
  `error` as `Account::$state`, a webhook's connection as `Notification::$state`, an error answer's
  code as `ProviderException::$connectionState`), its categories - the French, Spanish and European
  trees, child then parent - and else its transaction types onto `TransactionCategory`
  (`BankTransaction::$category`). `Mapper::ACCOUNT_KINDS`, `CONNECTION_STATES`, `CATEGORIES`,
  `TYPE_CATEGORIES`, `kind()`, `connectionState()`, `category()`. A word not published is `UNKNOWN`.
- The `categories` option (false by default): transactions asked with `expand=categories`, where
  Powens' categorisation is enabled on the domain.

### Fixed

- Back from the Webview, `connect()` finishes the connection instead of opening the Webview again:
  the state's `return` (the return URL's query, put there by the application) with a
  `connection_id` keeps it in the state, makes the consent active and gives no URL; with an `error`,
  a `ProviderException` (`providerCode` the error, the `error_description` in its message).
  omnibase/ledger's return route called `connect()` with that query and was sent to the Webview
  again, every time: a bank connected through Powens never reached the books.
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
