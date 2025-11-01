<?php

namespace Kir\PhpstanTypesFromSql\Common;

/**
 * @final
 * @abstract
 */
abstract class PHPTools {
	/**
	 * Embed lines of text into a phpdoc block.
	 *
	 * @param string $inner
	 * @return string
	 */
	public static function embedLinesIntoPhpDockBlock(string $inner): string {
		$typeLines = explode("\n", $inner);
		
		$typeLines = array_map(static fn($line) => " * $line", $typeLines);
		
		$typeBlock = implode("\n", $typeLines);
		return sprintf("/**\n%s\n */", $typeBlock);
	}
	
	/**
	 * @param string $analyzerPrefix
	 * @param string $tableName
	 * @param array<string, string> $properties Key = Property name, Value = Property type.
	 * @return string
	 */
	public static function generateAnalyzerShape(string $analyzerPrefix, string $tableName, string $shapeType, array $properties): string {
		$tableName = strtr($tableName, ['_' => ' ']);
		$tableName = ucwords($tableName);
		$tableName = strtr($tableName, [' ' => '']);
		
		$propertyStr = PHPTools::generateAnalyzerShapePropertiesAsString($properties);
		$propertyLines = explode("\n", $propertyStr);
		$propertyLines = array_map(static fn($line) => rtrim($line, ','), $propertyLines);
		$propertyLines = array_map(static fn($line) => "    $line", $propertyLines);
		$propertyStr = implode(",\n", $propertyLines);
		return sprintf("@%s-type T%s $shapeType{\n%s\n}\n",
			$analyzerPrefix,
			$tableName,
			$propertyStr
		);
	}
	
	/**
	 * @param array<string, string> $properties Key = Property name, Value = Property type.
	 * @return string
	 */
	public static function generateAnalyzerShapePropertiesAsString(array $properties): string {
		$lines = [];
		foreach($properties as $name => $type) {
			if(preg_match('/\\s+/', $name)) {
				$name = "'{$name}'";
			}
			$lines[] = sprintf('%s: %s', $name, $type);
		}
		return implode(",\n", $lines);
	}
}