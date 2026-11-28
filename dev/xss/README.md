# Corpus of the audit « XSS from an outside mail »

`corpus.py` writes 6 trapped mails (35 attacks, each with its own mark `window.__xss`) in `eml/`, and with
`--append` puts them in perso@inbox-test.invalid of the local test Dovecot (`dev/dovecot/run.sh`). Test data only.
Steps of the audit, results and setup (receiving server mx.inbox-test.invalid, source « quotation » for
forms.xss.example): analysis analyse-assainissement-xss.md and the audit report of 2026-11-14.
