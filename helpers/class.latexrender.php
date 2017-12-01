<?php
/**
* LaTeX Rendering Class
* Copyright (C) 2003  Benjamin Zeiss <zeiss@math.uni-goettingen.de>
*
* This library is free software; you can redistribute it and/or
* modify it under the terms of the GNU Lesser General Public
* License as published by the Free Software Foundation; either
* version 2.1 of the License, or (at your option) any later version.
*
* This library is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
* Lesser General Public License for more details.
*
* You should have received a copy of the GNU Lesser General Public
* License along with this library; if not, write to the Free Software
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
* --------------------------------------------------------------------
* @author Benjamin Zeiss <zeiss@math.uni-goettingen.de>
* @author Ger Bruinsma

* @version 1.0.0-dev - Several things rewritten to match with PHP 5.5
* @package latexrender
*
*/

namespace ger\sciencebbcode\helpers;
class LatexRender {
	
	// ====================================================================================
	// Variable Definitions
	// ====================================================================================
	                      
	// paths are initialized when creating class instance
	public $_picture_path;
	public $_picture_path_httpd;	
	public $_tmp_dir;
	
	// i was too lazy to write mutator functions for every single program used
	// just access it outside the class or change it here if nescessary
	var $_latex_path = "/usr/bin/latex";
	var $_dvips_path = "/usr/bin/dvips";
	var $_convert_path = "/usr/bin/convert";
	var $_identify_path = "/usr/bin/identify";
	
	public $_formula_density = 116;
	public $_textStyle_formula_density = 116;
	public $_xsize_limit = 2000;
	public $_ysize_limit = 1500;
	public $_string_length_limit = 4000;
	public $_font_size = 10;
	public $_latexclass = "article"; //install extarticle class if you wish to have smaller font sizes
	public $_tmp_filename;
	public $_image_format = "png"; //change to gif if you prefer
    
	// this most certainly needs to be extended. in the long term it is planned to use
	// a positive list for more security. this is hopefully enough for now. i'd be glad
	// to receive more bad tags !
	public $_latex_tags_blacklist = array(
		"include","def","command","loop","repeat","open","toks","output","input",
		"catcode","name","^^",
		"\\every","\\errhelp","\\errorstopmode","\\scrollmode","\\nonstopmode","\\batchmode",
		"\\read","\\write","csname","\\newhelp","\\uppercase", "\\lowercase","\\relax","\\aftergroup",
		"\\afterassignment","\\expandafter","\\noexpand","\\special"
		);
	public $_errorcode = 0;
	public $_errorextra = "";
	
	
	// ====================================================================================
	// constructor
	// ====================================================================================
	
	/**
	* Initializes the class
	*
	* @param string path where the rendered pictures should be stored
	* @param string same path, but from the httpd chroot
	*/
	public function __construct($picture_path, $picture_path_httpd, $tmp_dir) {
		$this->_picture_path = $picture_path;
		$this->_picture_path_httpd = $picture_path_httpd;
		$this->_tmp_dir = $tmp_dir;
		$this->_tmp_filename = 'temp_'.md5(rand());

		if (!is_dir($this->_picture_path)) {
			mkdir($this->_picture_path, 0666, true);
		}
		if (!is_dir($this->_tmp_dir)) {
			mkdir($this->_tmp_dir, 0666, true);
		}
	}
	
	// ====================================================================================
	// public functions
	// ====================================================================================
	
	/**
	* Picture path Mutator function
	*
	* @param string sets the current picture path to a new location
	*/
	public function setPicturePath($name) {
		$this->_picture_path = $name;
	}
	
	/**
	* Picture path Mutator function
	*
	* @returns the current picture path
	*/
	public function getPicturePath() {
		return $this->_picture_path;
	}
	
	/**
	* Picture path HTTPD Mutator function
	*
	* @param string sets the current httpd picture path to a new location
	*/
	public function setPicturePathHTTPD($name) {
		$this->_picture_path_httpd = $name;
	}
	
	/**
	* Picture path HTTPD Mutator function
	*
	* @returns the current picture path
	*/
	public function getPicturePathHTTPD() {
		return $this->_picture_path_httpd;
	}
	
