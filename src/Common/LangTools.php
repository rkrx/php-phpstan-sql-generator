<?php

namespace Kir\PhpstanTypesFromSql\Common;

class LangTools {
	public static function singularize(string $word): string {
		$rules = [
			'{(ies)$}'  => 'y',   // categories → category
			'{(sses)$}' => 'ss',  // addresses → address
			'{(xes)$}'  => 'x',
			'{(oes)$}'  => 'o',
			'{(ches)$}' => 'ch',
			'{(shes)$}' => 'sh',
			'{(s)$}'    => '',    // general rule
		];
		
		foreach ($rules as $pattern => $replacement) {
			if (preg_match($pattern, $word)) {
				return (string) preg_replace($pattern, $replacement, $word);
			}
		}
		
		return $word;
	}
}