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
	 * Syntax Type
	 *
	 * @return string The type
	 */
	public function getType() {
		return 'substition';
	}

	/**
	 * Paragraph Type
	 *
	 * Defines how this syntax is handled regarding paragraphs:
	 * 'block'  - Open paragraphs need to be closed before plugin output
	 *
	 * @return string The paragraph type
	 * @see Doku_Handler_Block
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
		$this->Lexer->addSpecialPattern('<chef\b.*?>.*?<\/chef>', $mode, 'plugin_chef');
	}

	/**
	 * Handler to prepare matched data for the rendering process
	 *
	 * @param string $match The match of the syntax
	 * @param int    $state The state of the handler
	 * @param int    $pos The position in the document
	 * @param Doku_Handler    $handler The handler
	 * @return array Data for the renderer
	 */
	public function handle($match, $state, $pos, Doku_Handler &$handler) {
		$data = array();
		preg_match('/<chef ?(.*)>(.*)<\/chef>/ms', $match, $components);

		if ($components[1]) { // parse parameters
			preg_match_all('/\s*(\S+)="([^"]*)"\s*/', $components[1], $params, PREG_SET_ORDER);
			foreach ($params as $param) {
				array_shift($param);
				list($key, $value) = $param;
				switch ($key) {
				case 'refresh':
					$data['refresh'] = (int)$value;
					break;
				case 'format':
					$parts = explode('%', $value);
					foreach ($parts as $pos => $part) {
						if ($pos % 2 == 0) { // the start and every second part is pure character data
							$data['format'][] = $part;
						} else { // this is the stuff inside % %
							if (strpos($part, '|') !== false) { // is this a link?
								list($link, $title) = explode('|', $part, 2);
								$data['format'][] = array($link => $title);
							} else { // if not just store the name, we'll recognize that again because of the position
								$data['format'][] = $part;
							}
						}
					}
					break;
				}
			}
		}

		$data['query'] = trim($components[2]);
		// set default values
		if (!isset($data['refresh'])) $data['refresh'] = 14400;
		if (!isset($data['format'])) $data['format'] = array('', array('link' => 'title'), '');

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
	public function render($mode, Doku_Renderer &$renderer, $data) {
		$renderer->meta['date']['valid']['age'] =
			isset($renderer->meta['date']['valid']['age']) ?
				min($renderer->meta['date']['valid']['age'], $data['refresh']) :
				$data['refresh'];

		// Don't fetch the data for rendering metadata
		// But still do it for all other modes in order to support different renderers
		if ($mode == 'metadata') {
			return;
		}

		// execute the query
		try {
			$result = $this->api('/search/node', 'GET', array('q' => $data['query']));
		} catch (Exception $e) {
			$this->render_error($renderer, 'Chef: ' . $e->getMessage());
			return false;
		}

		if (!$result->total) {
			// not really an error
			$this->render_error($renderer, 'No results');
			return true;
		}

		$renderer->listu_open();
		foreach ($result->rows as $item) {
			$renderer->listitem_open(1);
			$renderer->listcontent_open();
			foreach ($data['format'] as $pos => $val) {
				if ($pos % 2 == 0) { // outside % %, just character data
					$renderer->cdata($val);

				} else { // inside % %, either links or other fields
					if (is_array($val)) { // arrays are links
						foreach ($val as $link => $title) {
							$link = $this->resolve_value($renderer, $item, $link);
							$title = $this->resolve_value($renderer, $item, $title);
							if ($link !== false && $title !== false) {
								$renderer->externallink($link, $title);
							}
						}
					} else { // just a field
						$val = $this->resolve_value($renderer, $item, $val);
						if ($val !== false) {
							$renderer->cdata($val);
						}
					}
				}
			}
			$renderer->listcontent_close();
			$renderer->listitem_close();
		}
		$renderer->listu_close();

		return true;

	}

	/**
	 * Walk over variable described in $format
	 * Test if the value really exists and if isn't a stdClass (can't be casted
	 * to string)
	 */
	private function resolve_value($renderer, $item, $format) {
		$depth = explode('.', $format);
		foreach ($depth as $i => $val) {
			if (!isset($item->$val)) {
				// for inexistent keys return empty string
				return '';
			}

			if (!$item instanceof stdClass) {
				$val = join('.', array_splice($depth, 0, $i + 1));
				$this->render_error($renderer, 'Chef: Error: The given attribute ' . $val . ' is not an object');
				return false;
			}
			$item = $item->$val;
		}
		if ($item instanceof stdClass) {
			$val = $format;
			$this->render_error($renderer, 'Chef: Error: The given attribute ' . $val . ' is an object');
			return false;
		}
		return (string)$item;
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

	/**
	 * Helper function for displaying error messages. Currently just adds a paragraph with emphasis and the error message in it
	 */
	private function render_error($renderer, $error) {
		$renderer->p_open();
		$renderer->emphasis_open();
		$renderer->cdata($error);
		$renderer->emphasis_close();
		$renderer->p_close();
	}
}

// vim:ts=4:sw=4:noet:
