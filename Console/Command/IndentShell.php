<?php
//Configure::write('debug', 1);

if (!defined('TB')) {
	define('TB', "\t");
}
if (!defined('NL')) {
	define('NL', "\n");
}
if (!defined('CR')) {
	define('CR', "\r");
}
App::uses('Folder', 'Utility');
App::uses('AppShell', 'Console/Command');

/**
 * Indend Shell
 *
 * @cakephp 2.x
 * @author Mark Scherer
 * @license MIT
 * 2011-11-04 ms
 */
class IndentShell extends AppShell {

	protected $changes = null;

	public $settings = array(
		'files' => array('php', 'ctp', 'inc', 'tpl'),
		'againWithHalf' => true, # if 4, go again with 2 afterwards
		'outputToTmp' => false, # write to filename_.ext
		'debug' => false # add debug info after each line
	);

	protected $_paths = array();
	protected $_files = array();

	/**
	 * Main execution function to indend a folder recursivly
	 *
	 * @return void
	 */
	public function folder() {
		if (!empty($this->params['extensions'])) {
			$this->settings['files'] = String::tokenize($this->params['extensions']);
		}

		if (!empty($this->args)) {
			if (!empty($this->args[0]) && $this->args[0] !== 'app') {
				$folder = $this->args[0];
				if ($folder === '/') {
					$folder = APP;
				}

				$folder = realpath($folder);
				if (!file_exists($folder)) {
					$this->error('folder not exists: ' . $folder . '');
				}
				$this->_paths[] = $folder;
			} elseif ($this->args[0] === 'app') {
				$this->_paths[] = APP;
			}

			if (!empty($this->params['files'])) {
				$this->settings['files'] = explode(',', $this->params['files']);
			}

			$this->out($folder);
			$this->out('searching...');
			$this->_searchFiles();

			$this->out('found: ' . count($this->_files));
			if (!empty($this->params['dry-run'])) {
				$this->out('TEST DONE');
			} else {
				$continue = $this->in(__('Modifying files! Continue?'), array('y', 'n'), 'n');
				if (strtolower($continue) !== 'y' && strtolower($continue) !== 'yes') {
					$this->error('...aborted');
				}

				$this->_correctFiles();
				$this->out('DONE');
			}

		} else {
			$this->out('Usage: cake intend folder');
			$this->out('"folder" is then intended recursivly');
			$this->out('default file types are');
			$this->out('['.implode(', ', $this->settings['files']).']');

			$this->out('');
			$this->out('Specify file types manually:');
			$this->out('-files php,js,css');
		}
	}

	protected function _write($file, $text) {
		$text = implode(PHP_EOL, $text);
		if ($this->settings['outputToTmp']) {
			$filename = extractPathInfo('file', $file);
			if (mb_substr($filename, -1, 1) === '_') {
				return;
			}
			$file = extractPathInfo('dir', $file).DS.$filename.'_.'.extractPathInfo('ext', $file);
		}
		return file_put_contents($file, $text);
	}

	protected function _read($file) {
		$text = file_get_contents($file);
		if (empty($text)) {
			return array();
		}
		$pieces = explode(NL, $text);
		return $pieces;
	}


	/**
	 * NEW TRY!
	 * idea: just count spaces and replace those
	 *
	 * 2010-09-12 ms
	 */
	protected function _correctFiles() {
		foreach ($this->_files as $file) {
			$this->changes = false;
			$textCorrect = array();

			$pieces = $this->_read($file);
			$spacesPerTab = $this->params['spaces'];

			foreach ($pieces as $piece) {
				$tmp = $this->_process($piece, $spacesPerTab);
				if ($this->settings['againWithHalf'] && $spacesPerTab % 2 === 0 && $spacesPerTab > 3) {
					$tmp = $this->_process($tmp, $spacesPerTab/2);
				}
				$tmp = $this->_processSpaceErrors($tmp, 1);
				$textCorrect[] = $tmp;
			}

			if ($this->changes) {
				$this->_write($file, $textCorrect);
			}
		}
	}

	/**
	 * @return string
	 */
	protected function _process($piece, $spacesPerTab) {
		$pos = -1;
		$spaces = $mod = $tabs = 0;
		$debug = '';

		$newPiece = $piece;
		if ($spacesPerTab) {
			//TODO
			while (mb_substr($piece, $pos+1, 1) === ' ' || mb_substr($piece, $pos + 1, 1) === TB) {
				$pos++;
			}
			$piece1 = mb_substr($piece, 0, $pos + 1);
			$piece1 = str_replace(str_repeat(' ', $spacesPerTab), TB, $piece1, $count);
			if ($count > 0) {
				$this->changes = true;
			}

			$piece2 = mb_substr($piece, $pos+1);

			$newPiece = $piece1 . $piece2;
		}

		$newPiece = rtrim($newPiece) . $debug;
		if ($newPiece != $piece || strlen($newPiece) !== strlen($piece)) {
			$this->changes = true;
		}
		return $newPiece;
	}



