<?php

namespace ger\sciencebbcode\helpers;

class tex_helper 
{
	/**
	 * Primary function that's called by the TextFormatter class 
	 * We call ourselves here since TextFormatter requires a static function and we need non-static methods. 
	 * Kinda fuzzy, but better that adding more layers to the cake
	 *
	 * @param string $formula
	 * @return string
	 */
	static public function generateHash($formula = '')
	{
		// Since TextFormatter requires a static function, we call ourselves here
		// To be able to use non-static methods
		$tex_helper = new tex_helper();
		$tex_helper->ConvertTexToHtml($formula, 0);
		return md5($formula.chr(1));
	}

	/**
	 * Convert latex formula to image
	 * @param string $latex_formula
	 * @param int $doTextMode
	 */
	private function ConvertTexToHtml( $latex_formula, $doTextMode ) // tag = 'tex' of 'itex'
	{
		require_once(__DIR__."/class.latexrender.php");

		$tex_path = str_replace('ext/ger/sciencebbcode/helpers', 'images/latex', __DIR__) . '/pictures';
		$tex_path_tmp = str_replace('ext/ger/sciencebbcode/helpers', 'images/latex', __DIR__) . '/tmp';
		$tex_path_http = generate_board_url() . '/images/latex/pictures';

		$latexrender = new LatexRender($tex_path, $tex_path_http, $tex_path_tmp);

		// Undo any magically converted input
		$latex_formula = html_entity_decode($latex_formula);
		$latex_formula = preg_replace(array("/&#([0-9]+);/e","/&#x([0-9a-fA-F]+);/e"),array("chr('\\1')","chr(hexdec('\\1'))"),$latex_formula);
		$latex_formula = str_replace( array("<br>","<br/>","<br />") , array("\n","\n","\n") , $latex_formula );

		$url = $latexrender->getFormulaURL( $latex_formula , $doTextMode );
		if (!$url)
		{
			return "[unparseable or potentially dangerous latex formula]";
		}
		return "<img src='$url' title='LaTeX' alt='LaTeX' />";
	}

}