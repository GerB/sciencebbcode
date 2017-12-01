<?php
/**
 *
 * Collection of science BBcodes. An extension for the phpBB Forum Software package.
 * This implements [tex], [chem], and [doi] BBcode
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\sciencebbcode\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{


	static public function getSubscribedEvents()
	{
		return array(
			'core.text_formatter_s9e_configure_before'	=> 'pre_configure_text_formatter',
			'core.text_formatter_s9e_parse_after' 		=> 'add_doi_counter',
			'core.text_formatter_s9e_render_after' 		=> 'finalize_markup',
		);
	}
	protected $auth;

	public function __construct(\phpbb\auth\auth $auth)
	{
		$this->auth = $auth;
	}
    
	/**
	 * Whitelist callback functions for tex and chem BBcodes
	 *
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function pre_configure_text_formatter($event)
	{
		$usage = '[tex hash={ALNUM;useContent}]{TEXT}[/tex]';
		$template = '<img class="bbc_img" src="./images/latex/pictures/{@hash}.png" alt="LaTeX" />';
		$event['configurator']->BBCodes->addCustom($usage, $template);
		$event['configurator']->tags['tex']->attributes['hash']->filterChain->prepend('\ger\sciencebbcode\helpers\tex_helper::generateHash');

		$usage = '[chem formula={TEXT;useContent}]{TEXT}[/chem]';
		$template = '<chem>{@formula}</chem>';
		$event['configurator']->BBCodes->addCustom($usage, $template);
		$event['configurator']->tags['chem']->attributes['formula']->filterChain->prepend('\ger\sciencebbcode\helpers\chemrender::parse_chemcode');
	}

    /**
	 * Add counter to DOI links
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function add_doi_counter($event)
	{
		// Find doi-tags
		$counter = 0;
		$event['xml'] = \s9e\TextFormatter\Utils::replaceAttributes(
			$event['xml'],
			'DOI',
			function (array $attributes) use (&$counter)
			{
				$attributes['counter'] = ++$counter;

				return $attributes;
			}
		);
	}

	/**
	 * Allow <sub> and <sup> tags as well as 2 arrows within parsed [chem]-tags
	 * Usually all HTML is encoded but we need these.
	 * We'll take the paranoia approach to prevent any possible HTML abuse
	 *
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function finalize_markup($event)
	{
		// Find pseudo-tags
		preg_match_all('#<chem>(.*?)</chem>#', $event['html'], $chemtags);

		$replacements = array(
			'&lt;sub&gt;' => '<sub>',
			'&lt;/sub&gt;' => '</sub>',
			'&lt;sup&gt;' => '<sup>',
			'&lt;/sup&gt;' => '</sup>',
			'&lt;fa_exchange/&gt;' => '<span class="icon fa-fw fa-exchange"></span>',
			'&lt;fa_right/&gt;' => '<span class="icon fa-fw fa-long-arrow-right"></span>',
		);
		// Replace given elements within this markup
		foreach($chemtags[0] as $formula)
		{
			$parsed = str_replace(array_keys($replacements), array_values($replacements), $formula);
			$event['html'] = str_replace($formula, $parsed, $event['html']);
		}

		// Ditch now useless chem tag
		$event['html'] = str_replace('<chem>', '', $event['html']);
		$event['html'] = str_replace('</chem>', '', $event['html']);
	}
}
