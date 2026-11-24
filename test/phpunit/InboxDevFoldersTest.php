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
 * \file    inbox/test/phpunit/InboxDevFoldersTest.php
 * \ingroup inbox
 * \brief   Folders dev/ and test/ of a Git deployment (1.135.0): detection for the setup page, probes, CLI guards
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once dirname(__DIR__, 2).'/lib/inbox.lib.php';

/**
 * Tests of inboxDevFolders() and of the files it relies on (no database, no mail server)
 */
class InboxDevFoldersTest extends InboxTestCase
{
	/** @var string */
	private $tmp = '';

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->tmp !== '' && is_dir($this->tmp)) {
			foreach (array('dev', 'test', '.git') as $f) {
				$path = $this->tmp.'/'.$f;
				if (is_dir($path)) {
					rmdir($path);
				} elseif (is_file($path)) {
					unlink($path);
				}
			}
			rmdir($this->tmp);
		}
		parent::tearDown();
	}

	/**
	 * Empty directory of a fake module
	 *
	 * @return string
	 */
	private function fakeModule()
	{
		$this->tmp = sys_get_temp_dir().'/inbox-devfolders-'.getmypid().'-'.mt_rand();
		mkdir($this->tmp);
		return $this->tmp;
	}

	/**
	 * Installed from the zip: nothing to tell
	 *
	 * @return void
	 */
	public function testZipInstallHasNoFolder()
	{
		$r = inboxDevFolders($this->fakeModule());
		$this->assertSame(array(), $r['folders']);
		$this->assertFalse($r['git']);
	}

	/**
	 * Git clone: both folders and .git (a folder)
	 *
	 * @return void
	 */
	public function testGitClone()
	{
		$dir = $this->fakeModule();
		mkdir($dir.'/dev');
		mkdir($dir.'/test');
		mkdir($dir.'/.git');
		$r = inboxDevFolders($dir.'/');
		$this->assertSame(array('dev', 'test'), $r['folders']);
		$this->assertTrue($r['git']);
	}

	/**
	 * Copy by a deployment tool (no .git), one folder left; worktree (.git is a file)
	 *
	 * @return void
	 */
	public function testCopyAndWorktree()
	{
		$dir = $this->fakeModule();
		mkdir($dir.'/test');
		$r = inboxDevFolders($dir);
		$this->assertSame(array('test'), $r['folders']);
		$this->assertFalse($r['git']);
		file_put_contents($dir.'/.git', "gitdir: /elsewhere\n");
		$this->assertTrue(inboxDevFolders($dir)['git']);
	}

	/**
	 * The probes read by js/inbox-setup.js start with their marker, and every PHP file of dev/ and test/ refuses the
	 * web (same check as the CI)
	 *
	 * @return void
	 */
	public function testProbesAndGuards()
	{
		$module = dirname(__DIR__, 2);
		foreach (array('dev', 'test') as $folder) {
			$probe = $module.'/'.$folder.'/web-probe.txt';
			$this->assertFileExists($probe);
			$this->assertStringStartsWith('inbox-web-probe', (string) file_get_contents($probe));
		}
		$out = array();
		$code = 0;
		exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($module.'/dev/check-cli-guard.php').' 2>&1', $out, $code);
		$this->assertSame(0, $code, implode("\n", $out));
	}
}