	/**
	* Tries to match the LaTeX Formula given as argument against the
	* formula cache. If the picture has not been rendered before, it'll
	* try to render the formula and drop it in the picture cache directory.
	*
	* @param string formula in LaTeX format
	* @returns the webserver based URL to a picture which contains the
	* requested LaTeX formula. If anything fails, the resultvalue is false.
	*/
	public function getFormulaURL($latex_formula, $useTextStyle) {

		// circumvent certain security functions of web-software which
		// is pretty pointless right here
		$latex_formula = preg_replace("/&gt;/i", ">", $latex_formula);
		$latex_formula = preg_replace("/&lt;/i", "<", $latex_formula);
		
		// last minute hack by Rogier om die nare <br>'s van invision eruit te slopen
		$latex_formula = str_replace("<br>","\n", $latex_formula);
		$latex_formula = str_replace("<br />","\n", $latex_formula);
		//---

		// avoid extremely huge formulas
		$latex_formula = substr($latex_formula,0,20*1024);
		
		$formula_hash = md5($latex_formula.chr($useTextStyle?2:1));

		$filename = $formula_hash.".".$this->_image_format;
		$full_path_filename = $this->getPicturePath()."/".$filename;

		if (is_file($full_path_filename)) {
			return $this->getPicturePathHTTPD()."/".$filename;
		} else {
			// security filter: reject too long formulas
			if (strlen($latex_formula) > $this->_string_length_limit) {
				$this->_errorcode = 1;
				return false;
			}
			
			// security filter: try to match against LaTeX-Tags Blacklist
			for ($i=0;$i<sizeof($this->_latex_tags_blacklist);$i++) {
				if (stristr($latex_formula,$this->_latex_tags_blacklist[$i])) {
					$this->_errorcode = 2;
					return false;
				}
			}
			
			// security checks assume correct formula, let's render it
			if ($this->renderLatex($latex_formula,$useTextStyle,$formula_hash)) {
				return $this->getPicturePathHTTPD()."/".$filename;
			} else {
				// uncomment if required
				// $this->_errorcode = 3;
				return false;
			}
		}
	}
	
	// ====================================================================================
	// private functions
	// ====================================================================================
	
	/**
	 * exec() is not something we like. 
	 * Use a whitelist for the primary commands and a blacklist for the arguments
	 */	 
	private function wbl_exec( $cmd, $args )
	{
		$whitelist_commands = array(
			$this->_identify_path,
			$this->_latex_path,
			$this->_dvips_path,
			$this->_convert_path,
		);
		if (! in_array($cmd, $whitelist_commands))
		{
			return false;
		}
		$blacklist_args = array(';', '|', '&', '>', '<', '`', '$', '~', '?');
		foreach ($blacklist_args as $bla) 
		{
			if (stristr($args, $bla)) 
			{
				return false;
			}
		}
		
		// Seems legit.
		$res = array();
		exec($cmd .' ' . $args, $res);
		return implode(" " , $res);		
	}
	
	
	/**
	* wraps a minimalistic LaTeX document around the formula and returns a string
	* containing the whole document as string. Customize if you want other fonts for
	* example.
	*
	* @param string formula in LaTeX format
	* @returns minimalistic LaTeX document containing the given formula
	*/
	private function wrap_formula($latex_formula, $useTextStyle) {
		
		$newCommands = "\\newcommand{\\startmatrix}{\\begin{matrix}}\n";
		$newCommands .= "\\newcommand{\\endmatrix}{\\end{matrix}}\n";

		$boldCodes = 'cfhknpqrz'; 
		$n = strlen($boldCodes);
		for ($i=0; $i<$n; $i++)
		{
			$s = strtolower($boldCodes[$i]);
			$r = strtoupper($s);
			$bo = "{"; // to avoid PHP string evaluation
			$bc = "}";
			$newCommands .= "\\newcommand$bo\\$s$s$bc$bo\\mathbb$bo$r$bc$bc\n"; // resolves to: \newcommand{\xx}{\mathbb{X}}
		}

		$string  = "\\documentclass[".$this->_font_size."pt]{".$this->_latexclass."}\n";
		$string .= "\\usepackage[latin1]{inputenc}\n";
		$string .= "\\usepackage{amsmath}\n";
		$string .= "\\usepackage{amsfonts}\n";
		$string .= "\\usepackage{amssymb}\n";
		$string .= "\\usepackage{epsf}\n";
		$string .= "\\usepackage[version=3]{mhchem}\n";
		$string .= "\\usepackage{epsfig}\n";
		$string .= "\\pagestyle{empty}\n";
		$string .= $newCommands;
		$string .= "\\begin{document}\n";
		$string .= $useTextStyle ? '$' : '\[';
		$string .= $latex_formula;
		$string .= $useTextStyle ? '$' : '\]';
		$string .= "\n\\end{document}\n";
		
		return $string;
	}
	
