<?php
/**
 * DokuWiki Plugin Chef (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Elan Ruusamäe <glen@delfi.ee>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_chef extends DokuWiki_Syntax_Plugin {
	/**
	 * @return string Syntax mode type
	 */
	public function getType() {
		return 'substition';
	}

	/**
	 * @return string Paragraph type
	 */
	public function getPType() {
		return 'block';
	}

	/**
	 * @return int Sort order - Low numbers go before high numbers
	 */
	public function getSort() {
		return 306;
	}

	/**
	 * Connect lookup pattern to lexer.
	 *
	 * @param string $mode Parser mode
	 */
	public function connectTo($mode) {
		$this->Lexer->addSpecialPattern('\{\{chef>.+?\}\}', $mode, 'plugin_chef');
	}

	/**
	 * Handle matches of the chef syntax
	 *
	 * @param string $match The match of the syntax
	 * @param int    $state The state of the handler
	 * @param int    $pos The position in the document
	 * @param Doku_Handler    $handler The handler
	 * @return array Data for the renderer
	 */
	public function handle($match, $state, $pos, &$handler) {
		$data = array();
		$raw = substr($match, 7, -2);

		$data = array('q' => $raw);
		return $data;
	}

	/**
	 * Render xhtml output or metadata
	 *
	 * @param string         $mode      Renderer mode (supported modes: xhtml)
	 * @param Doku_Renderer  $renderer  The renderer
	 * @param array          $data      The data from the handler() function
	 * @return bool If rendering was successful.
	 */
	public function render($mode, &$renderer, $data) {
		if ($mode != 'xhtml') {
			return false;
		}

		// for debug
		$renderer->info['cache'] = false;

		try {
			$res = $this->api('/search/node', 'GET', array('q' => $data['q']));
		} catch (Exception $e) {
			msg('chef: ' . $e->getMessage(), -1);
			return false;
		}

		echo "Search: <b>", $res->total, "</b> matches: <pre>";
		print_r($res);
		echo "</pre>";

		return true;
	}

	/**
	 * Chef API calls.
	 *
	 * @param  string  $endpoint
	 * @param  mixed   $data
	 * @param  string  $method
	 * @throws RuntimeException
	 * @return mixed
	 */
	private function api($endpoint, $method = 'GET', $data = false) {
		static $chef;
		if (!$chef) {
			require_once dirname(__FILE__) . '/vendor/autoload.php';
			$config = $this->getConf('api');
			$chef = new \Jenssegers\Chef\Chef($config['server'], $config['client'], $config['key'], $config['version']);
		}

		$res = $chef->api($endpoint, $method, $data);
		if (isset($res->error)) {
			$error = join(', ', $res->error);
			throw new RuntimeException($error);
		}
		return $res;
	}
}

// vim:ts=4:sw=4:noet:
