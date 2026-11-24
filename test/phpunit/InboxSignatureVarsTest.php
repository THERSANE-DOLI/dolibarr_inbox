<?php
/* Copyright (C) 2026 THERSANE www.thersane.fr
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    inbox/test/phpunit/InboxSignatureVarsTest.php
 * \ingroup inbox
 * \brief   1.53.0: variables of a signature (identity, user who sends, company, other modules), nested ones, preview
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxtemplates.class.php');

/**
 * No mail server needed: the signature is only filled
 */
class InboxSignatureVarsTest extends InboxTestCase
{
	/** @var array<string,mixed> */
	const IDENTITY = array('id' => 7, 'name' => 'Ventes & Devis <B>', 'email' => 'devis@inbox-test.invalid', 'reply_to' => 'contact@inbox-test.invalid',
		'mailbox' => 'boite@inbox-test.invalid', 'mailbox_label' => 'Boîte commerciale', 'signature' => '');

	/**
	 * Values of the identity, a text escaped (a name with « & » or « < » stays text), an HTML value put as it is,
	 * an unknown variable kept
	 *
	 * @return void
	 */
	public function testFillHtml()
	{
		$values = InboxTemplates::identityValues(self::IDENTITY);
		$this->assertSame('Ventes & Devis <B>', $values['INBOX_IDENTITY_NAME']);
		$this->assertSame('boite@inbox-test.invalid', $values['INBOX_MAILBOX_EMAIL']);
		$this->assertSame('contact@inbox-test.invalid', $values['INBOX_IDENTITY_REPLYTO']);
		$this->assertSame('Boîte commerciale', $values['INBOX_MAILBOX_LABEL']);

		$values['MODULE_SIGNATURE'] = '<b>__INBOX_IDENTITY_NAME__</b>';
		$html = '<p>__INBOX_IDENTITY_NAME__ <a href="mailto:__INBOX_IDENTITY_EMAIL__">__INBOX_IDENTITY_EMAIL__</a> __UNKNOWN_KEY__ __CHECK_READ__</p>';
		$once = InboxTemplates::fillHtml($html, $values);
		$this->assertStringContainsString('Ventes &amp; Devis &lt;B&gt;', $once);
		$this->assertStringContainsString('href="mailto:devis@inbox-test.invalid"', $once);
		$this->assertStringContainsString('__UNKNOWN_KEY__', $once, 'unknown: kept, the preview names it');
		$this->assertStringNotContainsString('CHECK_READ', $once, 'tracking picture never put');

		// Two variables side by side are two variables (not one unknown « A____B »)
		$this->assertSame('devis@inbox-test.invalidboite@inbox-test.invalid', InboxTemplates::fillHtml('__INBOX_IDENTITY_EMAIL____INBOX_MAILBOX_EMAIL__', $values));

		// Nested: a signature of another module holding a variable of the identity, two passes
		$nested = InboxTemplates::fillHtml(InboxTemplates::fillHtml('<div>__MODULE_SIGNATURE__</div>', $values), $values);
		$this->assertSame('<div><b>Ventes &amp; Devis &lt;B&gt;</b></div>', $nested);
	}

	/**
	 * The signatures of the writing: variables of the identity and of the user who sends replaced, a signature without
	 * variable left as it is
	 *
	 * @return void
	 */
	public function testSignatures()
	{
		global $user;

		$a = self::IDENTITY;
		$a['signature'] = '<p>__INBOX_IDENTITY_NAME__ / __USER_EMAIL__ / __MYCOMPANY_NAME__</p>';
		$b = self::IDENTITY;
		$b['id'] = 8;
		$b['signature'] = '<p>Sans variable</p>';
		$tpl = new InboxTemplates($this->db);
		$out = $tpl->signatures($user, array($a, $b));
		$this->assertStringContainsString('Ventes &amp; Devis &lt;B&gt; / ', $out[7]);
		$this->assertStringNotContainsString('__USER_EMAIL__', $out[7]);
		$this->assertStringNotContainsString('__MYCOMPANY_NAME__', $out[7]);
		$this->assertSame('<p>Sans variable</p>', $out[8]);
	}

	/**
	 * Preview of the dialog of an identity: filled, the unknown variables named, the keys offered (identity first)
	 *
	 * @return void
	 */
	public function testPreview()
	{
		global $user;

		$i = self::IDENTITY;
		$i['signature'] = '<p>__INBOX_IDENTITY_EMAIL__ __TYPO_KEY__</p>';
		$r = (new InboxTemplates($this->db))->signaturePreview($user, $i);
		$this->assertStringContainsString('devis@inbox-test.invalid', $r['html']);
		$this->assertSame(array('TYPO_KEY'), $r['unknown']);
		foreach (InboxTemplates::IDENTITY_KEYS as $k) {
			$this->assertContains($k, $r['keys']);
		}
		$this->assertContains('USER_FULLNAME', $r['keys']);
		// What each key gives, for the picker (1.53.3): plain text, every key; a secret-looking key never shown
		$this->assertSame('devis@inbox-test.invalid', $r['values']['INBOX_IDENTITY_EMAIL']);
		$this->assertSame($r['keys'], array_keys($r['values']));
		foreach ($r['values'] as $k => $v) {
			$this->assertTrue($v === null || (strpos($v, '<') === false && mb_strlen($v) <= 80), $k);
		}
		foreach (array('MYMODULE_API_TOKEN', 'SMTP_PASSWORD', 'OAUTH_SECRET', 'STRIPE_KEY', 'APIKEY', 'USER_PASS') as $k) {
			$this->assertTrue(InboxTemplates::secretKey($k), $k);
		}
		foreach (array('USER_FULLNAME', 'MYCOMPANY_NAME', 'INBOX_IDENTITY_EMAIL', 'KEYWORDS', 'MONKEY_NAME') as $k) {
			$this->assertFalse(InboxTemplates::secretKey($k), $k);
		}
	}

	/**
	 * A template: the identity keys are known before the other modules complete the array (a substitution of a module
	 * holding __INBOX_IDENTITY_NAME__ gets it), and __SENDEREMAIL_SIGNATURE__ comes filled
	 *
	 * @return void
	 */
	public function testTemplateValues()
	{
		global $user;

		$i = self::IDENTITY;
		$i['signature'] = '<p>Signé __INBOX_IDENTITY_NAME__</p>';
		$values = (new InboxTemplates($this->db))->values($user, array('identity' => $i, 'mailbox' => $i['email']));
		$this->assertSame('Ventes & Devis <B>', $values['INBOX_IDENTITY_NAME']);
		$this->assertSame('boite@inbox-test.invalid', $values['INBOX_MAILBOX_EMAIL']);
		$this->assertSame('<p>Signé Ventes &amp; Devis &lt;B&gt;</p>', $values['SENDEREMAIL_SIGNATURE']);
	}
}
