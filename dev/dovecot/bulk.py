#!/usr/bin/env python3
"""Put N test mails in the local test Dovecot of inbox (perso@inbox-test.invalid), spread over folders, with encoded
subjects, attachments, threads and some malformed headers. Test data only. Usage: python3 bulk.py [N]"""
import imaplib, sys, time, random, email.utils, base64
n = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
random.seed(42)
m = imaplib.IMAP4('127.0.0.1', 10143)
m.login('perso@inbox-test.invalid', 'perso-test-pass')
folders = ['INBOX', 'INBOX.Archives.2025', 'INBOX.Archives.2024', 'INBOX.Clients', 'INBOX.Sent']
for f in folders[1:]:
    m.create(f)
names = ['Jean Dupont', 'Marie Curie', 'Ça va Société', 'Büro Müller', 'O\'Brien']
now = time.time()
for i in range(n):
    folder = folders[i % len(folders)]
    who = names[i % len(names)]
    date = now - (n - i) * 3600
    subject = '=?UTF-8?B?' + base64.b64encode(('Demande n°%d été' % i).encode()).decode() + '?=' if i % 3 == 0 else 'Demande %d' % i
    refs = '' if i < 10 or i % 4 else 'In-Reply-To: <bulk-%d@inbox-test.invalid>\r\nReferences: <bulk-%d@inbox-test.invalid>\r\n' % (i - 1, i - 1)
    head = 'Message-ID: <bulk-%d@inbox-test.invalid>\r\nFrom: %s <client%d@example.invalid>\r\nTo: perso@inbox-test.invalid\r\nSubject: %s\r\nDate: %s\r\n%sMIME-Version: 1.0\r\n' % (i, who, i % 50, subject, email.utils.formatdate(date, localtime=True), refs)
    if i % 997 == 0:
        head += 'X-Bad: \x01\x02 ' + 'A' * 5000 + '\r\nTo: ' + ', '.join('x%d@example.invalid' % k for k in range(300)) + '\r\n'
    if i % 5 == 0:
        body = 'Content-Type: multipart/mixed; boundary="b"\r\n\r\n--b\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBonjour %d\r\n--b\r\nContent-Type: application/pdf; name="devis-%d.pdf"\r\nContent-Disposition: attachment; filename="devis-%d.pdf"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n--b--\r\n' % (i, i, i)
    else:
        body = 'Content-Type: text/plain; charset=utf-8\r\n\r\nBonjour %d, merci.\r\n' % i
    flags = '(\\Seen)' if i % 2 else ''
    m.append(folder, flags, imaplib.Time2Internaldate(date), (head + body).encode('utf-8', 'surrogateescape'))
m.logout()
print('appended', n)