	/**
	 * NEW TRY!
	 * idea: hardcore replaceing
	 *
	 * @deprecated
	 * 2010-09-12 ms
	 */
	protected function _processSpaceErrors($piece) {
		$space = 1;

		$newPiece = $piece;
		if (mb_substr($piece, 0, $space) === ' ' && mb_substr($piece, $space, 1) === TB) {
			$newPiece = mb_substr($piece, $space);
		}
		if ($newPiece != $piece || strlen($newPiece) !== strlen($piece)) {
			$this->changes = true;
		}
		return $newPiece;
	}

	/**
	 * Old try - sometimes TABS at the beginning are not recogized...
	 * idea: strip tabs and spaces, remember their amount and add tabs again!
	 *
	 * @deprecated
	 * 2010-09-12 ms
	 */
	protected function _correctFilesTry() {
		foreach ($this->_files as $file) {
			$changes = false;
			$textCorrect = array();

			$pieces = $this->_read($file);
			foreach ($pieces as $piece) {
				$pos = -1;
				$spaces = $mod = $tabs = 0;
				$debug = '';

				$newPiece = trim($piece, CR);
				$newPiece = trim($newPiece, NL);
				//$debug .= ''.stripos($newPiece, TB);

				# detect tabs and whitespaces at the beginning
				//while (($pieceOfString = mb_substr($newPiece, 0, 1)) === ' ' || ($pieceOfString = mb_substr($newPiece, 0, 1)) == TB) {
				while ((stripos($newPiece, ' ')) === 0 || (stripos($newPiece, TB)) === 0) {
					$pieceOfString = mb_substr($newPiece, 0, 1);
					if ($pieceOfString === ' ') {
						$pos++;
						$spaces++;
					} elseif ($pieceOfString === TB) {
						$pos++;
						$spaces += $this->settings['spacesPerTab'];
					} else {
						$this->error('???');
					}

					$newPiece = mb_substr($newPiece, 1);
				}

				if ($pos >= 1) {
					$changes = true;

					# if only spaces and tabs, we might as well trim the line
					//should be done

					# now correct
					//$newPiece = mb_substr($piece, $pos + 1);

					# clear single spaces
					/*
					if (mb_substr($newPiece, 0, 1) === ' ' && mb_substr($newPiece, 1, 1) !== '*') {
						$newPiece = mb_substr($newPiece, 1);
					}
					*/


					$mod = $spaces % $this->settings['spacesPerTab'];
					$tabs = ($spaces - $mod) / $this->settings['spacesPerTab'];



					//$beginning = str_replace('  ', TB, $piece);
					$beginning = str_repeat(TB, $tabs);
					$beginning .= str_repeat(' ', $mod);
					$newPiece = $beginning . trim($newPiece);
				} else {
					$newPiece = rtrim($newPiece);
				}

				if ($this->settings['debug']) {
					$debug .= ' '. ($changes ? '[MOD]': '[]') .' (SPACES '.$tabs.', POS '.$pos.', TABS '.$tabs.', MOD '.$mod.')';
				}
				$textCorrect[] = $newPiece . $debug;
			}
			if ($changes) {
				$this->_write($file, $textCorrect);
			}
			//die();
		}
	}

	/**
	 * Search files that may contain translateable strings
	 *
	 * @return void
	 */
	protected function _searchFiles() {
		foreach ($this->_paths as $path) {
			$Folder = new Folder($path);
			$files = $Folder->findRecursive('.*\.('.implode('|', $this->settings['files']).')', true);
			foreach ($files as $file) {
				if (strpos($file, DS . 'Vendor' . DS) !== false) {
					continue;
				}
				$this->_files[] = $file;
			}
		}
	}

	public function getOptionParser() {
		$subcommandParser = array(
			'options' => array(
				'dry-run'=> array(
					'short' => 'd',
					'help' => __d('cake_console', 'Dry run the update, no files will actually be modified.'),
					'boolean' => true
				),
				'log'=> array(
					'short' => 'l',
					'help' => __d('cake_console', 'Log all ouput to file log.txt in TMP dir'),
					'boolean' => true
				),
				'interactive'=> array(
					'short' => 'i',
					'help' => __d('cake_console', 'Interactive'),
					'boolean' => true
				),
				'spaces'=> array(
					'short' => 's',
					'help' => __d('cake_console', 'Spaces per Tab'),
					'default' => '4',
				),
				'extensions'=> array(
					'short' => 'e',
					'help' => __d('cake_console', 'Extensions (comma-separated)'),
					'default' => '',
				),
			)
		);

		return parent::getOptionParser()
			->description(__d('cake_console', "Correct indentation of files"))
			->addSubcommand('folder', array(
				'help' => __d('cake_console', 'Indent all files in a folder'),
				'parser' => $subcommandParser
			));
	}

}