	/**
	* returns the dimensions of a picture file using 'identify' of the
	* imagemagick tools. The resulting array can be adressed with either
	* $dim[0] / $dim[1] or $dim["x"] / $dim["y"]
	*
	* @param string path to a picture
	* @returns array containing the picture dimensions
	*/
	private function getDimensions($filename) {

		$output=$this->wbl_exec($this->_identify_path,$filename);
		$result=explode(" ",$output);
		if (!is_array($result) || count($result)<3) return false;
		$dim=explode("x",$result[2]);
		$dim["x"] = $dim[0];
		$dim["y"] = $dim[1];
		
		return $dim;
	}
	
	/**
	* Renders a LaTeX formula by the using the following method:
	*  - write the formula into a wrapped tex-file in a temporary directory
	*    and change to it
	*  - Create a DVI file using latex (tetex)
	*  - Convert DVI file to Postscript (PS) using dvips (tetex)
	*  - convert, trim and add transparancy by using 'convert' from the
	*    imagemagick package.
	*  - Save the resulting image to the picture cache directory using an
	*    md5 hash as filename. Already rendered formulas can be found directly
	*    this way.
	*
	* @param string LaTeX formula
	* @returns true if the picture has been successfully saved to the picture
	*          cache directory
	*/
	private function renderLatex($latex_formula,$useTextStyle,$latex_hash) {
			
		$latex_document = $this->wrap_formula($latex_formula,$useTextStyle);
		
		$current_dir = getcwd();
		
		chdir($this->_tmp_dir);
		
		// create temporary latex file
		$fp = fopen($this->_tmp_dir."/".$this->_tmp_filename.".tex","a+");
		fputs($fp,$latex_document);
		fclose($fp);

		// create temporary dvi file
		$status_code = $this->wbl_exec($this->_latex_path," --interaction=nonstopmode ".$this->_tmp_filename.".tex");
				
		if (!$status_code) 
        { 
            $this->cleanTemporaryDirectory(); 
            chdir($current_dir); 
            $this->_errorcode = 4; 
            return false;    
        }
		// convert dvi file to postscript using dvips
		$status_code = $this->wbl_exec($this->_dvips_path," -E ".$this->_tmp_filename.".dvi"." -o ".$this->_tmp_filename.".ps");
		
		// imagemagick convert ps to image and trim picture
		$status_code = $this->wbl_exec($this->_convert_path," -density ".($useTextStyle?$this->_textStyle_formula_density:$this->_formula_density).
			" -quality 100 -trim -transparent '#FFFFFF' ".$this->_tmp_filename.".ps ".$this->_tmp_filename.".".$this->_image_format);
		// $status_code is empty, regardless if convert succeeded or not
		
		// test picture for correct dimensions
		$dim = $this->getDimensions($this->_tmp_filename.".".$this->_image_format);
		if ($dim===false) 
        { 
            $this->cleanTemporaryDirectory(); 
            chdir($current_dir); 
            $this->_errorcode = 7; 
            return false;
        }
		
		if ( ($dim["x"] > $this->_xsize_limit) or ($dim["y"] > $this->_ysize_limit)) 
        {
			$this->cleanTemporaryDirectory();
			chdir($current_dir);
			$this->_errorcode = 5;
			$this->_errorextra = ": " . $dim["x"] . "x" . number_format($dim["y"],0,"","");
			return false;
		}
		
		// copy temporary formula file to cahed formula directory
		$filename = $this->getPicturePath()."/".$latex_hash.".".$this->_image_format;
		
		$status_code = copy($this->_tmp_filename.".".$this->_image_format,$filename);
				
        $this->cleanTemporaryDirectory();
				
        if (!$status_code) 
        { 
            chdir($current_dir); 
            $this->_errorcode = 6; 
            return false;
        }
        chdir($current_dir);
				
        return true;
	}
	
	/**
	* Cleans the temporary directory
	*/
	private function cleanTemporaryDirectory() {
		$current_dir = getcwd();
		chdir($this->_tmp_dir);
		
		@unlink($this->_tmp_dir."/".$this->_tmp_filename.".tex");
		@unlink($this->_tmp_dir."/".$this->_tmp_filename.".aux");
		@unlink($this->_tmp_dir."/".$this->_tmp_filename.".log");
		@unlink($this->_tmp_dir."/".$this->_tmp_filename.".dvi");
		@unlink($this->_tmp_dir."/".$this->_tmp_filename.".ps");
		@unlink($this->_tmp_dir."/".$this->_tmp_filename.".".$this->_image_format);
		
		chdir($current_dir);
	}
	
}

// EoF