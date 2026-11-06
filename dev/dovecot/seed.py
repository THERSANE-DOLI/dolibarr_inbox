#!/usr/bin/env python3
"""Put a few test mails in the local test Dovecot of inbox (run.sh). Test data only."""
import imaplib, time, email.utils
def mail(mid, frm, subj, body, extra=''):
    return (f"Message-ID: <{mid}>\r\nFrom: {frm}\r\nTo: devis@inbox-test.invalid\r\nSubject: {subj}\r\n"
            f"Date: {email.utils.formatdate(localtime=True)}\r\nMIME-Version: 1.0\r\n{extra}"
            f"Content-Type: text/plain; charset=utf-8\r\n\r\n{body}\r\n").encode()
m = imaplib.IMAP4('127.0.0.1', 10143)
m.login('devis@inbox-test.invalid', 'devis-test-pass')
m.append('INBOX', '', imaplib.Time2Internaldate(time.time()), mail('seed-1@inbox-test.invalid', 'Jean Dupont <j.dupont@acme.invalid>', 'Demande de prix raccords inox DN25', 'Bonjour, merci de chiffrer.'))
m.append('INBOX', '', imaplib.Time2Internaldate(time.time()), mail('seed-2@inbox-test.invalid', 'Marie <marie@client.invalid>', 'Consultation réseau chauffage', 'Merci de répondre avant le 20/11.'))
m.append('INBOX.Archives', '(\\Seen)', imaplib.Time2Internaldate(time.time()), mail('seed-3@inbox-test.invalid', 'Paul <paul@x.invalid>', 'Ancienne commande', 'Archivée.'))
m.append('INBOX.Sent', '(\\Seen)', imaplib.Time2Internaldate(time.time()), mail('seed-4@inbox-test.invalid', 'devis@inbox-test.invalid', 'Re: Demande de prix', 'Voici notre offre.'))
m.create('"INBOX.Clients &AOk-t&AOk-"')  # "Clients été" in modified UTF-7
m.logout()
print('seeded')
