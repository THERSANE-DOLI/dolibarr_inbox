#!/usr/bin/env python3
"""Put the reading corpus of lot W3 in the local test Dovecot of inbox (perso@inbox-test.invalid): HTML with remote
pictures and a tracker, picture of the body (cid:), misleading link, PDF, text, CSV, zip, EICAR test file, report of non
delivery, display name imitating the company, attached mail, encrypted mail. Test data only, never a real mailbox."""
import base64, email.utils, imaplib, io, time, zipfile

TO = 'perso@inbox-test.invalid'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAF0lEQVR4nGNgYGBgaGhgYGBgYGD4TwABmQMBgVpSegAAAABJRU5ErkJggg==')
PDF = b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj 4 0 obj<</Length 44>>stream\nBT /F1 18 Tf 30 70 Td (Devis inbox test) Tj ET\nendstream endobj 5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'
EICAR = b'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'


def b64(data):
    return '\r\n'.join(base64.b64encode(data).decode()[i:i + 76] for i in range(0, len(base64.b64encode(data)), 76))


def head(mid, frm, subject, ctype, extra=''):
    return (f'Message-ID: <{mid}@inbox-test.invalid>\r\nFrom: {frm}\r\nTo: {TO}\r\nSubject: {subject}\r\n'
            f'Date: {email.utils.formatdate(localtime=True)}\r\nMIME-Version: 1.0\r\n{extra}Content-Type: {ctype}\r\n\r\n')


def part(ctype, data, name='', cid='', inline=False):
    h = f'Content-Type: {ctype}' + (f'; name="{name}"' if name else '') + '\r\nContent-Transfer-Encoding: base64\r\n'
    if cid:
        h += f'Content-ID: <{cid}>\r\n'
    if name:
        h += f'Content-Disposition: {"inline" if inline else "attachment"}; filename="{name}"\r\n'
    return h + '\r\n' + b64(data) + '\r\n'


def multipart(kind, parts, boundary):
    return f'This is a multi-part message.\r\n' + ''.join(f'--{boundary}\r\n{p}' for p in parts) + f'--{boundary}--\r\n'


zbuf = io.BytesIO()
with zipfile.ZipFile(zbuf, 'w') as z:
    z.writestr('plan/plan-collecteur.pdf', PDF)
    z.writestr('notes.txt', 'Notes du chantier\n')

html1 = ('<html><head><style>.sig{color:#1d6a8a}</style></head><body><p>Bonjour,</p><p>Voici notre <b>lettre</b> de novembre.</p>'
         '<p><img src="https://images.example.invalid/banner.png" width="600"></p>'
         '<p><a href="https://phishing.example.invalid/login">https://www.banque-exemple.fr/compte</a> (lien trompeur)</p>'
         '<p><a href="https://www.example.org/offre">Voir l\'offre</a></p>'
         '<p class="sig"><img src="cid:logo@inbox"> Le service client</p>'
         '<img src="https://track.example.invalid/open?id=42" width="1" height="1">'
         '<script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)">js</a></body></html>')

B = 'ibx-b1'
R = 'ibx-r1'
mails = [
    head('corpus-html', 'Lettre <news@shop.example.invalid>', 'Lettre de novembre (images, traceur, lien trompeur)', f'multipart/related; boundary="{R}"',
         'List-Unsubscribe: <mailto:unsub@shop.example.invalid>\r\nAuthentication-Results: mx.inbox-test.invalid; spf=pass smtp.mailfrom=shop.example.invalid; dkim=pass; dmarc=pass\r\n')
    + multipart('related', [part('text/html; charset=utf-8', html1.encode()), part('image/png', PNG, 'logo.png', 'logo@inbox', True)], R),
    head('corpus-att', 'Jean Dupont <j.dupont@acme.invalid>', 'Devis DN50 avec pièces jointes', f'multipart/mixed; boundary="{B}"')
    + multipart('mixed', [part('text/plain; charset=utf-8', 'Bonjour,\r\nCi-joint le plan, la liste et les photos. Voir https://www.example.org/plan\r\n'.encode()),
                          part('application/pdf', PDF, 'plan-collecteur.pdf'), part('image/png', PNG, 'photo-chantier.png'),
                          part('text/csv', 'Ref;Qte;Prix\r\nDN50;12;"14,20"\r\nDN25;40;"6,10"\r\n'.encode(), 'liste.csv'),
                          part('application/zip', zbuf.getvalue(), 'dossier.zip'),
                          part('application/octet-stream', b'MZ fake program', 'facture.pdf.exe')], B),
    head('corpus-virus', 'Inconnu <x@unknown.example.invalid>', 'Facture impayée (fichier de test antivirus)', f'multipart/mixed; boundary="{B}"')
    + multipart('mixed', [part('text/plain; charset=utf-8', 'Voir la pièce jointe.'.encode()), part('application/octet-stream', EICAR, 'facture.com')], B),
    head('corpus-dsn', 'Mail Delivery System <mailer-daemon@inbox-test.invalid>', 'Undelivered Mail Returned to Sender', f'multipart/report; report-type=delivery-status; boundary="{B}"')
    + f'--{B}\r\nContent-Type: text/plain\r\n\r\nThe mail could not be delivered.\r\n--{B}\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.inbox-test.invalid\r\n\r\n'
      f'Final-Recipient: rfc822; nobody@client.example.invalid\r\nAction: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 5.1.1 <nobody@client.example.invalid>: Recipient address rejected: User unknown\r\n--{B}--\r\n',
    head('corpus-imitation', 'Direction Inbox Test <boss.urgent@gmail.example.invalid>', 'Virement urgent à faire aujourd\'hui', 'text/plain; charset=utf-8')
    + 'Merci de faire un virement urgent, je suis en réunion.\r\n',
    head('corpus-fwd', 'Marie <marie@client.example.invalid>', 'Tr: mail joint', f'multipart/mixed; boundary="{B}"')
    + multipart('mixed', [part('text/plain; charset=utf-8', 'Je vous transfère le mail ci-joint.'.encode()),
                          'Content-Type: message/rfc822; name="origine.eml"\r\nContent-Disposition: attachment; filename="origine.eml"\r\n\r\n'
                          + head('corpus-inner', 'Paul <paul@x.example.invalid>', 'Mail d\'origine', 'text/html; charset=utf-8') + '<p>Bonjour, <b>texte du mail d\'origine</b>.</p>\r\n'], B),
    head('corpus-smime', 'Sécurité <secure@bank.example.invalid>', 'Mail chiffré', 'application/pkcs7-mime; smime-type=enveloped-data; name="smime.p7m"',
         'Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename="smime.p7m"\r\n') + b64(b'not really encrypted, only the type') + '\r\n',
]

m = imaplib.IMAP4('127.0.0.1', 10143)
m.login(TO, 'perso-test-pass')
for raw in mails:
    m.append('INBOX', '', imaplib.Time2Internaldate(time.time()), raw.encode('utf-8'))
m.logout()
print('corpus: %d mails' % len(mails))
